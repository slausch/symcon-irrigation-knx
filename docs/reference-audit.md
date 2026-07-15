# Fehleranalyse der Referenzimplementierung

Analysiert wurde der öffentliche Stand `7af96dac3820d51a19e48e912884ba13c0500720` des Repositories `elueckel/irrigation-control` (Branch `master`, Abruf am 15.07.2026). Die Analyse dient ausschließlich als Anforderungskatalog für eine neue Implementierung.

## Kritische und hohe Befunde

1. **Falsche Ziel-ID beim zweiten Hauptventil.** Beim Öffnen von Hauptventil 2 wird erneut `Group1MasterValve1` an `HM_WriteValueBoolean()` übergeben. Die interne Statusvariable für Ventil 2 wird trotzdem auf offen gesetzt. Anzeige und Hardware können dadurch auseinanderlaufen (damalige `module.php`, Zeilen 747–753).

2. **Regenstopp kann unmittelbar die nächste Zone öffnen.** Der Watchdog ruft zum Regenstopp `Group1SprinklerStringStop()` auf. Diese Funktion schließt die aktuelle Zone, erhöht die Zonennummer und ruft direkt wieder `SprinklerOperationGroup1()` auf. Dort wird die Regensperre vor dem Öffnen der nächsten Zone nicht geprüft (Zeilen 273–279 sowie 986–1049).

3. **Deaktivierte Zone 4 oder 5 beendet die Sequenz zu früh.** In den Fällen 4 und 5 wird bei einer deaktivierten Zone auf `0` statt auf die nächste Zone gewechselt. Dadurch werden spätere aktive Zonen übersprungen (Zeilen 864–868 und 885–889).

4. **Deaktivieren ist kein garantierter Hardware-Stopp.** `ApplyChanges()` deaktiviert Timer, fordert aber keinen definierten Aus-Zustand aller Ventile an. Auch der deaktivierte Zweig der Gruppensteuerung setzt vor allem interne Statuswerte zurück (Zeilen 230–233 und 918–930).

5. **Keine generische Aktoransteuerung.** Ventile sind fest an `HM_WriteValueBoolean()` gebunden. Boolean-Variablen mit Standardaktion und insbesondere KNX-Variablen werden dadurch nicht generisch unterstützt (unter anderem Zeilen 740–752 und 956–963).

## Weitere funktionale Befunde

6. **Konfigurationsschalter ohne Wirkung.** `RainStopsIrrigation`, `HumiditySensorActive` und `Group1MasterValveActive` werden registriert, aber nicht in der Steuerungsentscheidung verwendet. `Group1MasterValveWaitTime` wird gelesen beziehungsweise vorgesehen, seine Anwendung ist auskommentiert.

7. **Ungeschützte Sensorzugriffe.** Mehrfach wird `GetValue(ReadPropertyInteger(...))` ohne Prüfung auf ID `0`, Existenz oder Variablentyp ausgeführt. Leere optionale Sensorfelder können deshalb Warnungen oder Fehler erzeugen (zum Beispiel Zeilen 241–242, 269 und 397).

8. **WebFront-Aktionen fehlen.** Bedienvariablen werden registriert, aber `EnableAction()` und eine Modul-`RequestAction()`-Behandlung fehlen. Die vorgesehenen manuellen Schalter sind damit nicht als reguläre Instanzaktionen umgesetzt (Zeilen 185–204).

9. **Intervallplanung kann Starts fortlaufend verschieben.** Die nächste Zielzeit wird aus „jetzt“ neu gebildet und anschließend um das Ausführungsintervall verschoben. Diese Berechnung wird aus dem häufigen Watchdog erneut aufgerufen. Bei Intervallen größer null bleibt das Ziel dadurch in der Zukunft beziehungsweise erhält nach Überschreiten der Uhrzeit einen zusätzlichen Tag (Zeilen 443–484).

10. **Vorlauf der Hauptventile ist nicht implementiert.** Das Zeitfeld existiert, die Verwendung ist jedoch auskommentiert. Zonen werden unmittelbar nach der Hauptventilanforderung geöffnet (Zeilen 734 und 759 ff.).

11. **Keine Rückmelde- oder Laufzeitüberwachung.** Interne Statusvariablen werden als Hardwarezustand verwendet. Es gibt keine Prüfung des tatsächlichen Ventilzustands und keine unabhängige maximale Gesamtlaufzeit.

12. **Ungeprüfte Abhängigkeiten.** Der erste Archiv- beziehungsweise WebFront-Instanzeintrag wird mit `[0]` verwendet, ohne zu prüfen, ob eine solche Instanz existiert (Zeilen 599 und 666).

13. **Regenmengenauswertung ist semantisch unsicher.** Archivwerte einer Regenvariable werden addiert. Bei einem kumulativen Zähler oder periodisch geloggten Messstand führt dies zur Mehrfachzählung; korrekt wäre eine explizite Definition, ob Einzelmengen, Rate oder Zählerdifferenz erwartet wird (Zeilen 607–623).

14. **Evapotranspirations-Timer ist nicht zuverlässig täglich.** Der Timer wird zunächst auf fünf Stunden gesetzt und danach auf die verbleibende Zeit bis 14:00 geändert. Da IP-Symcon-Timer Intervalle wiederholen und der Callback die nächste Tagesfrist nicht neu setzt, kann der anfängliche Abstand anschließend als Wiederholintervall wirken (Zeilen 218–228).

## Konsequenzen in Irrigation KNX

- Die Queue enthält nur aktivierte Zonen; sie verwendet keine fehleranfällige Fallunterscheidung pro Zonennummer.
- Jedes Haupt- und Zonenventil behält seine eigene ID.
- Sensorprüfung findet vor jedem Start und während jedes Laufs statt.
- Sämtliche Hardwarezugriffe laufen zentral über `RequestAction($VariableID, $state)`.
- Rückmeldungen, feste Deadlines und eine maximale Programmlaufzeit überwachen den Lauf.
- Deaktivierung, Neustart und Konfigurationsänderung führen zum sicheren Aus-Zustand.
- Der Zeitplan verwendet ein festes Kalender-Ankerdatum und merkt den letzten Starttag.
- Optionale IDs werden nur gelesen, wenn sie größer null, vorhanden und vom erwarteten Typ sind.
