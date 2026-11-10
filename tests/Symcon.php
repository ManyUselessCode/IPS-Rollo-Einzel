<?php

declare(strict_types=1);

/**
 * Nachbau von Symcon für die Tests: Objektbaum, Variablen, Nachrichten, Timer, KNX-DPT-Instanzen
 * mit "Status emulieren" und ein Rolloaktor, der die Position nur am Ende der Fahrt und eine
 * Sekunde nach einem Stopp meldet. Die Uhr ist ersetzt, deshalb läuft das nur so:
 *
 *   php -d disable_functions=time,microtime tests/test.php
 */

const IPS_KERNELMESSAGE = 10100;
const KR_READY = 10103;
const VM_UPDATE = 10603;
const IS_ACTIVE = 102;
const IS_INACTIVE = 104;
const KL_NOTIFY = 10203;
const KL_WARNING = 10204;
const KL_ERROR = 10206;
const VARIABLETYPE_BOOLEAN = 0;
const VARIABLETYPE_INTEGER = 1;
const VARIABLETYPE_FLOAT = 2;
const VARIABLETYPE_STRING = 3;

const KNX_DPT1 = '{F3058AB2-AFDC-4479-A053-5F4599DF6F5B}';
const KNX_DPT5 = '{EBD0EE8D-DFA5-449F-BBE4-49CFC5F0EEB4}';
const STANDORT = '{45E97A63-F870-408A-B259-2933F7EABF74}';

date_default_timezone_set('Europe/Berlin');

// jede Warnung und jeder Hinweis von PHP lässt den Test scheitern, außer mit @ unterdrückte
set_error_handler(function (int $nr, string $text, string $datei, int $zeile): bool {
    if ((error_reporting() & $nr) == 0) {
        return true;
    }
    throw new ErrorException($text, 0, $nr, $datei, $zeile);
});

function time(): int
{
    return (int) floor(Sym::$jetzt);
}

function microtime(bool $as_float = false): string|float
{
    return $as_float ? Sym::$jetzt : sprintf('%.8F %d', Sym::$jetzt - floor(Sym::$jetzt), (int) floor(Sym::$jetzt));
}

/**
 * Die simulierte Anlage.
 */
final class Sym
{
    public static float $jetzt = 0.0;
    public static int $naechsteID = 10000;
    public static array $objekte = [];    // ID => Typ, Eltern, Ident, Name, Hidden, Position
    public static array $variablen = [];  // ID => Wert, Typ, Updated, Changed, Aktion, Darstellung
    public static array $instanzen = [];  // ID => Modul, Eigenschaften
    public static array $skripte = [];    // ID => Inhalt
    public static array $module = [];     // Instanzen eigener Module
    public static array $abos = [];       // Sender => Module, die VM_UPDATE wollen
    public static array $post = [];       // Nachrichten, die noch nicht zugestellt sind
    public static array $telegramme = []; // alles, was auf den Bus geschrieben wurde
    public static array $bus = [];        // KNX-Instanz => Reaktion eines Geräts auf ein Telegramm
    public static array $lesbar = [];     // KNX-Instanz => Antwort eines Geräts auf eine Leseanfrage
    public static array $ereignisse = []; // geplant: Zeit, laufende Nummer, Aufruf
    private static int $nummer = 0;

    public static function neu(float $jetzt): void
    {
        self::$jetzt = $jetzt;
        self::$naechsteID = 10000;
        self::$objekte = self::$variablen = self::$instanzen = self::$skripte = self::$module = [];
        self::$abos = self::$post = self::$telegramme = self::$bus = self::$lesbar = self::$ereignisse = [];
    }

    public static function objekt(string $typ, int $eltern, string $name, string $ident = ''): int
    {
        $id = self::$naechsteID++;
        self::$objekte[$id] = ['Typ' => $typ, 'Eltern' => $eltern, 'Ident' => $ident, 'Name' => $name, 'Hidden' => false, 'Position' => 0];
        return $id;
    }

