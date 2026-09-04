# Spec 44 — Report-Blatt: PDF, Druck und Kaskaden-Lesbarkeit

> **Tracking:** Office Dev-Package 23, Features-Board.

**Status:** gebaut auf `feat/report-print-layout`, Suite steht aus, nicht deployt.

## Anlass

Rückmeldung aus der Praxis am Produktionsblatt „Konfitüre: Kürbis-Apfel" und einem Amuse
mit drei Basisrezepten. Drei Beschwerden, dahinter fünf Befunde:

1. **„kommt kein Bild mit in die PDF"** — und zwar nie. `ReportExportService::photoDataUri()`
   suchte die Datei ausschließlich auf dem `public`-Disk. Schrittfotos laufen aber über
   `ContextFile`, und die liegt auf dem Default-Disk (demo-`.env`: `local` bzw. `hetzner`,
   nie `public`). Ergebnis immer `null`, Fallback `$foto->url()` — eine signierte
   Core-Route mit 60-Minuten-TTL, die DomPDF grundsätzlich nicht lädt. Jeder andere
   Bildpfad im Modul geht über `FoodAlchemistMediaService::dataUri()`; nur diese Methode
   war ein Eigenbau ohne ContextFile-Kette.
2. **„es wird links zu sehr abgeschnitten"** — `@page` hatte im HTML-Modus `margin: 0` und
   kein `size`, dazu `.doc { padding: 0 }` im Druck. Chrome druckte auf dem
   Locale-Default-Papier (Letter) randlos bis in den nicht druckbaren Bereich.
   Zusätzlich lag `band-bottom` im DOM **vor** `<main>`: „Erstellt mit Food Alchemist"
   war die erste Zeile oben auf dem Blatt.
3. **„es muss deutlicher werden, dass das ein Basisrezept ist und dazugehört"** — im PDF
   war die Einrückung der Kaskade komplett abgeschaltet (`margin-left: 0` im PDF-Zweig),
   der Typ stand nur als grauer Kleintext neben der ID. Alle Ebenen standen flach
   untereinander; ein Basisrezept las sich wie ein eigenständiges Rezept.
4. **„preis pro einheit fehlt"** — die Preisspalte mischte zwei Bedeutungen: bei GP den
   Kollipreis des Lead-LA, bei Basisrezepten den EK des **ganzen** Ansatzes. Der
   Zeilenwert (was die Menge im Ansatz kostet) fehlte ganz.
5. **„wäre es nicht sinnvoll die Bilder nebeneinander zu haben"** — ein Kasten je Schritt
   mit einem Bild links und 60 % Leerraum rechts war der größte Papierfresser.

## Umsetzung

**Kaskaden-Identität.** Adressen `K1` / `K3.2` (Zählung nach Zutaten-Position), Typ-Chip
`GERICHT` / `KOMPONENTE · BASISREZEPT`, Herkunftszeile „Komponente 3 von 4 in *X* ·
Einsatz dort: 0,005 kg", linker Farbbalken je Tiefe. In der Zutatenzeile steht die
Adresse als Verweis auf den Block weiter unten. Das Badge darf **nicht** in ein
`<h2>/<h3>`: DomPDF legt `inline-block` dort über den Titeltext — es sitzt in einer
Kicker-Zeile über dem Titel.

**Preise.** Zwei Spalten statt einer: `€ / Einheit` (Bezugspreis, auf €/kg bzw. €/l
normalisiert) und `EK-Anteil` (Zeilenwert), plus Σ-Zeile gegen „EK gesamt". Quelle ist
`RecipeRecomputeService::zeilenKostenUndMassen()` — dieselbe T3-Kaskade, die
`ek_total_eur` erzeugt, also keine zweite Preiswahrheit im Report. **Ungerundet** holen:
aus dem auf 2 Stellen gerundeten Anteil fällt der €/kg-Preis bei Amuse-Mengen auf 0
(0,008 kg × 0,18 €/kg = 0,0014 € → 0,00). Anzeige unter 1 € mit drei Nachkommastellen.
Der Kollipreis wandert samt Gebinde in die Lieferantenspalte.

**Papier.** Fotos als eine Reihe unter der Anleitung (Schritt-Nummer in der Caption),
Schritte dicht ohne Kasten, leere „—"-Kacheln unterdrückt, Komponenten mit
Kennzahlen-Zeile statt Kachel-Gitter, Titel nicht mehr doppelt (Dokumentkopf **und**
Knoten). Gemessen am Testgericht: **PDF 9 → 5 Seiten, Browser-Druck 6 → 4.**

**DomPDF-Leitplanken (getestet, nicht vermutet).**

- `<col style="width:…">` wird **ignoriert** — alle Spalten kamen gleich breit heraus.
  Breiten gehören auf die `<th>` der Kopfzeile, dort greifen sie.
- `position: fixed` für Kopf-/Fußband funktioniert im **PDF**, im **Browser-Druck** legt
  Chrome die Bänder über den Satz statt in den Seitenrand. Deshalb bleiben sie dort im
  Fluss; Seitenzahl und Titel liefert der Druckdialog selbst.
- `page-break-inside: avoid` an der Zutaten-Tabelle schob sie komplett auf die Folgeseite
  und ließ ~4 cm Blatt leer. Der wiederholte `thead` (`display: table-header-group`)
  trägt den Bruch besser.

**Geteiltes CSS.** Die Knoten-Styles liegen jetzt in
`partials/report-node-css.blade.php`. `speiseplan.blade.php` und `speisekarte.blade.php`
binden dasselbe Rezept-Partial für ihren Kaskaden-Anhang ein, kannten dessen Klassen
aber nie — Fotos liefen dort ohne Breiten-Deckel.

## Berührte Dateien

- `src/Services/ReportExportService.php` — `photoDataUri()` über MediaService,
  Kaskaden-Adressen + Eltern-Kontext in `recipeNode()`, Zeilen-EK und
  `preisProEinsatzEinheit()` in `ingredientNode()`, `unit`-Select um
  `dimension`/`default_in_g`/`default_in_ml` erweitert.
- `resources/views/dokumente/report.blade.php` — `@page`, Print-Block, Footer hinter
  `<main>`, `istDokumentKopf`.
- `resources/views/dokumente/partials/report-recipe-node.blade.php` — Kopf, Preisspalten,
  Anleitung, Kennzahlen-Zeile.
- `resources/views/dokumente/partials/report-node-css.blade.php` — neu.
- `resources/views/dokumente/speiseplan.blade.php`, `speisekarte.blade.php` — CSS-Include.
- `docs/rezepte.md` — Abschnitt „Das Report-Blatt".

## Offen

- Volle Suite (lief zum Commit-Zeitpunkt noch nicht durch).
- Abnahme im Browser an echten Daten, danach Merge nach `main` und demo-Deploy.
  Migrationen sind **keine** nötig — reine View-/Service-Änderung.
- Nebenbefund, unabhängig von dieser Runde: in der demo-`.env` steht `FILESYSTEM_DISK`
  dreimal (`local`, `hetzner`, `local`). Wackelstelle für alles, was Dateien ablegt.

## Cross-Refs

- Spec 27 (Step-by-Step-Zubereitung) — Foto-Pivot, aus dem der Fotostreifen liest.
- Spec 42 (Foodbook = reine Ausgabe), Spec 43 (Präsentation) — teilen das Report-Blatt.
