<?php

declare(strict_types=1);

/**
 * Tests für das Modul Rollocontroller gegen den Nachbau von Symcon in Symcon.php.
 *
 *   php -d disable_functions=time,microtime tests/test.php
 *
 * LAUT=1 davor zeigt auch die bestandenen Prüfungen.
 */

require __DIR__ . '/Symcon.php';
require __DIR__ . '/../Rollocontroller/module.php';

Sym::$echo = !getenv('OHNE_ECHO');

const AUF = 0;
const STEHT = 1;
const AB = 2;

$fehler = 0;
$anzahl = 0;

function pruefe(bool $ok, string $text): void
{
    global $fehler, $anzahl;
    $anzahl++;
    if (!$ok) {
        $fehler++;
        echo 'FEHLER  ' . $text . "\n";
    } elseif (getenv('LAUT')) {
        echo 'ok      ' . $text . "\n";
    }
}

function gleich($ist, $soll, string $text): void
{
    $ok = $ist === $soll;
    pruefe($ok, $text . ($ok ? '' : ': ist ' . json_encode($ist, JSON_UNESCAPED_UNICODE) . ', soll ' . json_encode($soll, JSON_UNESCAPED_UNICODE)));
}

function titel(string $text): void
{
    echo '== ' . $text . "\n";
}

function zeit(string $text): float
{
    return (float) strtotime($text);
}

/**
 * Baut eine Anlage mit einem Rollo: KNX-Instanzen, Taster, LEDs, Aktor, Location Control,
 * Ordner der Wohnung und die Instanz des Moduls. Liefert Modul, IDs und Aktor.
 */
function aufbau(array $opt = []): array
{
    $opt += [
        'Zeit' => '2026-11-10 12:00:00', 'Start' => 0, 'Status' => true, 'Sperre' => false, 'Fahrzeit' => 30,
        'Echt' => 19.0, 'Eigenschaften' => [], 'Anpassen' => null, 'Wohnung' => false, 'Werte' => [], 'Sonne' => [0.0, -30.0]
    ];
    Sym::neu(zeit($opt['Zeit']));
    $raum = Sym::kategorie('Raum');
    $k = [
        'Fahren'   => Sym::knx('Fahren', $raum, 1, false),
        'Stopp'    => Sym::knx('Stopp', $raum, 1),
        'Position' => Sym::knx('Position', $raum, 5),
        'Status'   => Sym::knx('Status', $raum, 5, $opt['Status'] ? (int) $opt['Start'] : null),
        'Sperren'  => Sym::knx('Sperren', $raum, 1, $opt['Sperre'])
    ];
    foreach (['MdtKurz', 'MdtLang', 'JungKurz', 'JungKurzUmgekehrt', 'JungLang', 'TasteAuf', 'TasteAb', 'TasteAbLang', 'JungBlau', 'JungAuf', 'JungAb'] as $name) {
        $k[$name] = Sym::knx($name, $raum, 1);
    }
    $k['LedAuf'] = Sym::knx('LedAuf', $raum, 5);
    $k['LedAb'] = Sym::knx('LedAb', $raum, 5);
    $k['Leds'] = [$k['LedAuf'], $k['LedAb'], $k['JungBlau'], $k['JungAuf'], $k['JungAb']];
    $k['Rollo'] = [$k['Fahren'], $k['Stopp'], $k['Position'], $k['Sperren']];

    $aktor = new Aktor($k, $opt['Echt'], (float) $opt['Start']);
    $aktor->sperre = (bool) $opt['Sperre'];

    $ort = Sym::instanz(STANDORT, 0, 'Location');
    $k['Azimut'] = Sym::variable(VARIABLETYPE_FLOAT, $ort, 'Azimuth', 'Azimuth', $opt['Sonne'][0]);
    $k['Hoehe'] = Sym::variable(VARIABLETYPE_FLOAT, $ort, 'Altitude', 'Altitude', $opt['Sonne'][1]);
    $k['Ordner'] = Sym::kategorie('Rollosteuerung');

    $id = Sym::objekt('Instanz', $raum, 'Rollo');
    $modul = new Rollocontroller($id);
    Sym::$module[$id] = $modul;
    $modul->Create();

    $wippe = fn (string $name, string $knx, array $mehr = []): string => json_encode([['Name' => $name, 'VariableID' => Sym::wert($k[$knx])] + $mehr]);
    $led = fn (string $knx): string => json_encode([['InstanceID' => $k[$knx]]]);
    $eigenschaften = [
        'FahrenID' => $k['Fahren'], 'StoppID' => $k['Stopp'], 'PositionID' => $k['Position'],
        'StatusID' => Sym::wert($k['Status']), 'SperrenID' => $k['Sperren'], 'Fahrzeit' => $opt['Fahrzeit'],
        'MdtWippenKurz' => $wippe('Wippe', 'MdtKurz'), 'MdtWippenLang' => $wippe('Wippe lang', 'MdtLang'),
        'JungWippenKurz' => json_encode([
            ['Name' => 'Jung', 'VariableID' => Sym::wert($k['JungKurz']), 'Invertiert' => false],
            ['Name' => 'Jung umgekehrt', 'VariableID' => Sym::wert($k['JungKurzUmgekehrt']), 'Invertiert' => true]
        ]),
        'JungWippenLang' => $wippe('Jung lang', 'JungLang', ['Invertiert' => false]),
        'TastenAufKurz' => $wippe('Taste auf', 'TasteAuf'), 'TastenAbKurz' => $wippe('Taste ab', 'TasteAb'),
        'TastenAbLang' => $wippe('Taste ab lang', 'TasteAbLang'),
        'MdtLedsAuf' => $led('LedAuf'), 'MdtLedsAb' => $led('LedAb'), 'JungLedsBlau' => $led('JungBlau'),
        'JungLedsAufGruen' => $led('JungAuf'), 'JungLedsAbGruen' => $led('JungAb')
    ];
    if ($opt['Wohnung']) {
        $eigenschaften['EinstellungenID'] = $k['Ordner'];
    }
    $eigenschaften = $opt['Eigenschaften'] + $eigenschaften;
    if ($opt['Anpassen'] !== null) {
        $eigenschaften = ($opt['Anpassen'])($eigenschaften, $k);
    }
    foreach ($eigenschaften as $name => $wert) {
        if (!array_key_exists($name, $modul->eigenschaften)) {
            throw new Exception('Der Test setzt eine Eigenschaft, die das Modul nicht kennt: ' . $name);
        }
        $modul->eigenschaften[$name] = $wert;
    }
    if ($opt['Wohnung']) {
        $modul->EinstellungenAnlegen();
        foreach ($opt['Werte'] as $ident => $wert) {
            Sym::setze(Sym::kind($k['Ordner'], $ident), $wert);
        }
        Sym::$post = [];
    }
    $modul->ApplyChanges();
    Sym::verarbeite();
    return [$modul, $k, $aktor];
}

function druecke(int $instanz, bool $wert, float $danach = 0.2): void
{
    Sym::telegramm($instanz, $wert);
    Sym::weiter($danach);
}

function wohnung(array $k, string $ident, $wert): void
{
    Sym::setze(Sym::kind($k['Ordner'], $ident), $wert);
    Sym::verarbeite();
}

function sonne(array $k, float $azimut, float $hoehe): void
{
    Sym::setze($k['Azimut'], $azimut);
    Sym::setze($k['Hoehe'], $hoehe);
    Sym::verarbeite();
}

/**
 * Die Fahrbefehle des Moduls seit $ab mit Uhrzeit, etwa "Di 08:00:00 auf".
 */
function fahrten(array $k, int $ab = 0, string $format = 'D H:i:s'): array
{
    $liste = [];
    foreach (array_slice(Sym::$telegramme, $ab) as $t) {
        if ($t['Extern'] || !in_array($t['Instanz'], [$k['Fahren'], $k['Position']], true)) {
            continue;
        }
        $was = $t['Instanz'] == $k['Position'] ? 'Position ' . $t['Wert'] : ($t['Wert'] ? 'ab' : 'auf');
        $liste[] = date($format, (int) $t['Zeit']) . ' ' . $was;
    }
    return $liste;
}

function privat(object $objekt, string $methode, ...$argumente)
{
    return (new ReflectionMethod($objekt, $methode))->invoke($objekt, ...$argumente);
}

// ====================================================================== Grundlagen