    public static function kategorie(string $name, int $eltern = 0): int
    {
        return self::objekt('Kategorie', $eltern, $name);
    }

    /**
     * Variable anlegen. $wert null heißt: noch nie aktualisiert.
     */
    public static function variable(int $typ, int $eltern, string $ident, string $name, $wert = null): int
    {
        $id = self::objekt('Variable', $eltern, $name, $ident);
        $vorgabe = [false, 0, 0.0, ''][$typ];
        self::$variablen[$id] = [
            'Wert' => $wert ?? $vorgabe, 'Typ' => $typ, 'Updated' => $wert === null ? 0 : time(),
            'Changed' => $wert === null ? 0 : time(), 'Aktion' => 0, 'Darstellung' => []
        ];
        return $id;
    }

    public static function instanz(string $modul, int $eltern, string $name, array $eigenschaften = []): int
    {
        $id = self::objekt('Instanz', $eltern, $name);
        self::$instanzen[$id] = ['Modul' => $modul, 'Eigenschaften' => $eigenschaften];
        return $id;
    }

    /**
     * KNX-DPT-Instanz mit ihrer Variable "Value". $wert null heißt: noch kein Telegramm gesehen.
     */
    public static function knx(string $name, int $eltern, int $dpt = 1, $wert = null): int
    {
        $id = self::instanz($dpt == 1 ? KNX_DPT1 : KNX_DPT5, $eltern, $name);
        self::variable($dpt == 1 ? VARIABLETYPE_BOOLEAN : VARIABLETYPE_INTEGER, $id, 'Value', 'Value', $wert);
        return $id;
    }

    public static function wert(int $instanz): int
    {
        return self::kind($instanz, 'Value');
    }

    public static function kind(int $eltern, string $ident): int
    {
        foreach (self::$objekte as $id => $objekt) {
            if ($objekt['Eltern'] == $eltern && $objekt['Ident'] === $ident) {
                return $id;
            }
        }
        return 0;
    }

    public static function kinder(int $eltern): array
    {
        return array_keys(array_filter(self::$objekte, fn (array $objekt): bool => $objekt['Eltern'] == $eltern));
    }

    /**
     * Setzt eine Variable und stellt die Nachricht VM_UPDATE in die Post.
     */
    public static function setze(int $variable, $wert): void
    {
        $v = &self::$variablen[$variable];
        $typ = [VARIABLETYPE_BOOLEAN => 'boolean', VARIABLETYPE_INTEGER => 'integer', VARIABLETYPE_FLOAT => 'double', VARIABLETYPE_STRING => 'string'][$v['Typ']];
        if (gettype($wert) != $typ) {
            throw new Exception('Variable ' . $variable . ' (' . self::$objekte[$variable]['Name'] . ') erwartet ' . $typ . ', bekommt ' . gettype($wert));
        }
        $alt = $v['Wert'];
        $geaendert = $alt !== $wert;
        $v['Wert'] = $wert;
        $v['Updated'] = time();
        if ($geaendert) {
            $v['Changed'] = time();
        }
        self::$post[] = [$variable, [$wert, $geaendert, $alt, time()]];
    }

    /**
     * Telegramm auf die Adresse einer KNX-Instanz: Die Instanz führt ihre Variable nach,
     * und ein Gerät am Bus reagiert. $extern heißt, ein anderes Gerät hat gesendet.
     */
    // Wie die Anlage: ein eigenes Telegramm kommt zweimal an, beim Schreiben und vom Bus zurück.
    // OHNE_ECHO=1 in der Umgebung schaltet das ab.
    public static bool $echo = true;

