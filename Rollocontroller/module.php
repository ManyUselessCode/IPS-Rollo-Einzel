<?php

declare(strict_types=1);

/**
 * Eine Instanz steuert ein Rollo, nach dem Home-Assistant-Blueprint "Rollo | Controller | Einzel".
 * Hier stehen alle Daten des Rollos: Sperre, Lüftungsposition, Verschattungsposition,
 * Himmelsrichtung und die Automatik (Morgens öffnen, Abends schließen, Verschattung nach
 * Sonnenstand). Dazu optional eigene Taster und LEDs, die nur dieses Rollo bedienen.
 * Wann die Rollos morgens und abends fahren, gibt die Wohnung in den zwei Variablen
 * "Rauf heute" und "Runter heute" vor, die ein Skript schreibt. Die Schalter "Automatik Rauf" und
 * "Automatik Runter" der Wohnung geben die beiden Fahrten für alle Rollos frei. Sie liegen mit dem
 * Schalter, dem Öffnungswinkel und der Mindest-Sonnenhöhe der Verschattung in einem gemeinsamen Ordner.
 *
 * Positionen sind Prozent geschlossen wie am Aktor und in Symcon: 0 = ganz offen, 100 = zu.
 * Die Position kommt aus der Status-Variable, nie aus einer Befehlsinstanz.
 * Ob das Rollo fährt, meldet der Aktor nicht. Das Modul leitet es aus den Telegrammen auf
 * den Adressen des Rollos ab: Ein Fahrbefehl beginnt die Fahrt, ein Stopp oder die neue
 * Position vom Aktor beendet sie, sonst die Fahrzeit. Unterwegs und nach einem Stopp
 * schätzt das Modul die Position aus der Fahrzeit, bis der Aktor sie meldet.
 */
class Rollocontroller extends IPSModule
{
    private const STANDORT_MODUL = '{45E97A63-F870-408A-B259-2933F7EABF74}'; // Location Control

    // Darstellungen, GUIDs aus IPS_GetPresentations (Symcon 9.0)
    private const DARSTELLUNG_SCHALTER = '{60AE6B26-B3E2-BDB1-A3A1-BE232940664B}';
    private const DARSTELLUNG_REGLER = '{6B9CAEEC-5958-C223-30F7-BD36569FC57A}';
    private const DARSTELLUNG_ZEIT = '{497C4845-27FA-6E4F-AE37-5D951D3BDBF9}';
    private const DARSTELLUNG_ROLLLADEN = '{6075FC22-69AF-B110-3749-C24138883082}';
    private const DARSTELLUNG_AUFZAEHLUNG = '{52D9E126-D7D2-2CBB-5E62-4CF7BA7C5D82}';

    // Werte der Variable "Fahrt", in der Reihenfolge der Tasten in der App
    private const FAHRT_AUF = 0;
    private const FAHRT_STEHT = 1;
    private const FAHRT_AB = 2;

    // Positionen in Prozent geschlossen
    private const OFFEN = 0;
    private const ZU = 100;

    // Farben der MDT-LEDs (DPT 5): Grün fährt, Blau steht. Bei den Tasten der Schalter: Grün an, Rot aus
    private const LED_ROT = 2;
    private const LED_GRUEN = 3;
    private const LED_BLAU = 4;

    private const BEFEHL_NAME = ['auf' => 'Auf', 'ab' => 'Ab', 'stopp' => 'Stopp', 'lueften' => 'Lüften'];

    private const STATUS_FEHLER = 201;
    private const STATUS_KEINE_EINSTELLUNGEN = 202;
    private const BEFEHL_SPERRE_S = 5;      // so lange zählt ein Telegramm mit dem Wert des eigenen Befehls als eigenes
    private const START_WARTEN_MS = 30000;  // Gateway und Bus nach dem Start abwarten
    private const ANTWORT_WARTEN_MS = 5000; // Zeit für die Antwort auf die Leseanfrage
    private const TOLERANZ = 2;             // Prozentpunkte, in denen eine Position als erreicht gilt
    private const FAHRT_WARTEN = 2;         // so viele Fahrzeiten wartet das Modul auf die Meldung des Aktors
    private const FAHRT_RESERVE_S = 3;      // Zuschlag auf diese Wartezeit
    private const NACHLAUF_S = 2;           // so lange nach Fahrtbeginn kann noch die Meldung der vorigen Fahrt kommen

    private const ROLLO_INSTANZEN = [
        'FahrenID'   => 'Auf/Ab fahren',
        'StoppID'    => 'Stopp',
        'PositionID' => 'Position Höhe anfahren',
        'SperrenID'  => 'Sperren'
    ];
    private const TASTER_LISTEN = [
        'MdtWippenKurz', 'MdtWippenLang', 'JungWippenKurz', 'JungWippenLang',
        'TastenAufKurz', 'TastenAbKurz', 'TastenAbLang'
    ];
    // Tasten, die einen Schalter des Rollos umschalten: Liste => Ident des Schalters
    private const SCHALTER_TASTEN = ['TastenAutomatik' => 'Automatik', 'TastenVerschattung' => 'Verschattung'];
    private const SCHALTER_LEDS = ['InstanceID', 'JungGruenID', 'JungRotID'];
    private const LED_LISTEN = ['MdtLedsAuf', 'MdtLedsAb', 'JungLedsBlau', 'JungLedsAufGruen', 'JungLedsAbGruen'];

    // Variablen der Wohnung im Ordner der Einstellungen, gesucht über ihren Ident.
    // Die Zeitpunkte fürs Öffnen und Schließen und die Einstellung, die das Rollo ihnen folgen lässt:
    private const ZEITPUNKTE = ['RaufHeute' => 'MorgensOeffnen', 'RunterHeute' => 'AbendsSchliessen'];
    // Ein Wechsel dieser Variablen prüft die Verschattung neu:
    private const EINSTELLUNGEN_SONNE = ['AutomatikVerschattung', 'Oeffnungswinkel', 'Mindesthoehe'];

    // Aktionsskript der Variablen der Wohnung: übernimmt den Wert aus App und WebFront
    private const AKTIONSSKRIPT = "<?php\n\nSetValue(\$_IPS['VARIABLE'], \$_IPS['VALUE']);\n";

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyInteger('EinstellungenID', 0);
        $this->RegisterPropertyInteger('FahrenID', 0);
        $this->RegisterPropertyInteger('StoppID', 0);
        $this->RegisterPropertyInteger('PositionID', 0);
        $this->RegisterPropertyInteger('StatusID', 0);
        $this->RegisterPropertyInteger('SperrenID', 0);
        $this->RegisterPropertyInteger('Fahrzeit', 30);
        $this->RegisterPropertyInteger('Lueftungsposition', 80);
        $this->RegisterPropertyFloat('Himmelsrichtung', 180.0);
        $this->RegisterPropertyBoolean('MorgensOeffnen', false);
        $this->RegisterPropertyBoolean('AbendsSchliessen', false);
        $this->RegisterPropertyBoolean('Verschattung', false);
        $this->RegisterPropertyInteger('Verschattungsposition', 80);
        foreach (array_merge(self::TASTER_LISTEN, self::LED_LISTEN, array_keys(self::SCHALTER_TASTEN)) as $liste) {
            $this->RegisterPropertyString($liste, '[]');
        }