titel('Grundlagen und Formular');
[$m, $k, $a] = aufbau();
gleich($m->status, IS_ACTIVE, 'Rollo ohne Automatik ist aktiv, auch ohne Ordner der Wohnung');
gleich($m->GetValue('Fahrt'), STEHT, 'Fahrt steht');
gleich($m->GetValue('Position'), 0, 'Position zeigt den Status');
gleich($m->GetValue('Sperre'), false, 'Sperre aus');
pruefe(Sym::kind($m->InstanceID, 'Automatik') > 0 && $m->GetValue('Automatik') === true, 'Schalter Automatik ist immer da und an, auch ohne Morgens und Abends');
gleich(Sym::kind($m->InstanceID, 'Verschattung'), 0, 'kein Schalter Verschattung ohne Verschattung');
pruefe(Sym::$objekte[$m->GetIDForIdent('Grund')]['Hidden'], 'Grund ist versteckt');
gleich(Sym::gesendet(0, $k['Leds']), ['LedAuf=4', 'LedAb=4', 'JungBlau=an', 'JungAuf=aus', 'JungAb=aus'], 'beim Übernehmen alle LEDs: MDT blau, Jung blau an');
gleich(Sym::gesendet(0, $k['Rollo']), [], 'beim Übernehmen kein Fahrbefehl');
gleich(array_keys($m->aktionen), ['Position', 'Fahrt', 'Sperre', 'Automatik'], 'bedienbar sind Position, Fahrt, Sperre und Automatik');

$form = json_decode(file_get_contents(__DIR__ . '/../Rollocontroller/form.json'), true);
pruefe(is_array($form), 'form.json ist gültiges JSON');
$namen = [];
$spalten = [];
$sammle = function (array $elemente) use (&$sammle, &$namen, &$spalten): void {
    foreach ($elemente as $element) {
        if (isset($element['name'])) {
            $namen[] = $element['name'];
        }
        if (($element['type'] ?? '') == 'List') {
            $spalten[$element['name']] = array_column($element['columns'], 'name');
        }
        $sammle($element['items'] ?? []);
    }
};
$sammle($form['elements']);
$registriert = array_keys($m->eigenschaften);
sort($namen);
sort($registriert);
gleich($namen, $registriert, 'Formular und Eigenschaften des Moduls stimmen überein');
foreach (['MdtWippenKurz', 'MdtWippenLang', 'TastenAufKurz', 'TastenAbKurz', 'TastenAbLang'] as $liste) {
    gleich($spalten[$liste], ['Name', 'VariableID'], 'Spalten der Liste ' . $liste);
}
foreach (['JungWippenKurz', 'JungWippenLang'] as $liste) {
    gleich($spalten[$liste], ['Name', 'VariableID', 'Invertiert'], 'Spalten der Liste ' . $liste);
}
foreach (['MdtLedsAuf', 'MdtLedsAb', 'JungLedsBlau', 'JungLedsAufGruen', 'JungLedsAbGruen'] as $liste) {
    gleich($spalten[$liste], ['InstanceID'], 'Spalten der Liste ' . $liste);
}
gleich(array_column($form['status'], 'code'), [104, 201, 202], 'Statusmeldungen im Formular');
pruefe(str_contains($form['actions'][0]['onClick'], 'ROLLOCONTROLLER_EinstellungenAnlegen($id)'), 'Schaltfläche ruft ROLLOCONTROLLER_EinstellungenAnlegen');
$modulJson = json_decode(file_get_contents(__DIR__ . '/../Rollocontroller/module.json'), true);
gleich([$modulJson['name'], $modulJson['prefix']], ['Rollocontroller', 'ROLLOCONTROLLER'], 'Modul heißt Rollocontroller mit Präfix ROLLOCONTROLLER');
pruefe(is_array(json_decode(file_get_contents(__DIR__ . '/../library.json'), true)), 'library.json ist gültiges JSON');

[$m] = aufbau(['Eigenschaften' => ['StoppID' => 0]]);
gleich($m->status, IS_INACTIVE, 'ohne Stopp-Instanz inaktiv (104)');
[$m] = aufbau(['Anpassen' => function (array $e, array $k): array {
    $e['StoppID'] = $k['Fahren'];
    return $e;
}]);
gleich($m->status, 201, 'dieselbe Instanz für Fahren und Stopp wird abgelehnt (201)');
[$m] = aufbau(['Anpassen' => function (array $e, array $k): array {
    $e['StatusID'] = Sym::wert($k['Position']);
    return $e;
}]);
gleich($m->status, 201, 'Status aus der Befehlsinstanz "Position anfahren" wird abgelehnt (201)');
[$m] = aufbau(['Anpassen' => function (array $e, array $k): array {
    $e['TastenAbKurz'] = json_encode([['Name' => 'falsch', 'VariableID' => Sym::wert($k['Fahren'])]]);
    return $e;
}]);
gleich($m->status, 201, 'eine Adresse des Rollos als Taster wird abgelehnt (201)');
[$m] = aufbau(['Eigenschaften' => ['TastenAbKurz' => json_encode([['Name' => 'leer', 'VariableID' => 0]])]]);
gleich($m->status, 201, 'Taster ohne Variable wird abgelehnt (201)');

// ====================================================================== Variablen der Wohnung

titel('Variablen der Wohnung');
[$m, $k] = aufbau(['Eigenschaften' => ['MorgensOeffnen' => true]]);
gleich($m->status, 202, 'Morgens öffnen ohne Ordner der Wohnung: Status 202');
[$m, $k] = aufbau(['Anpassen' => function (array $e, array $k): array {
    return ['MorgensOeffnen' => true, 'EinstellungenID' => $k['Ordner']] + $e;
}]);
gleich($m->status, 201, 'Ordner ohne Variablen: Status 201');
pruefe(str_contains($m->log[0][1] ?? '', 'fehlt die Variable RaufHeute'), 'das Log nennt die fehlende Variable');
gleich(Sym::kinder($k['Ordner']), [], 'das Modul legt von selbst nichts im Ordner an');
$m->EinstellungenAnlegen();
$m->ApplyChanges();
gleich($m->status, IS_ACTIVE, 'nach dem Anlegen der Variablen aktiv');
$erwartet = ['RaufHeute' => 0, 'RunterHeute' => 0, 'AutomatikRauf' => true, 'AutomatikRunter' => true, 'AutomatikVerschattung' => true, 'Oeffnungswinkel' => 80, 'Mindesthoehe' => 10];
$ist = [];
$skript = Sym::kind($k['Ordner'], 'WertUebernehmen');
foreach (Sym::kinder($k['Ordner']) as $id) {
    if (isset(Sym::$variablen[$id])) {
        $ist[Sym::$objekte[$id]['Ident']] = Sym::$variablen[$id]['Wert'];
        pruefe(Sym::$variablen[$id]['Aktion'] == $skript && isset(Sym::$variablen[$id]['Darstellung']['PRESENTATION']), 'Variable ' . Sym::$objekte[$id]['Name'] . ' hat Aktionsskript und Darstellung');
    }
}
gleich($ist, $erwartet, 'sieben Variablen mit Vorgaben, beide Zeitpunkte 0 = heute nicht');
$darstellung = Sym::$variablen[Sym::kind($k['Ordner'], 'RaufHeute')]['Darstellung'];
gleich([$darstellung['DATE'], $darstellung['TIME'], $darstellung['DAY_OF_THE_WEEK']], [0, 1, false], 'Rauf heute zeigt nur die Uhrzeit, kein Datum');
pruefe($skript > 0 && Sym::$objekte[$skript]['Hidden'] && str_contains(Sym::$skripte[$skript], "SetValue(\$_IPS['VARIABLE'], \$_IPS['VALUE']);"), 'verstecktes Aktionsskript übernimmt den Wert');
$sonneZeit = array_merge((new ReflectionClassConstant('Rollocontroller', 'EINSTELLUNGEN_SONNE'))->getValue(), array_keys((new ReflectionClassConstant('Rollocontroller', 'ZEITPUNKTE'))->getValue()));
gleich([array_diff($sonneZeit, array_keys($erwartet)), array_values(array_diff(array_keys($erwartet), $sonneZeit))], [[], ['AutomatikRauf', 'AutomatikRunter']], 'das Modul überwacht die Zeitpunkte und die Sonnenwerte, die Wohnungsschalter Rauf und Runter liest es erst zum Zeitpunkt');
Sym::setze(Sym::kind($k['Ordner'], 'Oeffnungswinkel'), 45);
$anzahlObjekte = count(Sym::$objekte);
$m->EinstellungenAnlegen();
gleich(count(Sym::$objekte), $anzahlObjekte, 'zweites Anlegen legt nichts doppelt an');
gleich(Sym::$variablen[Sym::kind($k['Ordner'], 'Oeffnungswinkel')]['Wert'], 45, 'vorhandene Werte bleiben');
pruefe(Sym::kind($m->InstanceID, 'Automatik') > 0 && $m->GetValue('Automatik') === true, 'Schalter Automatik des Rollos ist da und an');
[$m] = aufbau(['Eigenschaften' => ['EinstellungenID' => 0]]);
$wurf = false;
try {
    $m->EinstellungenAnlegen();
} catch (Exception $e) {
    $wurf = true;
}
pruefe($wurf, 'Anlegen ohne Ordner meldet einen Fehler');

