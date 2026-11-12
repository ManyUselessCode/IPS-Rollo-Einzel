# IPS-Rollo-Einzel

Symcon-Modul „Rollocontroller“ für Rollos mit KNX. Vorbild ist der Home-Assistant-Blueprint „Rollo | Controller | Einzel“. Eine Instanz steuert ein Rollo. Hier stehen alle Daten des Rollos: Sperre, Lüftungsposition, Verschattungsposition, Himmelsrichtung und die Automatik (Morgens öffnen und Abends schließen zu Zeitpunkten, die die Wohnung vorgibt, Verschattung nach Sonnenstand). Dazu optional eigene Taster und LEDs, die nur dieses Rollo bedienen.

Positionen sind überall Prozent geschlossen wie am Aktor und in Symcon: 0 = ganz offen, 100 = zu. Der Blueprint rechnet umgekehrt (Prozent offen), aus 20 dort wird hier 80.

## Variablen der Wohnung

Wann die Rollos morgens und abends fahren, rechnet nicht das Modul. Ein Skript schreibt die beiden Zeitpunkte des Tages in zwei Variablen, und alle Rollos halten sich daran. Die Variablen liegen mit den Werten der Verschattung in einem Ordner, den jede Instanz als „Ordner mit den Variablen der Wohnung“ bekommt. Das Modul findet sie über ihren Ident, die Namen sind frei. Nötig ist der Ordner nur für Morgens öffnen, Abends schließen und Verschattung.

| Ident | Name | Bedeutung | Vorgabe |
|---|---|---|---|
| `RaufHeute` | Rauf heute | Zeitpunkt, zu dem die Rollos heute auffahren | 0 |
| `RunterHeute` | Runter heute | Zeitpunkt, zu dem die Rollos heute zufahren | 0 |
| `AutomatikRauf` | Automatik Rauf | gibt Morgens öffnen für alle Rollos frei | an |
| `AutomatikRunter` | Automatik Runter | gibt Abends schließen für alle Rollos frei | an |
| `AutomatikVerschattung` | Automatik Verschattung | gibt die Verschattung für alle Rollos frei | an |
| `Oeffnungswinkel` | Öffnungswinkel | Grad links und rechts der Himmelsrichtung, in denen die Sonne aufs Fenster scheint | 80 |
| `Mindesthoehe` | Mindest-Sonnenhöhe | Grad über dem Horizont, ab denen verschattet wird | 10 |

Die beiden Zeitpunkte sind Zeitstempel des Tages, angezeigt wird nur die Uhrzeit. Ein Wert unter 86400 gilt als Sekunden seit Mitternacht des laufenden Tages. 0 heißt: heute nicht.

Die Schaltfläche „Fehlende Variablen der Wohnung anlegen“ im Formular oder `ROLLOCONTROLLER_EinstellungenAnlegen(ID)` legt im Ordner an, was fehlt: die Variablen mit Vorgabe und Darstellung und ein verstecktes Aktionsskript „Wert übernehmen“, damit sie sich auch in App und WebFront ändern lassen. Vorhandene Variablen und ihre Werte bleiben.

## Einstellungen der Instanz

- **Ordner mit den Variablen der Wohnung.**
- **Rollo:** Auf/Ab fahren (KNX DPT 1.008), Stopp (DPT 1), Position Höhe anfahren (DPT 5.001), Status Höhe (Variable, DPT 5.001), Sperren (DPT 1), Fahrzeit (Vorgabe 30 Sekunden), Lüftungsposition (Vorgabe 80), Himmelsrichtung (0 = Nord, 90 = Ost, 180 = Süd, 270 = West).
- **Automatik:** Morgens öffnen, Abends schließen, Verschattung, Verschattungsposition (Vorgabe 80), dazu „Tasten Automatik“ und „Tasten Verschattung“: je Zeile eine Taste (Variable „Kurz“) und optional ihre LED: bei MDT die „LED Farbe“, bei Jung „LED Grün“ und „LED Rot“.
- **MDT-Taster:** Wippen kurz und lang, LEDs der Auf-Tasten und der Ab-Tasten.
- **Jung-Taster:** Wippen kurz und lang (je mit „Invertiert“), LEDs Blau, Grün auf der Auf-Seite, Grün auf der Ab-Seite.
- **Einzeltasten:** Auf kurz, Ab kurz, Ab lang.

Jede Adresse des Rollos braucht ihre eigene Instanz. Dieselbe Instanz für zwei Adressen lehnt das Modul ab, ebenso eine Adresse des Rollos als Taster.

## Variablen der Instanz