        $this->RegisterTimer('Start', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], \'StartSchritt\', 0);');
        $this->RegisterTimer('Fahrt', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], \'FahrtAbgelaufen\', 0);');
        $this->RegisterTimer('Automatik', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], \'AutomatikPruefen\', 0);');

        $this->RegisterVariableInteger('Position', 'Position', [
            'PRESENTATION'        => self::DARSTELLUNG_ROLLLADEN,
            'USAGE_TYPE'          => 0, // Öffnung, nicht Lamellen
            'OPEN_OUTSIDE_VALUE'  => self::OFFEN,
            'CLOSE_INSIDE_VALUE'  => self::ZU
        ], 1);
        $this->RegisterVariableInteger('Fahrt', 'Fahrt', [
            'PRESENTATION' => self::DARSTELLUNG_AUFZAEHLUNG,
            'LAYOUT'       => 1, // Reihe
            'OPTIONS'      => json_encode([
                ['Value' => self::FAHRT_AUF, 'Caption' => 'Auf', 'IconActive' => true, 'IconValue' => 'arrow-up'],
                ['Value' => self::FAHRT_STEHT, 'Caption' => 'Stopp', 'IconActive' => true, 'IconValue' => 'stop'],
                ['Value' => self::FAHRT_AB, 'Caption' => 'Ab', 'IconActive' => true, 'IconValue' => 'arrow-down']
            ])
        ], 2);
        $this->RegisterVariableBoolean('Sperre', 'Sperre', $this->Schalter('lock'), 3);
        $this->RegisterVariableString('Grund', 'Grund', '', 6);
        foreach (['Position', 'Fahrt', 'Sperre'] as $ident) {
            $this->EnableAction($ident);
        }
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        foreach ($this->GetMessageList() as $sender => $nachrichten) {
            foreach ($nachrichten as $nachricht) {
                $this->UnregisterMessage($sender, $nachricht);
            }
        }
        foreach ($this->GetReferenceList() as $referenz) {
            $this->UnregisterReference($referenz);
        }
        $this->RegisterMessage(0, IPS_KERNELMESSAGE);

        // Den Schalter Automatik hat jedes Rollo, den Schalter Verschattung nur eines mit Verschattung
        $verschattung = $this->ReadPropertyBoolean('Verschattung');
        $this->MaintainVariable('Automatik', 'Automatik', VARIABLETYPE_BOOLEAN, $this->Schalter('sunrise'), 4, true);
        $this->MaintainVariable('Verschattung', 'Verschattung', VARIABLETYPE_BOOLEAN, $this->Schalter('sun'), 5, $verschattung);
        $this->EnableAction('Automatik');
        $this->Vorgabe('Automatik', true);
        if ($verschattung) {
            $this->EnableAction('Verschattung');
            $this->Vorgabe('Verschattung', true);
        }

        if (IPS_GetKernelRunlevel() == KR_READY) {
            $this->Einrichten();
        }
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message == IPS_KERNELMESSAGE) {
            if ($Data[0] == KR_READY) {
                $this->Einrichten(true);
                $this->SetBuffer('StartSchritt', 'Lesen');
                $this->SetTimerInterval('Start', self::START_WARTEN_MS);
            }
            return;
        }
        if ($Message != VM_UPDATE || $this->GetStatus() != IS_ACTIVE) {
            return;
        }

        // Taster: jedes Telegramm zählt als Druck
        if ($this->SchalterTasteGedrueckt($SenderID)) {
            return;
        }
        if ($this->TasterGedrueckt($SenderID, (bool) $Data[0])) {
            return;
        }

        // Telegramme auf den Adressen des Rollos, vom Modul selbst oder von anderen am Bus
        if ($SenderID == $this->WertVariable('FahrenID')) {
            $this->FahrbefehlGesehen($Data[0] ? self::ZU : self::OFFEN, $this->EigenesTelegramm('FahrenID', (bool) $Data[0])); // DPT 1.008: an = ab
            return;
        }
        if ($SenderID == $this->WertVariable('PositionID')) {
            $this->FahrbefehlGesehen($this->Begrenzen((int) $Data[0]), $this->EigenesTelegramm('PositionID', $this->Begrenzen((int) $Data[0])));
            return;
        }
        if ($SenderID == $this->WertVariable('StoppID')) {
            if (!$this->EigenesTelegramm('StoppID', (bool) $Data[0]) && $this->Fahrt() != self::FAHRT_STEHT) {
                $this->Grund('Stopp: von Extern');
            }
            $this->FahrtEndet();
            return;
        }
        if ($SenderID == $this->WertVariable('SperrenID')) {
            $this->SperreGemeldet((bool) $Data[0], (bool) $Data[1], $this->EigenesTelegramm('SperrenID', (bool) $Data[0]));
            return;
        }
        if ($SenderID == $this->ReadPropertyInteger('StatusID')) {
            $this->StatusGemeldet((int) $Data[0], (bool) $Data[1]);
            return;
        }

        if (!$Data[1]) {
            return; // alles andere nur, wenn sich der Wert geändert hat
        }
        if (in_array($SenderID, $this->SonneVariablen(), true)) {
            $this->SonnePruefen();
            return;
        }
        foreach (array_keys(self::ZEITPUNKTE) as $ident) {
            if ($SenderID == $this->EinstellungVariable($ident)) {
                $this->AutomatikPruefen();
                return;
            }
        }
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'Position':
                $this->PositionAusApp((int) round((float) $Value));
                break;
            case 'Fahrt':
                $befehl = [self::FAHRT_AUF => 'auf', self::FAHRT_STEHT => 'stopp', self::FAHRT_AB => 'ab'][(int) $Value] ?? '';
                if ($befehl == '') {
                    throw new Exception('Unbekannte Fahrt: ' . $Value);
                }
                $this->Ausfuehren($befehl, 'App');
                break;
            case 'Sperre':
                $this->SperreSchalten((bool) $Value, 'App');
                break;
            case 'Automatik':
            case 'Verschattung':
                $this->SchalterSetzen($Ident, (bool) $Value, 'App');
                break;
            case 'SchalterTaste':
                // ein anderes Rollo mit derselben Taste gleicht diesen Schalter an
                $auftrag = json_decode((string) $Value, true);
                if (!is_array($auftrag) || !in_array($auftrag['Ident'] ?? '', self::SCHALTER_TASTEN, true)) {
                    throw new Exception('Unbekannter Schalter: ' . $Value);
                }
                $this->SchalterSetzen($auftrag['Ident'], (bool) $auftrag['Wert'], (string) $auftrag['Quelle']);
                break;
            case 'StartSchritt':
                $this->StartSchritt();
                break;
            case 'FahrtAbgelaufen':
                // der Aktor hat nichts gemeldet: das Ziel gilt als erreicht, bis er meldet
                $this->SetTimerInterval('Fahrt', 0);
                $this->FahrtEndet();
                break;
            case 'AutomatikPruefen':
                $this->AutomatikPruefen();
                break;
            default:
                throw new Exception('Unbekannter Ident: ' . $Ident);
        }
    }

    /**
     * Befehl aus einem Skript oder von einer Rollo-Gruppe: auf, ab, stopp oder lueften.
     * Entspricht dem Ereignis rollo_befehl des Blueprints. Das Rollo führt den Befehl mit
     * seiner Sperre und seiner Lüftungsposition aus, $Quelle steht im Grund.
     */
    public function Befehl(string $Befehl, string $Quelle): void
    {
        if (!isset(self::BEFEHL_NAME[$Befehl])) {
            throw new Exception('Unbekannter Befehl: ' . $Befehl);
        }
        $this->Ausfuehren($Befehl, $Quelle);
    }

    /**
     * Legt im Ordner der Einstellungen die Variablen der Wohnung an, die dort noch fehlen:
     * mit Vorgabe, Darstellung und einem Aktionsskript, damit sie sich in App und WebFront
     * ändern lassen. Vorhandene Variablen und ihre Werte bleiben unberührt.
     */
    public function EinstellungenAnlegen(): void
    {
        $ordner = $this->ReadPropertyInteger('EinstellungenID');
        if ($ordner == 0 || !IPS_CategoryExists($ordner)) {
            throw new Exception('Kein Ordner für die Einstellungen der Wohnung gewählt');
        }
        $skript = @IPS_GetObjectIDByIdent('WertUebernehmen', $ordner);
        if ($skript === false) {
            $skript = IPS_CreateScript(0); // 0 = PHP
            IPS_SetParent($skript, $ordner);
            IPS_SetIdent($skript, 'WertUebernehmen');
            IPS_SetName($skript, 'Wert übernehmen');
            IPS_SetPosition($skript, 100);
            IPS_SetHidden($skript, true);
            IPS_SetScriptContent($skript, self::AKTIONSSKRIPT);
        }
        $position = 0;
        foreach ($this->Einstellungen() as $ident => $einstellung) {
            $position++;
            if (@IPS_GetObjectIDByIdent($ident, $ordner) !== false) {
                continue;
            }
            $id = IPS_CreateVariable($einstellung['Typ']);
            IPS_SetParent($id, $ordner);
            IPS_SetIdent($id, $ident);
            IPS_SetName($id, $einstellung['Name']);
            IPS_SetPosition($id, $position);
            IPS_SetVariableCustomPresentation($id, $einstellung['Darstellung']);
            IPS_SetVariableCustomAction($id, $skript);
            SetValue($id, $einstellung['Vorgabe']);
        }
    }

    /**
     * Beim Start ($start) wartet das Modul mit LEDs, Verschattung und Automatik auf die
     * gelesene Position in StartSchritt, statt mit dem alten Stand zu fahren.
     */
    private function Einrichten(bool $start = false): void
    {
        // eine laufende Fahrt kennt das Modul nach dem Start oder Übernehmen nicht mehr
        $this->SetTimerInterval('Fahrt', 0);
        $this->SetBuffer('Fahrt', '');
        $this->SetValue('Fahrt', self::FAHRT_STEHT);
        if ($start) {
            // die Position von vor dem Start gilt nur als Stand, bis der Aktor sie wieder meldet
            $position = $this->StatusPosition();
            $this->SetBuffer('Stand', $position === null ? '' : (string) $position);
        }

        $status = $this->ReadPropertyInteger('StatusID');
        $vollstaendig = $status > 0;
        foreach (array_keys(self::ROLLO_INSTANZEN) as $eigenschaft) {
            $vollstaendig = $vollstaendig && $this->ReadPropertyInteger($eigenschaft) > 0;
        }
        if (!$vollstaendig) {
            $this->SetTimerInterval('Automatik', 0);
            $this->SetStatus(IS_INACTIVE);
            return;
        }
        // die Variablen der Wohnung braucht nur die Automatik
        $ordner = $this->ReadPropertyInteger('EinstellungenID');
        if ($this->BrauchtEinstellungen() && ($ordner == 0 || !IPS_CategoryExists($ordner))) {
            $this->SetTimerInterval('Automatik', 0);
            $this->SetStatus(self::STATUS_KEINE_EINSTELLUNGEN);
            return;
        }
        $fehler = $this->Pruefen();
        if ($fehler != '') {
            $this->LogMessage($fehler, KL_ERROR);
            $this->SetTimerInterval('Automatik', 0);
            $this->SetStatus(self::STATUS_FEHLER);
            return;
        }

        $this->RegisterMessage($status, VM_UPDATE);
        $this->RegisterReference($status);
        foreach (array_keys(self::ROLLO_INSTANZEN) as $eigenschaft) {
            $this->RegisterMessage($this->WertVariable($eigenschaft), VM_UPDATE);
            $this->RegisterReference($this->ReadPropertyInteger($eigenschaft));
        }
        foreach (self::TASTER_LISTEN as $liste) {
            foreach ($this->Liste($liste) as $taster) {
                $this->RegisterMessage($taster['VariableID'], VM_UPDATE);
                $this->RegisterReference($taster['VariableID']);
            }
        }
        foreach (self::LED_LISTEN as $liste) {
            foreach ($this->Liste($liste) as $led) {
                $this->RegisterReference($led['InstanceID']);
            }
        }
        if ($this->BrauchtEinstellungen()) {
            $this->RegisterReference($ordner);
            foreach (array_merge(self::EINSTELLUNGEN_SONNE, array_keys(self::ZEITPUNKTE)) as $ident) {
                $this->RegisterMessage($this->EinstellungVariable($ident), VM_UPDATE);
            }
        }
        foreach (array_keys(self::SCHALTER_TASTEN) as $liste) {
            foreach ($this->Liste($liste) as $taste) {
                $this->RegisterMessage($taste['VariableID'], VM_UPDATE);
                $this->RegisterReference($taste['VariableID']);
                foreach (self::SCHALTER_LEDS as $feld) {
                    if (($taste[$feld] ?? 0) > 0) {
                        $this->RegisterReference($taste[$feld]);
                    }
                }
            }
        }
        if ($this->ReadPropertyBoolean('Verschattung')) {
            foreach ($this->StandortVariablen() as $variable) {
                $this->RegisterMessage($variable, VM_UPDATE);
            }
        }

        IPS_SetHidden($this->GetIDForIdent('Grund'), true);
        $this->SetStatus(IS_ACTIVE);
        if ($start) {
            // die Zeitpunkte der Wohnung gelten sofort, auch in der Wartezeit nach dem Start
            $this->AutomatikPruefen();
        } else {
            $this->Starten();
        }
    }

    private function Pruefen(): string
    {
        $falsch = [];
        foreach (self::ROLLO_INSTANZEN as $eigenschaft => $name) {
            if ($this->WertVariable($eigenschaft) == 0) {
                $falsch[] = $name . ' ' . $this->ReadPropertyInteger($eigenschaft);
            }
        }
        if (!IPS_VariableExists($this->ReadPropertyInteger('StatusID'))) {
            $falsch[] = 'Status Höhe ' . $this->ReadPropertyInteger('StatusID');
        }
        // jede Adresse des Rollos braucht ihre eigene Instanz, sonst antwortet das Modul auf sein eigenes Telegramm
        $rollo = [$this->ReadPropertyInteger('StatusID')];
        foreach (array_keys(self::ROLLO_INSTANZEN) as $eigenschaft) {
            $rollo[] = $this->WertVariable($eigenschaft);
        }
        $rollo = array_filter($rollo);
        if (count($rollo) != count(array_unique($rollo))) {
            $falsch[] = 'dieselbe Instanz mehrfach beim Rollo gewählt';
        }
        foreach (self::TASTER_LISTEN as $liste) {
            foreach ($this->Liste($liste) as $taster) {
                if (!IPS_VariableExists($taster['VariableID'])) {
                    $falsch[] = 'Taster ' . $taster['VariableID'];
                } elseif (in_array($taster['VariableID'], $rollo, true)) {
                    $falsch[] = 'Taster ' . $taster['VariableID'] . ' ist eine Adresse des Rollos';
                }
            }
        }
        foreach (self::LED_LISTEN as $liste) {
            foreach ($this->Liste($liste) as $led) {
                if (!IPS_InstanceExists($led['InstanceID'])) {
                    $falsch[] = 'LED ' . $led['InstanceID'];
                }
            }
        }
        foreach (array_keys(self::SCHALTER_TASTEN) as $liste) {
            foreach ($this->Liste($liste) as $taste) {
                if (!IPS_VariableExists($taste['VariableID'])) {
                    $falsch[] = 'Taste ' . $taste['VariableID'];
                } elseif (in_array($taste['VariableID'], $rollo, true)) {
                    $falsch[] = 'Taste ' . $taste['VariableID'] . ' ist eine Adresse des Rollos';
                }
                foreach (self::SCHALTER_LEDS as $feld) {
                    if (($taste[$feld] ?? 0) > 0 && !IPS_InstanceExists($taste[$feld])) {
                        $falsch[] = 'LED ' . $taste[$feld];
                    }
                }
            }
        }
        if ($this->BrauchtEinstellungen()) {
            foreach (array_keys($this->Einstellungen()) as $ident) {
                if ($this->EinstellungVariable($ident) == 0) {
                    $falsch[] = 'im Ordner der Einstellungen fehlt die Variable ' . $ident;
                }
            }
        }
        if ($this->ReadPropertyBoolean('Verschattung') && count($this->StandortVariablen()) < 2) {
            $falsch[] = 'Location Control ohne Azimuth und Altitude';
        }
        return count($falsch) ? 'Ungültige Einstellungen: ' . implode(', ', $falsch) : '';
    }

    /**
     * Nach dem Start und nach dem Übernehmen der Einstellungen, wie im Blueprint: Zustand und
     * LEDs zeigen, Verschattung sofort prüfen, den Timer auf Rauf heute und Runter heute stellen.
     */
    private function Starten(): void
    {
        $this->SetValue('Sperre', $this->Gesperrt());
        $this->PositionZeigen();
        $this->LedsSetzen(true);
        $this->SchalterLedsSetzen();

        $sonne = $this->SonneAufFenster();
        $this->SetBuffer('Sonne', $sonne ? '1' : '0');
        if ($this->ReadPropertyBoolean('Verschattung') && $sonne && !$this->Gesperrt()) {
            $position = $this->Position();
            $ziel = $this->ReadPropertyInteger('Verschattungsposition');
            if ($position === null || $position < $ziel - self::TOLERANZ) {
                $this->PositionAnfahren($ziel, 'Verschattung: Sonne auf dem Fenster');
            }
        }
        $this->AutomatikPruefen();
    }

    private function StartSchritt(): void
    {
        if ($this->GetBuffer('StartSchritt') == 'Lesen') {
            $this->SetBuffer('StartSchritt', 'Pruefen');
            $this->SetTimerInterval('Start', self::ANTWORT_WARTEN_MS);
            if ($this->GetStatus() == IS_ACTIVE) {
                $this->StatusAnfragen($this->ReadPropertyInteger('StatusID'));
            }
            return;
        }

        $this->SetTimerInterval('Start', 0);
        $this->SetBuffer('StartSchritt', '');
        if ($this->GetStatus() == IS_ACTIVE) {
            $this->Starten();
        }
    }

    private function StatusAnfragen(int $variable): void
    {
        $instanz = IPS_GetParent($variable);
        if (IPS_InstanceExists($instanz) && function_exists('KNX_RequestStatus')) {
            @KNX_RequestStatus($instanz);
        }
    }

    // ------------------------------------------------------------------ Taster und Befehle

    /**
     * Eine Taste aus "Tasten Automatik" oder "Tasten Verschattung" schaltet den Schalter um.
     * Haben mehrere Rollos dieselbe Taste, gleicht sie an: Ist einer ihrer Schalter an, gehen
     * alle aus, sonst alle an. Damit das genau einmal passiert, handelt nur das Rollo mit der
     * kleinsten ID und setzt die Schalter der anderen mit.
     */
    private function SchalterTasteGedrueckt(int $sender): bool
    {
        $getroffen = false;
        foreach (self::SCHALTER_TASTEN as $liste => $ident) {
            foreach ($this->Liste($liste) as $taste) {
                if ($taste['VariableID'] != $sender) {
                    continue;
                }
                $getroffen = true;
                $gruppe = $this->SchalterGruppe($liste, $sender);
                if (count($gruppe) == 0 || min(array_keys($gruppe)) != $this->InstanceID) {
                    break;
                }
                $name = trim((string) ($taste['Name'] ?? ''));
                if ($name == '') {
                    $name = 'Taste ' . $sender;
                }
                $ziel = !in_array(true, $gruppe, true);
                foreach (array_keys($gruppe) as $instanz) {
                    if ($instanz != $this->InstanceID) {
                        IPS_RequestAction($instanz, 'SchalterTaste', json_encode(['Ident' => $ident, 'Wert' => $ziel, 'Quelle' => $name]));
                    }
                }
                $this->SchalterSetzen($ident, $ziel, $name);
                break;
            }
        }
        return $getroffen;
    }

    /**
     * Die Rollos, deren Schalter $liste dieselbe Taste hat, mit dem Stand ihres Schalters:
     * Instanz => an. Dieses Rollo gehört dazu, wenn es den Schalter hat.
     */
    private function SchalterGruppe(string $liste, int $taste): array
    {
        $ident = self::SCHALTER_TASTEN[$liste];
        $gruppe = [];
        foreach (IPS_GetInstanceListByModuleID(IPS_GetInstance($this->InstanceID)['ModuleInfo']['ModuleID']) as $instanz) {
            if ($instanz != $this->InstanceID) {
                if (IPS_GetInstance($instanz)['InstanceStatus'] != IS_ACTIVE) {
                    continue;
                }
                $zeilen = json_decode((string) @IPS_GetProperty($instanz, $liste), true) ?: [];
                if (!in_array($taste, array_column($zeilen, 'VariableID'), true)) {
                    continue;
                }
            }
            $schalter = @IPS_GetObjectIDByIdent($ident, $instanz);
            if ($schalter !== false) {
                $gruppe[$instanz] = (bool) GetValue($schalter);
            }
        }
        return $gruppe;
    }

    /**
     * Setzt den Schalter Automatik oder Verschattung, aus der App oder von einer Taste.
     */
    private function SchalterSetzen(string $ident, bool $an, string $quelle): void
    {
        if (@IPS_GetObjectIDByIdent($ident, $this->InstanceID) === false) {
            return; // ein Rollo ohne Verschattung hat den Schalter nicht
        }
        if ($this->GetValue($ident) !== $an || $quelle == 'App') {
            $this->SetValue($ident, $an);
            $this->Grund($ident . ' ' . ($an ? 'an' : 'aus') . ': ' . $quelle);
            if ($ident == 'Verschattung') {
                $this->SonnePruefen();
            }
        }
        $this->SchalterLedsSetzen($ident);
    }

    /**
     * Die LED einer Schalter-Taste zeigt den Schalter: Grün an, Rot aus (MDT eine LED mit Farbwert,
     * Jung je Farbe eine LED). Teilen sich mehrere
     * Rollos die Taste, ist sie nur Grün, wenn alle ihre Schalter an sind.
     */
    private function SchalterLedsSetzen(string $nur = ''): void
    {
        if ($this->GetStatus() != IS_ACTIVE) {
            return;
        }
        foreach (self::SCHALTER_TASTEN as $liste => $ident) {
            if ($nur != '' && $nur != $ident) {
                continue;
            }
            foreach ($this->Liste($liste) as $taste) {
                $mdt = $taste['InstanceID'] ?? 0;
                $gruen = $taste['JungGruenID'] ?? 0;
                $rot = $taste['JungRotID'] ?? 0;
                if ($mdt == 0 && $gruen == 0 && $rot == 0) {
                    continue;
                }
                $gruppe = $this->SchalterGruppe($liste, $taste['VariableID']);
                if (!isset($gruppe[$this->InstanceID])) {
                    continue;
                }
                $an = !in_array(false, $gruppe, true);
                if ($mdt > 0) {
                    @KNX_WriteDPT5($mdt, $an ? self::LED_GRUEN : self::LED_ROT);
                }
                // Jung: je Farbe eine eigene LED, erst die falsche aus, dann die richtige an
                foreach ($an ? [[$rot, false], [$gruen, true]] : [[$gruen, false], [$rot, true]] as [$led, $wert]) {
                    if ($led > 0) {
                        @KNX_WriteDPT1($led, $wert);
                    }
                }
            }
        }
    }

    /**
     * Wippen senden die Richtung im Wert: MDT oben = aus = auf und unten = an = ab,
     * Jung an = auf und aus = ab. Lang fährt nur nach unten in die Lüftungsposition.
     */
    private function TasterGedrueckt(int $sender, bool $an): bool
    {
        foreach (self::TASTER_LISTEN as $liste) {
            foreach ($this->Liste($liste) as $taster) {
                if ($taster['VariableID'] != $sender) {
                    continue;
                }
                $name = trim((string) ($taster['Name'] ?? ''));
                if ($name == '') {
                    $name = 'Taster ' . $sender;
                }
                if (!empty($taster['Invertiert'])) {
                    $an = !$an;
                }
                switch ($liste) {
                    case 'MdtWippenKurz':
                        $this->TasteKurz($an ? self::FAHRT_AB : self::FAHRT_AUF, $name);
                        break;
                    case 'JungWippenKurz':
                        $this->TasteKurz($an ? self::FAHRT_AUF : self::FAHRT_AB, $name);
                        break;
                    case 'TastenAufKurz':
                        $this->TasteKurz(self::FAHRT_AUF, $name);
                        break;
                    case 'TastenAbKurz':
                        $this->TasteKurz(self::FAHRT_AB, $name);
                        break;
                    case 'MdtWippenLang':
                        if ($an) {
                            $this->Ausfuehren('lueften', $name);
                        }
                        break;
                    case 'JungWippenLang':
                        if (!$an) {
                            $this->Ausfuehren('lueften', $name);
                        }
                        break;
                    case 'TastenAbLang':
                        $this->Ausfuehren('lueften', $name);
                        break;
                }
                return true;
            }
        }
        return false;
    }

    /**
     * Kurz fährt in die Richtung der Taste. Fährt das Rollo schon dorthin, hält es an.
     */
    private function TasteKurz(int $richtung, string $name): void
    {
        if ($this->Fahrt() == $richtung) {
            $this->Ausfuehren('stopp', $name);
        } else {
            $this->Ausfuehren($richtung == self::FAHRT_AUF ? 'auf' : 'ab', $name);
        }
    }

    /**
     * Führt auf, ab, stopp oder lueften aus. Ein gesperrtes Rollo fährt nie.
     */
    private function Ausfuehren(string $befehl, string $quelle): void
    {
        if ($this->GetStatus() != IS_ACTIVE) {
            return;
        }
        if ($this->Gesperrt()) {
            $this->Grund('Gesperrt: ' . self::BEFEHL_NAME[$befehl] . ' von ' . $quelle . ' nicht ausgeführt');
            return;
        }
        $steht = $this->Fahrt() == self::FAHRT_STEHT;
        // nur eine vom Aktor gemeldete Endlage spart die Fahrt, eine geschätzte nicht
        $endlage = $steht && $this->GetBuffer('Stand') === '' ? $this->StatusPosition() : null;
        switch ($befehl) {
            case 'auf':
                if (!($endlage !== null && $endlage <= self::OFFEN)) {
                    $this->Fahren(self::OFFEN, 'Auf: ' . $quelle);
                }
                break;
            case 'ab':
                if (!($endlage !== null && $endlage >= self::ZU)) {
                    $this->Fahren(self::ZU, 'Ab: ' . $quelle);
                }
                break;
            case 'stopp':
                // auch wenn das Rollo als stehend gilt, der Aktor das aber noch nicht gemeldet hat
                if (!$steht || $this->GetBuffer('Stand') !== '') {
                    $this->Stoppen('Stopp: ' . $quelle);
                }
                break;
            case 'lueften':
                $this->PositionAnfahren($this->ReadPropertyInteger('Lueftungsposition'), 'Lüften: ' . $quelle);
                break;
        }
    }

    /**
     * Der Schieber in der App: Die Enden fahren über die Fahren-Adresse, alles dazwischen
     * über die Positions-Adresse.
     */
    private function PositionAusApp(int $ziel): void
    {
        if ($this->GetStatus() != IS_ACTIVE) {
            return;
        }
        $ziel = $this->Begrenzen($ziel);
        if ($this->Gesperrt()) {
            $this->Grund('Gesperrt: Position ' . $ziel . ' % von App nicht ausgeführt');
        } elseif ($ziel == self::OFFEN) {
            $this->Ausfuehren('auf', 'App');
        } elseif ($ziel == self::ZU) {
            $this->Ausfuehren('ab', 'App');
        } else {
            $this->PositionAnfahren($ziel, 'Position ' . $ziel . ' %: App');
        }
        if ($this->Fahrt() == self::FAHRT_STEHT) {
            $this->PositionZeigen(); // der Schieber springt zurück, wenn nichts fährt
        }
    }

    private function SperreSchalten(bool $an, string $quelle): void
    {
        if ($this->GetStatus() != IS_ACTIVE) {
            return;
        }
        $this->BefehlMerken('SperrenID', $an);
        $this->Grund('Sperre ' . ($an ? 'an' : 'aus') . ': ' . $quelle);
        if (!@KNX_WriteDPT1($this->ReadPropertyInteger('SperrenID'), $an)) {
            $this->LogMessage('Sperre schalten fehlgeschlagen: ' . $this->ReadPropertyInteger('SperrenID'), KL_ERROR);
        }
    }

    // ------------------------------------------------------------------ Senden

    /**
     * Ganz auf oder ganz zu über die Fahren-Adresse.
     */
    private function Fahren(int $ziel, string $grund): void
    {
        $this->BefehlMerken('FahrenID', $ziel == self::ZU);
        $this->Grund($grund);
        if (!@KNX_WriteDPT1($this->ReadPropertyInteger('FahrenID'), $ziel == self::ZU)) {
            $this->LogMessage('Fahren fehlgeschlagen: ' . $this->ReadPropertyInteger('FahrenID'), KL_ERROR);
            return;
        }
        $this->FahrtBeginnt($ziel);
    }

    /**
     * Position über die Positions-Adresse. RequestAction rechnet wie die App in Prozent,
     * unabhängig davon, wie die Instanz den Wert auf den Bus schreibt.
     */
    private function PositionAnfahren(int $ziel, string $grund): void
    {
        $ziel = $this->Begrenzen($ziel);
        $this->BefehlMerken('PositionID', $ziel);
        $this->Grund($grund);
        $variable = $this->WertVariable('PositionID');
        if ($variable == 0 || !@RequestAction($variable, $ziel)) {
            $this->LogMessage('Position anfahren fehlgeschlagen: ' . $this->ReadPropertyInteger('PositionID'), KL_ERROR);
            return;
        }
        $this->FahrtBeginnt($ziel);
    }

    private function Stoppen(string $grund): void
    {
        $this->BefehlMerken('StoppID', true);
        $this->Grund($grund);
        if (!@KNX_WriteDPT1($this->ReadPropertyInteger('StoppID'), true)) {
            $this->LogMessage('Stoppen fehlgeschlagen: ' . $this->ReadPropertyInteger('StoppID'), KL_ERROR);
        }
        $this->FahrtEndet();
    }

    // ------------------------------------------------------------------ Fahrt

    /**
     * Ein Fahrbefehl stand auf dem Bus, vom Modul selbst oder von einem anderen Gerät.
     */
    private function FahrbefehlGesehen(int $ziel, bool $eigen): void
    {
        if ($this->Gesperrt()) {
            // der Aktor verhindert die Fahrt, also beginnt auch im Modul keine
            if (!$eigen) {
                $this->Grund('Gesperrt: ' . $this->ZielName($ziel) . ' von Extern nicht ausgeführt');
            }
            return;
        }
        if (!$eigen) {
            $this->Grund($this->ZielName($ziel) . ': von Extern');
        }
        $this->FahrtBeginnt($ziel);
    }

    /**
     * Der Aktor meldet die Position am Ende einer Fahrt und nach einem Stopp, die Fahrt ist
     * damit zu Ende. Während der Fahrt zählt eine Meldung abseits des Ziels nicht, wenn sie
     * unverändert ist (Antwort auf eine Leseanfrage) oder gleich nach dem Fahrtbeginn kommt
     * (Meldung der vorigen Fahrt, etwa nach Stopp und sofortigem Richtungswechsel).
     */
    private function StatusGemeldet(int $position, bool $geaendert): void
    {
        $fahrt = json_decode($this->GetBuffer('Fahrt'), true);
        if ($this->Fahrt() != self::FAHRT_STEHT && is_array($fahrt)) {
            $amZiel = abs($position - (int) $fahrt['Ziel']) <= self::TOLERANZ;
            $frisch = microtime(true) - (float) $fahrt['Start'] < self::NACHLAUF_S;
            if (!$amZiel && (!$geaendert || $frisch)) {
                return;
            }
        }
        $this->FahrtEndet($this->Begrenzen($position));
    }

    private function SperreGemeldet(bool $an, bool $geaendert, bool $eigen): void
    {
        $this->SetValue('Sperre', $an);
        if ($geaendert && !$eigen) {
            $this->Grund('Sperre ' . ($an ? 'an' : 'aus') . ': von Extern');
        }
        if ($an && $this->Fahrt() != self::FAHRT_STEHT) {
            $this->Stoppen('Stopp: Sperre eingeschaltet');
        }
    }

    /**
     * Beginnt die Fahrt zum Ziel. Die Richtung ergibt sich aus der Position, an der das
     * Rollo steht oder gerade vorbeifährt. Steht es schon am Ziel, fährt es nicht.
     * Die Fahrt endet mit der Meldung des Aktors. Bleibt sie aus, endet sie nach der
     * doppelten gerechneten Fahrzeit, damit eine zu kurz eingestellte Fahrzeit nicht stört.
     */
    private function FahrtBeginnt(int $ziel): void
    {
        $fahrt = json_decode($this->GetBuffer('Fahrt'), true);
        if ($this->Fahrt() != self::FAHRT_STEHT && is_array($fahrt) && (int) $fahrt['Ziel'] == $ziel) {
            return; // dieselbe Fahrt noch einmal, etwa das Echo des eigenen Telegramms
        }
        $von = $this->GeschaetztePosition();
        if ($von === null) {
            // Position unbekannt: die ganze Strecke vom anderen Ende annehmen
            $von = $ziel >= 50 ? (float) self::OFFEN : (float) self::ZU;
        }
        $strecke = abs($ziel - $von);
        if ($strecke < 1) {
            $this->FahrtEndet();
            return;
        }
        $dauer = $strecke / 100 * max(1, $this->ReadPropertyInteger('Fahrzeit')) * self::FAHRT_WARTEN + self::FAHRT_RESERVE_S;
        $this->SetBuffer('Fahrt', json_encode(['Start' => microtime(true), 'Von' => $von, 'Ziel' => $ziel]));
        $this->FahrtSetzen($ziel > $von ? self::FAHRT_AB : self::FAHRT_AUF);
        $this->SetValue('Position', $ziel); // zeigt das Ziel, bis der Aktor die Position meldet
        $this->SetTimerInterval('Fahrt', (int) ceil($dauer * 1000));
    }

    /**
     * Beendet die Fahrt. Mit $gemeldet hat der Aktor die Position gemeldet, sie gilt.
     * Ohne Meldung (Stopp, Wartezeit abgelaufen) merkt sich das Modul die geschätzte
     * Position als Stand, bis der Aktor die echte meldet.
     */
    private function FahrtEndet(?int $gemeldet = null): void
    {
        $position = $gemeldet;
        if ($this->Fahrt() != self::FAHRT_STEHT) {
            if ($gemeldet === null) {
                $geschaetzt = $this->GeschaetztePosition();
                $position = $geschaetzt === null ? null : (int) round($geschaetzt);
            }
            $this->SetTimerInterval('Fahrt', 0);
            $this->SetBuffer('Fahrt', '');
            $this->SetBuffer('Stand', $gemeldet === null && $position !== null ? (string) $position : '');
            $this->FahrtSetzen(self::FAHRT_STEHT);
        } elseif ($gemeldet !== null) {
            $this->SetBuffer('Stand', '');
        }
        if ($position !== null) {
            $this->SetValue('Position', $position);
        }
    }

    private function FahrtSetzen(int $fahrt): void
    {
        if ($this->GetValue('Fahrt') !== $fahrt) {
            $this->SetValue('Fahrt', $fahrt);
        }
        $this->LedsSetzen();
    }

    /**
     * Beste bekannte Position. Fährt das Rollo, wird sie aus Startzeit und Fahrzeit
     * geschätzt, denn der Aktor meldet unterwegs nichts. Steht es, gilt der Stand aus der
     * letzten Schätzung, solange der Aktor seitdem nichts gemeldet hat, sonst die gemeldete.
     */
    private function GeschaetztePosition(): ?float
    {
        $fahrt = json_decode($this->GetBuffer('Fahrt'), true);
        if ($this->Fahrt() == self::FAHRT_STEHT || !is_array($fahrt)) {
            $stand = $this->GetBuffer('Stand');
            if ($stand !== '') {
                return (float) $stand;
            }
            $position = $this->StatusPosition();
            return $position === null ? null : (float) $position;
        }
        $von = (float) $fahrt['Von'];
        $ziel = (float) $fahrt['Ziel'];
        $weg = (microtime(true) - (float) $fahrt['Start']) / max(1, $this->ReadPropertyInteger('Fahrzeit')) * 100;
        return $ziel >= $von ? min($ziel, $von + $weg) : max($ziel, $von - $weg);
    }

    /**
     * LEDs zeigen die Fahrt: Grün in der Richtung, in die das Rollo fährt, sonst Blau.
     * Gesendet wird nur, was sich geändert hat, mit $alle jede LED.
     */
    private function LedsSetzen(bool $alle = false): void
    {
        $fahrt = $this->Fahrt();
        $soll = [];
        foreach ($this->Liste('MdtLedsAuf') as $led) {
            $soll[$led['InstanceID']] = $fahrt == self::FAHRT_AUF ? self::LED_GRUEN : self::LED_BLAU;
        }
        foreach ($this->Liste('MdtLedsAb') as $led) {
            $soll[$led['InstanceID']] = $fahrt == self::FAHRT_AB ? self::LED_GRUEN : self::LED_BLAU;
        }
        foreach ($this->Liste('JungLedsBlau') as $led) {
            $soll[$led['InstanceID']] = $fahrt == self::FAHRT_STEHT;
        }
        foreach ($this->Liste('JungLedsAufGruen') as $led) {
            $soll[$led['InstanceID']] = $fahrt == self::FAHRT_AUF;
        }
        foreach ($this->Liste('JungLedsAbGruen') as $led) {
            $soll[$led['InstanceID']] = $fahrt == self::FAHRT_AB;
        }

        $gesendet = $alle ? [] : (json_decode($this->GetBuffer('Leds'), true) ?: []);
        foreach ($soll as $instanz => $wert) {
            if (array_key_exists($instanz, $gesendet) && $gesendet[$instanz] === $wert) {
                continue;
            }
            if (is_bool($wert)) {
                @KNX_WriteDPT1($instanz, $wert);
            } else {
                @KNX_WriteDPT5($instanz, $wert);
            }
        }
        $this->SetBuffer('Leds', json_encode($soll));
    }

    // ------------------------------------------------------------------ Verschattung

    /**
     * Fährt nur beim Wechsel: Kommt die Sonne aufs Fenster, geht das Rollo in die
     * Verschattungsposition. Geht sie wieder oder wird die Verschattung für die Wohnung
     * ausgeschaltet, fährt es auf, wenn es noch dort steht.
     */
    private function SonnePruefen(): void
    {
        if ($this->GetStatus() != IS_ACTIVE || !$this->ReadPropertyBoolean('Verschattung')) {
            return;
        }
        $sonne = $this->SonneAufFenster();
        $vorher = $this->GetBuffer('Sonne');
        $this->SetBuffer('Sonne', $sonne ? '1' : '0');
        if ($vorher === '' || ($vorher === '1') == $sonne) {
            return;
        }
        if ($this->Gesperrt() || !$this->VerschattungFrei()) {
            return; // Schalter Verschattung aus: ein verschattetes Rollo bleibt stehen
        }
        $position = $this->Position();
        $ziel = $this->ReadPropertyInteger('Verschattungsposition');
        if ($sonne) {
            // nur weiter schließen: ein Rollo, das schon tiefer steht, bleibt
            if ($position === null || $position < $ziel - self::TOLERANZ) {
                $this->PositionAnfahren($ziel, 'Verschattung: Sonne auf dem Fenster');
            }
            return;
        }
        // nicht mehr öffnen, wenn die Sonne tief steht und das Rollo heute noch automatisch schließt
        $stand = $this->Sonnenstand();
        $tief = $stand === null || $stand['Hoehe'] <= (float) $this->Einstellung('Mindesthoehe', 10);
        $abends = $this->ReadPropertyBoolean('AbendsSchliessen') && $this->AutomatikFrei()
            && $this->Zeitpunkt('RunterHeute') > time();
        if ($position !== null && abs($position - $ziel) <= self::TOLERANZ && !($tief && $abends)) {
            $grund = $this->Einstellung('AutomatikVerschattung', false) ? 'Sonne nicht mehr auf dem Fenster' : 'Automatik Verschattung aus';
            $this->Fahren(self::OFFEN, 'Auf: ' . $grund);
        }
    }

    /**
     * Die Sonne scheint aufs Fenster, wenn sie höchstens um den Öffnungswinkel neben der
     * Himmelsrichtung steht und höher als die Mindest-Sonnenhöhe. Dazu müssen die
     * Verschattung der Wohnung und der Schalter des Rollos an sein.
     */
    private function SonneAufFenster(): bool
    {
        if (!$this->Einstellung('AutomatikVerschattung', false) || !$this->VerschattungFrei()) {
            return false;
        }
        $stand = $this->Sonnenstand();
        if ($stand === null) {
            return false;
        }
        $abweichung = fmod($stand['Azimut'] - $this->ReadPropertyFloat('Himmelsrichtung') + 180, 360);
        if ($abweichung < 0) {
            $abweichung += 360;
        }
        return abs($abweichung - 180) <= (float) $this->Einstellung('Oeffnungswinkel', 80)
            && $stand['Hoehe'] > (float) $this->Einstellung('Mindesthoehe', 10);
    }

    /**
     * Azimut und Höhe der Sonne aus Location Control, in Grad.
     */
    private function Sonnenstand(): ?array
    {
        $variablen = $this->StandortVariablen();
        if (count($variablen) < 2) {
            return null;
        }
        return ['Azimut' => (float) GetValue($variablen[0]), 'Hoehe' => (float) GetValue($variablen[1])];
    }

    // ------------------------------------------------------------------ Morgens und Abends

    /**
     * Die Wohnung gibt in den Variablen "Rauf heute" und "Runter heute" vor, wann die Rollos
     * fahren. Das Modul stellt seinen Timer auf den nächsten Zeitpunkt, der noch kommt, und
     * fährt, wenn er da ist. Ein Zeitpunkt, der beim Lesen schon vorbei ist, wird nie
     * nachgeholt, auch nicht nach einem Neustart. 0 heißt: heute nicht.
     * Läuft beim Start, bei jeder Änderung der beiden Variablen und kurz nach Mitternacht.
     */
    private function AutomatikPruefen(): void
    {
        if ($this->GetStatus() != IS_ACTIVE) {
            return;
        }
        $jetzt = time();
        $naechste = [mktime(0, 0, 30, (int) date('n', $jetzt), (int) date('j', $jetzt) + 1, (int) date('Y', $jetzt))];
        foreach (self::ZEITPUNKTE as $ident => $eigenschaft) {
            $gestellt = (int) $this->GetBuffer('Gestellt' . $ident);
            $ziel = $this->ReadPropertyBoolean($eigenschaft) ? $this->Zeitpunkt($ident) : 0;
            $this->SetBuffer('Gestellt' . $ident, $ziel > $jetzt ? (string) $ziel : '');
            if ($ziel > $jetzt) {
                $naechste[] = $ziel;
            } elseif ($gestellt > 0 && $gestellt == $ziel) {
                // der Zeitpunkt, auf den der Timer gestellt war, ist da
                if ($ident == 'RaufHeute') {
                    $this->MorgensAusfuehren();
                } else {
                    $this->AbendsAusfuehren();
                }
            }
        }
        $this->SetTimerInterval('Automatik', (min($naechste) - $jetzt) * 1000);
    }

    private function MorgensAusfuehren(): void
    {
        $hindernis = $this->AutomatikHindernis('AutomatikRauf', 'Rauf');
        if ($hindernis != '') {
            $this->Grund('Morgens nicht geöffnet: ' . $hindernis);
            return;
        }
        $position = $this->Position();
        if ($this->ReadPropertyBoolean('Verschattung') && $this->SonneAufFenster()) {
            // die Sonne scheint schon aufs Fenster: direkt in die Verschattungsposition
            $ziel = $this->ReadPropertyInteger('Verschattungsposition');
            if ($position === null || abs($position - $ziel) > self::TOLERANZ) {
                $this->PositionAnfahren($ziel, 'Verschattung: Morgens, Sonne auf dem Fenster');
            }
        } elseif ($position === null || $position > self::OFFEN) {
            $this->Fahren(self::OFFEN, 'Auf: Morgens');
        }
    }

    private function AbendsAusfuehren(): void
    {
        $hindernis = $this->AutomatikHindernis('AutomatikRunter', 'Runter');
        if ($hindernis != '') {
            $this->Grund('Abends nicht geschlossen: ' . $hindernis);
            return;
        }
        $position = $this->Position();
        if ($position === null || $position < self::ZU) {
            $this->Fahren(self::ZU, 'Ab: Abends');
        }
    }

    /**
     * Zum Zeitpunkt fährt das Rollo nur, wenn es nicht gesperrt ist und sein Schalter
     * Automatik nicht aus ist. Mit dem Schalter legt man je Rollo fest, ob es automatisch fährt.
     */
    /**
     * Warum das Rollo zum Zeitpunkt nicht fährt, oder leer. Der Schalter der Wohnung ($schalter,
     * Automatik Rauf oder Automatik Runter) gilt für alle Rollos, der Schalter Automatik nur für dieses.
     */
    private function AutomatikHindernis(string $schalter, string $name): string
    {
        if ($this->Gesperrt()) {
            return 'gesperrt';
        }
        if (!$this->AutomatikFrei()) {
            return 'Automatik ist aus';
        }
        if (!$this->Einstellung($schalter, true)) {
            return 'Automatik ' . $name . ' der Wohnung ist aus';
        }
        return '';
    }

    // ------------------------------------------------------------------ Zustand und Helfer

    /**
     * Gesperrt wird ein Rollo, indem "Sperren" gesetzt wird. Der Aktor verhindert dann selbst
     * jede Fahrt. Das Modul liest die Variable der Sperren-Instanz, also das letzte Telegramm
     * auf der Sperr-Adresse, und schickt einem gesperrten Rollo keine Fahrbefehle.
     */
    private function Gesperrt(): bool
    {
        $variable = $this->WertVariable('SperrenID');
        return $variable > 0 && GetValueBoolean($variable);
    }

    /**
     * Position aus der Status-Variable, null solange der Aktor noch nie eine gemeldet hat.
     */
    private function StatusPosition(): ?int
    {
        $id = $this->ReadPropertyInteger('StatusID');
        if (!IPS_VariableExists($id) || IPS_GetVariable($id)['VariableUpdated'] == 0) {
            return null;
        }
        return $this->Begrenzen((int) round((float) GetValue($id)));
    }

    /**
     * Position, mit der die Logik rechnet: die gemeldete, unterwegs und nach einem Stopp die geschätzte.
     */
    private function Position(): ?int
    {
        $position = $this->GeschaetztePosition();
        return $position === null ? null : (int) round($position);
    }

    private function PositionZeigen(): void
    {
        $position = $this->Position();
        if ($position !== null) {
            $this->SetValue('Position', $position);
        }
    }

    private function Fahrt(): int
    {
        $fahrt = $this->GetValue('Fahrt');
        return in_array($fahrt, [self::FAHRT_AUF, self::FAHRT_AB], true) ? $fahrt : self::FAHRT_STEHT;
    }

    /**
     * Ein Telegramm auf einer Adresse des Rollos ist das eigene, wenn das Modul dort gerade
     * selbst gesendet hat. Das gilt für genau ein Telegramm, das nächste kommt von außen.
     */
    /**
     * Merkt sich den eigenen Befehl auf einer Adresse des Rollos. Symcon liefert ein eigenes
     * Telegramm zweimal an die Instanz: beim Schreiben und eine Sekunde später vom Bus zurück.
     * Deshalb zählt jede Meldung mit diesem Wert innerhalb der Sperrzeit als eigene, ein
     * anderer Wert in der Zeit kommt von einem anderen Gerät.
     */
    private function BefehlMerken(string $eigenschaft, $wert): void
    {
        $this->SetBuffer('Befehl' . $eigenschaft, json_encode(['Zeit' => time(), 'Wert' => $wert]));
    }

    private function EigenesTelegramm(string $eigenschaft, $wert): bool
    {
        $befehl = json_decode($this->GetBuffer('Befehl' . $eigenschaft), true);
        return is_array($befehl)
            && time() - (int) $befehl['Zeit'] <= self::BEFEHL_SPERRE_S
            && json_encode($befehl['Wert']) === json_encode($wert);
    }

    private function AutomatikFrei(): bool
    {
        return $this->Freigabe('Automatik');
    }

    private function VerschattungFrei(): bool
    {
        return $this->Freigabe('Verschattung');
    }

    /**
     * Der Schalter des Rollos. Nur aus sperrt.
     */
    private function Freigabe(string $ident): bool
    {
        $id = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        return $id === false || (bool) GetValue($id);
    }

    /**
     * Variable einer KNX-DPT-Instanz. Sie trägt bei jeder DPT-Instanz den Ident "Value".
     */
    private function WertVariable(string $eigenschaft): int
    {
        $instanz = $this->ReadPropertyInteger($eigenschaft);
        if ($instanz == 0 || !IPS_InstanceExists($instanz)) {
            return 0;
        }
        $id = @IPS_GetObjectIDByIdent('Value', $instanz);
        return $id === false ? 0 : $id;
    }

    private function Standort(): int
    {
        $instanzen = IPS_GetInstanceListByModuleID(self::STANDORT_MODUL);
        return count($instanzen) ? $instanzen[0] : 0;
    }

    /**
     * Azimuth und Altitude von Location Control, in dieser Reihenfolge.
     */
    private function StandortVariablen(): array
    {
        $standort = $this->Standort();
        $variablen = [];
        foreach (['Azimuth', 'Altitude'] as $ident) {
            $id = $standort > 0 ? @IPS_GetObjectIDByIdent($ident, $standort) : false;
            if ($id !== false) {
                $variablen[] = $id;
            }
        }
        return $variablen;
    }

    /**
     * Variablen, bei deren Wechsel die Verschattung neu geprüft wird.
     */
    private function SonneVariablen(): array
    {
        $variablen = $this->ReadPropertyBoolean('Verschattung') ? $this->StandortVariablen() : [];
        foreach (self::EINSTELLUNGEN_SONNE as $ident) {
            $variablen[] = $this->EinstellungVariable($ident);
        }
        return $variablen;
    }

    // ------------------------------------------------------------------ Variablen der Wohnung

    /**
     * Morgens öffnen, Abends schließen und Verschattung brauchen die Variablen der Wohnung.
     * Ein Rollo, das nur mit Tastern und aus der App fährt, kommt ohne den Ordner aus.
     */
    private function BrauchtEinstellungen(): bool
    {
        return $this->ReadPropertyBoolean('MorgensOeffnen') || $this->ReadPropertyBoolean('AbendsSchliessen')
            || $this->ReadPropertyBoolean('Verschattung');
    }

    /**
     * Die Variablen der Wohnung mit Ident, Name, Typ, Vorgabe und Darstellung.
     * Die beiden Zeitpunkte sind Zeitstempel mit Datum und Uhrzeit, 0 heißt: heute nicht.
     */
    private function Einstellungen(): array
    {
        $zeitpunkt = ['Typ' => VARIABLETYPE_INTEGER, 'Vorgabe' => 0, 'Darstellung' => $this->Zeit()];
        return [
            'RaufHeute'             => ['Name' => 'Rauf heute'] + $zeitpunkt,
            'RunterHeute'           => ['Name' => 'Runter heute'] + $zeitpunkt,
            'AutomatikRauf'         => ['Name' => 'Automatik Rauf', 'Typ' => VARIABLETYPE_BOOLEAN, 'Vorgabe' => true, 'Darstellung' => $this->Schalter('sunrise')],
            'AutomatikRunter'       => ['Name' => 'Automatik Runter', 'Typ' => VARIABLETYPE_BOOLEAN, 'Vorgabe' => true, 'Darstellung' => $this->Schalter('sunset')],
            'AutomatikVerschattung' => ['Name' => 'Automatik Verschattung', 'Typ' => VARIABLETYPE_BOOLEAN, 'Vorgabe' => true, 'Darstellung' => $this->Schalter('sun')],
            'Oeffnungswinkel'       => ['Name' => 'Öffnungswinkel', 'Typ' => VARIABLETYPE_INTEGER, 'Vorgabe' => 80, 'Darstellung' => $this->Regler(0, 180, 5, '°', 'angle')],
            'Mindesthoehe'          => ['Name' => 'Mindest-Sonnenhöhe', 'Typ' => VARIABLETYPE_INTEGER, 'Vorgabe' => 10, 'Darstellung' => $this->Regler(0, 90, 1, '°', 'arrow-up')]
        ];
    }

    private function EinstellungVariable(string $ident): int
    {
        $ordner = $this->ReadPropertyInteger('EinstellungenID');
        if ($ordner == 0 || !IPS_CategoryExists($ordner)) {
            return 0;
        }
        $id = @IPS_GetObjectIDByIdent($ident, $ordner);
        return $id === false || !IPS_VariableExists($id) ? 0 : $id;
    }

    private function Einstellung(string $ident, $vorgabe)
    {
        $id = $this->EinstellungVariable($ident);
        return $id > 0 ? GetValue($id) : $vorgabe;
    }

    // ------------------------------------------------------------------ Kleine Helfer

    private function ZielName(int $ziel): string
    {
        if ($ziel <= self::OFFEN) {
            return 'Auf';
        }
        return $ziel >= self::ZU ? 'Ab' : 'Position ' . $ziel . ' %';
    }

    private function Begrenzen(int $position): int
    {
        return min(max($position, self::OFFEN), self::ZU);
    }

    private function Grund(string $text): void
    {
        $this->SetValue('Grund', $text);
    }

    private function Schalter(string $icon): array
    {
        return [
            'PRESENTATION'   => self::DARSTELLUNG_SCHALTER,
            'ICON_TRUE'      => $icon,
            'ICON_FALSE'     => $icon,
            'USE_ICON_FALSE' => false,
            'USAGE_TYPE'     => 0
        ];
    }

    /**
     * Rauf heute oder Runter heute als Zeitstempel, 0 = heute nicht. Die Variable hält den
     * Zeitstempel des Tages. Ein Wert unter 86400 gilt als Sekunden seit Mitternacht des
     * laufenden Tages, falls die App beim Ändern einer reinen Uhrzeit so einen Wert schreibt.
     */
    private function Zeitpunkt(string $ident): int
    {
        $wert = (int) $this->Einstellung($ident, 0);
        if ($wert <= 0) {
            return 0;
        }
        if ($wert < 86400) {
            $jetzt = time();
            return mktime(intdiv($wert, 3600), intdiv($wert % 3600, 60), $wert % 60, (int) date('n', $jetzt), (int) date('j', $jetzt), (int) date('Y', $jetzt));
        }
        return $wert;
    }

    // Nur die Uhrzeit, das Datum ist immer heute (Sven, 10.11.2026)
    private function Zeit(): array
    {
        return [
            'PRESENTATION'    => self::DARSTELLUNG_ZEIT,
            'DATE'            => 0, // kein Datum
            'TIME'            => 1, // Stunden und Minuten
            'DAY_OF_THE_WEEK' => false,
            'MONTH_TEXT'      => false
        ];
    }

    private function Regler(int $min, int $max, int $schritt, string $einheit, string $icon): array
    {
        return [
            'PRESENTATION' => self::DARSTELLUNG_REGLER,
            'MIN'          => $min,
            'MAX'          => $max,
            'STEP_SIZE'    => $schritt,
            'DIGITS'       => 0,
            'SUFFIX'       => $einheit,
            'ICON'         => $icon
        ];
    }

    private function Vorgabe(string $ident, $wert): void
    {
        $id = $this->GetIDForIdent($ident);
        if (IPS_GetVariable($id)['VariableUpdated'] == 0) {
            $this->SetValue($ident, $wert);
        }
    }

    private function Liste(string $name): array
    {
        return json_decode($this->ReadPropertyString($name), true) ?: [];
    }
}
