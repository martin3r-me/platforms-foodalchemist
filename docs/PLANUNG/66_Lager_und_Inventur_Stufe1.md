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

## Stufe 1b · Lager digital einrichten (2026-10-08)

Anlass: Vergleich mit necta. necta filtert die Inventur nach Lager, Produkt und **Stellplatz** und
zählt in der „Anbruchseinheit" (erste Verpackungsstufe). Entscheid Dominique: so bauen, plus das, was
FA durch Zustand und Warengruppe am Grundprodukt besser kann.

### 1b.1 Stellplätze
`foodalchemist_storage_bins`: je Lagerort, Name, **Zone** (kuehl | tk | trocken | getraenke |
sonstig) und **Laufweg-Reihenfolge** (`sort_order`). Löschen nimmt den Grundprodukten den Stammplatz.

### 1b.2 Stammplatz je Grundprodukt
`foodalchemist_storage_bin_items`: ein Stellplatz je (Team, Lagerort, GP). Die Zählliste übernimmt ihn
beim Anlegen (`count_lines.storage_bin_id`) und sortiert nach Laufweg, ohne Stellplatz zuletzt.

### 1b.3 Automatisch einsortieren
Zone aus Zustand (§9) und Warengruppe (§3): TK → tk; WG 15 → getraenke (sonst trocken); frisch +
WG 01–06/13/14 → kuehl; frisch sonst → trocken; trocken/konserviert → trocken; ohne Zustand → kein
Vorschlag. Ziel ist der erste aktive Stellplatz der Zone im Laufweg. Übernimmt nur Grundprodukte
OHNE Stammplatz, Handzuordnungen bleiben (`source` = manuell | vorschlag).

### 1b.4 Zählen in Gebinden
Schnappschuss beim Anlegen aus dem Lead-Artikel: `pack_label` + `pack_units` (Karton/Kiste =
`qty_ordering_per_packaging` Einheiten, nur wenn > 1), `unit_label` + `unit_base` (Einheit =
`qty` × kg/l → g/ml). Kein Gebinde, wenn die Bestelleinheit selbst ein Maß ist (kg, l …) oder die
Artikel-Einheit nicht zur Zeile passt. Gezählt: Kartons + Einheiten + lose (kg/l/Stk); Summe in
`qty_counted`, Aufteilung in `counted_packs/units/loose`. Freitext-Menge setzt die Aufteilung zurück.
Lokal geprüft: rund 42.000 Lieferantenartikel haben eine echte Karton-Stufe.

### 1b.5 Filter
Bestand, Zählliste und Einrichten teilen eine Filterleiste: Suche, Stellplatz (auch „ohne"), Zustand,
Warengruppe, Lieferant (Bestand), Status (noch nicht gezählt | gezählt | Differenz > 10 %),
ohne Preis, Ladenhüter (Bestand: 90 Tage keine Bewegung). Zählliste druckbar je Stellplatz
(`?stellplatz=ID|ohne`).

### 1b.6 „Nicht gezählt = 0"
Option beim Buchen (wie necta „Lager leeren"); an der Inventur festgehalten (`uncounted_zeroed`).

### 1b.7 MCP
`storage_bins.GET/POST/PUT/DELETE/ASSIGN` (ASSIGN mit `automatisch=true` für den Vorschlag);
`inventory_counts.GET` mit Stellplatz/Gebinde/Filter, `.PUT` mit `kartons/einheiten/lose`,
`.BOOK` mit `nicht_gezaehlt_null`; `inventory.GET` mit den Filtern.

Nicht in 1b: Scannen (EAN nur bei ~8–9 % der Artikel), mehrere Stellplätze je Grundprodukt.

## Stufe 2 (später)
Entnahme durch die Produktion bei Fertigmeldung, Bestand im Bestellvorschlag abziehen,
Mindestbestand, manuelle Zu-/Abgänge (Schwund mit Grund), MHD/Charge und Lieferschein-Nr. am
Wareneingang, Umlagerung.
