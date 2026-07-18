# Wangari Irrigation

Eigenständiges IP-Symcon-9-Modul für eine sichere Bewässerungssteuerung. Es steuert optional eine Beregnungspumpe und ein Hauptventil sowie zehn einzeln oder gruppenweise laufende Zonen mit jeweils bis zu zwei Ventilen.

Das Modul wurde neu strukturiert. Die öffentliche Referenz [`elueckel/irrigation-control`](https://github.com/elueckel/irrigation-control) diente ausschließlich zur Ermittlung des Funktionsumfangs und für eine Fehleranalyse. Es wurde kein Quellcode übernommen.

## Funktionsumfang

- optionale Beregnungspumpe mit eigener Druckaufbauzeit (Standard: 5 Sekunden)
- optionales Hauptventil; die Steuerung funktioniert auch ohne Pumpe und Hauptventil
- gemeinsame Wartezeit für Hauptventil und Zonenwechsel (Standard: 2 Sekunden)
- bis zu zehn Zonen mit jeweils einem oder zwei Boolean-Ventilen
- wählbare Bewässerungsart: keine Bewässerung, Zonen einzeln oder Zonen gleicher Gruppennummer gemeinsam
- Gruppe `0` bedeutet „keine Gruppe“ und läuft einzeln; Gruppen `1–100` verbinden gleiche Nummern
- Ausführung in der Reihenfolge der Zonentabelle; eine Gruppe läuft gemeinsam, sobald sie dort erstmals auftritt
- Zeitplan nach Uhrzeit, Wochentagen und Tagesintervall mit festem Ankerdatum
- manuelles Gesamtprogramm oder einzelne manuelle Zone
- Pause mit geschlossenem Zonen- und Hauptventil sowie Fortsetzung der gespeicherten Restlaufzeit
- Überspringen bereits während des Pumpen-Druckaufbaus, während des Zonenwechsels oder bei laufender Zone; mehrfaches Drücken öffnet die ausgelassenen Ventile nicht und startet bestehende Wartezeiten nicht neu
- Laufzeit und Teilnahme am Automatikprogramm für jede Zone als bedienbare Variable
- eigener Fortschritts-String je Zone, zum Beispiel `seit 7 Min, noch 53 Min`
- Boolean-Regensensor und numerischer Bodenfeuchtesensor
- optionale Boolean-Rückmeldung für jedes Haupt- und Zonenventil
- EIN-Rückmelde-Timeout, optional zuschaltbare AUS-Rückmeldungsüberwachung, maximale Gesamtlaufzeit und sofortiger Sensor-Stopp
- sicherer Stopp bei Deaktivierung, Konfigurationsänderung und Neustart
- Simulationsmodus ohne Hardware-Schaltbefehle
- separate Testlaufzeit für die Simulation (Standard: 1 Minute je Zone)
- Status mit Namen der aktiven beziehungsweise nächsten Zone sowie Restlaufzeit-, Sperr- und Fehlervariablen für die Visualisierung

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
- `IrrigationMode`: `0` keine Bewässerung, `1` Zonen einzeln (Standard), `2` Gruppen bewässern
- `ProgramActive`: Gesamtprogramm starten oder stoppen
- `Pause`: laufende Zone, Pumpe und Hauptventil schließen; mit derselben Restlaufzeit fortsetzen
- `Skip`: während des Druckaufbaus oder Zonenwechsels die nächste wartende Zone ohne Ventilöffnung auslassen; eine laufende Zone schließen und nach der Wartezeit fortfahren
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
IRRKNX_SetIrrigationMode($InstanceID, 2);
IRRKNX_Stop($InstanceID);
IRRKNX_EmergencyStop($InstanceID, 'Leckage erkannt');
```

Manuelle Starts beachten standardmäßig ebenfalls Regen und Bodenfeuchte. Es gibt bewusst keinen stillen Sensor-Override.

Der Status nennt während der Vorbereitung und des Zonenwechsels die nächste Zone, während der Bewässerung die aktive Zone und während einer Pause die pausierte Zone. Mehrfaches Überspringen während derselben Pumpen- oder Zonenwartezeit verlängert diese Wartezeit nicht.

Im Gruppenmodus bedeutet Gruppennummer `0`, dass die Zone keiner Gruppe angehört und einzeln läuft. Das ist der Standard für neue und bisher nicht gruppierte Zonen. Gleiche Gruppennummern von `1` bis `100` werden zu einem gemeinsamen Bewässerungsschritt verbunden. Die Ausführung folgt der Reihenfolge der Zonentabelle: Beim ersten Auftreten einer Gruppennummer laufen alle teilnehmenden Zonen dieser Gruppe gemeinsam; spätere Zeilen derselben Gruppe werden nicht nochmals ausgeführt. Werte über 100 werden mit sichtbarer Warnung intern auf 100 begrenzt, negative Werte entsprechend auf 0. Alle Zonen eines Gruppenschritts behalten ihre jeweilige Zonenlaufzeit und schließen daher gegebenenfalls zu unterschiedlichen Zeiten. Erst wenn die letzte Zone der Gruppe beendet ist, folgt die Zonenwartezeit vor dem nächsten Schritt. Pause und Überspringen wirken auf den vollständigen aktuellen Gruppenschritt; bei Regen schließen innerhalb einer gemischten Gruppe nur die als regenempfindlich markierten Zonen.

Bereits gespeicherte Gruppennummern bleiben bei einem Update erhalten. Wer Build 9 schon verwendet hat und dessen automatisch vorbelegte Werte `1–10` nicht als echte Gruppen benötigt, setzt diese Zonen einmalig auf `0`.

Beispiel: Haben die Zonen in Tabellenreihenfolge die Gruppen `0, 2, 2, 0`, läuft zuerst die erste Zone einzeln, danach Gruppe 2 gemeinsam und anschließend die letzte Zone einzeln.

Nach der letzten noch vorhandenen Zone wird immer der vollständige Abschaltpfad ausgeführt: alle Zonenventile schließen, anschließend Pumpe stoppen und das optionale Hauptventil schließen. Das gilt gleichermaßen für Gesamtprogramme und über `Manuelle Zone` beziehungsweise `IRRKNX_StartZone()` gestartete Einzelzonen. Direkte Fremdschaltungen einer konfigurierten Ventilvariable außerhalb dieser Modulbedienung sind kein Modullauf und werden deshalb nicht als manuelle Zone überwacht.

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

$bewässerungsart = IPS_GetObjectIDByIdent('IrrigationMode', $InstanceID);
RequestAction($bewässerungsart, 2); // Gruppen bewässern
```

Änderungen über Variablen gelten sofort und bleiben bis zur nächsten übernommenen Instanzkonfiguration bestehen. Beim Übernehmen der Konfiguration werden die dort eingetragenen Werte wieder als Vorgabe in die Variablen geladen. Jede übernommene Konfigurationsänderung stoppt einen gegebenenfalls laufenden Bewässerungslauf sicher.

## Mehrere Instanzen

Das Modul kann mehrfach instanziiert werden. Properties, Timer, Buffer, Bedienvariablen und Fortschrittsanzeigen sind je Instanz getrennt. Instanzen können im Objektbaum frei benannt werden, beispielsweise `Wangari Irrigation 1`, `Wangari Irrigation 2`, `Vorgarten` und `Hintergarten`.

Sensorvariablen dürfen von mehreren Instanzen gemeinsam gelesen werden. Dieselbe schaltbare Pumpe, dasselbe Hauptventil oder dasselbe Zonenventil sollte dagegen nicht mehreren Instanzen zugeordnet werden: Andernfalls könnten zwei unabhängig laufende Programme widersprüchliche `RequestAction`-Befehle senden.

Ein Modulupdate aktualisiert den gemeinsamen Programmcode. Alle vorhandenen Instanzen verwenden anschließend die neue Version; ihre Konfigurationen und Variablen bleiben anhand ihrer jeweiligen Instanz- und Objekt-IDs getrennt erhalten.

## Fortschrittsanzeige je Zone

Während eine Zone läuft, enthält ihre Fortschrittsvariable einen Text. Im Gruppenmodus können deshalb mehrere Fortschrittsvariablen gleichzeitig gefüllt sein. Zeiten unter einer Minute werden in Sekunden, längere Zeiten in vollen Minuten dargestellt:

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

Rückmeldungen sind optional. Eine konfigurierte EIN-Rückmeldung muss innerhalb von `FeedbackTimeoutSeconds` den erwarteten Zustand melden. Andernfalls beendet das Modul den Lauf, fordert für alle Zonenventile `false` an und schließt danach die Hauptventile. Invertierte Kontakte können je Rückmeldung konfiguriert werden.

Die Option `AUS-Rückmeldung überwachen` ist standardmäßig deaktiviert. Dadurch blockiert ein fehlendes KNX-AUS-Telegramm weder den nächsten Zonen- beziehungsweise Gruppenschritt noch den Abschluss und erzeugt keine Fehlermeldung. Wird die Option aktiviert, gilt wieder die strengere Prüfung: Ein fehlender Geschlossen-Status blockiert bis zum Timeout und führt anschließend zum Fehlerstatus.

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

Die Tests decken insbesondere Einzel- und Gruppenbewässerung, unterschiedliche Laufzeiten innerhalb einer Gruppe, gruppenweises Überspringen, Pause/Fortsetzen, Regen innerhalb gemischter Gruppen, die Ansteuerung von Pumpe und Hauptventil, AUS-Rückmeldungen mit beiden Einstellungen, instanzgetrennte Fortschrittsanzeigen, Migration, Sensorsperren und das Schließen sämtlicher Ausgänge ab.

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
