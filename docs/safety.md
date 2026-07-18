# Sicherheits- und Betriebsbeschreibung

## Abschaltursachen

Ein sicherer Stopp wird ausgelöst bei:

- manuellem Stopp oder Sicherheitsstopp,
- erreichtem Bodenfeuchte-Grenzwert,
- fehlender oder falscher EIN-Rückmeldung nach dem Timeout sowie optional fehlender AUS-Rückmeldung,
- Überschreitung der maximalen Programmlaufzeit,
- Modul-Deaktivierung,
- Konfigurationsänderung während eines Laufs,
- erkanntem unterbrochenem Lauf nach Neustart,
- Ausnahme beim Aufruf einer Ventilaktion.

Der Stopp fordert zuerst für sämtliche konfigurierten Zonenventile `false` und danach für die Hauptventile `false` an. Zusätzlich werden noch bekannte Ausgänge aus der vorherigen Konfiguration geschlossen. Nur wenn „AUS-Rückmeldung überwachen“ aktiviert ist, bleibt die Instanz anschließend bis zur Bestätigung oder zum Timeout in der Phase „Warte auf Geschlossen-Rückmeldung“. Standardmäßig werden fehlende AUS-Telegramme nicht als Fehler behandelt.

## Grenzen der Software-Sicherheit

Eine Softwaresteuerung ersetzt keine hydraulische oder elektrische Sicherheit. Für eine reale Anlage werden mindestens empfohlen:

- stromlos geschlossene Ventile,
- geeignete Absicherung und KNX-Aktorparametrierung mit eigener maximaler Einschaltzeit,
- physischer Hauptabsperrhahn,
- Leckage- beziehungsweise Durchflusserkennung,
- echte Endlagen- oder Durchflussrückmeldung statt reiner Software-Spiegelwerte,
- beaufsichtigter Test jeder Abschaltursache.

Im Gruppenmodus muss die Anlage für den gleichzeitigen Betrieb aller Ventile einer Gruppe ausgelegt sein. Förderleistung, Leitungsquerschnitte, Druck, Netzteil- und Aktorbelastung liegen außerhalb der Softwareprüfung.

Eine Pause schließt aus Sicherheitsgründen alle aktuell laufenden Zonen sowie Hauptventil und Pumpe. Bei aktivierter AUS-Überwachung müssen konfigurierte Rückmeldungen vor dem Fortsetzen den geschlossenen Zustand bestätigt haben.

„Überspringen“ schließt eine bereits laufende Zone zuerst und öffnet die nächste erst nach der konfigurierten Zonenwartezeit. Während Pumpen-Druckaufbau oder Zonenwechsel kann eine noch geschlossene wartende Zone ohne Ventilbefehl übersprungen werden; mehrfaches Drücken verlängert die bereits laufende Wartezeit nicht. Pumpe und Hauptventil bleiben während eines normalen Zonenwechsels geöffnet. Sobald keine weitere Zone in der Modulwarteschlange vorhanden ist, werden sie auch bei einer manuellen Einzelzone geschlossen.

Wenn `RequestAction(false)` wegen Kommunikations- oder Aktorfehler nicht ausgeführt werden kann, kann das Modul den Wasserfluss nicht physisch garantieren. Der Fehler wird in `LastError` sichtbar gemacht.

## Sensorsemantik

Der Regensensor ist Boolean und gilt als aktiv, wenn sein Wert dem konfigurierten Aktivwert entspricht. Die Reaktion wird je Zone festgelegt: Markierte Zonen werden übersprungen; setzt Regen während einer markierten Zone ein, wird sie geschlossen und nach der Zonenwartezeit mit der nächsten nicht markierten Zone fortgefahren. Regen löst deshalb nicht mehr grundsätzlich einen Sicherheitsstopp des gesamten Programms aus.

Der Bodenfeuchtesensor ist Integer oder Float. Ist „Bei/über Grenzwert sperren“ aktiv, sperrt `Messwert >= Grenzwert`; andernfalls sperrt `Messwert <= Grenzwert`. Je nach Sensortyp kann „feucht“ ein hoher oder niedriger Wert sein, weshalb diese Vergleichsrichtung konfigurierbar ist. Die Bodenfeuchte bleibt eine zentrale Sperre des gesamten Programms.

Sensoren gelten für manuelle und automatische Starts. Ein Bediener kann einen Sensor nicht unbemerkt umgehen.
