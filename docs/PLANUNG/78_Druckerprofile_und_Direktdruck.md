# Spec 78 · Druckerprofile und Direktdruck für Etiketten

Stand 2026-10-08 · Wunsch Dominique: Drucker in den Einstellungen anlegen. Wenn wir künftig einen Etikettendrucker mit anbieten, sollen Kompatibilität und Einrichtung sauber geregelt sein.

## Stufe 1 · Druckerprofile (umgesetzt)
- **Einstellungen → Etiketten → Drucker:** Name, Typ (A4-Bogen | Etikettendrucker/Rolle), Modell aus der Kompatibilitätsliste, Etikettengröße, Feinkorrektur der Ränder (Versatz X/Y in mm), Betrieb (optional) und Arbeitsplatz (Küche | Lager | Büro).
  - Für jeden Arbeitsplatz gibt es höchstens einen Standarddrucker.
- **Kompatibilitätsliste** (`DruckerService::MODELLE`): Modellgruppen mit vorbelegtem Format.
  - Brother QL (Rolle 62 mm)
  - Zebra ZD (4-Zoll-Rolle)
  - DYMO LabelWriter
  - A4-Bürodrucker (Bögen 24/40)
  - „Eigenes Format" (Breite × Höhe frei)
- **Etikettenvorlage:** wählt optional einen **Drucker**. Ist einer gewählt, kommen Format und Ränder vom Drucker, nicht mehr aus der Vorlage. Ein Profil für ein Modell, das wir anbieten, ist damit sofort richtig eingestellt.
- **Gedruckt wird weiter über den Druckdialog des Browsers.** Das geht mit jedem Drucker, braucht aber einen Klick im Dialog. Ein Hinweis am Drucker erklärt die Einstellung „Ränder: keine, Maßstab 100 %".
- **Daten:** `foodalchemist_printers` (Migration `2026_10_09_200300`) und `label_templates.printer_id`.

## Stufe 2 · Direktdruck ohne Dialog (Plan, vor dem Bau prüfen)
Ein Browser kann nicht ohne Dialog auf einen Drucker im Küchennetz drucken. Dafür braucht es eine Brücke. Zwei Wege sind gängig:

| | QZ Tray (lokal) | PrintNode (Cloud) |
|---|---|---|
| Prinzip | kleines Java-Programm am Küchen-PC, öffnet einen lokalen WebSocket. Die Webseite schickt den Druck direkt dorthin. | Client am PC verbindet sich mit dem PrintNode-Dienst. Unser Server schickt den Auftrag per JSON-API, der Client druckt sofort. |
| Formate | PDF, HTML, ZPL/EPL (Zebra), ESC/POS u. a. | PDF per URL (Dokument geht nicht über deren Server) oder Base64 |
| Druck aus | dem Browser am selben Gerät | dem Server, also auch von MCP/KI oder vom Tablet aus |
| Für uns | gut für den Wandmonitor am Küchen-PC | gut für ein Hardware-Angebot, weil zentral steuerbar |

**Prüfschritte vor Stufe 2:**
1. Modell festlegen und testen: Brother QL-820NWB (LAN/WLAN), Zebra ZD421 (ZPL) oder DYMO LabelWriter 550. Bei DYMO prüfen, ob nur Original-Etiketten laufen.
2. Brücke wählen: Lizenz und Kosten von QZ Tray bzw. PrintNode, Datenschutz/AVV, Betrieb am Küchen-PC.
3. Etikett als PDF in exakter Größe (DomPDF) pro Modell testen, ZPL nur falls nötig.
4. Danach: Drucker-Profil bekommt „Verbindung: Browser-Dialog | QZ Tray | PrintNode (Drucker-ID)". „Etikett drucken" am Wandmonitor druckt dann ohne Dialog.

Quellen: qz.io (QZ Tray), printnode.com/docs (PrintNode), Herstellerangaben Brother QL-820NWB / Zebra ZD421.
