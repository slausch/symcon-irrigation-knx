# Architektur und Zustandsmaschine

## Aufbau

`Irrigation KNX` ist eine einzelne IP-Symcon-Geräteinstanz. Konfiguration, Laufzustand und Hardwarezugriff sind logisch getrennt:

- Properties enthalten Ventile, Sensoren, Zeitplan und Grenzwerte.
- Instanzvariablen bilden Bedienung und beobachtbaren Zustand ab.
- persistente Buffer enthalten Queue, Phase, Deadlines und erwartete Ausgangszustände.
- `switchOutput()` ist das einzige Hardware-Gateway.

## Ablauf

1. Ein manueller oder geplanter Start erzeugt eine Queue aus den aktivierten Zonen.
2. Sensoren und Konfiguration werden geprüft.
3. Beide aktivierten Hauptventile werden geöffnet.
4. Nach Vorlauf und gegebenenfalls bestätigter Rückmeldung startet die erste Zone.
5. Nach Ablauf werden beide Zonenventile geschlossen.
6. Nach der Zwischenpause startet die nächste aktivierte Zone.
7. Nach der letzten Zone werden zunächst alle Zonen und anschließend alle Hauptventile geschlossen.

Es läuft niemals mehr als eine Zone gleichzeitig. Lücken in der Konfiguration sind zulässig: beispielsweise folgt auf Zone 1 direkt Zone 5, wenn die Zonen 2 bis 4 deaktiviert sind.

## Persistente Phasen

- `opening-master`: Hauptventile wurden angefordert; Vorlauf/Rückmeldung läuft.
- `running-zone`: genau eine Zone ist aktiv und besitzt eine Endzeit.
- `inter-zone`: vorherige Zone ist geschlossen; Pause vor der nächsten Zone.
- `stopping`: Aus-Befehle wurden gesendet; konfigurierte Rückmeldungen werden überwacht.
- `idle`: kein Lauf aktiv.

Ein bei `ApplyChanges()` vorgefundener aktiver Lauf wird nicht blind fortgesetzt. Stattdessen fordert das Modul den sicheren Aus-Zustand für alle bekannten und aktuell konfigurierten Ausgänge an.

## Simulation

Simulation durchläuft dieselbe Queue und dieselben Phasen. `switchOutput()` schreibt den erwarteten Zustand jedoch nur in einen Buffer. Der Simulationsmodus eines bereits laufenden Programms wird beim Start festgehalten. Dadurch kann eine Konfigurationsänderung von Hardware zu Simulation einen real geöffneten Ausgang beim anschließenden Sicherheitsstopp nicht versehentlich unbeachtet lassen.
