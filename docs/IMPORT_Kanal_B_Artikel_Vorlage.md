# Kanal B — Artikel-Datei-Vorlage (Spec 13 · E1)

> **Richtung:** nur hinein. Food Alchemist ist Master — es gibt keinen Rückweg zum Lieferanten, keinen VK-Export, keine Rück-Synchronisation (Spec 13 §0).
> **Frequenz:** manuell, quartalsweise (E5). Datei ablegen, Command laufen lassen.
> **Stand:** Stufe **S1a + S1b** — Artikel-Stamm **und Preis**, inklusive Post-Import-Kette (Abschnitt 7). Nährwerte, Allergene, Zusatzstoffe (S1c) und Lieferbedingungen (S2) folgen; ihre Spalten dürfen **jetzt schon** in der Datei stehen (sie werden erkannt und im Bericht namentlich genannt, aber noch nicht geschrieben).

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
| **Preis** (S1b) | EK-Preis, EK, Einkaufspreis, Nettopreis | Zahl (€ netto) | neue Zeile in `foodalchemist_prices` |
| Preis-Status (S1b) | Preisart | `Standard` \| `Aktion` | `status` der Preis-Zeile (`0`/`2`) |

**Einheit:** nur `kg`, `l`, `Stk` (tolerant: `Kilogramm`, `Liter`, `Stück`, `St`, `pce` …). Etwas anderes wird als Warnung gemeldet und das Feld bleibt leer — eine „ungefähre" Kalkulationseinheit ist die Preisfalle aus GL-03-A-2.

**Preis** (netto, ohne MwSt). Drei Werte sind **Zeilen-Fehler**, keine Preise — die Zeile bleibt sonst verwertbar, der Artikel-Stamm wird geschrieben, nur der EK nicht:

| Wert | warum abgelehnt |
|---|---|
| `0,00` | wäre nach GL-11 ein **aktiver** Standard-EK und zöge die Kosten jedes nutzenden Rezepts auf null — ein grün gemeldeter Falschpreis. Kein Preis bekannt ⇒ Zelle **leer** lassen. |
| negativ | Service-Zuschlag (GL-11 I5) — legt der Import nicht per Masse an. |
| Text (`auf Anfrage`) | keine Zahl. |

**Preis-Status** ohne Preis-Spalte bricht den Lauf ab (ein Status ohne Betrag ergibt keine Preis-Zeile). Ohne Status-Spalte gilt `Standard`.

### Spalten für spätere Stufen

Diese dürfen in der Datei stehen; der Bericht nennt sie und sagt, welche Stufe sie schreibt:

| Spalte | Stufe |
|---|---|
| `Nährwert…`, `Allergen…`, `Zusatzstoff…`, `Deklaration…` (Präfix) | **S1c** — `item_nutritionals` / `item_allergens` / `item_declarations` |
| Mindestbestellwert, Frei-Haus ab, Zahlungsziel, Rückvergütung | **S2** — Lieferbedingungen am Lieferanten (E3) |

**Preis-Notiz** wird erkannt und als „ohne Ziel-Feld" gemeldet: Preis-Zeilen tragen keine Notiz. Alles andere wird ignoriert und im Bericht als „nicht Teil der Vorlage" aufgeführt.

## 4. Wie zugeordnet wird (Upsert-Schlüssel, E2)

1. `(supplier_id, Artikel-Nr)` — der Primärweg.
2. Trifft das nichts, greift die **EAN** (Gebinde, dann Bestelleinheit) gegen beide EAN-Felder des Bestands. Das ist der Fall „Lieferant hat neu nummeriert" — die EAN ist dann die stabilere Identität, die neue Artikelnummer wird mitgeschrieben.
3. Kein Treffer → **neuer Artikel**, gehört dem Import-Team.

Ausdrücklich **nicht** `legacy_id` — das ist Necta-Erbe, frische Dateien haben es nicht.

**Mehrdeutigkeit ist ein Fehler, keine Wahl.** Trifft eine Artikelnummer oder EAN mehr als einen Bestandsartikel, wird die Zeile abgelehnt (es gibt heute keinen Unique-Index auf `(supplier_id, article_number)`; der aus E2 ist eine eigene Migration).

## 5. Team-Regel (D1)

Der Import schreibt in das Team aus `--team`. Trifft eine Zeile einen **geerbten** Artikel des Eltern-Teams, wird sie mit Grund **übersprungen** — nicht verändert (fremdes Eigentum) und auch nicht als team-lokale Kopie angelegt (das wäre der stille Doppel-Katalog). Solche Artikel pflegt das Besitzer-Team.

## 6. Wiederholung / Abbruch

Der Upsert ist idempotent: derselbe Lauf mit derselben Datei meldet beim zweiten Mal alles als „unverändert" und schreibt nichts — **auch keine Preis-Zeile**. Das ist beim Preis kein Nebeneffekt, sondern Absicht: `foodalchemist_prices` ist append-only, ein Schreiben je Lauf machte die Historie nach drei Quartalen unlesbar und ließe den Preis-Trend „Δ 0 %" als jüngste Generation lesen. Ein abgebrochener Lauf wird also einfach durch erneutes Ausführen fortgesetzt — es gibt keinen Zwischenzustand zu reparieren.

Jeder scharfe Lauf hinterlässt eine Zeile in `foodalchemist_bulk_runs` (Typ `ingest`); eine Zeile mit Preis-Fehler zählt dort als `failed`, auch wenn ihr Artikel-Stamm geschrieben wurde („EK nicht angekommen" ist kein Teilerfolg). Darauf setzt S3 (`ingest.STATUS`) auf.

## 7. Post-Import-Kette (E4) — was nach dem Preis passiert

Ein neuer EK, der nur in der Preistabelle liegt, ist die **stille Drift**: Kalkulation, Marge und Cockpit zeigten weiter den alten Stand. Darum läuft nach dem Import automatisch:

1. **Betroffene GPs** — über die LA↔GP-Struktur (nicht nur über den Lead-LA: die Kosten-Kaskade nimmt den Lead als *bevorzugten* Kandidaten und fällt sonst auf den Mittelwert aller aktiven LAs zurück, ein Nicht-Lead-Preis bewegt die Kosten also ebenfalls).
2. **`recomputeAndPropagate`** je nutzendem Rezept — samt aller transitiven Eltern (Basisrezept → Gericht → Paket).
3. **`preisSprungMargeImpact`** einmal für das Team — der bestehende R2.1-Detektor sucht sich die frischen Änderungen selbst (Lookback + Dedup je neuem Preis). Der Importer baut **keine** zweite Schwellen-Logik.

Die Kette läuft **einmal am Ende** über die deduplizierte Menge, nicht je Zeile — ein Katalog rührt dieselben Eltern-Rezepte sonst hundertfach an. Der Trockenlauf zeigt sie als Vorschau („x GP · y Rezept(e) würden neu berechnet"), ohne zu rechnen.

**Obergrenze:** 1.000 Rezepte je Lauf (`MAX_RECOMPUTE`). Wird sie erreicht, sagt der Bericht das ausdrücklich; den Rest zieht `php artisan foodalchemist:recompute` nach.

## 8. Beispiel

Siehe [kanal_b_artikel_beispiel.csv](kanal_b_artikel_beispiel.csv) — fünf Zeilen mit den häufigsten Fällen (Vollzeile mit Preis, nur Schlüssel + Bezeichnung, Aktionspreis, unbekannte Einheit, Zeile ohne Preis).