// ====================================================================== Taster

titel('Taster: kurz fahren, nochmal Stopp, lang Lüften');
[$m, $k, $a] = aufbau();
$ab = count(Sym::$telegramme);
druecke($k['MdtKurz'], true, 4.0);
gleich(Sym::gesendet($ab, $k['Rollo']), ['Fahren=an'], 'MDT kurz unten (an) fährt ab');
gleich($m->GetValue('Fahrt'), AB, 'Fahrt zeigt ab');
gleich($m->GetValue('Position'), 100, 'Position zeigt das Ziel');
gleich($m->GetValue('Grund'), 'Ab: Wippe', 'Grund nennt den Taster');
gleich(Sym::gesendet($ab, $k['Leds']), ['LedAb=3', 'JungBlau=aus', 'JungAb=an'], 'LEDs: Ab-Taste grün, Jung blau aus und Ab grün an, sonst nichts');
$ab = count(Sym::$telegramme);
druecke($k['MdtKurz'], true, 0.5);
gleich(Sym::gesendet($ab, $k['Rollo']), ['Stopp=an'], 'nochmal in Fahrtrichtung stoppt');
gleich($m->GetValue('Fahrt'), STEHT, 'Fahrt steht');
gleich($m->GetValue('Position'), 13, 'Position geschätzt aus der Fahrzeit (4 von 30 s)');
gleich(Sym::gesendet($ab, $k['Leds']), ['LedAb=4', 'JungBlau=an', 'JungAb=aus'], 'LEDs zurück auf Blau');
Sym::weiter(2);
gleich($m->GetValue('Position'), 21, 'Position nach der Meldung des Aktors (4 von 19 s)');
gleich($m->GetValue('Grund'), 'Stopp: Wippe', 'Grund Stopp');

[$m, $k, $a] = aufbau();
$ab = count(Sym::$telegramme);
druecke($k['MdtKurz'], true, 3.0);
druecke($k['MdtKurz'], true, 0.5);
druecke($k['MdtKurz'], false, 0.3);
gleich(Sym::gesendet($ab, $k['Rollo']), ['Fahren=an', 'Stopp=an', 'Fahren=aus'], 'ab, Stopp, 0,5 s später auf: alle drei gesendet');
Sym::weiter(1.5);
gleich($m->GetValue('Fahrt'), AUF, 'die verspätete Meldung des Stopps beendet die neue Fahrt nicht');
Sym::weiter(30);
gleich([$m->GetValue('Fahrt'), $m->GetValue('Position'), $a->position], [STEHT, 0, 0.0], 'am Ende offen und steht');

[$m, $k, $a] = aufbau();
$ab = count(Sym::$telegramme);
druecke($k['MdtKurz'], true, 18.7);
gleich($m->GetValue('Fahrt'), AB, 'ganze Fahrt: kurz vor dem Ende fährt es noch');
Sym::weiter(0.3);
gleich([$m->GetValue('Fahrt'), $m->GetValue('Position')], [STEHT, 100], 'die Meldung des Aktors beendet die Fahrt nach 19 s');
gleich($m->timer['Fahrt']['Faellig'], null, 'der Timer der Fahrt ist aus');
Sym::weiter(120);
gleich(Sym::gesendet($ab, $k['Rollo']), ['Fahren=an'], 'danach kommt nichts mehr');
$ab = count(Sym::$telegramme);
druecke($k['MdtKurz'], true);
gleich(Sym::gesendet($ab, $k['Rollo']), [], 'steht schon ganz zu: ab sendet nichts');
druecke($k['MdtLang'], false);
gleich(Sym::gesendet($ab, $k['Rollo']), [], 'MDT lang oben ist ohne Funktion');
druecke($k['MdtLang'], true, 2.0);
gleich(Sym::gesendet($ab, $k['Rollo']), ['Position=80'], 'MDT lang unten fährt die Lüftungsposition an');
gleich([$m->GetValue('Fahrt'), $m->GetValue('Grund')], [AUF, 'Lüften: Wippe lang'], 'von 100 auf 80 fährt es auf');
Sym::weiter(10);
gleich([$m->GetValue('Fahrt'), $m->GetValue('Position')], [STEHT, 80], 'steht in der Lüftungsposition');

foreach ([
    ['JungKurz', true, 'Fahren=aus', 'Auf: Jung', 'Jung kurz an fährt auf'],
    ['JungKurz', false, 'Fahren=an', 'Ab: Jung', 'Jung kurz aus fährt ab'],
    ['JungKurzUmgekehrt', true, 'Fahren=an', 'Ab: Jung umgekehrt', 'Jung invertiert: an fährt ab'],
    ['JungKurzUmgekehrt', false, 'Fahren=aus', 'Auf: Jung umgekehrt', 'Jung invertiert: aus fährt auf'],
    ['JungLang', false, 'Position=80', 'Lüften: Jung lang', 'Jung lang aus fährt die Lüftungsposition an'],
    ['TasteAuf', true, 'Fahren=aus', 'Auf: Taste auf', 'Auf-Taste fährt auf'],
    ['TasteAuf', false, 'Fahren=aus', 'Auf: Taste auf', 'Auf-Taste: jedes Telegramm zählt'],
    ['TasteAb', false, 'Fahren=an', 'Ab: Taste ab', 'Ab-Taste fährt ab'],
    ['TasteAbLang', true, 'Position=80', 'Lüften: Taste ab lang', 'Ab-Taste lang fährt die Lüftungsposition an'],
    ['MdtKurz', false, 'Fahren=aus', 'Auf: Wippe', 'MDT kurz oben (aus) fährt auf']
] as [$taster, $wert, $telegramm, $grund, $text]) {
    [$m, $k] = aufbau(['Start' => 50]);
    $ab = count(Sym::$telegramme);
    druecke($k[$taster], $wert);
    gleich([Sym::gesendet($ab, $k['Rollo']), $m->GetValue('Grund')], [[$telegramm], $grund], $text);
}
[$m, $k] = aufbau(['Start' => 50]);
$ab = count(Sym::$telegramme);
druecke($k['JungLang'], true);
gleich(Sym::gesendet($ab, $k['Rollo']), [], 'Jung lang an ist ohne Funktion');
druecke($k['TasteAuf'], true, 2.0);
druecke($k['TasteAuf'], true);
gleich(Sym::gesendet($ab, $k['Rollo']), ['Fahren=aus', 'Stopp=an'], 'Auf-Taste zweimal: fahren, dann Stopp');
[$m, $k] = aufbau();
$ab = count(Sym::$telegramme);
druecke($k['MdtKurz'], false);
gleich(Sym::gesendet($ab, $k['Rollo']), [], 'steht schon ganz offen: auf sendet nichts');
[$m, $k] = aufbau(['Eigenschaften' => ['Lueftungsposition' => 60]]);
$ab = count(Sym::$telegramme);
druecke($k['MdtLang'], true);
gleich(Sym::gesendet($ab, $k['Rollo']), ['Position=60'], 'Lüftungsposition aus den Einstellungen');

// ====================================================================== Sperre

titel('Eigene Telegramme kommen doppelt an');
[$m, $k, $a] = aufbau();
druecke($k['MdtKurz'], true, 1.0);
gleich($m->GetValue('Grund'), 'Ab: Wippe', 'eigenes Fahren: die zweite Zustellung zählt nicht als Extern');
druecke($k['MdtKurz'], true, 1.0);
gleich($m->GetValue('Grund'), 'Stopp: Wippe', 'eigener Stopp: kein Extern');
druecke($k['MdtLang'], true, 1.0);
gleich($m->GetValue('Grund'), 'Lüften: Wippe lang', 'eigene Position: kein Extern');
Sym::weiter(70.0); // Fahrt zu Ende, sonst stoppt die Sperre
$m->RequestAction('Sperre', true);
Sym::verarbeite();
gleich($m->GetValue('Grund'), 'Sperre an: App', 'eigene Sperre: kein Extern');
$m->RequestAction('Sperre', false);
Sym::verarbeite();
[$m, $k, $a] = aufbau();
druecke($k['MdtKurz'], true, 1.0);
Sym::telegramm($k['Fahren'], false);
Sym::verarbeite();
gleich($m->GetValue('Grund'), 'Auf: von Extern', 'anderer Wert kurz nach dem eigenen Befehl kommt von einem anderen Gerät');
[$m, $k, $a] = aufbau();
druecke($k['MdtKurz'], true, 6.0);
Sym::telegramm($k['Fahren'], true);
Sym::verarbeite();
gleich($m->GetValue('Grund'), 'Ab: von Extern', 'gleicher Wert nach der Sperrzeit ist Extern');