- **Position:** Position des Rollos als Rollladen. Der Schieber fährt die Position an, die Enden fahren ganz auf und ganz zu.
- **Fahrt:** Auf, Stopp, Ab. Zeigt, ob und wohin das Rollo fährt, und fährt es aus der App.
- **Sperre:** an = gesperrt. Setzt „Sperren“ am Aktor.
- **Automatik:** legt für dieses Rollo fest, ob es automatisch fährt. Aus = es folgt „Rauf heute“ und „Runter heute“ nicht. Den Schalter hat jedes Rollo, Vorgabe an.
- **Verschattung:** aus = keine Verschattung für dieses Rollo. Gibt es nur, wenn die Verschattung eingestellt ist, Vorgabe an.
- **Grund:** Text zur letzten Entscheidung, zum Beispiel „Ab: Tür Wippe 2“, „Lüften: Tür Wippe 2“, „Auf: Morgens“, „Verschattung: Sonne auf dem Fenster“, „Ab: von Extern“ oder „Gesperrt: Ab von Tür Wippe 2 nicht ausgeführt“. Versteckt, zum Auswerten im Archiv aufzeichnen.

Beide Schalter lassen sich in App und WebFront schalten und zusätzlich mit einer Taste: Jedes Telegramm einer Taste aus „Tasten Automatik“ oder „Tasten Verschattung“ schaltet den Schalter um, im Grund steht dann zum Beispiel „Automatik aus: Taste 11“. Die LED der Taste zeigt immer den Schalter, Grün = an, Rot = aus, auch nach einer Änderung in der App und nach dem Start. Bei Jung-Tastern schaltet das Modul dafür die grüne und die rote LED, Blau fasst es nicht an.

Für eine Taste je Raum trägt man dieselbe Taste und LED bei jedem Rollo des Raums ein. Die Taste gleicht dann an: Ist einer der Schalter an, gehen alle aus, sonst alle an. Die LED ist nur Grün, wenn alle an sind. Ein Rollo ohne Verschattung hat keinen Schalter Verschattung und zählt dort nicht mit.

## Taster

- **Kurz:** fahren. In die laufende Richtung nochmal: Stopp.
- **Lang unten:** Lüftungsposition. **Lang oben:** ohne Funktion.
- MDT-Wippen senden oben = aus = auf und unten = an = ab. Jung-Wippen senden an = auf und aus = ab, lang aus = Lüftungsposition. Sendet eine Jung-Wippe umgekehrt, „Invertiert“ setzen.
- Bei Einzeltasten zählt jedes Telegramm als Druck.
- Steht das Rollo laut Aktor schon ganz offen oder ganz zu, sendet das Modul den Fahrbefehl dorthin nicht.

## LEDs

Die LEDs zeigen die Fahrt: Grün in der Richtung, in die das Rollo fährt, Blau wenn es steht. Gesendet wird nur, was sich ändert.

- **MDT** (DPT 5, eine LED je Taste): Grün 3, Blau 4.
- **Jung** (je eine DPT-1-Instanz): Blau an, wenn das Rollo steht. Grün auf der Auf-Seite an, solange es auffährt, Grün auf der Ab-Seite, solange es abfährt.

## Sperre

Gesperrt wird ein Rollo, indem „Sperren“ gesetzt wird, aus der App über die Variable „Sperre“ oder von einem anderen Gerät am Bus. Der Aktor verhindert dann selbst jede Fahrt. Das Modul liest das letzte Telegramm auf der Sperr-Adresse und schickt einem gesperrten Rollo keine Fahrbefehle, weder vom Taster noch aus der App oder von der Automatik. Der Grund sagt dann, was nicht ausgeführt wurde. Wurde die Sperre noch nie gesetzt, ist das Rollo nicht gesperrt. Wird sie eingeschaltet, während das Rollo fährt, schickt das Modul zusätzlich Stopp.

## Morgens öffnen und Abends schließen

