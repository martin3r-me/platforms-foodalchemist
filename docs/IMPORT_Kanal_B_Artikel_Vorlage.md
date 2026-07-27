# Kanal B — Artikel-Datei-Vorlage (Spec 13 · E1)

> **Richtung:** nur hinein. Food Alchemist ist Master — es gibt keinen Rückweg zum Lieferanten, keinen VK-Export, keine Rück-Synchronisation (Spec 13 §0).
> **Frequenz:** manuell, quartalsweise (E5). Datei ablegen, Command laufen lassen — oder über MCP auslösen (Abschnitt 1).
> **Stand:** Stufe **S1a + S1b + S1c + S2** — die Vorlage wird **vollständig** geschrieben: Artikel-Stamm, **Preis**, die drei **Detail-Blöcke** (Nährwerte / Allergene / Zusatzstoffe) und die **Lieferbedingungen** am Lieferanten, inklusive Post-Import-Kette (Abschnitt 7). Was der Reader nicht kennt, wird im Bericht namentlich genannt statt still verschluckt.

## 1. Aufruf

```bash
php artisan foodalchemist:import-articles --file=/pfad/katalog.csv --supplier=12 --team=6
```

Ohne `--apply` ist der Lauf ein **Trockenlauf** (Default) und schreibt nichts — die Ausgabe ist identisch zum scharfen Lauf, also eine echte Vorschau. Scharf:

```bash
php artisan foodalchemist:import-articles --file=/pfad/katalog.csv --supplier=12 --team=6 --apply
```

Vor dem ersten scharfen Lauf auf echten Daten: DB-Backup ziehen.

### Auslösung über MCP (S3b) — `foodalchemist.ingest.IMPORT`