titel('Sperre');
[$m, $k, $a] = aufbau(['Sperre' => null]);
gleich($m->GetValue('Sperre'), false, 'Sperre nie gemeldet: gilt als nicht gesperrt');
$ab = count(Sym::$telegramme);
druecke($k['MdtKurz'], true);
gleich(Sym::gesendet($ab, $k['Rollo']), ['Fahren=an'], 'Sperre nie gemeldet: das Rollo fährt');

[$m, $k, $a] = aufbau();
$ab = count(Sym::$telegramme);
$m->RequestAction('Sperre', true);
Sym::weiter(0.2);
gleich(Sym::gesendet($ab, $k['Rollo']), ['Sperren=an'], 'Sperre aus der App setzt Sperren am Aktor');
gleich([$m->GetValue('Sperre'), $a->sperre, $m->GetValue('Grund')], [true, true, 'Sperre an: App'], 'Sperre ist an');
$ab = count(Sym::$telegramme);
druecke($k['MdtKurz'], true);
gleich(Sym::gesendet($ab, $k['Rollo']), [], 'gesperrt: der Taster sendet nichts');
gleich($m->GetValue('Grund'), 'Gesperrt: Ab von Wippe nicht ausgeführt', 'der Grund sagt, warum nichts fährt');
$m->RequestAction('Position', 50);
$m->RequestAction('Fahrt', AB);
$m->Befehl('lueften', 'Gruppe');
Sym::weiter(0.2);
gleich(Sym::gesendet($ab, $k['Rollo']), [], 'gesperrt: auch App und Befehl senden nichts');
gleich($m->GetValue('Position'), 0, 'gesperrt: der Schieber springt zurück');
Sym::telegramm($k['Fahren'], true);
Sym::weiter(5);
gleich(Sym::gesendet($ab, $k['Rollo']), [], 'Fahrbefehl von außen bei gesperrtem Rollo: das Modul sendet nichts, auch keinen Stopp');
gleich([$m->GetValue('Fahrt'), $a->position, $m->GetValue('Grund')], [STEHT, 0.0, 'Gesperrt: Ab von Extern nicht ausgeführt'], 'der Aktor fährt nicht, im Modul beginnt keine Fahrt');
$m->RequestAction('Sperre', false);
Sym::weiter(0.2);
$ab = count(Sym::$telegramme);
druecke($k['MdtKurz'], true);
gleich(Sym::gesendet($ab, $k['Rollo']), ['Fahren=an'], 'Sperre aus: das Rollo fährt wieder');

[$m, $k, $a] = aufbau();
druecke($k['MdtKurz'], true, 5.0);
$ab = count(Sym::$telegramme);
Sym::telegramm($k['Sperren'], true);
Sym::weiter(0.2);
gleich(Sym::gesendet($ab, $k['Rollo']), ['Stopp=an'], 'Sperre an während der Fahrt: das Modul schickt Stopp');
gleich([$m->GetValue('Sperre'), $m->GetValue('Grund')], [true, 'Stopp: Sperre eingeschaltet'], 'Sperre von außen steht im Modul');

// ====================================================================== App und Befehl

titel('App und Befehl');
[$m, $k, $a] = aufbau();
$ab = count(Sym::$telegramme);
$m->RequestAction('Position', 50);
Sym::weiter(1);
gleich(Sym::gesendet($ab, $k['Rollo']), ['Position=50'], 'Schieber auf 50 schreibt die Positions-Adresse');
gleich([$m->GetValue('Fahrt'), $m->GetValue('Position'), $m->GetValue('Grund')], [AB, 50, 'Position 50 %: App'], 'fährt ab, Position zeigt das Ziel');
Sym::weiter(15);
gleich([$m->GetValue('Fahrt'), $m->GetValue('Position')], [STEHT, 50], 'steht bei 50');
$ab = count(Sym::$telegramme);
$m->RequestAction('Position', 0);
Sym::weiter(1);
gleich(Sym::gesendet($ab, $k['Rollo']), ['Fahren=aus'], 'Schieber ganz offen fährt über die Fahren-Adresse');
Sym::weiter(15);
$ab = count(Sym::$telegramme);
$m->RequestAction('Position', 100);
Sym::weiter(1);
gleich(Sym::gesendet($ab, $k['Rollo']), ['Fahren=an'], 'Schieber ganz zu fährt über die Fahren-Adresse');
$m->RequestAction('Fahrt', STEHT);
Sym::weiter(3);
gleich(Sym::gesendet($ab, $k['Rollo']), ['Fahren=an', 'Stopp=an'], 'Stopp aus der App');
$m->RequestAction('Fahrt', AUF);
Sym::weiter(0.5);
gleich([$m->GetValue('Fahrt'), $m->GetValue('Grund')], [AUF, 'Auf: App'], 'Auf aus der App');
Sym::weiter(15);
$m->RequestAction('Fahrt', AB);
Sym::weiter(1);
gleich($m->GetValue('Fahrt'), AB, 'Ab aus der App');

[$m, $k, $a] = aufbau(['Start' => 30]);
$ab = count(Sym::$telegramme);
$m->Befehl('ab', 'Gruppe Wohnen');
Sym::weiter(1);
$m->Befehl('stopp', 'Gruppe Wohnen');
Sym::weiter(2);
$m->Befehl('auf', 'Gruppe Wohnen');
Sym::weiter(20);
$m->Befehl('lueften', 'Gruppe Wohnen');
Sym::weiter(1);
gleich(Sym::gesendet($ab, $k['Rollo']), ['Fahren=an', 'Stopp=an', 'Fahren=aus', 'Position=80'], 'Befehl: ab, stopp, auf, lueften');
gleich($m->GetValue('Grund'), 'Lüften: Gruppe Wohnen', 'die Quelle steht im Grund');
$wurf = false;
try {
    $m->Befehl('hoch', 'Skript');
} catch (Exception $e) {
    $wurf = true;
}
pruefe($wurf, 'unbekannter Befehl meldet einen Fehler');

// ====================================================================== Fahrt

titel('Fahrt: Sonderfälle');
[$m, $k, $a] = aufbau(['Echt' => 40.0]);
$ab = count(Sym::$telegramme);
druecke($k['MdtKurz'], true, 34.0);
gleich($m->GetValue('Fahrt'), AB, 'Fahrzeit zu kurz eingestellt (30 statt 40 s): nach 34 s fährt es im Modul noch');
druecke($k['MdtKurz'], true, 0.5);
gleich(Sym::gesendet($ab, $k['Rollo']), ['Fahren=an', 'Stopp=an'], 'nochmal ab stoppt auch dann');
Sym::weiter(2);
gleich([$m->GetValue('Position'), $a->faehrt()], [85, false], 'der Aktor steht, die Position kommt von ihm (34 von 40 s)');

[$m, $k, $a] = aufbau();
$a->defekt = true;
$ab = count(Sym::$telegramme);
druecke($k['MdtKurz'], true, 62.0);
gleich($m->GetValue('Fahrt'), AB, 'keine Meldung vom Aktor: nach 62 s fährt es im Modul noch');
Sym::weiter(1.5);
gleich([$m->GetValue('Fahrt'), $m->GetValue('Position')], [STEHT, 100], 'nach der doppelten Fahrzeit plus Reserve steht es, das Ziel gilt als erreicht');
gleich($m->timer['Fahrt']['Faellig'], null, 'der Timer der Fahrt ist aus');
$m->RequestAction('Fahrt', STEHT);
druecke($k['MdtKurz'], true);
gleich(Sym::gesendet($ab, $k['Rollo']), ['Fahren=an', 'Stopp=an', 'Fahren=an'], 'ohne Meldung des Aktors werden Stopp und Fahren trotzdem gesendet');

[$m, $k, $a] = aufbau();
$ab = count(Sym::$telegramme);
druecke($k['MdtKurz'], true, 6.0);
druecke($k['MdtKurz'], false, 0.5);
gleich([Sym::gesendet($ab, $k['Rollo']), $m->GetValue('Fahrt')], [['Fahren=an', 'Fahren=aus'], AUF], 'Richtungswechsel ohne Stopp');
Sym::weiter(20);
gleich([$m->GetValue('Fahrt'), $m->GetValue('Position')], [STEHT, 0], 'danach offen');

[$m, $k, $a] = aufbau();
druecke($k['MdtKurz'], true, 7.0);
$ab = count(Sym::$telegramme);
Sym::telegramm($k['Stopp'], true);
Sym::weiter(0.2);
gleich([$m->GetValue('Fahrt'), $m->GetValue('Grund'), Sym::gesendet($ab, $k['Rollo'])], [STEHT, 'Stopp: von Extern', []], 'Stopp von außen beendet die Fahrt');
Sym::weiter(2);
gleich($m->GetValue('Position'), 37, 'Position vom Aktor');