    public static function telegramm(int $instanz, $wert, bool $extern = true): bool
    {
        if (!isset(self::$instanzen[$instanz])) {
            trigger_error('Instanz ' . $instanz . ' gibt es nicht', E_USER_WARNING);
            return false;
        }
        self::$telegramme[] = ['Zeit' => self::$jetzt, 'Instanz' => $instanz, 'Name' => self::$objekte[$instanz]['Name'], 'Wert' => $wert, 'Extern' => $extern];
        self::setze(self::wert($instanz), $wert);
        if (!$extern && self::$echo) {
            self::setze(self::wert($instanz), $wert);
        }
        if (isset(self::$bus[$instanz])) {
            (self::$bus[$instanz])($wert);
        }
        return true;
    }

    public static function plane(float $wann, callable $was): void
    {
        self::$ereignisse[] = [$wann, self::$nummer++, $was];
    }

    public static function verarbeite(): void
    {
        while (count(self::$post)) {
            [$sender, $daten] = array_shift(self::$post);
            foreach (self::$abos[$sender] ?? [] as $modul) {
                $modul->MessageSink(0, $sender, VM_UPDATE, $daten);
            }
        }
    }

    /**
     * Lässt die Zeit bis $bis laufen und führt dabei geplante Ereignisse und Timer aus.
     */
    public static function lauf(float $bis): void
    {
        while (true) {
            self::verarbeite();
            $zeit = INF;
            $was = null;
            foreach (self::$ereignisse as $schluessel => $ereignis) {
                if ($ereignis[0] < $zeit || ($ereignis[0] == $zeit && $was !== null && $was[0] == 'E' && $ereignis[1] < self::$ereignisse[$was[1]][1])) {
                    $zeit = $ereignis[0];
                    $was = ['E', $schluessel];
                }
            }
            foreach (self::$module as $modul) {
                foreach ($modul->timer as $name => $timer) {
                    if ($timer['Faellig'] !== null && $timer['Faellig'] < $zeit) {
                        $zeit = $timer['Faellig'];
                        $was = ['T', $modul, $name];
                    }
                }
            }
            if ($was === null || $zeit > $bis) {
                break;
            }
            self::$jetzt = max(self::$jetzt, $zeit);
            if ($was[0] == 'E') {
                $aufruf = self::$ereignisse[$was[1]][2];
                unset(self::$ereignisse[$was[1]]);
                $aufruf();
            } else {
                [, $modul, $name] = $was;
                // Timer in Symcon laufen periodisch, bis das Modul das Intervall ändert
                $modul->timer[$name]['Faellig'] = self::$jetzt + $modul->timer[$name]['Intervall'] / 1000;
                if (!preg_match("/IPS_RequestAction\\(\\\$_IPS\\['TARGET'\\], '(\\w+)', 0\\);/", $modul->timer[$name]['Skript'], $treffer)) {
                    throw new Exception('Timer-Skript nicht verstanden: ' . $modul->timer[$name]['Skript']);
                }
                $modul->RequestAction($treffer[1], 0);
            }
        }
        self::$jetzt = max(self::$jetzt, $bis);
        self::verarbeite();
    }

    public static function weiter(float $sekunden): void
    {
        self::lauf(self::$jetzt + $sekunden);
    }

    /**
     * Die Telegramme seit $ab als kurze Texte, optional nur für bestimmte Instanzen.
     */
    public static function gesendet(int $ab = 0, ?array $instanzen = null, bool $extern = false): array
    {
        $texte = [];
        foreach (array_slice(self::$telegramme, $ab) as $t) {
            if ($t['Extern'] != $extern || ($instanzen !== null && !in_array($t['Instanz'], $instanzen, true))) {
                continue;
            }
            $texte[] = $t['Name'] . '=' . (is_bool($t['Wert']) ? ($t['Wert'] ? 'an' : 'aus') : $t['Wert']);
        }
        return $texte;
    }
}

/**
 * Rolloaktor: fährt mit seiner echten Fahrzeit, meldet die Position am Ende der Fahrt und
 * eine Sekunde nach einem Stopp. Ist die Sperre gesetzt, nimmt er keine Befehle an.
 */
