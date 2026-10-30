# 91 – MCP-Filter-Gleichstand (UI-Listen ⇄ MCP-LIST/SEARCH)

Bestandsaufnahme vom 29.10.2026, Stand `origin/main` 31ea8756 (#632). Die GP-Zeilen enthalten zusätzlich die Filter aus dem Branch `feat/mcp-gps-list-ohne-la`.

Für jede Listenansicht der Oberfläche steht hier, welche Filter und Spalten sie hat, welche Parameter das passende MCP-Tool deklariert, welche davon `execute()` tatsächlich anwendet, welche Felder es zurückgibt und wo Lücken sind. Pfade sind relativ zum Modul (`src/…`, `resources/views/…`).

Auftrag (Dominique, Kurator 29.10.): Gleichstand Oberfläche ↔ MCP. Lücken werden nach dem gps.LIST-Muster geschlossen:
- optionale Filter, die nur greifen, wenn sie gesetzt sind;
- `felder=kurz|voll`;
- Sicht und Rechte bleiben unverändert.

Ein Wächter-Test sichert den Gleichstand ab.

**Core-Verhalten vorweg:** Unbekannte Parameter werden still angenommen, nicht abgelehnt.
- `platforms-core/src/Services/ToolValidationService.php:41`: `$additionalProperties = $schema['additionalProperties'] ?? true;`
- `platforms-core/src/Tools/ToolExecutor.php:461`: `continue; // Unbekannte Felder werden ignoriert`

Folge: `artikel.LIST {supplier_id: 5}` liefert keinen Fehler, sondern die ungefilterte Liste.

Legende Status:
- **ok** – in UI und MCP vorhanden und wirksam
- **Lücke** – UI-Filter ohne MCP-Parameter
- **ignoriert** – MCP-Parameter deklariert oder dokumentiert, aber nicht angewendet
- **nur MCP** – Filter nur im Tool vorhanden

---

## 0. Priorität: `foodalchemist.artikel.LIST` ignoriert `supplier_id` und Suche

**Bestätigt.** In `src/Tools/ArtikelListTool.php`:
- Das Schema deklariert nur `page` und `per_page`.
- `execute()` ruft `SupplierItemService::searchGlobal($team, '', [], $perPage)` auf. Suchtext und Filter sind fest leer.
- Ein übergebenes `supplier_id` oder `q` wird still verworfen. Das Tool blättert durch den ganzen sichtbaren, lieferbaren Katalog. Die rund 231.829 Treffer passen dazu, nachgezählt habe ich nicht.

Der Service kann bereits filtern (`src/Services/SupplierItemService.php`):
- `supplier_ids` (`:91`)
- `art` = nonfood | pruefen | food (`:93-95`)
- `onlyActive` (`:88`)
- `paginateForSupplier($team, $supplierId, ['q', 'art', 'onlyActive'])` (`:29-47`), diesen Weg nutzt die UI (`src/Livewire/Suppliers/Index.php:328`)

Irreführende Metadaten: `SuppliersGetTool.php:64` und `IngestImportTool.php:147` verweisen auf `artikel.LIST`, als ginge das pro Lieferant.

`artikel.SEARCH` (`ArtikelSearchTool.php`):
- nur `q` (Pflicht) und `limit` ≤ 50;
- kein Lieferanten-Filter, kein `art`, kein Paging;
- auch der semantische Zweig filtert nicht nach Lieferant.

## 1. Grundprodukte (GPs)

**UI:** `src/Livewire/Gps/Browser.php`
- Filter `:33-46`
- Abfrage → `GpService::paginateBrowser` (`browserQuery` blendet `merged` aus, `GpService.php:420`)

**MCP:**
- `gps.LIST`: Paging `page`/`per_page` ≤ 200
- `gps.SEARCH`: `q`, `limit` ≤ 50, kein Paging

| UI-Filter | MCP-Parameter | Status |
|---|---|---|
| `search` (`?q=`) | LIST: fest `''` · SEARCH: `q` | **Lücke** in LIST |
| `commodity_group` (`?wg=`) | `commodity_group_code` | ok |
| `subKategorie` (`?sub=`) | `sub_category` | ok |
| `status` | `status` | ok (siehe Hinweis merged) |
| `perPage` (25–500) | `per_page` ≤ 200 | ok |
| `ansicht` (Spalten) | `felder` kurz/voll | teilweise |
| – | `marke_offen`, `rezepturware`, `ohne_lead`, `ohne_la`, `nur_la_pflicht` | nur MCP |

Hinweis: `gps.LIST` ohne `status` liefert auch `merged`-Tombstones, die UI blendet sie aus.

Spalten in der UI ohne MCP-Feld: Lead-Preis, Anzahl Lieferantenartikel, Anzahl Rezepte, Allergene.

## 2. Lieferantenartikel

**UI:** `src/Livewire/Suppliers/Index.php`
- Filter `:26-67`
- Abfrage `:327-328`

**MCP:** `artikel.LIST`, `artikel.SEARCH` (siehe Abschnitt 0)

| UI-Filter | MCP-Parameter | Status |
|---|---|---|
| `supplierId` (`?lieferant=`) | keiner | **Lücke** (still verworfen) |
| `artikelSuche` (`?aq=`) | keiner | **Lücke** |
| `q` (globale Suche) | SEARCH `q` | ok (max. 50, kein Paging) |
| `art` (nonfood/pruefen/food) | keiner | **Lücke** |
| `onlyActive` | keiner (fest true) | **Lücke** |
| `perPage` | `per_page` | ok |

MCP liefert nur id, designation, article_number und supplier.

Spalten in der UI ohne MCP-Feld:
- supplier_id
- Gebinde/Menge/Einheit
- Lieferstatus
- EK (`aktiver_preis` rechnet der Service schon)
- Vergleichspreis
- GP-Zuordnung
- Lead-Kennzeichen
- Aktion bis

## 3. Lieferanten

**UI:** Seitenleiste in `Suppliers/Index.php`
- `supplierSuche`
- `gruppe` (`?gruppe=`)
- `includeInactive`
- `einstufen` (`?einstufen=`)

**MCP:**
- `suppliers.SEARCH`:
  - `q` ist Pflicht, `""` liefert alle, aber höchstens 50
  - `include_inactive`
  - `limit` ≤ 50
  - kein Paging, `total` = Zahl der gelieferten Zeilen
- Ein `suppliers.LIST` gibt es nicht.

| UI-Filter | MCP-Parameter | Status |
|---|---|---|
| `supplierSuche` | `q` | ok |
| `includeInactive` | `include_inactive` | ok |
| `gruppe` | keiner | **Lücke** |
| `einstufen` (Rolle offen) | keiner | **Lücke** |

Fehlende Felder: item_count, mapped_count.

Paging fehlt: Bei mehr als 50 Lieferanten sind per MCP nicht alle auflistbar.

## 4. Basisrezepte

**UI:** `src/Livewire/Recipes/Browser.php`
- Filter `:27-109`
- Abfrage → `RecipeService::paginateBrowser`

**MCP:**
- `recipes.LIST`: Paging; Filter `status`, `rolle`
- `recipes.SEARCH`: `q`, `status`, `limit` ≤ 50

| UI-Filter | MCP-Parameter | Status |
|---|---|---|
| `search` | LIST fest `''` · SEARCH `q` | **Lücke** in LIST |
| `hauptgruppe`, `kategorie`, `ohneKategorie` | keiner | **Lücke** (3×) |
| `status` | `status` | ok, aber im semantischen SEARCH-Zweig **ignoriert** (`RecipesSearchTool.php:62-64`) |
| `geschmack`, `fertigung`, `nurTemplates` | keiner | **Lücke** (3×) |
| – | `rolle` | nur MCP |

Fehlende Felder: Kategorie/Hauptgruppe, Geschmack, Fertigung, Zutatenzahl, Allergen-Konfidenz.

## 5. Gerichte / Verkaufsrezepte (VK)

**UI:** `src/Livewire/Verkauf/Browser.php`
- Filter `:30-54`
- Abfrage → `SalesRecipeService::browserQuery`

**MCP:**
- `verkaufsrezepte.LIST`: nur `page`/`per_page`
- `verkaufsrezepte.SEARCH`: `q`, `limit` ≤ 50

| UI-Filter | MCP-Parameter | Status |
|---|---|---|
| `search` | SEARCH `q` | ok (nur SEARCH) |
| `hauptgruppe`, `klasse`, `status`, `geschmack` | keiner | **Lücke** (4×) |

Fehlende Felder: **status**, Geschmack, Anzahl Komponenten, Deklaration.

## 6. Konzepte (Concepter)

**UI:**
- `src/Livewire/Concepter/Browser.php`
  - Reiter `concepts` → `ConceptService::paginateBrowser`
  - Reiter `pakete` → `paginatePakete` (Concepts mit `kind=paket`)
- Ältere Seite `src/Livewire/Concepts/Index.php`: `search`, `showVorlagen`, `categoryFilter`, `phaseFilter`

**MCP:**
- `concepts.LIST`: Paging; `status`, `vorlagen`
- `concepts.SEARCH`: `q`, `status`, `vorlagen`, `limit` ≤ 50

| UI-Filter | MCP-Parameter | Status |
|---|---|---|
| `search` | LIST fest `''` · SEARCH `q` | Lücke in LIST |
| `klasse` | keiner (Service kann `class`) | **Lücke** |
| `statusFilter` | `status` | ok; im semantischen SEARCH-Zweig **ignoriert** |
| `showVorlagen` | `vorlagen` | ok |
| `categoryFilter` (inkl. none) | keiner | **Lücke** |
| Servierform, Eventtyp, Einsatzmoment, Saison | keiner | **Lücke** (4×) |
| `phaseFilter` (`/concepts`) | keiner | **Lücke** |
| Reiter „Pakete“ (Concepts mit `kind=paket`) | kein Tool; `pakete.LIST` liest die Tabelle `foodalchemist_packages` | **Lücke** |

Fehlende Felder: Eventtyp, Servierform, €/Person, Wareneinsatz.

## 7. Formate

**UI:** `src/Livewire/Formate/Browser.php` → `FormatService::paginateBrowser`

**MCP:**
- `formats.LIST`: `status`, `origin`; fest 200 Zeilen, **kein Paging**
- `formats.SEARCH`: `query`, fest 50

| UI-Filter | MCP-Parameter | Status |
|---|---|---|
| `search` | SEARCH `query` | ok (nur SEARCH) |
| `statusFilter`, `originFilter` | `status`, `origin` | ok |
| Servierform, Eventtyp, Einsatzmoment, Saison | keiner | **Lücke** (4×) |

Fehlende Felder: Eventtyp, Servierform.

## 8. Foodbooks

**UI:** `src/Livewire/Foodbooks/Index.php`, einziger Listenfilter `search`

**MCP:**
- `foodbooks.LIST`: `status`, `per_page`; **kein `page`**, also nur Seite 1
- `foodbooks.SEARCH`: `q`, `limit` ≤ 50

| UI-Filter | MCP-Parameter | Status |
|---|---|---|
| `search` | SEARCH `q` | ok (nur SEARCH) |

Fehlende Felder: Kapitelzahl; in LIST außerdem der Kundenname.

Paging fehlt.

## 9. Angebote

**UI:** `src/Livewire/Angebote/Index.php`: `search`, `statusFilter`

**MCP:**
- `angebote.LIST`: `status`, Paging
- `angebote.SEARCH`: `q`, `status`, `limit` ≤ 50

| UI-Filter | MCP-Parameter | Status |
|---|---|---|
| `search` | SEARCH `q` | ok (nur SEARCH) |
| `statusFilter` | `status` | ok; im semantischen SEARCH-Zweig **ignoriert** (`AngeboteSearchTool.php:60`) |

Fehlende Felder: Datum, Angebotssumme.

## 10. Speisekarten

**UI:** `src/Livewire/Speisekarte/Index.php`: `search`

**MCP:**
- `speisekarten.LIST`: `status`, `per_page`; **kein `page`**
- `speisekarten.SEARCH`: `q`, `status`, `karten_typ`, `limit`

| UI-Filter | MCP-Parameter | Status |
|---|---|---|
| `search` | SEARCH `q` | ok (nur SEARCH) |

Fehlende Felder in LIST: Anzahl Rubriken.

Paging fehlt.

## 11. Speisepläne

**UI:** `src/Livewire/Speiseplan/Index.php`: `search`

**MCP:** `speiseplaene.LIST` mit `search`, `status`, `per_page`. Ein SEARCH-Tool gibt es nicht.

| UI-Filter | MCP-Parameter | Status |
|---|---|---|
| `search` | `search` | ok |
| – | `status` | **ignoriert**: wird ins Filter-Array gelegt, `SpeiseplanService::paginateBrowser` wertet nur `search` aus |

Fehlendes Feld: Anzahl Einträge (der Service zählt sie schon per `withCount('entries')`).

Paging fehlt.

## 12. Pakete (`/pakete`, Tabelle `foodalchemist_packages`)

**UI:** `src/Livewire/Pakete/Index.php`: `search`, `rolleFilter`

**MCP:**
- `pakete.LIST`: `role`, `per_page`, `outlet_id`; kein `page`
- `pakete.SEARCH`: `query`, `role`, `per_page`

| UI-Filter | MCP-Parameter | Status |
|---|---|---|
| `search` | SEARCH `query` | ok |
| `rolleFilter` | `role` | ok |

Fehlendes Feld: Anzahl Gerichte.

Paging fehlt.

## 13. Bestellungen

**UI:** `src/Livewire/Orders/Index.php`, Filter:
- `statusFilter`
- `supplierFilter`
- `productionFilter`
- `suche`
- `datumsbasis`, `von`, `bis`, `zeitraum`
- `nurMitPositionen`, `nurMitKlaerung`

**MCP:** `orders.GET` ohne `order_id` liefert die Liste. Parameter nur `status`; **kein Paging**.

| UI-Filter | MCP-Parameter | Status |
|---|---|---|
| `statusFilter` | `status` | ok |
| `supplierFilter` | keiner | **Lücke** |
| `von`/`bis`/`datumsbasis`/`zeitraum` | keiner (`OrderService::listForTeam` kann es, `:2291-2308`) | **Lücke** |
| `suche` | keiner | **Lücke** |
| `productionFilter` | keiner | **Lücke** |
| `nurMitPositionen`/`nurMitKlaerung` | keiner (`warnings` sind im Ergebnis) | Lücke (gering) |

Fehlende Felder: Standort, Bestelldatum, Produktion/Herkunft, supplier_id.

## 14. Wareneingang (Lieferscheine, Rechnungen, Abgleich)

**MCP:**
- `delivery_notes.GET`: alle UI-Filter angewendet, plus `von`/`bis`
- `supplier_invoices.GET`: alle UI-Filter angewendet
- `triple_match.GET`: alle UI-Filter angewendet

Felder sind deckungsgleich mit der UI.

**Ohne Lücke.** Einzige Einschränkung: Lieferscheine und Rechnungen sind hart auf 500 Zeilen gedeckelt, ohne Paging.

## 15. Lager und Inventur

**UI:** `src/Livewire/Lager/Index.php`
- gemeinsame Filterleiste: suche, stellplatz, zustand, warengruppe, lieferant, status, ohne_preis, ladenhueter
- Lagerort
- Bewegungen: `quelle`, `grundFilter`
- Chargen: `nurAblaufend`

**MCP:** `inventory.GET`, `inventory_counts.GET`, `inventory_batches.GET`, `lagerartikel.GET`

| UI-Filter | MCP-Parameter | Status |
|---|---|---|
| Bestand: Ort + 7 Leistenfilter | gleichnamig | ok |
| Bewegungen: `quelle` | `quelle` | ok |
| Bewegungen: `grundFilter` | keiner (Service kann `grund`) | **Lücke** |
| Inventur-Zeilen: suche/zustand/warengruppe/ohne_preis | nur `stellplatz`, `status` werden durchgereicht | **Lücke** |
| Chargen: `nurAblaufend` | `ablaufend_tage` | ok |

Fehlende Felder in der Inventur-Liste: Anzahl Positionen, Standort.

Unklar: Ob das Tool bei den Bewegungen die Notiz liefert, habe ich nicht vollständig geprüft.

## 16. Produktion

**UI:** `src/Livewire/Produktion/Browser.php`: `statusFilter`, `von`, `bis`, `suche`, `perPage`

**MCP:** `production_orders.GET` ohne `order_id` liefert die Liste. Parameter nur `status`; ungepaged, lädt alles.

| UI-Filter | MCP-Parameter | Status |
|---|---|---|
| `statusFilter` | `status` | ok |
| `von`/`bis`/`suche` | keiner (`ProductionOrderService::paginateBrowser` kann es) | **Lücke** (3×) |
| `perPage` | keiner (kein Paging) | **Lücke** |

Fehlende Felder: Standort, Ziele, Ansätze, Portionen, Arbeitszeit, Posten, Bedarfs-Indikator.

## 17. Signale

**UI:** `src/Livewire/ReviewQueue.php` (Reiter `signale`)
- `signalStatus`
- `signalTyp`
- Betriebsbrille
- implizit `exclude_types` (gedämpfte, aggregierte Typen)

**MCP:**
- `signale.LIST`: `status`, `type`, Paging, `outlet_id`
- `signale.SEARCH`: `status`, `type`, `limit`, `outlet_id`; **ohne Suchtext**

| UI-Filter | MCP-Parameter | Status |
|---|---|---|
| `signalStatus` | `status` | ok, aber **ignoriert/falsch dokumentiert**: Die SEARCH-Beschreibung nennt `abgeschlossen`, das Enum kennt nur offen/erledigt/ignoriert. Ergebnis: still 0 Treffer |
| `signalTyp` | `type` | ok |
| Betriebsbrille | `outlet_id` | ok |
| implizit `exclude_types` | keiner | Abweichung: MCP zeigt gedämpfte Einzelmeldungen |

Fehlende Felder: description, payload.

## 18. Wissen

**UI:** `src/Livewire/Knowledge/Browser.php`
- `search`
- `filterCategory`
- `filterStatus` (all / active / inactive)
- `semantic`

**MCP:**
- `knowledge.LIST`: `category`, `offset`/`limit` ≤ 200, `with_frontmatter`, `include_inactive`
- `knowledge.SEARCH`: `q`, `category`, `limit` ≤ 50, `include_inactive`

| UI-Filter | MCP-Parameter | Status |
|---|---|---|
| `search` | SEARCH `q` | ok |
| `filterCategory` | `category` | ok |
| `filterStatus` = all/active | `include_inactive` | ok |
| `filterStatus` = inactive (nur inaktive) | keiner | **Lücke** |
| `semantic` | keiner (nach Config) | Lücke (gering) |

Fehlendes Feld: die numerische `id`. Unklar, ob `slug` als Schlüssel reicht.

## 19. Läufe (Massen-Läufe / Kaskade)

Keine Listenansicht in der UI. Der Fortschritt erscheint nur im jeweiligen Kontext.

`runs.GET` und `planung_kaskade.*` haben kein UI-Gegenstück, daher gibt es keinen Gleichstand zu prüfen.

---

## Priorisierte Lückenliste

**A. Ignoriert oder still verworfen** (falsche Mengen ohne Fehler)

1. `artikel.LIST` ohne `supplier_id` und Suche. Außerdem die `related_tools`-Verweise korrigieren (`SuppliersGetTool.php:64`, `IngestImportTool.php:147`).
2. `speiseplaene.LIST`: `status` wird ignoriert.
3. `signale.SEARCH`: `status=abgeschlossen` ist dokumentiert, existiert aber nicht.
4. Semantische Zweige ignorieren Filter: `recipes.SEARCH` (`status`), `concepts.SEARCH` (`status`/`vorlagen`), `angebote.SEARCH` (`status`).
5. Grundsätzlich: Mit `additionalProperties` = true wird jeder falsch geratene Parameter still verworfen. Vorschlag: `'additionalProperties' => false` in den Schemas der LIST/SEARCH-Tools. Der Core meldet dann „Unbekanntes Feld“. Bitte entscheiden, ob das zum Paket gehört.

**B. Unvollständige Aufzählung** (Paging fehlt oder Deckel)

6. `suppliers.SEARCH`: max. 50, kein Paging.
7. Kein `page`: `formats.LIST` (fest 200), `foodbooks.LIST`, `speisekarten.LIST`, `speiseplaene.LIST`, `pakete.LIST`/`SEARCH`.
8. `orders.GET` und `production_orders.GET` ohne Paging; `delivery_notes.GET` und `supplier_invoices.GET` hart auf 500.

**C. Häufig genutzte UI-Filter ohne MCP-Parameter**

9. Lieferantenartikel: `art`, `only_active`. Lieferanten: `gruppe`, `einstufen`.
10. Verkaufsrezepte: `status`, `hauptgruppe`, `klasse`, `geschmack` (LIST hat gar keine Filter).
11. Basisrezepte: `hauptgruppe`, `kategorie`, `ohne_kategorie`, `geschmack`, `fertigung`, `nur_templates`; Suche in LIST.
12. Bestellungen: `supplier_id`, Datum (`von`/`bis`/`datumsbasis`), `suche`, `production`.
13. Produktion: `von`, `bis`, `suche`.
14. Konzepte: `class`, `category`, `phase` und vier Facetten. Formate: dieselben vier Facetten. Concepter-Pakete (`kind=paket`) ohne Tool.
15. GPs: Suche in LIST; `merged` ohne Status-Filter sichtbar.
16. Lager: `grund` bei Bewegungen; Inventur-Zeilen-Filter. Wissen: „nur inaktive“.

**D. Spalten, die MCP nicht liefert** (das LLM muss je Zeile nachfragen)

17. Lieferantenartikel: EK, Vergleichspreis, Gebinde, Lieferstatus, GP, Lead. GPs: Lead-Preis, Anzahl Lieferantenartikel, Anzahl Rezepte, Allergene.
18. Verkaufsrezepte: Status, Geschmack, Komponenten, Deklaration. Basisrezepte: Kategorie, Geschmack, Fertigung, Zutatenzahl, Allergen-Konfidenz.
19. Weitere:
   - Konzepte/Formate: Eventtyp, Servierform (Konzepte zusätzlich €/Person, Wareneinsatz)
   - Angebote: Datum, Summe
   - Pakete: Anzahl Gerichte
   - Foodbooks: Kapitelzahl, Kundenname
   - Speisekarten: Rubriken
   - Speisepläne: Einträge
20. Bestellungen: Standort, Datum, Produktion. Produktion: Ziele, Ansätze, Portionen, Arbeitszeit, Posten, Bedarf. Inventur: Positionen, Standort. Signale: description, payload.

**E. Nachschlage-Werte ohne Tool** (ids nur aus Ergebnissen ablesbar)

21. Rezept-Hauptgruppen und -Kategorien: `recipes.LIST` filtert seit PR 3 nach `hauptgruppe`/`kategorie`, aber kein MCP-Tool listet die erlaubten Werte (Gerichte haben `dish_classes.GET`). Nachbau nach dem Deploy (Kurator 30.10.).

---

## Vorschlag Portionen (je PR 3–5 Tools)

| PR | Tools | Inhalt |
|---|---|---|
| 1 | `artikel.LIST`, `artikel.SEARCH`, `suppliers.SEARCH` (+ Metadaten-Verweise) | Priorität A1 und B6. Artikel: `supplier_id`, `q`, `art`, `only_active`, `felder=voll` (EK, Gebinde, Lieferstatus, GP, Lead). Lieferanten: Paging, `gruppe`, `einstufen`, Zählungen. |
| 2 | `speiseplaene.LIST`, `signale.SEARCH`, `recipes.SEARCH`, `concepts.SEARCH`, `angebote.SEARCH` | Ignorierte Parameter A2–A4 |
| 3 | `verkaufsrezepte.LIST`, `recipes.LIST`, `gps.LIST` (Suche, merged) | Rezept-/Gericht-Filter C10, C11, C15 und Felder D17/D18 |
| 4 | `orders.GET`, `production_orders.GET`, `inventory.GET`/`inventory_counts.GET` | C12, C13, C16 und Paging B8 |
| 5 | `concepts.LIST`, `formats.LIST`, `foodbooks.LIST`, `speisekarten.LIST`, `pakete.LIST` | Facetten C14, `page` B7, Felder D19 |
| 6 | Wächter | Mapping-Liste UI-Ansicht → Tool mit Filter-Gegenstück; ein UI-Filter ohne Gegenstück macht den Test rot. Analog Spec77b/ToolListe. Kommt in PR 1 als Gerüst und wächst je PR mit (Ausnahmen begründet). |

Entscheidungen (Kurator 29.10.):
- `additionalProperties` bleibt offen (die Plattform-KI schickt über callWithTools teils Zusatzfelder; der Core ist Fremdmodul). Stattdessen meldet ein gemeinsamer Helfer in `FoodAlchemistTool` (`parameterWarnungen` / `erfolgMitWarnungen`) unbekannte Parameter im Ergebnis als `warnungen` — ignoriert, aber nicht mehr still.
- Concepter-Pakete: Filter `kind` an `concepts.LIST`/`SEARCH`, kein eigenes Tool.
- A1–A4 vor neuen Filtern.

## Stand

| PR | Stand |
|---|---|
| 1 | gebaut (Branch `feat/mcp-filter-gleichstand`): artikel.LIST `supplier_id`/`q`/`art`/`only_active`/`felder`; artikel.SEARCH `supplier_id`/`art`/`only_active` (auch im semantischen Pass); suppliers.SEARCH Paging mit Gesamtzahl, `gruppe`, `einstufen`, `item_count`/`mapped_count`; Helfer `warnungen`; Wächter `Spec91FilterGleichstandWaechterTest` (Lieferanten-Browser, GP-Browser). Die `related_tools`-Verweise auf `artikel.LIST` stimmen damit. |
| 2 | in main (#646): speiseplaene.LIST `status` wirkt; signale.SEARCH/LIST `status`-enum offen/erledigt/ignoriert; semantische Pässe von recipes/concepts/angebote.SEARCH filtern `status`/`vorlagen`; Helfer `enumFehler` (Core prüft kein enum); Wächter + Speiseplan, Angebote (Grenze: dort keine `#[Url]`-Filter, nur Zuordnungs-Prüfung). |
| 3 | gebaut (Branch `feat/mcp-rezept-gericht-filter`): gps.LIST `q`, ohne `status` keine merged-GPs, `felder=voll` mit Lead-Preis/Vergleichspreis/`la_count`/`rezepte_count`/Allergen-Badges (gemeinsam mit dem Browser über `GpService::listenKennzahlen`); recipes.LIST `q`/`hauptgruppe`/`kategorie`/`ohne_kategorie`/`geschmack`/`fertigung`/`nur_templates` + Spalten; verkaufsrezepte.LIST `q`/`hauptgruppe`/`klasse`/`status`/`geschmack` + Status, Geschmack, Komponenten, Deklaration; Wächter + Rezept-, Gerichte-Browser. Offen: kein MCP-Tool für Rezept-Hauptgruppen/-Kategorien → Punkt 21. |
