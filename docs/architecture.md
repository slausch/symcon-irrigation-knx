# Architektur und Zustandsmaschine

## Aufbau

`Wangari Irrigation` ist eine einzelne IP-Symcon-Geräteinstanz für bis zu zehn Zonen. Konfiguration, Laufzustand und Hardwarezugriff sind logisch getrennt:

- Properties enthalten Ventile, Sensoren, Zeitplan und Grenzwerte.
- Instanzvariablen bilden Bedienung und beobachtbaren Zustand ab.
- persistente Buffer enthalten Queue, Phase, Deadlines und erwartete Ausgangszustände.
- `switchOutput()` ist das einzige Hardware-Gateway.

## Ablauf

1. Ein manueller oder geplanter Start erzeugt eine Queue aus den aktivierten Zonen.
2. Sensoren und Konfiguration werden geprüft.
3. Falls konfiguriert, startet zuerst die Beregnungspumpe. Nach der einstellbaren Druckaufbauzeit öffnet das optionale Hauptventil.
4. Nach der gemeinsamen Hauptventil-/Zonenwartezeit und gegebenenfalls bestätigter Rückmeldung startet die erste Zone.
5. Nach Ablauf werden beide Zonenventile geschlossen.
6. Nach der Zwischenpause startet die nächste aktivierte Zone.
7. Nach der letzten Zone werden zunächst alle Zonen und anschließend Pumpe und Hauptventil geschlossen. Das gilt auch für eine einzeln gestartete manuelle Zone.

Es läuft niemals mehr als eine Zone gleichzeitig. Lücken in der Konfiguration sind zulässig: beispielsweise folgt auf Zone 1 direkt Zone 5, wenn die Zonen 2 bis 4 deaktiviert sind.

Während `opening-pump`, `opening-master` und `inter-zone` kann die jeweils nächste Zone bereits übersprungen werden. Mehrfaches Überspringen behält die ursprünglich laufende Druckaufbau- beziehungsweise Zonenwartezeit bei; die ausgelassenen Ventile werden nicht kurz geöffnet. Der instanzlokale Status nennt dabei die nächste Zone.

## Persistente Phasen

- `opening-master`: Hauptventile wurden angefordert; Vorlauf/Rückmeldung läuft.
- `opening-pump`: Beregnungspumpe wurde angefordert; Druckaufbau/Rückmeldung läuft.
- `running-zone`: genau eine Zone ist aktiv und besitzt eine Endzeit.
- `inter-zone`: vorherige Zone ist geschlossen; Pause vor der nächsten Zone.
- `stopping`: Aus-Befehle wurden gesendet; konfigurierte Rückmeldungen werden überwacht.
- `paused`: aktuelle Zone und Hauptventile sind geschlossen; die Restlaufzeit ist eingefroren.
- `idle`: kein Lauf aktiv.

Ein bei `ApplyChanges()` vorgefundener aktiver Lauf wird nicht blind fortgesetzt. Stattdessen fordert das Modul den sicheren Aus-Zustand für alle bekannten und aktuell konfigurierten Ausgänge an.

Beim Fortsetzen einer Pause werden Pumpe und Hauptventil wieder in derselben Reihenfolge geöffnet. Danach öffnet dieselbe Zone erneut und läuft exakt mit der gespeicherten Restzeit weiter. Pausenzeit zählt nicht gegen die maximale Programmlaufzeit.

`SkipCurrentZone()` schließt die laufende Zone, erhöht die Queue-Position und startet nach der variablen Zonenwartezeit den nächsten Eintrag. Ein weiterer Aufruf während dieser Wartezeit überspringt auch den nächsten Queue-Eintrag. Hinter dem letzten Eintrag folgt der normale sichere Programmabschluss.

Jede Instanz besitzt zehn eigene Fortschrittsvariablen. `CurrentZoneTotalSeconds` hält die Gesamtlaufzeit der aktiven Zone im Instanzbuffer. Aus Gesamtlaufzeit und `PhaseDeadline` werden verstrichene und verbleibende Zeit berechnet. Die Anzeige wird während einer Pause nicht weitergerechnet und bei jedem Verlassen der aktiven Zone geleert.

## Simulation

Simulation durchläuft dieselbe Queue und dieselben Phasen. `switchOutput()` schreibt den erwarteten Zustand jedoch nur in einen Buffer. Der Simulationsmodus eines bereits laufenden Programms wird beim Start festgehalten. Dadurch kann eine Konfigurationsänderung von Hardware zu Simulation einen real geöffneten Ausgang beim anschließenden Sicherheitsstopp nicht versehentlich unbeachtet lassen.