final class Aktor
{
    public float $position;
    public ?float $ziel = null;
    public float $von = 0.0;
    public float $seit = 0.0;
    public bool $sperre = false;
    public bool $defekt = false; // fährt und meldet nicht
    private int $lauf = 0;

    public function __construct(private array $knx, public float $fahrzeit = 19.0, float $start = 0.0)
    {
        $this->position = $start;
        Sym::$bus[$knx['Fahren']] = fn (bool $ab) => $this->fahre($ab ? 100.0 : 0.0);
        Sym::$bus[$knx['Position']] = fn (int $ziel) => $this->fahre((float) $ziel);
        Sym::$bus[$knx['Stopp']] = fn () => $this->halte();
        Sym::$bus[$knx['Sperren']] = function (bool $an): void {
            $this->sperre = $an;
        };
        Sym::$lesbar[$knx['Status']] = fn () => $this->melde();
    }

    public function jetzt(): float
    {
        if ($this->ziel === null) {
            return $this->position;
        }
        $weg = (Sym::$jetzt - $this->seit) / $this->fahrzeit * 100;
        return $this->ziel >= $this->von ? min($this->ziel, $this->von + $weg) : max($this->ziel, $this->von - $weg);
    }

    public function faehrt(): bool
    {
        return $this->ziel !== null;
    }

    public function fahre(float $ziel): void
    {
        if ($this->sperre || $this->defekt) {
            return;
        }
        $this->position = $this->jetzt();
        $this->von = $this->position;
        $this->seit = Sym::$jetzt;
        $this->ziel = $ziel;
        $lauf = ++$this->lauf;
        $dauer = abs($ziel - $this->position) / 100 * $this->fahrzeit;
        if ($dauer == 0.0) {
            $this->ziel = null; // steht schon dort, keine Meldung
            return;
        }
        Sym::plane(Sym::$jetzt + $dauer, function () use ($lauf): void {
            if ($lauf != $this->lauf || $this->ziel === null) {
                return;
            }
            $this->position = $this->ziel;
            $this->ziel = null;
            $this->melde();
        });
    }

    public function halte(): void
    {
        if ($this->ziel === null || $this->sperre) {
            return;
        }
        $this->position = $this->jetzt();
        $this->ziel = null;
        $this->lauf++;
        Sym::plane(Sym::$jetzt + 1.0, fn () => $this->melde());
    }

    public function melde(): void
    {
        Sym::setze(Sym::wert($this->knx['Status']), (int) round($this->jetzt()));
    }
}

/**
 * Basisklasse der Module, soweit das Modul sie braucht.
 */
class IPSModule
{
    public int $InstanceID;
    public array $eigenschaften = [];
    public array $attribute = [];
    public array $puffer = [];
    public array $timer = [];
    public array $nachrichten = [];
    public array $referenzen = [];
    public array $aktionen = [];
    public array $log = [];
    public int $status = 0;

    public function __construct(int $InstanceID)
    {
        $this->InstanceID = $InstanceID;
    }

    public function Create()
    {
    }

    public function ApplyChanges()
    {
    }

    private function eigenschaft(string $name, $vorgabe): bool
    {
        if (!array_key_exists($name, $this->eigenschaften)) {
            $this->eigenschaften[$name] = $vorgabe;
        }
        return true;
    }

    protected function RegisterPropertyInteger(string $name, int $vorgabe): bool
    {
        return $this->eigenschaft($name, $vorgabe);
    }

    protected function RegisterPropertyFloat(string $name, float $vorgabe): bool
    {
        return $this->eigenschaft($name, $vorgabe);
    }

    protected function RegisterPropertyBoolean(string $name, bool $vorgabe): bool
    {
        return $this->eigenschaft($name, $vorgabe);
    }

    protected function RegisterPropertyString(string $name, string $vorgabe): bool
    {
        return $this->eigenschaft($name, $vorgabe);
    }