Derselbe Import, anderer Auslöser. Geschrieben wird weiterhin nur von `FileArticleImportService` — das Tool ist die **Auslösung**, kein zweiter Import-Pfad (DoD „Bulk bleibt artisan").

| Punkt | Regel |
|---|---|
| **Ablage-Ordner** | `storage/app/foodalchemist/import/`. Der Parameter `datei` ist ein **reiner Dateiname**, kein Pfad — Verzeichnis-Anteile, `..`, absolute Pfade, Punkt-Dateien und fremde Endungen werden abgelehnt, ebenso ein Symlink, der aus dem Ordner heraus zeigt. Der Ordner ist absichtlich **nicht konfigurierbar**: ein Tool, das einen freien Pfad annimmt, ist ein Lese-Zugriff auf das Server-Dateisystem. |
| **Ohne `datei`** | listet das Tool, was im Ordner liegt (Name, Größe, Änderungsdatum, geschätzte Zeilenzahl). Damit muss niemand Dateinamen raten. |
| **`apply=false`** (Default) | Trockenlauf, synchron, schreibt nichts — auch keine Lauf-Zeile (ein Trockenlauf ist kein Vorgang, sondern eine Frage). Grenze: **2.000 Zeilen** (`MAX_VORSCHAU_ZEILEN`); größere Dateien prüft man mit dem Kommando oben, dort gilt nur `MAX_ZEILEN`. |
| **`apply=true`** | stellt scharf und reiht einen **Job** ein (`ImportArticlesJob`, Timeout 900 s, `tries=1`) — der Lauf kann bis zu 1.000 Rezept-Ketten neu rechnen und gehört nicht in einen synchronen Aufruf. Zurück kommt die `run_id`; Fortschritt und Ergebnis liest `foodalchemist.ingest.STATUS` (Block `laeufe`). Stirbt der Job, steht der Lauf auf `failed` statt für immer auf `running`. |
| **Lieferant** | `supplier_id` ist Pflicht, sobald `datei` gesetzt ist (die Datei enthält nur Artikelnummern, nicht ihren Lieferanten) und muss in der Team-Kette sichtbar sein. Geschrieben wird in das Team des Aufruf-Kontexts. |
| **Kein Lauf-Lock** | ein offener `ingest`-Lauf sperrt nichts, er wird nur als Hinweis mitgegeben (`laufende_laeufe`). Eine Sperre auf `running` wäre im Fehlerfall eine Dauer-Sperre — `bulk_runs` kennt kein Ende ohne Erfolg. |

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

### Detail-Blöcke (S1c) — Nährwerte, Allergene, Zusatzstoffe

Diese Spalten heißen **`<Präfix> <Wert>`**; der Präfix wählt den Block, der Rest den Wert. Groß/Klein, Umlaute, Punkte und Bindestriche sind wie überall egal — `Nährwert kcal`, `Naehrwert-kcal` und `NÄHRWERT KCAL` sind dieselbe Spalte. Erlaubt sind nur die Präfixe unten, jeweils auch im Plural.

| Präfix | Ziel-Tabelle | Werte |
|---|---|---|
| `Nährwert …` / `Nährwerte …` | `item_nutritionals` (je **100 g**) | Zahl |
| `Allergen …` / `Allergene …` | `item_allergens` | `ja` \| `Spuren` \| `nein` \| `unbekannt` |
| `Zusatzstoff …` / `Deklaration …` | `item_declarations` | `ja` \| `nein` \| `unbekannt` |

**Nährwerte** — geschrieben werden die **8 Kernwerte**, dieselben, die auch das Artikel-Modal pflegt:

| Spalten-Rest | akzeptierte Synonyme | Ziel |
|---|---|---|
| kcal | Energie, Brennwert | `energy_kcal` |
| kJ | Energie kJ | `energy_kj` |
| Eiweiß | Protein | `protein` |
| Fett | | `fat` |
| gesättigte Fettsäuren | davon ges. Fettsäuren | `saturated_fat` |
| Kohlenhydrate | KH | `carbs_absorbable` |
| Zucker | davon Zucker | `sugar` |
| Natrium | | `sodium` (mg) |
| **Salz** | Salt | wird nach GL-08 in Natrium umgerechnet: Natrium (mg) = Salz (g) × 400 |

`Nährwert Salz` ist der Wert, den echte Datenblätter tragen — die Tabelle kennt nur Natrium, darum die Umrechnung. Stehen **beide** in einer Zeile, gewinnt Natrium (der ungerechnete Wert) und Salz wird als Warnung gemeldet. Alle übrigen BLS-Spalten (Ballaststoffe, Mineralstoffe, Aminosäuren …) sind **kein** Import-Ziel; eine solche Spalte wird als „Nährwert-Spalte ohne Ziel" gemeldet, nicht als Tippfehler.

**Allergene** (14 EU) — Spalten-Rest: Gluten (glutenhaltiges Getreide), Krebstiere, Eier, Fisch, Erdnüsse, Soja, Milch, Schalenfrüchte (Nüsse), Sellerie, Senf, Sesam, Sulfite (Schwefeldioxid), Lupine, Weichtiere.
Werte: `ja`/`x`/`enthalten` → **enthalten** · `Spuren`/`kann Spuren enthalten` → **Spuren** · `nein`/`-`/`frei` → **nicht enthalten** · `unbekannt`/`k.A.` → **unbekannt**.

**Zusatzstoffe / Deklarationen** (18 LMIV) — Spalten-Rest: Farbstoff, Konservierungsstoff, Antioxidationsmittel, Geschmacksverstärker, geschwefelt, geschwärzt, gewachst, Phosphat, Süßungsmittel, Phenylalanin, abführend, Schutzatmosphäre, koffeinhaltig, Milcheiweiß, Chinin, Taurin, Aufmerksamkeit Kinder, Zuckerarten und Süßungsmittel.

Drei Regeln für alle drei Blöcke:

1. **Teil-Datei ergänzt, sie ersetzt nicht.** Eine Datei mit nur `Allergen Milch` setzt die übrigen 13 Werte **nicht** zurück — der Import mischt mit dem Ist-Stand. (Das Artikel-Modal dagegen ersetzt voll, weil sein Formular alle Werte postet.)
2. **Leere Zelle ändert nichts, ein hingeschriebenes `unbekannt` schon.** Die leere Zelle heißt „steht nicht in der Datei"; `unbekannt` ist eine Aussage des Lieferanten und wird übernommen.
3. **Unlesbare Werte sind Warnungen, keine Zeilen-Fehler** — anders als beim Preis. Ein nicht gesetzter Allergen-Wert bleibt `unbekannt` und damit nach GL-01 auf der konservativen Seite; ein nicht gesetzter Preis wäre dagegen ein fehlender EK.

Zeilen aus dem Datei-Import tragen die Lineage `source = datei` (das Artikel-Modal zeigt sie als Quelle an) — nicht `manual` und nicht leer: leer steht im Bestand für den alten Necta-Bulk-Import.

### Lieferbedingungen (S2) — sie gelten dem Lieferanten, nicht der Zeile

Vier Spalten, die **nicht** am Artikel landen, sondern an `foodalchemist_suppliers` (E3, geteilte Migration mit R9). Geschrieben wird über denselben Weg wie das Konditionen-Tab im Lieferanten-Modal (`SupplierService::updateConditions`).

| Spalte | Ziel | Zulässig | Beispiel |
|---|---|---|---|
| `Mindestbestellwert` | `min_order_value` | Betrag ≥ 0 (netto) | `250,00` |
| `Frei Haus ab` | `free_shipping_threshold` | Betrag ≥ 0 (netto) | `500` |
| `Zahlungsziel` | `payment_term_days` | 0–365 Tage, Einheit optional | `30`, `30 Tage`, `netto 30` |
| `Rückvergütung` | `rebate_pct` | 0–100 % | `3,5 %` |

Aliase u. a.: `Mindestauftragswert`/`MBW` · `Frachtfrei ab`/`Versandkostenfrei ab` · `Zahlungsfrist`/`Nettotage` · `Bonus`/`Jahresbonus`.

**Vier Regeln:**

1. **Eine Kondition steht in jeder Zeile — geschrieben wird sie einmal.** Dass derselbe Mindestbestellwert 400-mal in der Datei steht, ist der Normalfall. Es reicht auch, ihn nur in die erste Zeile zu schreiben.
2. **Widerspruch wird abgelehnt, nicht geraten.** Sagt Zeile 2 „30 Tage" und Zeile 5 „60 Tage", wird das *Zahlungsziel* nicht gesetzt und der Bericht nennt beide Zeilen. Die übrigen Konditionen bleiben davon unberührt.
3. **Bänder statt blindem Übernehmen.** Ein Bonus von `150` und ein Zahlungsziel von `30/2 %` (Skonto-Schreibweise) sind Tippfehler bzw. eine andere Angabe — beide werden abgelehnt und benannt. **`0` ist überall gültig** („frei Haus ab 0 €" ist eine echte Aussage).
4. **D1 gilt auch hier.** Konditionen eines geerbten Katalog-Lieferanten pflegt nur das Besitzer-Team; der Import überspringt sie mit Grund. Die Artikelzeilen derselben Datei laufen davon unabhängig weiter.

Die drei Bestell-Logistik-Felder derselben Tabelle (Liefertage, Bestellschluss, Vorlaufzeit — Spec 17) sind **bewusst nicht** Teil der Vorlage: sie beschreiben die Bestellschiene, nicht die kommerzielle Kondition, stehen in keiner Preisliste und werden im Lieferanten-Modal gepflegt.

### Spalten ohne Ziel

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

Der Upsert ist idempotent: derselbe Lauf mit derselben Datei meldet beim zweiten Mal alles als „unverändert" und schreibt nichts — **auch keine Preis-Zeile und keinen Detail-Block**. Das ist beim Preis kein Nebeneffekt, sondern Absicht: `foodalchemist_prices` ist append-only, ein Schreiben je Lauf machte die Historie nach drei Quartalen unlesbar und ließe den Preis-Trend „Δ 0 %" als jüngste Generation lesen. Ein abgebrochener Lauf wird also einfach durch erneutes Ausführen fortgesetzt — es gibt keinen Zwischenzustand zu reparieren.

Jeder scharfe Lauf hinterlässt eine Zeile in `foodalchemist_bulk_runs` (Typ `ingest`); eine Zeile mit Preis-Fehler zählt dort als `failed`, auch wenn ihr Artikel-Stamm geschrieben wurde („EK nicht angekommen" ist kein Teilerfolg). Darauf setzt S3 (`ingest.STATUS`) auf.

## 7. Post-Import-Kette (E4) — was nach dem Schreiben passiert

Ein neuer EK, der nur in der Preistabelle liegt, ist die **stille Drift**: Kalkulation, Marge und Cockpit zeigten weiter den alten Stand. Für Allergene, Zusatzstoffe und Nährwerte gilt dasselbe — sie sind am Rezept **aggregierte Spalten**, kein Live-Join; ohne Recompute stünde das neue Allergen am Artikel, während Rezept, Foodbook und Aushang den alten Stand zeigen. Ein bewegter Artikel ist darum jeder mit neuem Preis **oder** neuem Detail-Wert. Nach dem Import läuft automatisch:

1. **Betroffene GPs** — über die LA↔GP-Struktur (nicht nur über den Lead-LA: die Kosten-Kaskade nimmt den Lead als *bevorzugten* Kandidaten und fällt sonst auf den Mittelwert aller aktiven LAs zurück, ein Nicht-Lead-Preis bewegt die Kosten also ebenfalls).
2. **`recomputeAndPropagate`** je nutzendem Rezept — samt aller transitiven Eltern (Basisrezept → Gericht → Paket).
3. **`preisSprungMargeImpact`** einmal für das Team — der bestehende R2.1-Detektor sucht sich die frischen Änderungen selbst (Lookback + Dedup je neuem Preis). Der Importer baut **keine** zweite Schwellen-Logik.

Die Kette läuft **einmal am Ende** über die deduplizierte Menge, nicht je Zeile — ein Katalog rührt dieselben Eltern-Rezepte sonst hundertfach an. Der Trockenlauf zeigt sie als Vorschau („x GP · y Rezept(e) würden neu berechnet"), ohne zu rechnen.

**Obergrenze:** 1.000 Rezepte je Lauf (`MAX_RECOMPUTE`). Wird sie erreicht, sagt der Bericht das ausdrücklich; den Rest zieht `php artisan foodalchemist:recompute` nach.

## 8. Beispiel

Siehe [kanal_b_artikel_beispiel.csv](kanal_b_artikel_beispiel.csv) — fünf Zeilen mit den häufigsten Fällen (Vollzeile mit Preis + allen drei Detail-Blöcken, Aktionspreis mit Spuren-Werten, unbekannte Einheit, Zeile ganz ohne Preis und Details).