[$m, $k, $a] = aufbau();
$ab = count(Sym::$telegramme);
Sym::telegramm($k['Fahren'], true);
Sym::weiter(1);
gleich([$m->GetValue('Fahrt'), $m->GetValue('Grund'), Sym::gesendet($ab, $k['Rollo'])], [AB, 'Ab: von Extern', []], 'Fahrbefehl von außen: Fahrt ab, Grund von Extern');
druecke($k['MdtKurz'], true);
gleich(Sym::gesendet($ab, $k['Rollo']), ['Stopp=an'], 'der Taster stoppt auch eine Fahrt von außen');
[$m, $k, $a] = aufbau(['Start' => 20]);
Sym::telegramm($k['Position'], 70);
Sym::weiter(1);
gleich([$m->GetValue('Fahrt'), $m->GetValue('Grund')], [AB, 'Position 70 %: von Extern'], 'Position von außen');
Sym::weiter(15);
gleich([$m->GetValue('Fahrt'), $m->GetValue('Position')], [STEHT, 70], 'steht bei 70');

[$m, $k, $a] = aufbau();
druecke($k['MdtKurz'], true, 0.3);
KNX_RequestStatus($k['Status']);
Sym::weiter(0.2);
gleich($m->GetValue('Fahrt'), AB, 'Antwort auf eine Leseanfrage gleich nach dem Start beendet die Fahrt nicht');

[$m, $k, $a] = aufbau(['Status' => false]);
gleich($m->GetValue('Position'), 0, 'Position nie gemeldet: Anzeige bleibt beim Anfangswert');
$ab = count(Sym::$telegramme);
druecke($k['MdtKurz'], false, 1.0);
gleich([Sym::gesendet($ab, $k['Rollo']), $m->GetValue('Fahrt')], [['Fahren=aus'], AUF], 'Position nie gemeldet: auf wird gesendet');

titel('Neustart von Symcon');
[$m, $k, $a] = aufbau();
druecke($k['MdtKurz'], true, 6.0);
$m->puffer = [];
foreach ($m->timer as $name => $timer) {
    $m->timer[$name]['Faellig'] = null;
}
$ab = count(Sym::$telegramme);
$m->MessageSink(0, 0, IPS_KERNELMESSAGE, [KR_READY]);
gleich([$m->GetValue('Fahrt'), $m->status], [STEHT, IS_ACTIVE], 'nach dem Start steht die Fahrt im Modul');
gleich(Sym::gesendet($ab, $k['Leds']), [], 'die LEDs kommen erst nach der Wartezeit');
Sym::weiter(13.5);
gleich($m->GetValue('Position'), 100, 'die Meldung am Ende der Fahrt kommt an');
Sym::weiter(30);
gleich(Sym::gesendet($ab, $k['Leds']), ['LedAuf=4', 'LedAb=4', 'JungBlau=an', 'JungAuf=aus', 'JungAb=aus'], 'nach 35 s alle LEDs neu');
gleich($m->timer['Start']['Faellig'], null, 'der Start-Timer ist aus');

[$m, $k, $a] = aufbau();
$a->position = 60.0;
$m->puffer = [];
$m->MessageSink(0, 0, IPS_KERNELMESSAGE, [KR_READY]);
$ab = count(Sym::$telegramme);
druecke($k['MdtKurz'], false, 1.0);
gleich(Sym::gesendet($ab, $k['Rollo']), ['Fahren=aus'], 'nach dem Start gilt der alte Status nur als Stand: auf wird gesendet, obwohl er offen sagt');
Sym::weiter(40);
gleich([$m->GetValue('Fahrt'), $m->GetValue('Position'), $a->position], [STEHT, 0, 0.0], 'danach offen');

// ====================================================================== Morgens und Abends

titel('Rauf heute und Runter heute');
$automatik = ['MorgensOeffnen' => true, 'AbendsSchliessen' => true];
$um = fn (string $uhr, string $tag = '2026-11-10'): int => (int) zeit($tag . ' ' . $uhr);
$tag = ['RaufHeute' => $um('08:00:00'), 'RunterHeute' => $um('16:49:05')];
$nacht = ['Zeit' => '2026-11-10 03:00:00', 'Start' => 100, 'Wohnung' => true, 'Eigenschaften' => $automatik];

[$m, $k, $a] = aufbau($nacht + ['Werte' => $tag]);
gleich($m->status, IS_ACTIVE, 'mit Ordner und Variablen aktiv');
gleich($m->timer['Automatik']['Faellig'], zeit('2026-11-10 08:00:00'), 'der Timer steht auf Rauf heute');
Sym::lauf(zeit('2026-11-10 23:00:00'));
gleich(fahrten($k), ['Tue 08:00:00 auf', 'Tue 16:49:05 ab'], 'das Rollo fährt genau zu den beiden Zeitpunkten');
gleich($m->GetValue('Grund'), 'Ab: Abends', 'Grund Abends');
gleich($m->timer['Automatik']['Faellig'], zeit('2026-11-11 00:00:30'), 'danach steht der Timer auf kurz nach Mitternacht');

[$m, $k, $a] = aufbau($nacht);
Sym::lauf(zeit('2026-11-12 03:00:00'));
gleich(fahrten($k), [], 'beide Zeitpunkte 0: es fährt nichts');

[$m, $k, $a] = aufbau(['Zeit' => '2026-11-10 00:01:00', 'Start' => 100, 'Wohnung' => true, 'Eigenschaften' => $automatik]);
Sym::lauf(zeit('2026-11-10 00:05:00'));
wohnung($k, 'RaufHeute', $tag['RaufHeute']);
wohnung($k, 'RunterHeute', $tag['RunterHeute']);
Sym::lauf(zeit('2026-11-10 23:00:00'));
gleich(fahrten($k), ['Tue 08:00:00 auf', 'Tue 16:49:05 ab'], 'das Skript schreibt die Zeitpunkte um 00:05: alle Rollos folgen');

[$m, $k, $a] = aufbau(['Zeit' => '2026-11-10 03:00:00', 'Start' => 100, 'Wohnung' => true, 'Eigenschaften' => ['MorgensOeffnen' => true], 'Werte' => $tag]);
Sym::lauf(zeit('2026-11-10 23:00:00'));
gleich(fahrten($k), ['Tue 08:00:00 auf'], 'nur Morgens öffnen eingestellt: Runter heute zählt für dieses Rollo nicht');
gleich(Sym::kind($m->InstanceID, 'Automatik') > 0, true, 'der Schalter Automatik des Rollos ist da');

titel('Nie nachholen');
[$m, $k, $a] = aufbau(['Zeit' => '2026-11-10 08:00:01'] + $nacht + ['Werte' => $tag]);
Sym::weiter(3600);
gleich(fahrten($k), [], 'Zeitpunkt beim Übernehmen 1 Sekunde vorbei: es wird nicht nachgeholt');
[$m, $k, $a] = aufbau($nacht);
Sym::lauf(zeit('2026-11-10 07:00:00'));
wohnung($k, 'RaufHeute', $um('06:59:59'));
Sym::weiter(3600);
gleich(fahrten($k), [], 'das Skript schreibt einen Zeitpunkt, der schon vorbei ist: es fährt nichts');
wohnung($k, 'RaufHeute', $um('08:00:00', '2026-11-09'));
Sym::weiter(60);
gleich(fahrten($k), [], 'ein Zeitpunkt von gestern zählt nicht');
[$m, $k, $a] = aufbau($nacht + ['Werte' => ['RaufHeute' => 8 * 3600, 'RunterHeute' => 16 * 3600 + 49 * 60 + 5]]);
Sym::lauf(zeit('2026-11-10 23:00:00'));
gleich(fahrten($k), ['Tue 08:00:00 auf', 'Tue 16:49:05 ab'], 'Sekunden seit Mitternacht statt Zeitstempel: gelten für heute');
[$m, $k, $a] = aufbau($nacht + ['Werte' => ['RaufHeute' => 8 * 3600]]);
Sym::lauf(zeit('2026-11-10 09:00:00'));
wohnung($k, 'RaufHeute', 7 * 3600);
Sym::lauf(zeit('2026-11-10 23:00:00'));
gleich(fahrten($k), ['Tue 08:00:00 auf'], 'Sekunden seit Mitternacht, die heute schon vorbei sind: nichts nachgeholt');

