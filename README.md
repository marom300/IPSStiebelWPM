# StiebelWPL – Stiebel Eltron WPL für IP-Symcon (ISG Modbus TCP)

Symcon-Modul zur Anbindung einer **Stiebel Eltron WPL-A AC** (Regler **WPM 3**) über das
**ISG web** per **Modbus TCP** – inkl. Deckenkühlung, Energiedaten, SG Ready und einem
HTML-Dashboard mit Anlagenschema (Vorlauf/Rücklauf direkt an den Leitungen ablesbar).

## Funktionsumfang

**Lesen (Poll, Standard alle 30 s):**
- Temperaturen: Außen, Raum (FEK/FE7), Heizkreis 1 (+ optional HK 2), Vorlauf/Rücklauf
  (Anlage und Wärmepumpe), Puffer, Warmwasser, Heißgas
- Drücke (Nieder-/Mittel-/Hochdruck), Volumenstrom
- Raumfeuchte, Taupunkt und berechnete **Taupunkt-Reserve** (Vorlauf − Taupunkt,
  wichtig bei Deckenkühlung)
- Kühlung: Ist/Soll Flächenkühlung
- Status bitcodiert: Verdichter, Heizbetrieb, Warmwasserbetrieb, **Kühlbetrieb**,
  Sommerbetrieb, Abtauen, HK-Pumpe, NHZ, EVU-Freigabe, Störung + Fehlernummer
- Energiedaten: Wärmemenge & Stromaufnahme (heute/gesamt, Heizen/WW), NHZ-Anteile,
  Laufzeiten Verdichter, berechnete **Arbeitszahlen (AZ/COP)**
- SG Ready Betriebszustand, Reglerkennung

**Schreiben (abschaltbar per Konfiguration):**
- Betriebsart (Bereitschaft/Programm/Komfort/ECO/Warmwasser/Notbetrieb)
- Heizen: Komfort-/ECO-Temperatur, Heizkurven-Steigung
- Warmwasser: Komfort-/ECO-Temperatur
- Kühlung: Vorlauf-Soll, Grenze Kühlen (Außentemperatur-Einschaltgrenze, Reg. 1516 –
  in der Stiebel-Doku als „Raumsolltemperatur“ geführt, wirkt bei der WPL-A AC aber
  als Grenze Kühlen), Hysterese (Flächenkühlung)
- SG Ready: aktiv, Eingang 1/2 (z. B. für PV-Überschusssteuerung → Zustand 3)

## Installation

1. Ordner `symcon-stiebel-wpl` auf den Symcon-Rechner kopieren, z. B. nach
   `C:\ProgramData\Symcon\modules\symcon-stiebel-wpl`
   (Linux: `/var/lib/symcon/modules/symcon-stiebel-wpl`).
   Alternativ in der Konsole: **Kern Instanzen → Module Control → Hinzufügen** und den
   lokalen Pfad bzw. die Git-URL eintragen.
2. In Symcon: **Instanz hinzufügen → Stiebel Eltron → StiebelWPL**.
3. IP-Adresse des ISG web eintragen (Port 502 bleibt Standard), speichern.
4. Button **„Verbindung testen“** – sollte Regler „WPM 3“ und die Außentemperatur melden.
   Der nötige Register-Adressoffset (0 oder −1, je nach ISG-Firmware) wird automatisch
   erkannt.

### Voraussetzung am ISG
Die Modbus-TCP-Erweiterung muss auf dem ISG web freigeschaltet sein
(kostenpflichtige Software-Erweiterung von Stiebel Eltron, Best.-Nr. 316303).
Slave-ID ist fix 1, Port 502.

## Dashboard

Bei aktivierter Option registriert das Modul den WebHook **`/hook/stiebelwpl`**:

```
http://<Symcon-IP>:3777/hook/stiebelwpl
```

- SVG-Anlagenschema: Außeneinheit, Puffer, Warmwasserspeicher, Heizkreis,
  Deckenkühlung – Vorlauf-/Rücklauftemperatur und Volumenstrom direkt an den Leitungen
- Rohrfarben je nach Betrieb (Heizen = orange/blau, Kühlen = blau), Flussanimation
- Taupunkt-Ampel für die Deckenkühlung (grün ≥ 2 K Reserve, gelb ≥ 1 K, rot darunter)
- Kacheln: Raumklima, Warmwasser, Kühlung, Heizung, Energie (heute/gesamt inkl.
  Arbeitszahl), Laufzeiten, SG Ready
- Sollwerte und Betriebsart direkt im Dashboard änderbar (nur wenn Schreibzugriff aktiv);
  optional mit **PIN-Abfrage** (in der Instanz konfigurierbar, PIN wird pro Sitzung gemerkt)
- **Zwei Seiten** über Umschalter im Header (Zustand wird pro Gerät gemerkt):
  „Anlage“ = Schema + Raumklima/Warmwasser/Kühlung/Heizung,
  „Details“ = Energie heute/gesamt, Laufzeiten, SG Ready – beide Seiten passen auf ein
  Tablet ohne Scrollen
- Werte, die das ISG als „nicht verfügbar“ meldet, werden als „–“ angezeigt
- Aktualisierung alle 10 s (liest die Symcon-Variablen, kein zusätzlicher Modbus-Verkehr)

Das Dashboard eignet sich unverändert als WebView-Kachel in IPSView / im WebFront.

## Hinweise / Grenzen der ISG-Modbus-Schnittstelle

- **Kühlen EIN/AUS** und die Kühlart (Flächen-/Gebläsekühlung) sind über Modbus
  **nicht schaltbar** – das geht nur in der Servicewelt. Per Modbus änderbar sind die
  Kühl-Sollwerte; die Kühlung selbst startet automatisch oberhalb von „Grenze Kühlen“.
- Inverter-Details der Servicewelt (Verdichterdrehzahl, Ölsumpftemperatur, Spannung,
  Lüfterleistung, Verdichterstarts, Abtau-Laufzeit) stellt das ISG per Modbus nicht bereit.
- Nicht verfügbare Register liefern den Ersatzwert 0x8000 und werden übersprungen
  (Variable behält den letzten Wert).

## Archivierung

Bei aktivierter Option „Alle Werte automatisch archivieren“ (Standard: an) meldet das Modul
sämtliche Zahlen- und Statusvariablen selbstständig beim Archive Control an –
es ist nichts weiter einzurichten.

## Diagnose

Der Button **„Register-Diagnose“** in der Instanzkonfiguration liest alle Registerblöcke
und zeigt die Rohwerte an (n/v = vom ISG als nicht verfügbar gemeldet). Hilfreich, wenn
einzelne Werte fehlen – z. B. um zu sehen, unter welchem Register die Raumtemperatur
bei der eigenen Anlage geliefert wird.

## Projektstruktur

```
symcon-stiebel-wpl/
├── library.json
└── StiebelWPL/
    ├── module.json       Modul-Definition (Prefix SWPL)
    ├── module.php        Modbus-TCP-Client, Variablen, WebHook
    ├── form.json         Instanzkonfiguration
    ├── locale.json
    └── dashboard.html    Dashboard mit Anlagenschema
```

Registerbasis: Stiebel Eltron „Software-Dokumentation Modbus TCP/IP für ISG web“
(Blöcke 1–6, Geräteklasse WPM 3).
