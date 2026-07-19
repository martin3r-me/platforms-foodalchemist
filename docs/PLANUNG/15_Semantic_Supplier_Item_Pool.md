# Semantische Suche über Lieferantenartikel (Supplier-Item-Pool) — RAG-Erweiterung

> **ROADMAP-Bezug:** #507 (semantischer Layer) · Q2 (Katalog-Ingest). **Anlass:** Frage Dominique 2026-07-19 — „läuft das RAG auch auf Lieferanten und deren Sortiment?" **Antwort: nein** (heute nur GP · Rezept · Wissen · Anker). Diese Spec ergänzt einen **`supplier_item`-Pool**, damit Lieferanten-Sortimente semantisch durchsuchbar werden.
> **Reifegrad: ⚪ Dossier** (code-kartiert, entscheidungs-offen). Größe **M–L** (Pool + Observer klein; der Backfill-Umfang ist groß).

---

## 0. Warum / Nutzen
Heute arbeitet die semantische Suche auf der **abstrakten GP-Ebene**. Konkrete Lieferantenartikel (LAs) erreicht man nur über ihr GP-Mapping. Fehlt:
- „Finde Artikel *wie* X quer über **alle** Lieferanten-Kataloge" (Sortiments-Recherche, Substitut-Quellen).
- Semantisches Matching auf **Artikel-Bezeichnungen** (Marketing-/Handelsnamen, Marke, Herkunft) — genau da, wo Fuzzy-String heute an Wortvarianten scheitert.
- Beschleunigt LA→GP-Matching (Kandidaten-Recall) und Beschaffungs-Recherche.

## 1. Code-Kartierung (verifiziert 2026-07-19)

**Pool-Architektur (reuse, 1:1 vorhandenes Muster):** `PoolEmbeddingService`
- Konstanten `ENTITY_TYPE_GP='foodalchemist_gp'` `:45`, `ENTITY_TYPE_RECIPE='foodalchemist_recipe'` `:47` → **NEU** `ENTITY_TYPE_SUPPLIER_ITEM='foodalchemist_supplier_item'`.
- Muster je Pool: `embedX(onlyTeamId)` (Bulk) + `queueX(model)` (Einzel, Provider-Guard + Pool-Zugehörigkeit) + `deleteX()` + `xEmbedText(obj)` + `storeByTeam()` (Partition je Team). Speicher: Core-`EmbeddingService::…(teamId, entityType, id)`.
- Partition-Merge (Team-Ahnenkette ∪ Global-Sentinel) steckt in `partitionTeamId()` — greift für LAs identisch (LAs sind team-eigen, D1).

**Auto-Embedding (reuse-Muster):** `GpEmbeddingObserver`/`RecipeEmbeddingObserver` (created/updated/deleted, re-queue nur bei Änderung embed-relevanter Felder, No-op ohne Provider), registriert in `FoodAlchemistServiceProvider:108-109`. → **NEU** `SupplierItemEmbeddingObserver` auf `FoodAlchemistSupplierItem`.

**Backfill-Command (reuse):** `EmbedCommand` `foodalchemist:embed {--pool=gps|recipes|knowledge|all}` `:19` → **NEU** `--pool=supplier_items` + in `all` aufnehmen.

**Retrieval (reuse + erweitern):** `SemanticRetrievalService::candidates(team, q, [entityTypes], cap, floor)` — nimmt eine Entity-Typ-Liste; der neue Typ wird optional mit-durchsucht. `EmbedEvalCommand` sweept heute GP+Rezept — LA-Golden-Fälle optional ergänzen.

**Such-Oberfläche:** heute `ArtikelSearchTool` (MCP, fuzzy/lexikalisch) + `Suppliers/Index`-Suche (`SupplierItemService::searchGlobal`). → semantischen Kandidaten-Pfad **dazuschalten** (hybrid, wie bei GPs), kein Neubau der UI.

**Daten:** `foodalchemist_supplier_items` — `article_number`, `designation`, `brand`, `manufacturer`, `origin`, `marketing_name`, `additional_text`, `origin_country`, Einheit; Mapping → GP via `foodalchemist_supplier_item_structures`. **Skala:** Fixture 456; **Master = Zehntausende** (bei 7.813 GPs) → der mit Abstand **größte Pool**.