[$m, $k, $a] = aufbau($nacht + ['Werte' => $tag]);
Sym::lauf(zeit('2026-11-10 07:50:00'));
wohnung($k, 'RaufHeute', $um('09:00:00'));
Sym::lauf(zeit('2026-11-10 12:00:00'));
gleich(fahrten($k), ['Tue 09:00:00 auf'], 'Zeitpunkt um 07:50 von 08:00 auf 09:00 verschoben: fährt um 09:00');
[$m, $k, $a] = aufbau($nacht + ['Werte' => $tag]);
Sym::lauf(zeit('2026-11-10 07:50:00'));
wohnung($k, 'RaufHeute', $um('07:55:00'));
Sym::lauf(zeit('2026-11-10 12:00:00'));
gleich(fahrten($k), ['Tue 07:55:00 auf'], 'auf 07:55 vorgezogen: fährt um 07:55 und um 08:00 nicht noch einmal');
[$m, $k, $a] = aufbau($nacht + ['Werte' => $tag]);
Sym::lauf(zeit('2026-11-10 07:50:00'));
wohnung($k, 'RaufHeute', $um('07:30:00'));
Sym::lauf(zeit('2026-11-10 12:00:00'));
gleich(fahrten($k), [], 'um 07:50 auf 07:30 gestellt: der Zeitpunkt ist vorbei, es fährt heute nicht');
[$m, $k, $a] = aufbau($nacht + ['Werte' => $tag]);
Sym::lauf(zeit('2026-11-10 07:50:00'));
wohnung($k, 'RaufHeute', 0);
Sym::lauf(zeit('2026-11-10 12:00:00'));
gleich(fahrten($k), [], 'um 07:50 auf 0 gestellt: es fährt heute nicht');

$neustart = function (Rollocontroller $m): void {
    $m->puffer = [];
    foreach ($m->timer as $name => $timer) {
        $m->timer[$name]['Faellig'] = null;
    }
};
[$m, $k, $a] = aufbau($nacht + ['Werte' => $tag]);
Sym::lauf(zeit('2026-11-10 07:59:40'));
$neustart($m);
$m->MessageSink(0, 0, IPS_KERNELMESSAGE, [KR_READY]);
Sym::lauf(zeit('2026-11-10 08:30:00'));
gleich(fahrten($k), ['Tue 08:00:00 auf'], 'Neustart 20 Sekunden vor dem Zeitpunkt: das Rollo fährt pünktlich, auch in der Wartezeit nach dem Start');
[$m, $k, $a] = aufbau($nacht + ['Werte' => $tag]);
Sym::lauf(zeit('2026-11-10 07:59:00'));
$neustart($m);
Sym::lauf(zeit('2026-11-10 08:00:10'));
$m->MessageSink(0, 0, IPS_KERNELMESSAGE, [KR_READY]);
Sym::lauf(zeit('2026-11-10 12:00:00'));
gleich(fahrten($k), [], 'Symcon war zum Zeitpunkt aus und startet 10 Sekunden danach: es wird nicht nachgeholt');

[$m, $k, $a] = aufbau($nacht + ['Werte' => $tag]);
Sym::lauf(zeit('2026-11-10 08:05:00'));
$a->position = 100.0;
Sym::setze(Sym::wert($k['Status']), 100);
Sym::verarbeite();
$m->ApplyChanges();
$m->MessageSink(0, 0, IPS_KERNELMESSAGE, [KR_READY]);
wohnung($k, 'Oeffnungswinkel', 70);
Sym::lauf(zeit('2026-11-10 12:00:00'));
gleich(fahrten($k), ['Tue 08:00:00 auf'], 'jeder Zeitpunkt gilt einmal, auch nach Übernehmen und Neustart');

titel('Je Rollo: Schalter Automatik, Sperre');
foreach ([
    ['Schalter Automatik des Rollos aus', fn ($m, $k) => $m->RequestAction('Automatik', false), 'Morgens nicht geöffnet: Automatik ist aus', 'Abends nicht geschlossen: Automatik ist aus'],
    ['gesperrt', function ($m, $k) {
        Sym::telegramm($k['Sperren'], true);
        Sym::verarbeite();
    }, 'Morgens nicht geöffnet: gesperrt', 'Abends nicht geschlossen: gesperrt']
] as [$text, $vorher, $morgens, $abends]) {
    [$m, $k, $a] = aufbau($nacht + ['Werte' => $tag]);
    $vorher($m, $k);
    Sym::lauf(zeit('2026-11-10 12:00:00'));
    gleich([fahrten($k), $m->GetValue('Grund')], [[], $morgens], 'Morgens fährt nicht: ' . $text);
    Sym::lauf(zeit('2026-11-10 23:00:00'));
    gleich([fahrten($k), $m->GetValue('Grund')], [[], $abends], 'Abends fährt nicht: ' . $text);
}
[$m, $k, $a] = aufbau(['Start' => 50] + $nacht + ['Werte' => $tag]);
$m->RequestAction('Automatik', false);
Sym::lauf(zeit('2026-11-10 08:10:00'));
$m->RequestAction('Automatik', true);
Sym::lauf(zeit('2026-11-10 12:00:00'));
gleich(fahrten($k), [], 'Automatik nach dem Zeitpunkt wieder an: es wird nichts nachgeholt');
Sym::lauf(zeit('2026-11-10 23:00:00'));
gleich(fahrten($k), ['Tue 16:49:05 ab'], 'abends fährt das Rollo wieder mit');
[$m, $k, $a] = aufbau(['Start' => 0] + $nacht + ['Werte' => $tag]);
Sym::lauf(zeit('2026-11-10 12:00:00'));
gleich(fahrten($k), [], 'schon offen: Morgens sendet nichts');

titel('Wohnung: Automatik Rauf und Automatik Runter');
[$m, $k, $a] = aufbau(['Start' => 50] + $nacht + ['Werte' => $tag + ['AutomatikRauf' => false]]);
Sym::lauf(zeit('2026-11-10 12:00:00'));
gleich([fahrten($k), $m->GetValue('Grund')], [[], 'Morgens nicht geöffnet: Automatik Rauf der Wohnung ist aus'], 'Automatik Rauf der Wohnung aus: Morgens fährt nicht');
Sym::lauf(zeit('2026-11-10 23:00:00'));
gleich(fahrten($k), ['Tue 16:49:05 ab'], 'Abends fährt trotzdem, Automatik Runter ist an');
[$m, $k, $a] = aufbau($nacht + ['Werte' => $tag + ['AutomatikRunter' => false]]);
Sym::lauf(zeit('2026-11-10 23:00:00'));
gleich([fahrten($k), $m->GetValue('Grund')], [['Tue 08:00:00 auf'], 'Abends nicht geschlossen: Automatik Runter der Wohnung ist aus'], 'Automatik Runter der Wohnung aus: Morgens fährt, Abends nicht');
[$m, $k, $a] = aufbau($nacht + ['Werte' => $tag]);
Sym::lauf(zeit('2026-11-10 07:00:00'));
wohnung($k, 'AutomatikRauf', false);
Sym::lauf(zeit('2026-11-10 08:30:00'));
wohnung($k, 'AutomatikRauf', true);
Sym::lauf(zeit('2026-11-10 12:00:00'));
gleich(fahrten($k), [], 'Automatik Rauf der Wohnung nach dem Zeitpunkt wieder an: nichts nachgeholt');
[$m, $k, $a] = aufbau(['Zeit' => '2026-11-10 12:00:00', 'Start' => 0, 'Wohnung' => true, 'Eigenschaften' => ['Verschattung' => true] + $automatik, 'Werte' => $tag + ['AutomatikRauf' => false, 'AutomatikRunter' => false], 'Sonne' => [180.0, 30.0]]);
gleich(fahrten($k, 0, ''), [' Position 80'], 'Verschattung ist unabhängig von Automatik Rauf und Automatik Runter der Wohnung');

titel('Über Mitternacht und mehrere Tage');
[$m, $k, $a] = aufbau(['Zeit' => '2026-11-10 12:00:00', 'Start' => 0, 'Wohnung' => true, 'Eigenschaften' => $automatik, 'Werte' => ['RunterHeute' => $um('00:50:00', '2026-11-11')]]);
Sym::lauf(zeit('2026-11-11 03:00:00'));
gleich(fahrten($k), ['Wed 00:50:00 ab'], 'Runter heute liegt nach Mitternacht: das Rollo fährt um 00:50');

[$m, $k, $a] = aufbau(['Zeit' => '2027-03-20 23:00:00', 'Start' => 100, 'Wohnung' => true, 'Eigenschaften' => $automatik]);
for ($i = 1; $i <= 30; $i++) {
    $datum = date('Y-m-d', (int) zeit('2027-03-20 12:00:00') + $i * 86400);
    Sym::plane(zeit($datum . ' 00:05:00'), function () use ($k, $um, $datum): void {
        wohnung($k, 'RaufHeute', $um('07:30:00', $datum));
        wohnung($k, 'RunterHeute', $um('19:15:00', $datum));
    });
}
Sym::lauf(zeit('2027-04-20 12:00:00'));
$jeTag = array_count_values(fahrten($k, 0, 'Y-m-d'));
$uhrzeiten = array_count_values(fahrten($k, 0, 'H:i:s'));
gleich([count($jeTag), max($jeTag), $uhrzeiten], [60, 1, ['07:30:00 auf' => 30, '19:15:00 ab' => 30]], '30 Tage mit Skript, über die Zeitumstellung: jeden Tag einmal auf um 07:30 und einmal ab um 19:15');