- **Morgens öffnen:** Das Rollo fährt zum Zeitpunkt in „Rauf heute“ ganz auf. Scheint die Sonne dann schon aufs Fenster, fährt es direkt in die Verschattungsposition.
- **Abends schließen:** Das Rollo fährt zum Zeitpunkt in „Runter heute“ zu.
- Ändert sich ein Zeitpunkt, stellen sich alle Rollos sofort um.
- **Es wird nie nachgeholt.** Gefahren wird nur in dem Moment, in dem der Zeitpunkt erreicht ist. Ein Zeitpunkt, der beim Schreiben oder nach einem Neustart schon vorbei ist, bleibt ohne Wirkung, ebenso einer von gestern.
- Jeder Zeitpunkt gilt einmal.
- Mit dem Schalter „Automatik“ legt man für jedes Rollo fest, ob es automatisch fährt. Aus heißt, das Rollo folgt den Zeitpunkten nicht. Wird er nach dem Zeitpunkt wieder eingeschaltet, fährt das Rollo erst beim nächsten.
- „Automatik Rauf“ und „Automatik Runter“ der Wohnung gelten für alle Rollos: Morgens fährt ein Rollo nur, wenn „Automatik Rauf“ der Wohnung und „Automatik“ des Rollos an sind, abends nur mit „Automatik Runter“ und „Automatik“. Die Verschattung bleibt davon unberührt.
- Ein gesperrtes Rollo fährt nicht. Der Grund sagt, warum ein Rollo zum Zeitpunkt nicht gefahren ist.

## Verschattung

Die Sonne scheint aufs Fenster, wenn sie höchstens um den Öffnungswinkel links oder rechts von der Himmelsrichtung steht und höher als die Mindest-Sonnenhöhe. Azimut und Höhe kommen aus Location Control.

- **Sonne kommt aufs Fenster:** Das Rollo fährt in die Verschattungsposition, wenn es weiter offen steht. Ein Rollo, das schon tiefer steht, bleibt.
- **Sonne geht oder Automatik Verschattung der Wohnung aus:** Das Rollo fährt ganz auf, wenn es noch in der Verschattungsposition steht. Nicht, wenn die Sonne unter die Mindest-Sonnenhöhe gesunken ist und das Rollo heute noch automatisch schließt.
- **Schalter Verschattung des Rollos aus:** keine Verschattung, ein gerade verschattetes Rollo bleibt stehen.
- Gefahren wird nur beim Wechsel. Wer ein verschattetes Rollo von Hand verstellt, behält seine Position.

## Fahrt und Position

Die Position kommt aus der Status-Variable. Ein eigenes Telegramm liefert Symcon zweimal an die Instanz, beim Schreiben und vom Bus zurück; das Modul zählt beide als eigene, solange der Wert stimmt. Der Aktor meldet nicht, ob das Rollo fährt. Das Modul leitet es aus den Telegrammen auf den Adressen des Rollos ab, auch aus denen anderer Geräte: Ein Fahrbefehl beginnt die Fahrt, ein Stopp oder die Meldung des Ziels vom Aktor beendet sie. Meldet der Aktor auch unterwegs (etwa MDT Jalousieaktor), rechnet das Modul von jeder Meldung aus weiter; kommt 3 s lang keine weitere, endet die Fahrt bei der letzten Meldung. Bleibt die Meldung aus, endet die Fahrt nach der doppelten Fahrzeit. Unterwegs zeigt „Position“ das Ziel, nach einem Stopp die geschätzte Position, bis der Aktor die echte meldet. Solange der Aktor nichts gemeldet hat, spart das Modul keine Fahrt ein und gibt jeden Stopp weiter. Fährt jemand das Rollo von außen, steht „von Extern“ im Grund.

## Start

Nach dem Start gelten die beiden Zeitpunkte sofort. Nach 30 Sekunden liest das Modul die Position vom Bus, setzt die LEDs und prüft die Verschattung. Dasselbe passiert nach dem Übernehmen der Einstellungen, bei Sonne auf dem Fenster kann das Rollo dabei sofort in die Verschattungsposition fahren.

## Skripte und Gruppen

`ROLLOCONTROLLER_Befehl(ID, Befehl, Quelle)` mit den Befehlen `auf`, `ab`, `stopp` und `lueften`. Das Rollo führt den Befehl mit seiner Sperre und seiner Lüftungsposition aus, die Quelle steht im Grund. Das entspricht dem Ereignis `rollo_befehl` des Blueprints, über das Rollo-Gruppen ihre Rollos steuern.

## Voraussetzung

- KNX-DPT-Instanzen mit „Status emulieren“: DPT 1 für Auf/Ab fahren, Stopp, Sperren, Taster und Jung-LEDs, DPT 5 für Position, Status und MDT-LEDs.
- Der Aktor meldet die Position am Ende der Fahrt und nach einem Stopp, manche Aktoren auch laufend unterwegs (beides geht).
- Location Control für den Sonnenstand der Verschattung. Symcon ab 8.0.

## Tests

Die Tests lassen das Modul gegen einen Nachbau von Symcon mit einem simulierten Rolloaktor laufen. Die Uhr wird dafür ersetzt:

```bash
php -d disable_functions=time,microtime tests/test.php
```
