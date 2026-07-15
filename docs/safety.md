# Sicherheits- und Betriebsbeschreibung

## Abschaltursachen

Ein sicherer Stopp wird ausgelöst bei:

- manuellem Stopp oder Not-Aus,
- Regen oder erreichtem Bodenfeuchte-Grenzwert,
- fehlender oder falscher Ventilrückmeldung nach dem Timeout,
- Überschreitung der maximalen Programmlaufzeit,
- Modul-Deaktivierung,
- Konfigurationsänderung während eines Laufs,
- erkanntem unterbrochenem Lauf nach Neustart,
- Ausnahme beim Aufruf einer Ventilaktion.

Der Stopp fordert zuerst für sämtliche konfigurierten Zonenventile `false` und danach für die Hauptventile `false` an. Zusätzlich werden noch bekannte Ausgänge aus der vorherigen Konfiguration geschlossen. Sind Rückmeldungen vorhanden, bleibt die Instanz anschließend in der Phase „Warte auf Geschlossen-Rückmeldung“. Erst bestätigte Aus-Zustände beenden den Stopp; ein Timeout setzt einen Fehler.

## Grenzen der Software-Sicherheit

Eine Softwaresteuerung ersetzt keine hydraulische oder elektrische Sicherheit. Für eine reale Anlage werden mindestens empfohlen:

- stromlos geschlossene Ventile,
- geeignete Absicherung und KNX-Aktorparametrierung mit eigener maximaler Einschaltzeit,
- physischer Hauptabsperrhahn,
- Leckage- beziehungsweise Durchflusserkennung,
- echte Endlagen- oder Durchflussrückmeldung statt reiner Software-Spiegelwerte,
- beaufsichtigter Test jeder Abschaltursache.

Eine Pause schließt aus Sicherheitsgründen nicht nur die aktuelle Zone, sondern auch die Hauptventile beziehungsweise Pumpe. Vor dem Fortsetzen müssen konfigurierte Rückmeldungen den geschlossenen Zustand bestätigt haben.

„Überspringen“ schließt zuerst das aktuelle Zonenventil. Erst nach der konfigurierten Zonenwartezeit darf die nächste Zone öffnen. Pumpe und Hauptventil bleiben während eines normalen Zonenwechsels geöffnet; nach der letzten Zone werden auch sie geschlossen.

Wenn `RequestAction(false)` wegen Kommunikations- oder Aktorfehler nicht ausgeführt werden kann, kann das Modul den Wasserfluss nicht physisch garantieren. Der Fehler wird in `LastError` sichtbar gemacht.

## Sensorsemantik

Der Regensensor ist Boolean; sein sperrender Wert ist konfigurierbar. Der Bodenfeuchtesensor ist Integer oder Float. Je nach Sensortyp kann „feucht“ ein hoher oder niedriger Wert sein, weshalb die Vergleichsrichtung konfigurierbar ist.

Sensoren gelten für manuelle und automatische Starts. Ein Bediener kann einen Sensor nicht unbemerkt umgehen.