// ====================================================================== Verschattung

titel('Verschattung');
$schatten = ['Verschattung' => true, 'Himmelsrichtung' => 180.0];
[$m, $k, $a] = aufbau(['Zeit' => '2026-06-15 09:00:00', 'Start' => 0, 'Wohnung' => true, 'Eigenschaften' => $schatten, 'Sonne' => [90.0, 30.0]]);
gleich([$m->status, $m->GetValue('Verschattung')], [IS_ACTIVE, true], 'aktiv, Schalter Verschattung des Rollos ist da und an');
gleich(fahrten($k), [], 'Sonne im Osten, 90 Grad neben dem Südfenster: keine Verschattung');
sonne($k, 100.0, 35.0);
Sym::weiter(1);
gleich([fahrten($k, 0, 'H:i'), $m->GetValue('Grund')], [['09:00 Position 80'], 'Verschattung: Sonne auf dem Fenster'], 'Sonne kommt aufs Fenster (80 Grad daneben): Verschattungsposition');
Sym::weiter(60);
gleich($m->GetValue('Position'), 80, 'steht in der Verschattungsposition');
sonne($k, 180.0, 60.0);
sonne($k, 255.0, 30.0);
Sym::weiter(1);
gleich(count(fahrten($k)), 1, 'solange die Sonne auf dem Fenster bleibt, fährt nichts');
sonne($k, 261.0, 28.0);
Sym::weiter(1);
gleich([array_slice(fahrten($k, 0, 'H:i'), 1), $m->GetValue('Grund')], [['09:01 auf'], 'Auf: Sonne nicht mehr auf dem Fenster'], 'Sonne geht vom Fenster: wieder auf');

[$m, $k, $a] = aufbau(['Zeit' => '2026-06-15 09:00:00', 'Start' => 100, 'Wohnung' => true, 'Eigenschaften' => $schatten, 'Sonne' => [90.0, 30.0]]);
sonne($k, 150.0, 40.0);
Sym::weiter(1);
gleich(fahrten($k), [], 'Rollo steht tiefer als die Verschattungsposition: es bleibt');
[$m, $k, $a] = aufbau(['Zeit' => '2026-06-15 09:00:00', 'Start' => 79, 'Wohnung' => true, 'Eigenschaften' => $schatten, 'Sonne' => [90.0, 30.0]]);
sonne($k, 150.0, 40.0);
Sym::weiter(1);
gleich(fahrten($k), [], 'Rollo steht 1 Punkt neben der Verschattungsposition: es bleibt');
[$m, $k, $a] = aufbau(['Zeit' => '2026-06-15 09:00:00', 'Start' => 0, 'Wohnung' => true, 'Eigenschaften' => $schatten, 'Sonne' => [150.0, 5.0]]);
sonne($k, 150.0, 10.0);
Sym::weiter(1);
gleich(fahrten($k), [], 'Sonne genau auf der Mindest-Sonnenhöhe: noch keine Verschattung');
sonne($k, 150.0, 10.5);
Sym::weiter(1);
gleich(count(fahrten($k)), 1, 'Sonne über der Mindest-Sonnenhöhe: Verschattung');

[$m, $k, $a] = aufbau(['Zeit' => '2026-06-15 09:00:00', 'Start' => 0, 'Wohnung' => true, 'Eigenschaften' => $schatten, 'Sonne' => [150.0, 40.0]]);
gleich(fahrten($k, 0, ''), [' Position 80'], 'Sonne scheint beim Übernehmen schon aufs Fenster: sofort verschatten');
Sym::weiter(60);
$m->RequestAction('Position', 50);
Sym::weiter(60);
sonne($k, 270.0, 30.0);
Sym::weiter(1);
gleich(count(fahrten($k)), 2, 'von Hand verstellt: wenn die Sonne geht, bleibt das Rollo stehen');

[$m, $k, $a] = aufbau(['Zeit' => '2026-06-15 09:00:00', 'Start' => 0, 'Wohnung' => true, 'Eigenschaften' => $schatten, 'Sonne' => [150.0, 40.0]]);
Sym::weiter(60);
$m->RequestAction('Verschattung', false);
Sym::weiter(1);
sonne($k, 270.0, 30.0);
Sym::weiter(1);
gleich([count(fahrten($k)), $m->GetValue('Position')], [1, 80], 'Schalter Verschattung des Rollos aus: das verschattete Rollo bleibt stehen, auch wenn die Sonne geht');
sonne($k, 150.0, 40.0);
$m->RequestAction('Verschattung', true);
Sym::weiter(1);
gleich(count(fahrten($k)), 1, 'Schalter wieder an, Rollo steht schon in der Verschattungsposition: nichts zu tun');

[$m, $k, $a] = aufbau(['Zeit' => '2026-06-15 09:00:00', 'Start' => 0, 'Wohnung' => true, 'Eigenschaften' => $schatten, 'Sonne' => [150.0, 40.0]]);
Sym::weiter(60);
wohnung($k, 'AutomatikVerschattung', false);
Sym::weiter(1);
gleich([array_slice(fahrten($k, 0, ''), 1), $m->GetValue('Grund')], [[' auf'], 'Auf: Automatik Verschattung aus'], 'Automatik Verschattung der Wohnung aus: das verschattete Rollo fährt auf');
Sym::weiter(60);
wohnung($k, 'AutomatikVerschattung', true);
Sym::weiter(1);
gleich(array_slice(fahrten($k, 0, ''), 2), [' Position 80'], 'Automatik Verschattung wieder an, die Sonne scheint noch: verschatten');
Sym::weiter(60);
wohnung($k, 'Oeffnungswinkel', 20);
Sym::weiter(1);
gleich(array_slice(fahrten($k, 0, ''), 3), [' auf'], 'Öffnungswinkel auf 20 Grad verkleinert (Sonne 30 Grad daneben): wieder auf');

$abend = ['Zeit' => '2026-06-15 19:00:00', 'Start' => 0, 'Wohnung' => true, 'Eigenschaften' => $schatten + ['AbendsSchliessen' => true], 'Sonne' => [250.0, 15.0]];
$spaeter = ['RunterHeute' => (int) zeit('2026-06-15 21:30:00')];
[$m, $k, $a] = aufbau($abend + ['Werte' => $spaeter]);
Sym::weiter(60);
sonne($k, 252.0, 9.0);
Sym::weiter(1);
gleich(count(fahrten($k)), 1, 'Sonne sinkt unter die Mindest-Sonnenhöhe und das Rollo schließt heute noch automatisch: nicht mehr öffnen');
[$m, $k, $a] = aufbau($abend);
Sym::weiter(60);
sonne($k, 252.0, 9.0);
Sym::weiter(1);
gleich(count(fahrten($k)), 2, 'dasselbe ohne Runter heute: wieder auf');
[$m, $k, $a] = aufbau($abend + ['Werte' => $spaeter]);
Sym::weiter(60);
$m->RequestAction('Automatik', false);
sonne($k, 252.0, 9.0);
Sym::weiter(1);
gleich(count(fahrten($k)), 2, 'dasselbe mit Schalter Automatik des Rollos aus: wieder auf');

[$m, $k, $a] = aufbau(['Zeit' => '2026-06-15 09:00:00', 'Start' => 0, 'Wohnung' => true, 'Eigenschaften' => ['Verschattung' => true, 'Himmelsrichtung' => 10.0], 'Sonne' => [350.0, 40.0]]);
gleich(count(fahrten($k)), 1, 'Himmelsrichtung 10, Sonne bei 350 Grad: 20 Grad daneben, über Nord hinweg gerechnet');
[$m, $k, $a] = aufbau(['Zeit' => '2026-06-15 09:00:00', 'Start' => 0, 'Sperre' => true, 'Wohnung' => true, 'Eigenschaften' => $schatten, 'Sonne' => [150.0, 40.0]]);
gleich(fahrten($k), [], 'gesperrt: keine Verschattung');

[$m, $k, $a] = aufbau(['Zeit' => '2026-06-15 03:00:00', 'Start' => 100, 'Wohnung' => true, 'Eigenschaften' => $schatten + ['MorgensOeffnen' => true], 'Sonne' => [60.0, -10.0], 'Werte' => ['RaufHeute' => (int) zeit('2026-06-15 08:00:00')]]);
Sym::lauf(zeit('2026-06-15 07:00:00'));
sonne($k, 120.0, 30.0);
Sym::lauf(zeit('2026-06-15 12:00:00'));
gleich([fahrten($k, 0, 'H:i'), $m->GetValue('Grund')], [['08:00 Position 80'], 'Verschattung: Morgens, Sonne auf dem Fenster'], 'Morgens öffnen bei Sonne auf dem Fenster: direkt in die Verschattungsposition');
[$m, $k, $a] = aufbau(['Zeit' => '2026-06-15 03:00:00', 'Start' => 100, 'Wohnung' => true, 'Eigenschaften' => $schatten + ['MorgensOeffnen' => true], 'Sonne' => [60.0, -10.0], 'Werte' => ['RaufHeute' => (int) zeit('2026-06-15 08:00:00')]]);
Sym::lauf(zeit('2026-06-15 07:00:00'));
sonne($k, 120.0, 30.0);
$m->RequestAction('Verschattung', false);
Sym::lauf(zeit('2026-06-15 12:00:00'));
gleich(fahrten($k, 0, 'H:i'), ['08:00 auf'], 'mit Schalter Verschattung aus fährt Morgens öffnen ganz auf');

