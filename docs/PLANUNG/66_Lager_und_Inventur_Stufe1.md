# Spec 66 · Lager und Inventur — Stufe 1

Stand 2026-10-08 · Entscheid Dominique: Inventur ja, Lager im Food Alchemist, Stufe 1 bauen,
Stufe 2 später anhängen.

## Anlass

Einkauf ist als „Warenwirtschaft light" weit ausgebaut (Bestellung, Wareneingang,
Rechnungsprüfung, Reklamation). Lager gab es nur als Zugangsseite, eine Inventur gar nicht.
Der Ist-Wareneinsatz im Controlling ist deshalb nur „Einkauf ÷ Umsatz" — eine Perioden-Rechnung,
die ein voller Kühlraum am Monatsende verfälscht.

## Leitidee: periodisches Lager

Bestand entsteht aus **Wareneingang + Inventur**. Der Verbrauch ergibt sich rechnerisch:
**Anfangsbestand + Einkauf − Endbestand**. So rechnet Gastronomie ohnehin (wöchentlich/monatlich).
Laufende Entnahme-Buchungen aus der Produktion sind Stufe 2 — das Bewegungsjournal ist darauf
vorbereitet, Stufe 1 braucht sie nicht.

Abgrenzung: kein ERP. Keine Buchhaltung/OP, keine Stellplätze, keine Umlagerung zwischen vielen
Lagern, kein Pfandkonto. Bestand wird je **Grundprodukt** geführt (nur der FA weiß, dass drei
Lieferantenartikel dasselbe GP sind), ohne GP je Lieferantenartikel.

## Stufe 1

### 1. Wareneingang korrigieren
- Zugang = **gelieferte Gebinde × Gebinde-Inhalt** (vorher: anteiliger *Bedarf* — 3 × 5 kg
  geliefert ergab 7,3 kg Bestand).
- Basiseinheit aus der Gebinde-Einheit des Artikels: kg → g, l → ml, Stk → Stk. Unbekannte
  Einheit: keine Buchung (vorher stillschweigend „1 kg je Gebinde").
- Fehlmengen-Anzeige rechnet den Bedarf in der Basiseinheit (vorher kg statt g → „0 g",
  Ursache der Test-Quarantäne #795).
- Einkaufsjournal: gelieferte bzw. berechnete Menge statt bestellter Menge.

### 2. Inventur
- Inventur = Stichtag + Lagerort + Zählliste. Status **offen → gebucht**.
- Zählliste vorbelegt mit allem, was am Lagerort Bestand hat oder in den letzten 90 Tagen
  eingekauft wurde; Positionen lassen sich ergänzen.
- Je Position: Soll-Bestand (eingefroren beim Anlegen), gezählte Menge, Bewertungspreis je
  Basiseinheit (eingefroren beim Anlegen: aktueller EK des GP), Wert.
- **Buchen**: gezählte Positionen setzen den Bestand auf die gezählte Menge (Differenz als
  Bewegung `source = inventur`). Nicht gezählte Positionen bleiben unberührt (Hinweis).
- Zählliste als Druck/PDF.

### 3. Lagerseite `/lager`
Reiter Bestand (je GP und Lagerort, Menge, Wert), Bewegungen, Inventuren (Liste, anlegen,
zählen, buchen, drucken).

### 4. Einkauf je Grundprodukt
Am GP (Detailspalte und GP-Modal): eingekaufte Menge und Wert je Monat (letzte 12 Monate),
Lieferanten, aktueller Bestand. Quelle: Einkaufsjournal.

### 5. Ist-Wareneinsatz
Liegen zwei gebuchte Inventuren vor (Anfang ≤ Periodenbeginn, Ende im Zeitraum), rechnet das
Controlling **Verbrauch = Anfangsbestand + Einkauf zwischen den Stichtagen − Endbestand** und
nennt beide Stichtage. Sonst wie bisher (Perioden-Rechnung, mit Hinweis).

### 6. MCP
`inventory.GET` (Bestand), `inventory_counts.GET/POST/PUT/BOOK`, `gp_einkauf.GET`.

## Stufe 2 (später)
Entnahme durch die Produktion bei Fertigmeldung, Bestand im Bestellvorschlag abziehen,
Mindestbestand, manuelle Zu-/Abgänge (Schwund mit Grund), MHD/Charge und Lieferschein-Nr. am
Wareneingang, Umlagerung.
