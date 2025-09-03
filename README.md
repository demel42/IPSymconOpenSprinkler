[![IPS-Version](https://img.shields.io/badge/Symcon_Version-6.0+-red.svg)](https://www.symcon.de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/)
![Code](https://img.shields.io/badge/Code-PHP-blue.svg)
[![License](https://img.shields.io/badge/License-CC%20BY--NC--SA%204.0-green.svg)](https://creativecommons.org/licenses/by-nc-sa/4.0/)

## Dokumentation

**Inhaltsverzeichnis**

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Installation](#3-installation)
4. [Funktionsreferenz](#4-funktionsreferenz)
5. [Konfiguration](#5-konfiguration)
6. [Anhang](#6-anhang)
7. [Versions-Historie](#7-versions-historie)

## 1. Funktionsumfang

Das Modul dient zur Anbindung eines [OpenSprinkler-Controller](https://opensprinkler.com). Dabei wird auch und insbesondere die deutsche Erweiterung von [OpenSprinklerShop](https://opensprinklershop.de) unterstützt; besonders hingewisen wird hier auch auf die Übertragung von Messwerte vom IPS zum OpenSprinkler-Controller und der damit verbundenen Möglichkeit von Programm-Anpassung und Überwachung.

Programmiert wurde mit der Firmware-Version 2.2.3 auf einem OpenSprinkler-Controller 3.3. Ein *OpenSprinkler Pi* sowie ein *Opensprinkler Bee* wurde nicht getestet, sollte aber genauso funktionieren, das die API gleich sein soll..

Es werden alle relevanten Informationen geholt und Funktionen zur Verfügung gestellt; die Konfiguration muss aber weiterhin auf dem OpenSprinkler-Controller erfolgen.
Zur Erleichterung der Einrichtung kann die relevante Konfiguration vom OpenSprinkler in der Instanz-Konfiguration geholt werden.

Ein als *RemoteController* betriebener *OpenSprinkler* wird auch unterstützt, hier werden erwartungsgemäß nur wenige Informationen angezeigt und dient mehr dazu, grundsätzlich das Gerät auch im IPS zu haben.

## 2. Voraussetzungen

- IP-Symcon ab Version 6.0
- eingerichteter OpenSprinkler-Controller mit einer Firmware-Version 2.2.3 und später

## 3. Installation

### a. Installation des Moduls

Im [Module Store](https://www.symcon.de/service/dokumentation/komponenten/verwaltungskonsole/module-store/) ist das Modul unter dem Suchbegriff *OpenSprinkler* zu finden.<br>
Alternativ kann das Modul über [Module Control](https://www.symcon.de/service/dokumentation/modulreferenz/module-control/) unter Angabe der URL `https://github.com/demel42/IPSymconOpenSprinkler.git` installiert werden.

### b. Einrichtung in IPS

Die Instanz ist mit einem MQTT-Server verbunden; hier kann der eventuell bereits vorhandene MQTT-Server mit der Portnummer ☆1883* verwendet werden.
Ich empfehle aber zur besseren Fehlersuche einen eigenen MQTT-Server auf einem anderen Port einzuricht - dem muss natürlich auf dem OpenSprinkler-Controller auch so angegeben werden.

Nach Einrichtung der Zugangsdaten des Controller kann die Konfiguration des Controller (unter *Konfiguration der Steuereinheit*) abgerufen werden und steht dann in der Instanz zur Verfügung.
Die hier gewählten Bewässerungskreise, Sensoren und Programme werden dann als Variablen angelegt. Bei einem erneuten Abruf der Konfiguration kann kann die Änderung übernommen und ausgewertet werden - eventuell bereits umbenannte Variablen werden natürlich nicht geändert; hierzu steht die Funktion *Variablennamen anpassen* im Experten-Bereich des Aktionsbereichs zur Verfügung.

## 4. Funktionsreferenz

`OpenSprinkler_SetControllerEnabled(int $InstanzID, bool $enable)`<br>
Controller ausschalten (*enable*=**true**) ode einschalten (*enable*=**false**)

`OpenSprinkler_SetWateringLevel(int $InstanzID, int $level)`<br>
Bewässerungslevel (prozentuale Veränderung der eingestellten Bewässeungszeiten) manuell setzen, *level* darf den Wert von 0 .. 250 haben.

`OpenSprinkler_SetRainDelay(int $InstanzID, int $mode, int $hour)`<br>
Bewässerung aussetzen (*mode*=**0**), die Anzahl der Stunden (*hour*) kann 0 .. 32767 annehmen; *mode*=**1** (oder *hour*=**0**) hebt die Aussetzung auf.<br>
Wichtig: die Bezeichnung *Delay* ist irreführend, in der ausgesetzten Zeit nicht durchgeführte Bewässerungen werden nicht nachgeholt.

`OpenSprinkler_StopAllStations(int $InstanzID)`<br>
Stoppen aller laufenden Bewässerungskreise.

`OpenSprinkler_StationStartManually(int $InstanzID, int $sid, int $mode, int $seconds)`<br>
Einen Bewässerungskreis manuell starten (*mode*=**0**), die Dauer (*seconds*) kann von 0 .. 64800 reichen; *mode*=**1** (oder *seconds*=**0**) stoppt den Kreis.
Wenn der Kreis gestoppt wird, wird ein eventuell folgender Kreis aufrücken.

`OpenSprinkler_PauseQueue(int $InstanzID, int $mode, int $seconds)`<br>
Bewässerung pausieren (*mode*=**0**), die Dauer (*seconds*) darf nicht negativ sein; *mode*=**1** (oder *seconds*=**0**) hebt beendet die Pause..
Nach Ende der Pause werden laufende Bewässeungen weiter ausgeführt.

`OpenSprinkler_SetStationDisabled(int $InstanzID, int $sid, bool $disable)`<br>
Den Bewässerungskreis mit der ID *sid* (0-relativ) ausschalten (*disable*=**true**) oder einschalten (*disable*=**false**).

`OpenSprinkler_SetStationIgnoreRain(int $InstanzID, int $sid, bool $ignore)`<br>
Im Bewässerungskreis mit der ID *sid* (0-relativ) die Beachtung von *RainDelay* ignorieren (*ignore*=**true**) oder beachten (*ignore*=**false**).

`OpenSprinkler_SetStationIgnoreSensor1(int $InstanzID, int $sid, bool $ignore)`<br>
Im Bewässerungskreis mit der ID *sid* (0-relativ) den Sensor 1 ignorieren (*ignore*=**true**) oder beachten (*ignore*=**false**).<br>
Wichtig: das gilt nur, wenn der Sensor 1 nicht als Durchflusssensor eingerichtet ist.

`OpenSprinkler_SetStationIgnoreSensor2(int $InstanzID, int $sid, bool $ignore)`<br>
Im Bewässerungskreis mit der ID *sid* (0-relativ) den Sensor 2 ignorieren (*ignore*=**true**) oder beachten (*ignore*=**false**).

`OpenSprinkler_SetStationFlowThreshold(int $InstanzID, int $sid, float $value)`<br>
Im Bewässerungskreis mit der ID *sid* (0-relativ) den Wert für die Strömungsüberwachung (*value*) setzen; der Wert muss natürlich größßer als 0 sein.

`OpenSprinkler_SetProgramEnabled(int $InstanzID, int $pid, bool $enable)`<br>
Das Programm mit der ID *pid* (0-relativ) einschalten (*enable*=**true**) oder ausschalten (*enable*=**false**).

`OpenSprinkler_SetProgramWeatherAdjust(int $InstanzID, int $pid, bool $enable)`<br>
Im Programm mit der ID *pid* (0-relativ) die Beachtung von wetterbasierten Modifikationen der Bewässerungszeit einschalten (*enable*=**true**) oder ausschalten (*enable*=**false**).

`OpenSprinkler_ProgramStartManually(int $InstanzID, int $pid, bool $weatherAdjustmnent)`<br>
Das Programm mit der ID *pid* (0-relativ) starten unter Berücvksichtigung der wetterbasierten Anpassungen (*weatherAdjustment*=**true**) oder ohne (*weatherAdjustment*=**false**).

`OpenSprinkler_GetLogs(int $InstanzID, int $from, int $until, int $groupBy, array $sidList)`<br>
Instanz-interne Protokolle abrufen, Zeitstempel (Sekunden ab dem 1.1.1970) *von*/*bin* und der Gruppierung (*groupBy*: **0**=ohne, **1**=Datum, **2**=Bewässerungskreis-ID und **3**=Bewässerungskreis-Name).
Weiterhin kann man auf Bewässerungskreise einschränken: (*sidList*: **false**=keine Einschränkung, **[]**=jede Station, sonst einschränken auf die Liste der angegebenen *sid*'s.<br>
Derzeit gibt es nur Bewässerungskreisbezogenen Einträge, kann perspektivisch aber auch erweitert werden.<br>
Zurückgeliefert wird eine JSON-kodierte Struktur, die recht selbsterklärend sein sollte.

## 5. Konfiguration

### OpenSprinkler

#### Properties

| Eigenschaft               | Typ      | Standardwert | Beschreibung |
| :------------------------ | :------  | :----------- | :----------- |
| Instanz deaktivieren      | boolean  | false        | Instanz temporär deaktivieren |
|                           |          |              | |

#### Aktionen

| Bezeichnung                | Beschreibung |
| :------------------------- | :----------- |

### Variablenprofile

Es werden folgende Variablenprofile angelegt:
* Boolean<br>
OpenSprinkler.SensorState,
OpenSprinkler.YesNo,

* Integer<br>
OpenSprinkler.Current,
OpenSprinkler.Duration,
OpenSprinkler.IrrigationDurationHours,
OpenSprinkler.IrrigationDurationMinutes,
OpenSprinkler.IrrigationDurationSeconds,
OpenSprinkler.PauseQueueHours,
OpenSprinkler.PauseQueueMinutes,
OpenSprinkler.PauseQueueSeconds,
OpenSprinkler.ProgramDayRestriction,
OpenSprinkler.ProgramScheduleType,
OpenSprinkler.ProgramStart,
OpenSprinkler.ProgramStarttimeType,
OpenSprinkler.RainDelayAction,
OpenSprinkler.RainDelayDays,
OpenSprinkler.RainDelayHours,
OpenSprinkler.RebootCause,
OpenSprinkler.StationStartManuallyHours,
OpenSprinkler.StationStartManuallyMinutes,
OpenSprinkler.StationStartManuallySeconds,
OpenSprinkler.StationState,
OpenSprinkler.StopAllStations,
OpenSprinkler.SummaryDays,
OpenSprinkler.SummaryGroupBy,
OpenSprinkler.WateringLevel,
OpenSprinkler.WeatherQueryStatus,
OpenSprinkler.Wifi,

* Float<br>
OpenSprinkler.WaterFlowmeter,
OpenSprinkler.WaterFlowrate,

* String<br>

## 6. Anhang

### GUIDs
- Modul: `{E91B516D-3191-FEFF-A392-14E3BEB0956E}`
- Instanzen:
  - OpenSprinkler: `{DFB042F3-B26F-81DC-E1DB-B2704A28AAC5}`
- Nachrichten:

### Quellen

## 7. Versions-Historie

- 1.5 @ 03.09.2025 17:03
  - Fix: Angabe zum "vendor" fehlte

- 1.4 @ 18.08.2025 18:31
  - Neu: Angabe von Dauern (Unterbrechung, Pause, Laufzeit) kann nun optional alternativ als Zeichenkette angegeben werden

- 1.3.1 @ 14.08.2025 11:07
  - Fix: Typo in Beispiel-Script docs/notify.php

- 1.3 @ 09.07.2025 12:55
  - Neu: internes Log incl. Abruffunktion GetLogs()
  - Neu: testhalber Abruf und Auswertung vom Log des OpenSprinkler-Controllers; Ausgabe im Debug
  - Neu: Rundung von Wasserdurchfluß und Wasserverbrauch
  - Neu: Zusammenfassung der Bewässerung für das ganze System mit Auswahl von Gruppierung und Anzahl Tagen und pro Bewässerungskreis
  - Verbesserung: vervollständigte Auswertung von MQTT 'station/#' mit Berücksichtigung des externen Wasserzählers  und Erzeugung des internen Log-Eintrags.
  - Verbesserung: die MQTT-Nachrichten (insbesondere auch 'station/#') kommen nicht in der Reihenfolge des realen Ablaufs, das wird nun abgefangen
  - Verbesserung: README überarbeitet
  - Fix: diverse Anpassungen und Korrekturen

- 1.2 @ 13.04.2025 17:17
  - Neu: externe Wasseruhr zur Ermittlung des Wasserverbrauchs
  - Neu: Script zur Ausgabe von Warnungen (derzeit Strömungsmenge und Monitoring (Bestandteil der Pakets "analogen Sensoren")
  - Fix: diverse Anpassungen und Korrekturen

- 1.1 @ 23.03.2025 15:07
  - Fix: Änderung in der API: der Abruf "je" liefert einen Leerstring, denn keine special-stations definiert sind
  - Fix: fehlende Übersetzung
  - Fix: Schreibfehler

- 1.0 @ 02.01.2025 13:48
  - Initiale Version