titel('Tasten für Automatik und Verschattung');
/**
 * Zweites Rollo in derselben Anlage, mit eigenen KNX-Instanzen.
 */
function zweites(array $eigenschaften): Rollocontroller
{
    $raum = Sym::kategorie('Raum 2');
    $id = Sym::objekt('Instanz', $raum, 'Rollo 2');
    $modul = new Rollocontroller($id);
    Sym::$module[$id] = $modul;
    $modul->Create();
    $eigenschaften += [
        'FahrenID' => Sym::knx('Fahren 2', $raum, 1, false), 'StoppID' => Sym::knx('Stopp 2', $raum, 1),
        'PositionID' => Sym::knx('Position 2', $raum, 5), 'StatusID' => Sym::wert(Sym::knx('Status 2', $raum, 5, 0)),
        'SperrenID' => Sym::knx('Sperren 2', $raum, 1, false)
    ];
    foreach ($eigenschaften as $name => $wert) {
        $modul->eigenschaften[$name] = $wert;
    }
    $modul->ApplyChanges();
    Sym::verarbeite();
    return $modul;
}
$tasten = function (array $e, array $k): array {
    $raum = IPS_GetParent($k['Fahren']);
    foreach (['T11', 'T12'] as $name) {
        $GLOBALS['t'][$name] = Sym::knx($name, $raum, 1);
        $GLOBALS['t'][$name . 'Led'] = Sym::knx($name . ' LED', $raum, 5);
    }
    $t = $GLOBALS['t'];
    return [
        'Verschattung' => true, 'EinstellungenID' => $k['Ordner'],
        'TastenAutomatik' => json_encode([['Name' => 'Taste 11', 'VariableID' => Sym::wert($t['T11']), 'InstanceID' => $t['T11Led']]]),
        'TastenVerschattung' => json_encode([['Name' => '', 'VariableID' => Sym::wert($t['T12']), 'InstanceID' => $t['T12Led']]])
    ] + $e;
};
$leds = fn (int $ab): array => Sym::gesendet($ab, [$GLOBALS['t']['T11Led'], $GLOBALS['t']['T12Led']]);

$t = [];
[$m, $k, $a] = aufbau(['Anpassen' => $tasten]);
$m->EinstellungenAnlegen();
$ab = count(Sym::$telegramme);
$m->ApplyChanges();
Sym::verarbeite();
gleich([$m->status, $m->GetValue('Automatik'), $m->GetValue('Verschattung')], [IS_ACTIVE, true, true], 'aktiv, beide Schalter da und an');
gleich($leds($ab), ['T11 LED=3', 'T12 LED=3'], 'nach dem Übernehmen zeigen beide LEDs Grün');
$ab = count(Sym::$telegramme);
druecke($t['T11'], true);
gleich([$m->GetValue('Automatik'), $m->GetValue('Grund'), $leds($ab)], [false, 'Automatik aus: Taste 11', ['T11 LED=2']], 'Taste 11: Automatik aus, LED Rot, Grund nennt die Taste');
gleich(fahrten($k), [], 'die Taste fährt das Rollo nicht');
$ab = count(Sym::$telegramme);
druecke($t['T11'], true);
gleich([$m->GetValue('Automatik'), $leds($ab)], [true, ['T11 LED=3']], 'derselbe Wert nochmal: jedes Telegramm zählt, Automatik an, LED Grün');
$ab = count(Sym::$telegramme);
druecke($t['T12'], false);
gleich([$m->GetValue('Verschattung'), $m->GetValue('Automatik'), $leds($ab)], [false, true, ['T12 LED=2']], 'Taste 12: Verschattung aus, Automatik bleibt, LED Rot');
pruefe(str_starts_with($m->GetValue('Grund'), 'Verschattung aus: Taste '), 'ohne Namen steht die Variable der Taste im Grund');
$ab = count(Sym::$telegramme);
$m->RequestAction('Automatik', false);
Sym::verarbeite();
gleich([$m->GetValue('Automatik'), $m->GetValue('Grund'), $leds($ab)], [false, 'Automatik aus: App', ['T11 LED=2']], 'aus der App: LED zieht nach');
$ab = count(Sym::$telegramme);
$m->MessageSink(0, 0, IPS_KERNELMESSAGE, [KR_READY]);
Sym::weiter(60);
gleich($leds($ab), ['T11 LED=2', 'T12 LED=2'], 'nach dem Start zeigen die LEDs den Stand der Schalter');

titel('Eine Taste für zwei Rollos: angleichen');
$t = [];
[$m, $k, $a] = aufbau(['Anpassen' => $tasten]);
$m->EinstellungenAnlegen();
$m->ApplyChanges();
// das zweite Rollo hat keine Verschattung und dieselbe Taste 11
$m2 = zweites(['TastenAutomatik' => $m->eigenschaften['TastenAutomatik'], 'TastenVerschattung' => $m->eigenschaften['TastenVerschattung']]);
gleich([$m2->status, Sym::kind($m2->InstanceID, 'Verschattung')], [IS_ACTIVE, 0], 'zweites Rollo aktiv, ohne Schalter Verschattung');
$beide = fn (): array => [$m->GetValue('Automatik'), $m2->GetValue('Automatik')];
druecke($t['T11'], true);
gleich($beide(), [false, false], 'beide an, Taste: beide aus');
gleich(end(Sym::$telegramme)['Wert'], 2, 'LED Rot');
gleich($m2->GetValue('Grund'), 'Automatik aus: Taste 11', 'auch das zweite Rollo nennt die Taste im Grund');
druecke($t['T11'], true);
gleich($beide(), [true, true], 'beide aus, Taste: beide an');
gleich(end(Sym::$telegramme)['Wert'], 3, 'LED Grün');
$m2->RequestAction('Automatik', false);
Sym::verarbeite();
gleich([$beide(), end(Sym::$telegramme)['Wert']], [[true, false], 2], 'eines in der App aus: LED Rot, weil nicht alle an sind');
druecke($t['T11'], true);
gleich([$beide(), end(Sym::$telegramme)['Wert']], [[false, false], 2], 'verschieden, Taste: einer war an, also beide aus');
$m->RequestAction('Automatik', true);
Sym::verarbeite();
gleich(end(Sym::$telegramme)['Wert'], 2, 'nur eines in der App an: LED bleibt Rot');
$m2->RequestAction('Automatik', true);
Sym::verarbeite();
gleich(end(Sym::$telegramme)['Wert'], 3, 'beide an: LED Grün');
druecke($t['T12'], true);
gleich([$m->GetValue('Verschattung'), $beide()], [false, [true, true]], 'Taste 12 schaltet nur das Rollo mit Verschattung, das andere hat den Schalter nicht');
gleich(end(Sym::$telegramme)['Wert'], 2, 'LED Taste 12 Rot');
// das Rollo mit der kleineren ID ist gestört: das andere übernimmt die Taste
$m->eigenschaften['StatusID'] = 0;
$m->ApplyChanges();
druecke($t['T11'], true);
gleich($beide(), [true, false], 'ein inaktives Rollo zählt nicht mit: nur das andere schaltet');

[$m, $k, $a] = aufbau(['Anpassen' => function (array $e, array $k): array {
    return ['TastenAutomatik' => json_encode([['Name' => 'x', 'VariableID' => 99999, 'InstanceID' => 0]])] + $e;
}]);
gleich($m->status, 201, 'eine Taste, die es nicht gibt: Fehler 201');
[$m, $k, $a] = aufbau(['Anpassen' => function (array $e, array $k): array {
    return ['TastenAutomatik' => json_encode([['Name' => 'x', 'VariableID' => Sym::wert($k['TasteAuf']), 'InstanceID' => 0]]), 'TastenAufKurz' => '[]'] + $e;
}]);
druecke($k['TasteAuf'], true);
gleich([$m->status, $m->GetValue('Automatik')], [IS_ACTIVE, false], 'Taste ohne LED: schaltet um');

echo "\n" . $anzahl . ' Prüfungen, ' . $fehler . " Fehler\n";
exit($fehler > 0 ? 1 : 0);
