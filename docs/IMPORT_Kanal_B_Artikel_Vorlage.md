# Kanal B — Artikel-Datei-Vorlage (Spec 13 · E1)

> **Richtung:** nur hinein. Food Alchemist ist Master — es gibt keinen Rückweg zum Lieferanten, keinen VK-Export, keine Rück-Synchronisation (Spec 13 §0).
> **Frequenz:** manuell, quartalsweise (E5). Datei ablegen, Command laufen lassen.
> **Stand:** Stufe **S1a** — der Artikel-Stamm. Preis, Nährwerte, Allergene, Zusatzstoffe und Lieferbedingungen folgen in S1b/S1c/S2; ihre Spalten dürfen **jetzt schon** in der Datei stehen (sie werden erkannt und im Bericht namentlich genannt, aber noch nicht geschrieben).

## 1. Aufruf

```bash
php artisan foodalchemist:import-articles --file=/pfad/katalog.csv --supplier=12 --team=6
```

Ohne `--apply` ist der Lauf ein **Trockenlauf** (Default) und schreibt nichts — die Ausgabe ist identisch zum scharfen Lauf, also eine echte Vorschau. Scharf:

```bash
php artisan foodalchemist:import-articles --file=/pfad/katalog.csv --supplier=12 --team=6 --apply
```

Vor dem ersten scharfen Lauf auf echten Daten: DB-Backup ziehen.

## 2. Datei-Format

| Punkt | Regel |
|---|---|
| Format | **CSV oder TSV**, UTF-8 (BOM erlaubt). Trennzeichen wird an der Kopfzeile erkannt (`;` `,` Tab `\|`). |
| xlsx | wird **nicht** gelesen — in der Tabellen-App als CSV (Trennzeichen `;`) exportieren. Ein xlsx-Reader wäre eine neue Composer-Abhängigkeit im Modul; das ist eine offene Entscheidung, keine Import-Frage. |
| Struktur | Eine Zeile je Artikel. Erste nicht-leere Zeile = Kopfzeile. Eine Datei = **ein** Lieferant (kommt aus `--supplier`, nicht aus einer Spalte). |
| Umfang | max. 20.000 Zeilen je Lauf (`MAX_ZEILEN`) — größere Kataloge splitten. |
| Zahlen | Deutsch oder englisch: `2,5` · `2.5` · `1.234,56`; `€`/`%`/Leerzeichen werden ignoriert. |
| Ja/Nein | `ja/j/yes/y/1/true/wahr/x` = ja · `nein/n/no/0/false/falsch/-` = nein · leer = *keine Angabe*. |
| **Leere Zelle** | heißt „steht nicht in der Datei", **nicht** „lösche den Wert". Ein Teil-Katalog überschreibt also keine gepflegten Felder. Wer einen Wert wirklich leeren will, macht das im Editor. |

## 3. Spalten

Header-Schreibweise ist egal (Groß/Klein, Umlaute, Punkte, Bindestriche, Leerzeichen werden ignoriert). Pflicht sind **Bezeichnung** und mindestens einer der Schlüssel (Artikel-Nr **oder** eine EAN).

| Spalte (Vorlage) | akzeptierte Synonyme | Typ | Ziel-Feld |
|---|---|---|---|
| **Artikel-Nr** | ArtNr, Artikelnummer, ArticleNumber | Text | `article_number` |
| **Bezeichnung** | Artikelbezeichnung, Name, Designation | Text | `designation` |
| Marketingname | Handelsname, Marketingbezeichnung | Text | `marketing_name` |
| Verkehrsbezeichnung | rechtliche Bezeichnung | Text | `regulated_name` |
| Marke | Brand | Text | `brand` |
| Hersteller | Produzent, Manufacturer | Text | `manufacturer` |
| Herkunft | Ursprung | Text | `origin` |
| Herkunftsland | Ursprungsland | Text | `origin_country` |
| Gebindeeinheit | Verpackungseinheit, VE | Text | `packaging_unit` |
| Bestelleinheit | BE | Text | `ordering_unit` |
| Gebinde je Bestelleinheit | VE je BE | Zahl | `qty_ordering_per_packaging` |
| Gebindemenge | Inhalt, Menge, Füllmenge | Zahl | `qty` |
| Einheit | Kalkulationseinheit, Mengeneinheit | `kg` \| `l` \| `Stk` | `unit_code` |
| EAN Gebinde | EAN, GTIN, EAN VE | Text | `ean_packaging` |
| EAN Bestelleinheit | EAN BE | Text | `ean_ordering` |
| MwSt | USt, Steuersatz | Zahl (%) | `vat` |
| Bio | Öko, Organic | ja/nein | `is_organic` |
| Bio-Kontrollnummer | Öko-Kontrollnummer | Text | `organic_control_number` |
| Vegan | | ja/nein | `is_vegan` |
| Vegetarisch | | ja/nein | `is_vegetarian` |
| Alkohol | alkoholhaltig | ja/nein | `is_alcohol` |
| Halal | | ja/nein | `is_halal` |
| GVO-frei | gentechnikfrei, ohne Gentechnik | ja/nein | `is_gmo_free` |
| Vorbestellung | vorbestellpflichtig | ja/nein | `is_preorder` |
| Vorbestelltage | Vorlaufzeit Tage, Vorbestellzeit | Zahl | `preorder_days` |
| Ausgelistet | eingestellt, inaktiv | ja/nein | `is_discontinued` |
| Zutatenliste | Zutaten | Text | `ingredients_supplier` |
| Zusatztext | Bemerkung, Hinweis | Text | `additional_text` |