## 2. Vorzuklärende Entscheidungen
| # | Frage | Optionen / Tendenz |
|---|---|---|
| E1 | Eigener Pool vs. GP-Text anreichern | **Eigener `supplier_item`-Pool** (Artikel-Granularität, echte „finde Artikel"-Treffer). Alternative (Artikel-Synonyme in den GP-Text kippen) gibt keine Artikel-Ebene → verworfen. |
| E2 | Embed-Text | `designation` + `brand`/`manufacturer` + `marketing_name` + `origin(_country)` + **GP-Name des Mappings** (Kontext: was der Artikel IST) + Lieferantenname. Analog `gpEmbedText`. |
| E3 | Scope / discontinued | Nur aktive (`is_discontinued=0`, nicht deleted) embedden; ausgelistete raus (wie Pool-Austritts-Gate bei GPs). |
| E4 | Kosten/Skala | Master-Backfill = Zehntausende Artikel → **Token-/Kosten-Schätzung vor dem Lauf**; getaktet + resumefähig (der Backfill hat schon einmal an einem OpenAI-Timeout abgebrochen — Batch-Retry/Resume nötig). |
| E5 | Eigener Floor | Artikel-Namen sind kürzer/markenlastig → evtl. eigener `pool_sem_floor` für den Typ; am Golden-Set (E5-Harness) mit-eichen. |
| E6 | MCP | Entweder `ArtikelSearchTool` um `semantic=true` erweitern **oder** neues `artikel.SEARCH`-Semantik-Tool (Lockstep). |

## 3. Etappen (Vorschlag)
| # | Etappe | Größe | Inhalt |
|---|---|---|---|
| S1 | Pool + Backfill | M | `ENTITY_TYPE_SUPPLIER_ITEM` + `embedSupplierItems`/`queueSupplierItem`/`deleteSupplierItem`/`supplierItemEmbedText` + `--pool=supplier_items`. Idempotent, batch-resumefähig (E4). |
| S2 | Auto-Embedding | S | `SupplierItemEmbeddingObserver` (created/updated/deleted, relevante Felder), registriert. |
| S3 | Retrieval + Oberfläche | M | LA-Typ in `candidates()` einhängen; hybrider semantischer Pfad in Artikel-Suche (UI + MCP, E6). |
| S4 | Eichung | S | LA-Golden-Fälle + Floor je Typ (E5), `embed-eval` erweitern. |

## 4. DoD
- [ ] Neuer `supplier_item`-Pool: Bulk-Backfill + Einzel-Queue + Delete, team-partitioniert, No-op ohne Provider.
- [ ] Auto-Embedding bei LA-Anlage/-Änderung/-Löschung (Observer), nur bei embed-relevanter Änderung.
- [ ] Semantische Artikel-Suche (hybrid) in UI **und** MCP; nur aktive Artikel im Index.
- [ ] Floor am Golden-Set geeicht (eigener oder geerbter Wert, belegt — nicht geraten).
- [ ] Kosten/Skala vor Master-Backfill dokumentiert; Lauf resumefähig + getaktet (kein Timeout-Totalabbruch).
- [ ] MCP-Lockstep (Reads team-scoped).

## 5. Reuse-vs-Neu
| Reuse | Neu |
|---|---|
| `PoolEmbeddingService`-Pool-Muster, `EmbeddingService` (Core-Store), `partitionTeamId`/Partition-Merge, Observer-Muster, `EmbedCommand --pool`, `SemanticRetrievalService::candidates`, `EmbedEvalCommand`, `ArtikelSearchTool`/`SupplierItemService::searchGlobal` | `ENTITY_TYPE_SUPPLIER_ITEM` + 4 Pool-Methoden, `SupplierItemEmbeddingObserver`, `--pool=supplier_items`, LA-Zweig in Retrieval + Such-Oberfläche, LA-Golden-Fälle, ggf. eigener Floor |

## 5a. Geschwister-Lücken (gleiche Pool-Mechanik, 2026-07-19)
Dieselbe „fehlt im semantischen Index"-Lücke betrifft mehrere Entitäten — **ein kohärentes Pool-Vervollständigungs-Paket**, nicht nur LAs:
- **Lieferanten (Entität, `foodalchemist_suppliers`)** — Dominique-Befund: Suche nach einem Lieferanten fand ihn nicht (heute nur lexikalisches `LIKE` auf `name` in `Suppliers/Index`). Embed-Text: name + branch + city. **Eigener Pool `supplier`.**
- **Konzepte (`foodalchemist_concepts`)** — nicht im Index. Embed-Text: Titel + Facetten (Eventtyp/Saison/Moment) + Kurzbeschreibung.
- **Foodbooks (`foodalchemist_foodbooks`)** — nicht im Index. Embed-Text: Titel + Kapitel-/Gericht-Namen.
- **Rezepte/Gerichte (Basis + VK)** — ✅ **bereits im Index** (kein Nachzug nötig).

Jede dieser Lücken = **derselbe Bau** wie unten (ENTITY_TYPE + 4 Pool-Methoden + Observer + Retrieval-Zweig). Sinnvoll als **eine Feature-Runde „Semantische Abdeckung vervollständigen"** statt vier Einzel-Issues. Skala/Kosten: Lieferanten + Konzepte + Foodbooks sind klein; LAs sind der große Brocken.

## 5b. Weitere Kandidaten + Embed-Tiefe (2026-07-19)
- **Lab-Notes (`foodalchemist_lab_notes`, R6.11 S3)** — R&D-Hypothesen/Notizen semantisch auffindbar („schon mal hypothetisiert?"). Kleiner Pool, hoher Fit → **einplanen** (eigener Pool `lab_note`, Embed-Text title+body).
- **⚠️ Embed-Text-TIEFE (wichtiger als weitere Entitätstypen):** heute embeddet `recipeEmbedText` nur Name + Kategorie-Label + Top-Zutaten; `gpEmbedText` nur Name + Zustand + WG. **Zubereitung/Beschreibung/Notizen fließen NICHT ein** → „finde Gericht mit Technik/Verfahren X" trifft nicht. Recall-Hebel: reicherer Embed-Text (Preparation, Description) bei bestehenden Pools — eigener Slice, oft wirksamer als ein neuer Pool.
- **Situativ (kein Muss):** Angebote (ähnliche Alt-Angebote im Sales-Funnel wiederfinden), Feedback R2.6. **Cross-Modul-Stretch:** Food-DNA/Canvas als Grounding — größerer Umbau (Owner-Types), bewusst später.

## 6. Abhängigkeit
- Setzt den **#507-Layer live** voraus (Provider-Key ✅ auf demo vorhanden; Backfill/Floor-Eichung = laufende Go-Live-Runde). Der LA-Pool ist ein **Nachzug** darauf, kein eigenständiger Layer.
- Verwandt: Q2 Katalog-Ingest (frische Artikel → frischer Index via Observer).

*Dossier 2026-07-19, aus Dominique-Frage „RAG auf Lieferanten/Sortiment?". Einstieg: `00_Orchestrierung_Naechste_Schritte.md` / `_Spec_Status_Matrix.md`.*
