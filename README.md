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

Das Modul dient zur Anbindung eines [OpenSprinkler-Controller](https://opensprinkler.com). Dabei wird auch und insbesondere die deutsche Erweiterung von [OpenSprinklerShop](https://opensprinklershop.de) unterstützt.

Programmiert wurde mit der Firmware-Version 2.2.3 auf einem OpenSprinkler-Controller 3.3. Ein *OpenSprinkler Pi* sowie ein *Opensprinkler Bee* wurde mnicht getestet, sollte aber genauso funktionieren.

Es werden alle relevanten Informationen geholt und Funktionen zur Verfügung gestellt; die Konfiguration muss aber weiterhin auf dem OpenSprinkler-Controller erfolgen.
Zur Erleichterung deriEInrichtung kann die relevante Konfiguration vom OpenSprinkler in der INstanz-Konfiguration geholt werden.

## 2. Voraussetzungen

- IP-Symcon ab Version 6.0
- eingerichteter OpenSprinkler-Controller mit einer Firmware-Version 2.2.3 und später

## 3. Installation

### a. Installation des Moduls

Im [Module Store](https://www.symcon.de/service/dokumentation/komponenten/verwaltungskonsole/module-store/) ist das Modul unter dem Suchbegriff *OpenSprinkler* zu finden.<br>
Alternativ kann das Modul über [Module Control](https://www.symcon.de/service/dokumentation/modulreferenz/module-control/) unter Angabe der URL `https://github.com/demel42/IPSymconOpenSprinkler.git` installiert werden.

### b. Einrichtung in IPS

## 4. Funktionsreferenz

alle Funktionen sind über _RequestAction_ der jew. Variablen ansteuerbar

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
* Integer<br>
* Float<br>
* String<br>

## 6. Anhang

### GUIDs
- Modul: `{E91B516D-3191-FEFF-A392-14E3BEB0956E}`
- Instanzen:
  - OpenSprinkler: `{DFB042F3-B26F-81DC-E1DB-B2704A28AAC5}`
- Nachrichten:

### Quellen

## 7. Versions-Historie

- 1.0 @ 02.01.2025 13:48
  - Initiale Version