    private function lies(string $name, string $typ)
    {
        if (!array_key_exists($name, $this->eigenschaften)) {
            throw new Exception('Eigenschaft ' . $name . ' ist nicht registriert');
        }
        if (gettype($this->eigenschaften[$name]) != $typ) {
            throw new Exception('Eigenschaft ' . $name . ' ist ' . gettype($this->eigenschaften[$name]) . ', gelesen als ' . $typ);
        }
        return $this->eigenschaften[$name];
    }

    protected function ReadPropertyInteger(string $name): int
    {
        return $this->lies($name, 'integer');
    }

    protected function ReadPropertyFloat(string $name): float
    {
        return $this->lies($name, 'double');
    }

    protected function ReadPropertyBoolean(string $name): bool
    {
        return $this->lies($name, 'boolean');
    }

    protected function ReadPropertyString(string $name): string
    {
        return $this->lies($name, 'string');
    }

    protected function RegisterAttributeInteger(string $name, int $vorgabe): bool
    {
        if (!array_key_exists($name, $this->attribute)) {
            $this->attribute[$name] = $vorgabe;
        }
        return true;
    }

    protected function ReadAttributeInteger(string $name): int
    {
        return $this->attribute[$name];
    }

    protected function WriteAttributeInteger(string $name, int $wert): bool
    {
        if (!array_key_exists($name, $this->attribute)) {
            throw new Exception('Attribut ' . $name . ' ist nicht registriert');
        }
        $this->attribute[$name] = $wert;
        return true;
    }

    protected function RegisterTimer(string $name, int $intervall, string $skript): bool
    {
        if (!isset($this->timer[$name])) {
            $this->timer[$name] = ['Skript' => $skript, 'Intervall' => $intervall, 'Faellig' => null];
        }
        return true;
    }

    protected function SetTimerInterval(string $name, int $intervall): bool
    {
        if (!isset($this->timer[$name])) {
            throw new Exception('Timer ' . $name . ' ist nicht registriert');
        }
        if ($intervall < 0) {
            throw new Exception('Timer ' . $name . ' mit negativem Intervall ' . $intervall);
        }
        $this->timer[$name]['Intervall'] = $intervall;
        $this->timer[$name]['Faellig'] = $intervall > 0 ? Sym::$jetzt + $intervall / 1000 : null;
        return true;
    }

    private function registriere(string $ident, string $name, int $typ, $darstellung, int $position): int
    {
        $id = Sym::kind($this->InstanceID, $ident);
        if ($id == 0) {
            $id = Sym::variable($typ, $this->InstanceID, $ident, $name);
        }
        Sym::$variablen[$id]['Darstellung'] = $darstellung;
        Sym::$objekte[$id]['Position'] = $position;
        return $id;
    }

    protected function RegisterVariableBoolean(string $ident, string $name, $darstellung = '', int $position = 0)
    {
        return $this->registriere($ident, $name, VARIABLETYPE_BOOLEAN, $darstellung, $position);
    }

    protected function RegisterVariableInteger(string $ident, string $name, $darstellung = '', int $position = 0)
    {
        return $this->registriere($ident, $name, VARIABLETYPE_INTEGER, $darstellung, $position);
    }

    protected function RegisterVariableString(string $ident, string $name, $darstellung = '', int $position = 0)
    {
        return $this->registriere($ident, $name, VARIABLETYPE_STRING, $darstellung, $position);
    }

    protected function MaintainVariable(string $ident, string $name, int $typ, $darstellung, int $position, bool $behalten)
    {
        $id = Sym::kind($this->InstanceID, $ident);
        if ($behalten) {
            return $this->registriere($ident, $name, $typ, $darstellung, $position);
        }
        if ($id > 0) {
            unset(Sym::$objekte[$id], Sym::$variablen[$id], $this->aktionen[$ident]);
        }
        return true;
    }

    protected function EnableAction(string $ident): bool
    {
        $this->GetIDForIdent($ident);
        $this->aktionen[$ident] = true;
        return true;
    }

