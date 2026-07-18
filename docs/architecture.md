# Architektur und Zustandsmaschine

## Aufbau

`Wangari Irrigation` ist eine einzelne IP-Symcon-Geräteinstanz für bis zu zehn Zonen. Konfiguration, Laufzustand und Hardwarezugriff sind logisch getrennt:

- Properties enthalten Ventile, Sensoren, Zeitplan und Grenzwerte.
- Instanzvariablen bilden Bedienung und beobachtbaren Zustand ab.
- persistente Buffer enthalten Queue, Phase, Deadlines und erwartete Ausgangszustände.
- `switchOutput()` ist das einzige Hardware-Gateway.

## Ablauf

1. Ein manueller oder geplanter Start erzeugt eine Queue aus Bewässerungsschritten. Ein Schritt enthält im Einzelmodus eine Zone, im Gruppenmodus alle teilnehmenden Zonen derselben Gruppennummer.
2. Sensoren und Konfiguration werden geprüft.
3. Falls konfiguriert, startet zuerst die Beregnungspumpe. Nach der einstellbaren Druckaufbauzeit öffnet das optionale Hauptventil.
4. Nach der gemeinsamen Hauptventil-/Zonenwartezeit und gegebenenfalls bestätigter Rückmeldung startet die erste Zone.
5. Im Gruppenmodus öffnen alle Zonen eines Schritts gemeinsam. Jede Zone schließt nach ihrer eigenen Laufzeit.
6. Wenn keine Zone des Schritts mehr läuft, startet nach der Zwischenpause der nächste Schritt.
7. Nach der letzten Zone werden zunächst alle Zonen und anschließend Pumpe und Hauptventil geschlossen. Das gilt auch für eine einzeln gestartete manuelle Zone.

Im Einzelmodus läuft niemals mehr als eine Zone gleichzeitig. Im Gruppenmodus laufen ausschließlich Zonen derselben Gruppennummer parallel. Die Gruppen werden numerisch aufsteigend ausgeführt. Lücken in Zonen- und Gruppennummern sind zulässig.

Während `opening-pump`, `opening-master` und `inter-zone` kann die jeweils nächste Zone bereits übersprungen werden. Mehrfaches Überspringen behält die ursprünglich laufende Druckaufbau- beziehungsweise Zonenwartezeit bei; die ausgelassenen Ventile werden nicht kurz geöffnet. Der instanzlokale Status nennt dabei die nächste Zone.

## Persistente Phasen

- `opening-master`: Hauptventile wurden angefordert; Vorlauf/Rückmeldung läuft.
- `opening-pump`: Beregnungspumpe wurde angefordert; Druckaufbau/Rückmeldung läuft.
- `running-zone`: ein Bewässerungsschritt ist aktiv; jede darin noch laufende Zone besitzt eine eigene Endzeit.
- `inter-zone`: vorherige Zone ist geschlossen; Pause vor der nächsten Zone.
- `stopping`: Aus-Befehle wurden gesendet; konfigurierte Rückmeldungen werden überwacht.
- `paused`: aktuelle Zone und Hauptventile sind geschlossen; die Restlaufzeit ist eingefroren.
- `idle`: kein Lauf aktiv.

Ein bei `ApplyChanges()` vorgefundener aktiver Lauf wird nicht blind fortgesetzt. Stattdessen fordert das Modul den sicheren Aus-Zustand für alle bekannten und aktuell konfigurierten Ausgänge an.

Beim Fortsetzen einer Pause werden Pumpe und Hauptventil wieder in derselben Reihenfolge geöffnet. Danach öffnen alle zuvor noch laufenden Zonen des Schritts erneut und jede läuft exakt mit ihrer gespeicherten Restzeit weiter. Pausenzeit zählt nicht gegen die maximale Programmlaufzeit.

`SkipCurrentZone()` schließt den gesamten laufenden Schritt, erhöht die Queue-Position und startet nach der variablen Zonenwartezeit den nächsten Eintrag. Im Gruppenmodus wird damit die vollständige Gruppe übersprungen. Ein weiterer Aufruf während dieser Wartezeit überspringt auch den nächsten Queue-Eintrag. Hinter dem letzten Eintrag folgt der normale sichere Programmabschluss.

Jede Instanz besitzt zehn eigene Fortschrittsvariablen. `ActiveStepZones`, `ActiveZoneDeadlines` und `ActiveZoneTotalSeconds` halten die aktiven Gruppenmitglieder und ihre individuellen Zeiten. Die Anzeige wird während einer Pause nicht weitergerechnet und beim Verlassen der jeweiligen Zone geleert.

## Simulation

Simulation durchläuft dieselbe Queue und dieselben Phasen. `switchOutput()` schreibt den erwarteten Zustand jedoch nur in einen Buffer. Der Simulationsmodus eines bereits laufenden Programms wird beim Start festgehalten. Dadurch kann eine Konfigurationsänderung von Hardware zu Simulation einen real geöffneten Ausgang beim anschließenden Sicherheitsstopp nicht versehentlich unbeachtet lassen.
