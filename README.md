# Irrigation KNX

Eigenständiges IP-Symcon-Modul für eine sichere KNX-Bewässerungssteuerung. Es steuert bis zu zwei Hauptventile und zehn sequenziell laufende Zonen mit jeweils bis zu zwei Ventilen.

Das Modul wurde neu strukturiert. Die öffentliche Referenz [`elueckel/irrigation-control`](https://github.com/elueckel/irrigation-control) diente ausschließlich zur Ermittlung des Funktionsumfangs und für eine Fehleranalyse. Es wurde kein Quellcode übernommen.

## Funktionsumfang

- bis zu zwei Hauptventile mit konfigurierbarem Vorlauf
- bis zu zehn Zonen mit jeweils einem oder zwei Boolean-Ventilen
- sequenzieller Programmlauf; deaktivierte Zonen werden sicher übersprungen
- Zeitplan nach Uhrzeit, Wochentagen und Tagesintervall mit festem Ankerdatum
- manuelles Gesamtprogramm oder einzelne manuelle Zone
- Pause mit geschlossenem Zonen- und Hauptventil sowie Fortsetzung der gespeicherten Restlaufzeit
- Boolean-Regensensor und numerischer Bodenfeuchtesensor
- optionale Boolean-Rückmeldung für jedes Haupt- und Zonenventil
- Rückmelde-Timeout, maximale Gesamtlaufzeit und sofortiger Sensor-Stopp
- sicherer Stopp bei Deaktivierung, Konfigurationsänderung und Neustart
- Simulationsmodus ohne Hardware-Schaltbefehle
- separate Testlaufzeit für die Simulation (Standard: 1 Minute je Zone)
- Status-, Restlaufzeit-, Sperr- und Fehlervariablen für die Visualisierung

## Zentrales Sicherheitsprinzip

Ventilvariablen müssen vom Typ Boolean sein und eine Standardaktion besitzen. Jeder Hardware-Schaltbefehl läuft ausschließlich über:

```php
RequestAction($VariableID, true);  // öffnen
RequestAction($VariableID, false); // schließen
```

Der Aufruf ist in `switchOutput()` zentralisiert. Direkte KNX-, Homematic- oder `SetValue()`-Schaltzugriffe auf externe Ventile werden nicht verwendet. `SetValue()` wird nur für die vom Modul selbst angelegten Statusvariablen eingesetzt.

## Installation und privater Test

Das Repository bleibt für den Testbetrieb privat. Zugangsdaten oder GitHub-Tokens gehören nicht in die Repository-URL und nicht in die Modulkonfiguration. Für einen lokalen Test kann das Repository auf dem IP-Symcon-System in das Modulverzeichnis ausgecheckt und von dort geladen werden. Alternativ wird ein auf dem Testsystem sicher hinterlegter Lesezugang für das private Repository benötigt.

Nach dem Laden erscheint das Gerät unter dem eindeutigen Namen **Irrigation KNX**.

Empfohlene Erstinbetriebnahme:

1. Instanz anlegen und `Simulation` aktiviert lassen. Die `Testlaufzeit (nur Simulation)` steht standardmäßig auf 1 Minute je Zone.
2. Hauptventile und Zonen als Boolean-Variablen auswählen.
3. Laufzeiten, Sensoren, Rückmeldungen und Sicherheitszeiten konfigurieren.
4. In der Simulation Gesamtprogramm, Einzelzonen, Regenstopp und Rückmeldefehler prüfen.
5. Erst danach Simulation deaktivieren und jede Zone unter Aufsicht kurz testen.
6. Automatik erst nach erfolgreichem Hardwaretest einschalten.

## Bedienung

Die Instanz legt folgende bedienbare Variablen an:

- `Automatic`: Zeitplan ein-/ausschalten
- `ProgramActive`: Gesamtprogramm starten oder stoppen
- `ManualZone`: einzelne Zone starten; `0` stoppt
- `ManualRuntime`: Laufzeit der manuellen Einzelzone
- `Pause`: aktuelle Zone und Hauptventile schließen; beim Ausschalten mit derselben Restlaufzeit fortsetzen
- `EmergencyStop`: alle konfigurierten Ausgänge schließen

Öffentliche Modulfunktionen:

```php
IRRKNX_StartProgram($InstanceID, true);
IRRKNX_StartZone($InstanceID, 3, 15);
IRRKNX_Pause($InstanceID);
IRRKNX_TogglePause($InstanceID); // pausiert bzw. setzt einen pausierten Lauf fort
IRRKNX_Resume($InstanceID);
IRRKNX_Stop($InstanceID);
IRRKNX_EmergencyStop($InstanceID, 'Leckage erkannt');
```

Manuelle Starts beachten standardmäßig ebenfalls Regen und Bodenfeuchte. Es gibt bewusst keinen stillen Sensor-Override.

## Zeitsteuerung

`StartTime` verwendet `HH:MM`. Der Zeitplan läuft nur an aktivierten Wochentagen. `IntervalDays` wird gegen `IntervalAnchor` (`YYYY-MM-DD`) berechnet. Damit wandert der Termin nicht bei jedem Timerlauf weiter. Pro Kalendertag wird höchstens ein automatischer Start ausgelöst.

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

Die Tests decken insbesondere beide Hauptventil-IDs, das Überspringen deaktivierter Zonen, Pause/Fortsetzen, die Migration von sechs auf zehn Zonen, Sensorsperren, Rückmeldefehler und das Schließen sämtlicher Ausgänge ab.

## Dokumentation

- [Sicherheits- und Betriebsbeschreibung](docs/safety.md)
- [Fehleranalyse der Referenz](docs/reference-audit.md)
- [Architektur und Zustandsmaschine](docs/architecture.md)

## Entwicklungsstand

Version `0.1` ist für den privaten Testbetrieb vorgesehen. Vor einem öffentlichen Release sollten Tests auf der konkret eingesetzten IP-Symcon-Version, den KNX-Aktoraktionen und realen Rückmeldekontakten protokolliert werden.
