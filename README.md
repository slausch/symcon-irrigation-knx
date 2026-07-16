# Wangari Irrigation

Eigenständiges IP-Symcon-9-Modul für eine sichere Bewässerungssteuerung. Es steuert optional eine Beregnungspumpe und ein Hauptventil sowie zehn sequenziell laufende Zonen mit jeweils bis zu zwei Ventilen.

Das Modul wurde neu strukturiert. Die öffentliche Referenz [`elueckel/irrigation-control`](https://github.com/elueckel/irrigation-control) diente ausschließlich zur Ermittlung des Funktionsumfangs und für eine Fehleranalyse. Es wurde kein Quellcode übernommen.

## Funktionsumfang

- optionale Beregnungspumpe mit eigener Druckaufbauzeit (Standard: 5 Sekunden)
- optionales Hauptventil; die Steuerung funktioniert auch ohne Pumpe und Hauptventil
- gemeinsame Wartezeit für Hauptventil und Zonenwechsel (Standard: 2 Sekunden)
- bis zu zehn Zonen mit jeweils einem oder zwei Boolean-Ventilen
- sequenzieller Programmlauf; deaktivierte Zonen werden sicher übersprungen
- Zeitplan nach Uhrzeit, Wochentagen und Tagesintervall mit festem Ankerdatum
- manuelles Gesamtprogramm oder einzelne manuelle Zone
- Pause mit geschlossenem Zonen- und Hauptventil sowie Fortsetzung der gespeicherten Restlaufzeit
- Überspringen der laufenden oder nächsten wartenden Zone; nach der letzten Zone endet das Programm
- Laufzeit und Teilnahme am Automatikprogramm für jede Zone als bedienbare Variable
- eigener Fortschritts-String je Zone, zum Beispiel `seit 7 Min, noch 53 Min`
- Boolean-Regensensor und numerischer Bodenfeuchtesensor
- optionale Boolean-Rückmeldung für jedes Haupt- und Zonenventil
- Rückmelde-Timeout, maximale Gesamtlaufzeit und sofortiger Sensor-Stopp
- sicherer Stopp bei Deaktivierung, Konfigurationsänderung und Neustart
- Simulationsmodus ohne Hardware-Schaltbefehle
- separate Testlaufzeit für die Simulation (Standard: 1 Minute je Zone)
- Status-, Restlaufzeit-, Sperr- und Fehlervariablen für die Visualisierung

## Zentrales Sicherheitsprinzip

Ausgangsvariablen müssen vom Typ Boolean sein und eine Standardaktion besitzen. Dadurch ist das Modul nicht auf KNX beschränkt: Auch HomeMatic-, Shelly- oder andere Boolean-Variablen können verwendet werden, sofern ihre Aktion `true/false` akzeptiert. Jeder Hardware-Schaltbefehl läuft ausschließlich über:

```php
RequestAction($VariableID, true);  // öffnen
RequestAction($VariableID, false); // schließen
```

Der Aufruf ist in `switchOutput()` zentralisiert. Direkte KNX-, Homematic- oder `SetValue()`-Schaltzugriffe auf externe Ventile werden nicht verwendet. `SetValue()` wird nur für die vom Modul selbst angelegten Statusvariablen eingesetzt.

## Installation und privater Test

Das Repository bleibt für den Testbetrieb privat.
Nach dem Laden erscheint das Gerät unter dem Namen **Wangari Irrigation**. Die frühere Bezeichnung **Irrigation KNX** bleibt als Alias erhalten.

Empfohlene Erstinbetriebnahme:

1. Instanz anlegen und `Simulation` aktiviert lassen. Die `Testlaufzeit (nur Simulation)` steht standardmäßig auf 1 Minute je Zone.
2. Mindestens eine Zone benennen, für das Automatikprogramm aktivieren und mindestens eine schaltbare Boolean-Ventilvariable auswählen. Pumpe und Hauptventil sind optional.
3. Laufzeiten, Sensoren, Rückmeldungen und Sicherheitszeiten konfigurieren.
4. In der Simulation Gesamtprogramm, Einzelzonen, zonenweise Regenreaktion und Rückmeldefehler prüfen.
5. Erst danach Simulation deaktivieren und jede Zone unter Aufsicht kurz testen.
6. Automatik erst nach erfolgreichem Hardwaretest einschalten.

## Bedienung

Die Instanz legt folgende bedienbare Variablen an:

- `Automatic`: Zeitplan ein-/ausschalten
- `ProgramActive`: Gesamtprogramm starten oder stoppen
- `Pause`: laufende Zone, Pumpe und Hauptventil schließen; mit derselben Restlaufzeit fortsetzen
- `Skip`: aktuelle Zone schließen und nach der Wartezeit mit der nächsten Zone fortfahren
- `ManualZone`: einzelne Zone starten; `0` stoppt
- `ManualRuntime`: Laufzeit der manuellen Einzelzone
- `PumpLeadSeconds`: Druckaufbauzeit der Pumpe in Sekunden
- `InterZoneSeconds`: Wartezeit nach Hauptventilöffnung und zwischen zwei Zonen
- `Zone1Automatic` bis `Zone10Automatic`: Teilnahme der Zone am Gesamtprogramm
- `Zone1Runtime` bis `Zone10Runtime`: Laufzeit der Zone in Minuten
- `Zone1Progress` bis `Zone10Progress`: Fortschritt der aktiven Zone; der sichtbare Variablenname entspricht dem konfigurierten Zonennamen
- `EmergencyStop`: sichtbarer Sicherheitsstopp; schließt alle konfigurierten Ausgänge und setzt einen Fehlerstatus. Der interne Ident bleibt aus Kompatibilitätsgründen unverändert.

Öffentliche Modulfunktionen:

```php
IRRKNX_StartProgram($InstanceID, true);
IRRKNX_StartZone($InstanceID, 3, 15);
IRRKNX_Pause($InstanceID);
IRRKNX_TogglePause($InstanceID); // pausiert bzw. setzt einen pausierten Lauf fort
IRRKNX_Resume($InstanceID);
IRRKNX_SkipCurrentZone($InstanceID);
IRRKNX_SetZoneAutomatic($InstanceID, 3, true);
IRRKNX_SetZoneRuntime($InstanceID, 3, 25);
IRRKNX_Stop($InstanceID);
IRRKNX_EmergencyStop($InstanceID, 'Leckage erkannt');
```

Manuelle Starts beachten standardmäßig ebenfalls Regen und Bodenfeuchte. Es gibt bewusst keinen stillen Sensor-Override.

Wichtig zur Bedienlogik:

- `IRRKNX_StartProgram($InstanceID, true)` startet manuell. Der Wert `false` startet ebenfalls, kennzeichnet den Lauf aber als automatischen Zeitplanstart; er bedeutet nicht „Aus“.
- `IRRKNX_Stop($InstanceID)` beendet den Lauf regulär und endgültig. Ein neuer Start beginnt wieder bei der ersten teilnehmenden Zone.
- `IRRKNX_Pause($InstanceID)` pausiert immer, `IRRKNX_Resume($InstanceID)` setzt immer fort und `IRRKNX_TogglePause($InstanceID)` wählt abhängig vom aktuellen Zustand zwischen beiden Aktionen.
- Die interne Variable `Pause` kann mit `RequestAction($PauseVariableID, true)` pausiert und mit `RequestAction($PauseVariableID, false)` fortgesetzt werden. Die Modulfunktionen `Pause()`, `Resume()` und `TogglePause()` erhalten keinen Boolean-Parameter.
- `IRRKNX_EmergencyStop()` heißt aus Kompatibilitätsgründen weiterhin so, wird in der Oberfläche aber als Sicherheitsstopp bezeichnet. Er beendet endgültig, setzt den Status auf Fehler und schreibt den angegebenen Grund in `LastError`.
- Stop und Sicherheitsstopp sind absichtlich nicht fortsetzbar. Für eine später fortzusetzende Unterbrechung ist ausschließlich Pause vorgesehen.

Dieselben Werte lassen sich über die internen Variablen mit `RequestAction` setzen:

```php
$laufzeit = IPS_GetObjectIDByIdent('Zone3Runtime', $InstanceID);
RequestAction($laufzeit, 25);

$automatik = IPS_GetObjectIDByIdent('Zone3Automatic', $InstanceID);
RequestAction($automatik, true);

$zonenWartezeit = IPS_GetObjectIDByIdent('InterZoneSeconds', $InstanceID);
RequestAction($zonenWartezeit, 2);
```

Änderungen über Variablen gelten sofort und bleiben bis zur nächsten übernommenen Instanzkonfiguration bestehen. Beim Übernehmen der Konfiguration werden die dort eingetragenen Werte wieder als Vorgabe in die Variablen geladen. Jede übernommene Konfigurationsänderung stoppt einen gegebenenfalls laufenden Bewässerungslauf sicher.

## Mehrere Instanzen

Das Modul kann mehrfach instanziiert werden. Properties, Timer, Buffer, Bedienvariablen und Fortschrittsanzeigen sind je Instanz getrennt. Instanzen können im Objektbaum frei benannt werden, beispielsweise `Wangari Irrigation 1`, `Wangari Irrigation 2`, `Vorgarten` und `Hintergarten`.

Sensorvariablen dürfen von mehreren Instanzen gemeinsam gelesen werden. Dieselbe schaltbare Pumpe, dasselbe Hauptventil oder dasselbe Zonenventil sollte dagegen nicht mehreren Instanzen zugeordnet werden: Andernfalls könnten zwei unabhängig laufende Programme widersprüchliche `RequestAction`-Befehle senden.

Ein Modulupdate aktualisiert den gemeinsamen Programmcode. Alle vorhandenen Instanzen verwenden anschließend die neue Version; ihre Konfigurationen und Variablen bleiben anhand ihrer jeweiligen Instanz- und Objekt-IDs getrennt erhalten.

## Fortschrittsanzeige je Zone

Während eine Zone läuft, enthält nur deren Fortschrittsvariable einen Text. Zeiten unter einer Minute werden in Sekunden, längere Zeiten in vollen Minuten dargestellt:

- `seit 34 Sek, noch 59 Min`
- `seit 7 Min, noch 53 Min`
- `seit 59 Min, noch 13 Sek`

Während einer Pause bleibt der Text eingefroren. Bei Überspringen, Zonenwechsel, Stopp, Sicherheitsstopp oder Programmende wird die Anzeige der bisherigen Zone geleert.

## Zeitsteuerung

`StartTime` verwendet `HH:MM`. `IntervalDays` wird in Kalendertagen gegen `IntervalAnchor` (`YYYY-MM-DD`) berechnet; ein Ankerdatum wie `2026-01-01` ist zulässig. Zusätzlich muss der errechnete Tag als Wochentag aktiviert sein. Beide Bedingungen sind UND-verknüpft:

- Intervall `1` und Montag/Mittwoch/Freitag: Bewässerung nur an diesen drei Wochentagen.
- Intervall `3` und alle Wochentage: alle drei Kalendertage ab dem Ankerdatum.
- Trifft ein Intervalltag auf einen deaktivierten Wochentag, wird dieser Lauf am nächsten aktivierten Wochentag nachgeholt.
- Ein Nachhollauf verschiebt die folgenden regulären Intervalltage nicht. Treffen mehrere fällige Läufe auf denselben erlaubten Tag, startet an diesem Kalendertag höchstens ein Programm.

Damit wandert der Termin nicht bei jedem Timerlauf weiter. Pro Kalendertag wird höchstens ein automatischer Start ausgelöst.

## Regen- und Bodenfeuchtesensor

Der optionale Regensensor muss Boolean sein. Er ist genau dann aktiv, wenn sein Wert dem konfigurierten `RainActiveValue` entspricht. Bei einem Sensor, der Regen mit `true` meldet, wird der Aktivwert eingeschaltet. Bei einem invertierten Kontakt, der Regen mit `false` meldet, wird er ausgeschaltet.

In der Zonentabelle bestimmt `Reagiert auf Regen` die Wirkung je Zone. Bei aktivem Regen werden nur entsprechend markierte Zonen übersprungen. Nicht markierte Zonen können weiterhin bewässern. Setzt Regen während einer markierten laufenden Zone ein, wird ihr Ventil geschlossen; nach der eingestellten Wartezeit Zone läuft das Programm mit der nächsten nicht markierten Zone weiter. Gibt es keine solche Zone mehr, endet das Programm regulär. Bereits wegen Regen übersprungene Zonen werden in diesem Programmlauf nicht nachgeholt.

Die Statusvariable `Sensor aktiv / Sperre` zeigt weiterhin an, dass Regen oder die Bodenfeuchtegrenze aktiv ist. Bei Regen kann das Programm trotzdem mit nicht markierten Zonen weiterlaufen.

Die optionale Bodenfeuchte muss Integer oder Float sein. Ist `SoilBlocksAboveLimit` aktiv, sperrt ein Messwert größer oder gleich `SoilMoistureLimit`. Ist die Option aus, sperrt ein Wert kleiner oder gleich dem Grenzwert. So werden sowohl Sensoren unterstützt, bei denen hohe Werte „feucht“ bedeuten, als auch Sensoren mit umgekehrter Skala.

Beide Sensoren werden vor manuellen und automatischen Starts sowie während eines laufenden Programms geprüft. Bodenfeuchte bleibt eine zentrale Sperre und löst während eines Laufs einen Sicherheitsstopp des gesamten Programms aus. Regen wirkt dagegen ausschließlich auf die je Zone markierte Auswahl.

## Rückmeldungen

Rückmeldungen sind optional. Sobald eine Rückmeldevariable konfiguriert ist, muss sie innerhalb von `FeedbackTimeoutSeconds` den erwarteten Zustand melden. Andernfalls beendet das Modul den Lauf, fordert für alle Zonenventile `false` an und schließt danach die Hauptventile. Invertierte Kontakte können je Rückmeldung konfiguriert werden.

Eine Rückmeldung sollte den tatsächlichen Ventilzustand erfassen. Die reine Spiegelung derselben Aktorvariable erkennt weder ein klemmendes Ventil noch einen defekten Ausgang.

## Tests

Statische Vertragsprüfungen:

```bash
python3 -m unittest tests/test_contract.py
```

Laufzeittests mit IP-Symcon-Stubs (benötigt PHP 8):

```bash
php tests/run.php
```

Die Tests decken insbesondere die sequenzielle Ansteuerung von Pumpe und Hauptventil, den Betrieb ohne beide Versorgungs-Ausgänge, Überspringen, Pause/Fortsetzen, variable Zonenlaufzeiten und Automatikteilnahme, instanzgetrennte Fortschrittsanzeigen, die Migration von sechs auf zehn Zonen, Sensorsperren, Rückmeldefehler und das Schließen sämtlicher Ausgänge ab.

## Dokumentation

- [Sicherheits- und Betriebsbeschreibung](docs/safety.md)
- [Fehleranalyse der Referenz](docs/reference-audit.md)
- [Architektur und Zustandsmaschine](docs/architecture.md)

## Credit und Lizenz

Das Modul ist ein kleines Dankeschön von Sepp Lausch für all die vielen Module, die ich über die Jahre happy nutzen konnte.

Hier mein erstes Modul (Beschwerden an Codex).

Es kann gut sein, dass ich nicht in der Lage bin, Support zu leisten oder Änderungswünsche zu behandeln – schon ein Wunder, dass ich das Ding geschafft habe. Aber es hat bisher in mehreren (KNX) Installationen geklappt.

Sepp Lausch, Lausch SmarthomeCoach, Wangari Smart Integration LTD

Das Projekt steht unter der [Zero-Clause BSD License (0BSD)](LICENSE). Nutzung, Änderung und Weitergabe sind auch ohne Namensnennung erlaubt. Die Software wird ohne Gewährleistung bereitgestellt.