**Einheit:** nur `kg`, `l`, `Stk` (tolerant: `Kilogramm`, `Liter`, `Stück`, `St`, `pce` …). Etwas anderes wird als Warnung gemeldet und das Feld bleibt leer — eine „ungefähre" Kalkulationseinheit ist die Preisfalle aus GL-03-A-2.

### Spalten für spätere Stufen

Diese dürfen in der Datei stehen; der Bericht nennt sie und sagt, welche Stufe sie schreibt:

| Spalte | Stufe |
|---|---|
| Preis, EK-Preis, Preis-Status, Preis-Notiz | **S1b** — Preis-Write + Recompute-Kette (E4) |
| `Nährwert…`, `Allergen…`, `Zusatzstoff…`, `Deklaration…` (Präfix) | **S1c** — `item_nutritionals` / `item_allergens` / `item_declarations` |
| Mindestbestellwert, Frei-Haus ab, Zahlungsziel, Rückvergütung | **S2** — Lieferbedingungen am Lieferanten (E3) |

Alles andere wird ignoriert und im Bericht als „nicht Teil der Vorlage" aufgeführt.

## 4. Wie zugeordnet wird (Upsert-Schlüssel, E2)

1. `(supplier_id, Artikel-Nr)` — der Primärweg.
2. Trifft das nichts, greift die **EAN** (Gebinde, dann Bestelleinheit) gegen beide EAN-Felder des Bestands. Das ist der Fall „Lieferant hat neu nummeriert" — die EAN ist dann die stabilere Identität, die neue Artikelnummer wird mitgeschrieben.
3. Kein Treffer → **neuer Artikel**, gehört dem Import-Team.

Ausdrücklich **nicht** `legacy_id` — das ist Necta-Erbe, frische Dateien haben es nicht.

**Mehrdeutigkeit ist ein Fehler, keine Wahl.** Trifft eine Artikelnummer oder EAN mehr als einen Bestandsartikel, wird die Zeile abgelehnt (es gibt heute keinen Unique-Index auf `(supplier_id, article_number)`; der aus E2 ist eine eigene Migration).

## 5. Team-Regel (D1)

Der Import schreibt in das Team aus `--team`. Trifft eine Zeile einen **geerbten** Artikel des Eltern-Teams, wird sie mit Grund **übersprungen** — nicht verändert (fremdes Eigentum) und auch nicht als team-lokale Kopie angelegt (das wäre der stille Doppel-Katalog). Solche Artikel pflegt das Besitzer-Team.

## 6. Wiederholung / Abbruch

Der Upsert ist idempotent: derselbe Lauf mit derselben Datei meldet beim zweiten Mal alles als „unverändert" und schreibt nichts. Ein abgebrochener Lauf wird deshalb einfach durch erneutes Ausführen fortgesetzt — es gibt keinen Zwischenzustand zu reparieren. Jeder scharfe Lauf hinterlässt eine Zeile in `foodalchemist_bulk_runs` (Typ `ingest`); darauf setzt S3 (`ingest.STATUS`) auf.

## 7. Beispiel

Siehe [kanal_b_artikel_beispiel.csv](kanal_b_artikel_beispiel.csv) — vier Zeilen mit den häufigsten Fällen (Vollzeile, nur Schlüssel + Bezeichnung, Zeile mit Preis-Spalte für S1b, Zeile mit unbekannter Einheit).