    public function GetIDForIdent(string $ident): int
    {
        $id = Sym::kind($this->InstanceID, $ident);
        if ($id == 0) {
            throw new Exception('Variable ' . $ident . ' gibt es in der Instanz nicht');
        }
        return $id;
    }

    protected function SetValue(string $ident, $wert): bool
    {
        Sym::setze($this->GetIDForIdent($ident), $wert);
        return true;
    }

    public function GetValue(string $ident)
    {
        return Sym::$variablen[$this->GetIDForIdent($ident)]['Wert'];
    }

    protected function SetBuffer(string $name, string $daten): bool
    {
        $this->puffer[$name] = $daten;
        return true;
    }

    protected function GetBuffer(string $name): string
    {
        return $this->puffer[$name] ?? '';
    }

    protected function RegisterMessage(int $sender, int $nachricht): bool
    {
        if ($sender != 0 && !isset(Sym::$objekte[$sender])) {
            throw new Exception('Nachricht für ein Objekt, das es nicht gibt: ' . $sender);
        }
        $this->nachrichten[$sender][] = $nachricht;
        if ($nachricht == VM_UPDATE && !in_array($this, Sym::$abos[$sender] ?? [], true)) {
            Sym::$abos[$sender][] = $this;
        }
        return true;
    }

    protected function UnregisterMessage(int $sender, int $nachricht): bool
    {
        unset($this->nachrichten[$sender]);
        Sym::$abos[$sender] = array_values(array_filter(Sym::$abos[$sender] ?? [], fn ($modul): bool => $modul !== $this));
        return true;
    }

    protected function GetMessageList(): array
    {
        return $this->nachrichten;
    }

    protected function RegisterReference(int $id): bool
    {
        if (!isset(Sym::$objekte[$id])) {
            throw new Exception('Referenz auf ein Objekt, das es nicht gibt: ' . $id);
        }
        $this->referenzen[$id] = $id;
        return true;
    }

    protected function UnregisterReference(int $id): bool
    {
        unset($this->referenzen[$id]);
        return true;
    }

    protected function GetReferenceList(): array
    {
        return array_values($this->referenzen);
    }

    protected function SetStatus(int $status): bool
    {
        $this->status = $status;
        return true;
    }

    protected function GetStatus(): int
    {
        return $this->status;
    }

    protected function LogMessage(string $text, int $art): bool
    {
        $this->log[] = [$art, $text];
        return true;
    }

    protected function SendDebug(string $nachricht, string $daten, int $format): bool
    {
        return true;
    }
}

// ---------------------------------------------------------------------- Funktionen von Symcon

function IPS_GetKernelRunlevel(): int
{
    return KR_READY;
}

function IPS_InstanceExists(int $id): bool
{
    return isset(Sym::$instanzen[$id]) || isset(Sym::$module[$id]);
}

function IPS_VariableExists(int $id): bool
{
    return isset(Sym::$variablen[$id]);
}

function IPS_CategoryExists(int $id): bool
{
    return isset(Sym::$objekte[$id]) && Sym::$objekte[$id]['Typ'] == 'Kategorie';
}

function IPS_GetParent(int $id): int
{
    return Sym::$objekte[$id]['Eltern'];
}

function IPS_GetObjectIDByIdent(string $ident, int $eltern): int|false
{
    $id = Sym::kind($eltern, $ident);
    if ($id == 0) {
        trigger_error('Objekt mit Ident ' . $ident . ' gibt es unter ' . $eltern . ' nicht', E_USER_WARNING);
        return false;
    }
    return $id;
}

function IPS_GetVariable(int $id): array
{
    $v = Sym::$variablen[$id];
    return ['VariableID' => $id, 'VariableType' => $v['Typ'], 'VariableUpdated' => $v['Updated'], 'VariableChanged' => $v['Changed'], 'VariableValue' => $v['Wert']];
}

function GetValue(int $id)
{
    return Sym::$variablen[$id]['Wert'];
}

function GetValueBoolean(int $id): bool
{
    if (Sym::$variablen[$id]['Typ'] != VARIABLETYPE_BOOLEAN) {
        throw new Exception('GetValueBoolean auf eine Variable anderen Typs: ' . $id);
    }
    return Sym::$variablen[$id]['Wert'];
}

function SetValue(int $id, $wert): bool
{
    Sym::setze($id, $wert);
    return true;
}

/**
 * Wie die App: KNX-Variablen schreiben auf den Bus, Variablen mit Aktionsskript übernehmen den Wert.
 */
function RequestAction(int $id, $wert): bool
{
    $eltern = Sym::$objekte[$id]['Eltern'];
    if (isset(Sym::$instanzen[$eltern]) && in_array(Sym::$instanzen[$eltern]['Modul'], [KNX_DPT1, KNX_DPT5], true)) {
        return Sym::telegramm($eltern, $wert, false);
    }
    if (isset(Sym::$module[$eltern])) {
        Sym::$module[$eltern]->RequestAction(Sym::$objekte[$id]['Ident'], $wert);
        return true;
    }
    if (isset(Sym::$skripte[Sym::$variablen[$id]['Aktion']])) {
        Sym::setze($id, $wert);
        return true;
    }
    trigger_error('Variable ' . $id . ' hat keine Aktion', E_USER_WARNING);
    return false;
}

function IPS_GetInstanceListByModuleID(string $modul): array
{
    return array_keys(array_filter(Sym::$instanzen, fn (array $instanz): bool => $instanz['Modul'] == $modul));
}

function IPS_GetProperty(int $id, string $name)
{
    return Sym::$instanzen[$id]['Eigenschaften'][$name];
}

function IPS_SetHidden(int $id, bool $versteckt): bool
{
    Sym::$objekte[$id]['Hidden'] = $versteckt;
    return true;
}

function IPS_CreateVariable(int $typ): int
{
    return Sym::variable($typ, 0, '', 'Unbenannt');
}

function IPS_CreateScript(int $typ): int
{
    $id = Sym::objekt('Skript', 0, 'Unbenannt');
    Sym::$skripte[$id] = '';
    return $id;
}

function IPS_SetScriptContent(int $id, string $inhalt): bool
{
    Sym::$skripte[$id] = $inhalt;
    return true;
}

function IPS_SetParent(int $id, int $eltern): bool
{
    Sym::$objekte[$id]['Eltern'] = $eltern;
    return true;
}

function IPS_SetIdent(int $id, string $ident): bool
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $ident)) {
        throw new Exception('Ident mit unerlaubten Zeichen: ' . $ident);
    }
    if (Sym::kind(Sym::$objekte[$id]['Eltern'], $ident) != 0) {
        throw new Exception('Ident ' . $ident . ' gibt es in der Ebene schon');
    }
    Sym::$objekte[$id]['Ident'] = $ident;
    return true;
}

function IPS_SetName(int $id, string $name): bool
{
    Sym::$objekte[$id]['Name'] = $name;
    return true;
}

function IPS_SetPosition(int $id, int $position): bool
{
    Sym::$objekte[$id]['Position'] = $position;
    return true;
}

function IPS_SetVariableCustomPresentation(int $id, array $darstellung): bool
{
    Sym::$variablen[$id]['Darstellung'] = $darstellung;
    return true;
}

function IPS_SetVariableCustomAction(int $id, int $skript): bool
{
    Sym::$variablen[$id]['Aktion'] = $skript;
    return true;
}

function KNX_WriteDPT1(int $InstanceID, bool $B): bool
{
    return Sym::telegramm($InstanceID, $B, false);
}

function KNX_WriteDPT5(int $InstanceID, int $U): bool
{
    return Sym::telegramm($InstanceID, $U, false);
}

function KNX_RequestStatus(int $InstanceID): bool
{
    if (isset(Sym::$lesbar[$InstanceID])) {
        (Sym::$lesbar[$InstanceID])();
    }
    return true;
}
