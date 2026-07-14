# ROADMAP — Food Alchemist

> Ausführungsplan zu [[GOALS]] (Stand 2026-07-03). Jedes Arbeitspaket hat eine **Definition of Done (DoD)** —
> messbar, nicht verhandelbar. Ein Paket ohne erfüllte DoD ist „in Arbeit", nie „fertig".
> Tracking: Dev-Modul, Package `platforms-food-alchemisten` (ID 23). Diese Datei ist die Landkarte, das Dev-Modul der Tacho.

---

## ⭐ Strategie-Update 2026-07-11 (überschreibt Sequenz-Annahmen von 2026-07-03)

**Einstiegspunkt für die nächste Session: [`_NEXT_SESSION_TODO.md`](_NEXT_SESSION_TODO.md)** (konkrete To-do).

Beschlossen (Dominique):
- **FUNKTION zuerst, auf kleinen sauberen TESTDATEN (Seeder).** NICHT die echten 600 MB in MySQL beim Feature-Bauen — Schema churnt, echte Daten jedes Mal neu importieren wäre Wahnsinn. `migrate:fresh --seed` = Sekunden-Reset. Echte Daten (`import-master`) kommen EINMAL am Ende, wenn Funktionen stabil.
- **Wert-Features (Horizont 1) sind datengated:** nur ~2/1037 Gerichte bepreist → **VK-Preise (R1.2) = Gate** — aber bewusst NACH der Funktions-Phase (Daten verbessern statt löschen).
- **Architektur:** EINE SQL (lokales MySQL = Kanon, Migration installiert/vorbereitet) = Wahrheit + Laufzeit + Rechenbasis. **Wissens-DB lebt IN FA** (`knowledge_documents/aliases/routings`, deterministisch, on-demand, pflegbar via #469 — kein separates Modul). **GRAPH KOMPLETT RAUS** — kein Kùzu/Neo4j/SPARQL, weder Runtime noch Linse noch Autoren-Schicht; Mehr-Hop/Bridging via MySQL-8.4-`WITH RECURSIVE`. Kùzu/Neo4j-Artefakte = reine Historie.
- **Datenmodell-Depth (Ebene 3–5: Muttersaucen-Vererbung, Geschmacks-Editoren, Gericht-Textur/SKF, Event-Dramaturgie) = nachrangig (R6-Thread)** gegenüber Funktion + Pricing-Gate.

Details/Historie: Memory `project_fa_klarschiff_cleanup.md` + `_MEMORY_FoodBrain.md`.

## ⭐ Update 2026-07-12 (Session: Gesamt-Bug-Audit + Master-Vererbung)

Erledigt + beschlossen (Dominique). Details: Memory `project_fa_bug_audit_2026-07-12.md` + `feedback_mcp_lockstep.md`.
- **Master-Vererbung LIVE (Kern-Mechanik):** BHG.DIGITAL (Root/Team 9) = Master; globaler Seed (`team_id NULL`) + Master-Katalog kaskadieren zu den Kind-Teams (alle Caterer sind direkte Kinder von 9); jedes Team verwaltet Eigenes; Master/Seed sind für Kinder **read-only**. Trait `visibleToTeam` = NULL-OR-Ancestry + Helper `Support/TeamScope` + Write-Guards `isOwnedBy` (Settings/Knowledge/Services) + **MCP mitgezogen**. 623 Tests grün + 2-Team-MySQL-Smoke. Gepusht (`ce4d508`/`4db3e90`). → liefert Querschnitt-**#390** (Org→Team→Projekt-Vererbung) auf Team-Ebene. Offen: **#483** „Freischalten"-Admin-Flag (Master steuert, *was* kaskadiert) + **#484** Wissens-Sichtbarkeit definieren.
- **5 Bug-Fixes gepusht** (Board #477–479): 2 MySQL-only-Crashes (`category_id`, `||`→`CONCAT`), §7-Allergen-Konfidenz rekursiv „schwächstes Glied", Recompute topologisch (Diamond-sicher), MatchService Cross-Team-IDOR. **Regel:** MCP (`src/Tools/`) muss bei JEDEM Feature im Lockstep mit — kein Retrofit (Präzedenz R0.2).
- **R1.2 = nur noch Tuning** (Downgrade vom harten Gate): Aufschläge/Regler frei justierbar (Cost-plus-Baseline reicht zum Simulieren, R2.2). Echte Zielpreise optional — nur damit der Preis-Alarm (R2.1) nicht zirkulär gegen die eigene Baseline läuft.
- **R6-Depth:** Muttersaucen-Vererbung ✅ erledigt (liegt in der Wissens-DB, #469). **St.3-Rest** (Geschmacks-Editoren-Matrix, Hybrid-Fertigprodukte) + **St.4** (Gericht-Textur/SKF) + **St.5** (Event-/Trinitas-Hyperkanten) bleiben gültig — nur relevant, falls Foodpairing zum Schwerpunkt wird.
- **3-DB-Datenmodell endgültig RAUS** (veraltet — bestätigt; Chemie/Pairing SQL-nativ, Graph raus).
- **Bulk-Skripte (105/206/layer2): NICHT auf MySQL portieren** — 206-Recompute läuft in FA (`RecipeRecomputeService::recomputeAll`); 105/layer2 als Legacy/Beleg ablegen.
- **Board-Hygiene offen:** ~17 Issues im „Done"-Slot sind nie auf `is_done` geflippt (blähen die Feature-Zahl auf, „83" ≠ 83 offen); #470 „MySQL migrieren" ist erledigt, steht aber noch in „To Do".

## ⭐ Update 2026-07-13 (Session: FA-Demo-Testrunde abgearbeitet)

Dominiques Demo-Test auf demo.bhgdigital.de → 9 Befunde (#496–#504). **9/9 umgesetzt** (#504 als eigene Session, s. u.) (Details: Memory `project_fa_demo_testrunde_2026-07-13.md` + `project_fa_mcp_audit_504.md` + `00_INBOX/_FA_MCP_Audit_504_TODO.md`). Pest **668/669** grün (1 skip), je Fix MySQL-/Livewire-Smoke, null Regressionen.
- **#497** Aroma-Netz-Crash: `PairingService::aromaNetz` `distinct()` + `ORDER BY rp.type` (nicht im SELECT) = MySQL-3065 → `distinct()` raus (Downstream dedupt via `unique('id')`); Modul-`distinct()`-Sweep sauber.
- **#500** Foodbook-Dokument-Crash: Blade `$kunde` → `$customer` (dokumentDaten liefert seit #486-Rename `customer`).
- **#499** Alle KI-Funktionen auf demo down (kein LLM-Provider gebunden → un-catchbare `BindingResolutionException`): neue typisierte `KiNichtVerfuegbarException` (RuntimeException); `AiGatewayService::provider()` guarded (`app()->bound(...)`) + **vor** dem Backoff aufgelöst (28 ms statt 28 s Sinnlos-Sleeps); alle bare KI-Entry-Points gewrappt → graceful statt 500. **Martin-Teil offen: LLM-Provider auf demo binden** (entblockt zugleich R6.1-Blindtest #492).
- **#498** Basisrezepte-Liste: Feedback-Spalte raus (leer) + Name-Spalte flexibel (kein truncate); VK-Browser identisch nachgezogen; `feedbackAgg`-Query entfernt.
- **#496** MCP `knowledge.LIST` neu (`KnowledgeContextService::listDocuments`, Paging + Frontmatter-Parse thema/sub_thema/relevanz/recherche_datum/tags) — Bestand (~1.010 Docs) jetzt voll abrufbar (SEARCH cappt bei 50).
- **#503** Doppeltes Geschmacks-Chart: beide behalten, klar differenziert (Heading „· sensorisch" vs „· Aroma-Anker" + Skalen-/Quell-Subtitles).
- **#502** Kalkulations-Werkstatt aufgelöst: Regel-Editor zurück in Einstellungen → Herstellkosten; „Was-wäre-wenn" = eigener Preissimulations-Screen; Nav umbenannt; Autocomplete-Dropdown-`overflow`-Bug gefixt.
- **#501** Standalone interne R3.1-Ansicht **entfernt** (Route + Link + `Livewire\Foodbooks\Ansicht` + Blade + `FoodbookService::ansichtDaten`) — Kunden-Wording-Vorschau lebt im Editor-Menü-Toggle, Marge im Editor-Pax-Cockpit.
- **#504** MCP-Audit aller 49 Tools ✅ **ABGESCHLOSSEN 2026-07-13** (eigene Session). Alle 49 gegen 6 Dimensionen (Rename-Drift/MySQL-Kompat/Feature-Drift/Tenancy/LIST-Lücken/Contract-Hygiene) geprüft; ~25 komplett sauber. **Gefixt (je MySQL-Smoke, Write-Tools zusätzlich Cross-Team-Negativtest):** 4 HIGH Cross-Team-Write-IDOR (`concept_slots.POST` package_id, `foodbook_blocks.POST` concept_id, `canvas.PUT` owner isOwnedBy, `recipe_klasse.POST` acceptKlasse isOwnedBy) + 4 MED Tenancy (`canvas.GET` owner-Visibility, `speiseplan_eintraege.POST` 3 Ref-Guards, `signale.PUT` Ownership statt Ancestry, `foodbook_kapitel.POST` parent_id-Bindung); 1 Correctness (`foodbook_blocks.POST` Staffel-Desc `min_personen/preis`→`min_persons/price`); **2 MySQL-Crashes** in Browser-Services (`FoodbookService::paginateBrowser` `kunde`→`customer`, `SpeiseplanService::detail` `farbe/ist_vegetarisch`→`color/is_vegetarian`); 3 stale Descriptions (`anlass`→`occasion`). **7 neue LIST-Tools** (gps/artikel/recipes/verkaufsrezepte/concepts/angebote/signale — page/per_page-Paging, `read_only=true`, schließt die #496-LIST-Lücke katalogweit). **Entscheidungen Dominique:** deutsche Payload-Keys bleiben (Modul-Konvention); `foodbook_blocks.POST` dish-via-`text` entfernt (Doku-Regel „Foodbook komponiert Concepts", #11); `knowledge.PUT active` bleibt (dokumentiert gewollt, #9). Pest **668/669** grün, null Regressionen. **Live-Connector zeigt die 7 LIST-Tools erst nach demo-Deploy (Martin).**

## 🚉 Datenmodell-Fahrplan (Chemie/Pairing Phase 1–4 ⊕ 5 Produkt-Ebenen)

Quellen: `Datenmodell Food.Alchemist.md` (5 Ebenen) + `07.02_Flavor_Pairing/Datenbank Foodalchemist/_Plan_Datenmodell_Chemie-Pairing-DB.md` (Chemie-first Phase 1–4). Stationen von hier bis Voll-Ausbau:

**Station 0 — ERREICHT ✅**
- Ebene 1 Rohstoff (Anker/Moleküle/Chemie) · Ebene 2 Zustände (Prep-Delta, state-pairing 748/1000)
- Chemie-DB Phase 1: molecules 74k, `ingredient_aroma_vector`, 14/70-Ontologie, Klassifikator v2, computed pairings (Kalibrierung ρ 0,54 — ρ-Deckel strukturell)
- Ebene 3 Rezept: Signatur-Netz + Zustands-Charakter (Kern) · Know-how in FA-SQL (`knowledge_documents`)

**Station 1 — FUNKTION** → siehe `_NEXT_SESSION_TODO.md`
- MySQL: **migriert + Volldaten importiert** ✅ (Seeder-first verworfen — Dominique: „laden, dass es steht"; 121 Tabellen echt in MySQL, Canon).
- **#469 Wissens-Pflege-Modul: FERTIG ✅ + gepusht** (Browser + Kategorien/Einsatzorte + Bindungen grob/fein + Gateway-Injektion). Doku: `platforms-foodalchemist/docs/wissen.md`, Spec `_Wissensmodul_Spec.md`.
- #468 UI-Rendering (aroma/geschmack im Rezept-/GP-Panel): OFFEN.

> **Nachtrag 2026-07-11 Abend:** Der Seeder-first-Ansatz oben wurde in der Praxis übersprungen — Dominique wollte die lokale MySQL „so vorbereiten, dass sie steht", darum Volldaten via `import-master` importiert. #469 komplett gebaut. Nächster Hebel: Wissens-Modul auf demo sichtbar machen (Server-Schritte/Martin) + VK-Preise R1.2.

**Station 2 — Pairing-Projektion (Coverage-Loch schließen)** ✅ **DONE (2026-07-12)**
- computed-Kanten → FA `pairing_anchor_edges` als Lückenfüllung. Real **~145k** Kanten projiziert (nicht ~12k — die Label→Anker-Multiplizität + permissive harmonie-Kanten trieben die Menge), `source_slug='computed'` (keine `source`-Spalte), **gradiertes Gewicht** `weight = 0.6 × Molekül-confidence` (nullable Spalte) statt binärer Schwelle; `edgeBest()`/`componentSuggestions()`: `weight ?? GEWICHTE[type]`, **holes-only → kuratiert nie berührt** (Inv. 3+5). Gemessen am **Master (foodalchemist_full, 2.559 Rezepte): Coverage 36,6 %→58,1 %, Ø-Score 92→67 (ehrlich, kein Rauschen), 159 Rezepte aus 0 %-Coverage gerettet.** Command `foodalchemist:pairing-project-computed` (--apply/--purge, idempotent). Graph zudem **global** (`team_id=NULL`) + `import-master` bewahrt global.
  - **Station 3 (Anker-Reichweite):** molekular **ausgereizt** — von 187 unmapped Ankern nur 67 recipe-relevant, davon FooDB nur ~9 (grob); 8 saubere Mappings gesetzt → +2.642 Kanten. Exoten (yuzu/tomatillo/perilla/gochujang…) sind NICHT in FooDB, aber **kuratiert dicht** via `book_pairings` (9.034 geladene Buch-Kanten) — kein Mapping-Loch.
  - **Taxonomie (2026-07-12):** Kanten-Typen final **aroma / kontrast / erprobt** (klassisch+modern→`erprobt` verschmolzen, Ära ist kein Fit-Kriterium). Migration `000040` beide DBs + recipe_pairings; Code/Blades/Tests nachgezogen (627/628 grün).
  - **Graph-first Plattform-KI (2026-07-13):** `KnowledgeContextService::pairingBlock` zog die Pairing-Partner bisher aus dem **Markdown-Volltext** (`extractPairingNames`) — die in-App-KI (Rezept-Generator) „las die md" statt im Graphen zu denken. Jetzt aus dem **Anker-Graphen** (`PairingService::neighborsForName`), Typ-gefiltert je Stil (klassisch→erprobt, kreativ→erprobt+aroma, gewagt→aroma+kontrast); md-Prosa nur noch fürs Grounding. MCP-Pfad (Claude-Tools) war schon graph-first. Damit denken **beide** KIs im Gehirn.
  - **⚠️ „0,2 %-Kohärenz-Loch" war eine Metrik-Verwechslung:** Station 2 schloss das **Coverage/Dichte-Loch** (37 %→58 %). Die „0,2 %" aus Q5 ist etwas anderes — **% Rezepte mit *persistiertem* KI-Kohärenz-Score** (Tabelle `recipe_culinary_coherence`, aktuell 0 Zeilen). Das ist der **Q5-Batch-Lauf**, noch offen (KI-Judge, braucht echten Gemini-Provider — Dev = `fake`). Station 2 war die Vorarbeit; der Batch-Lauf ist die Ernte.

**Station 3 — Ebene 3 Rezept-Werkstatt komplett** ◻️
- Muttersaucen `ABGELEITET_VON` (Aroma/Allergen/Finanz-Vererbung) · **Geschmacks-Editoren als Kanten-Modifikatoren** (Säure→Frucht-Ester-OAV↑, Salz→Bitter↓, trigeminal-Multiplikator = Phase-4-Matrix-Effekte) · Hybrid-Fertigprodukte (virtuelles Aroma-Profil) · Rezept-als-eigene-Aroma-Identität (über Signatur-Netz hinaus).

**Station 4 — Ebene 4 Gericht** ◻️
- Konsistenz-Layer (role + texture als Kanten-Properties) · **SKF/Textur-Kontrast-Score** („Birnen-Bohnen-Speck": 5 Geschmäcker + Balance-Regeln + 60 Texturen, Buch S.36).

**Station 5 — Ebene 5 Event + Higher-Order** ◻️
- Menü-Dramaturgie (Intensitätskurve) · Buffet-Harmonie-Matrix · Flying-Sektoren-Verteilung · **Trinitas/Stacks als Hyperkanten** (CulinaryDB-Co-Occurrence + Buch-Verbund-Pairings).

**Station 6 — Volle Buch-Treue (Genauigkeits-Hebel, teils extern blockiert)** ◻️
- **OT(m)-Geruchsschwellen → echtes OAV** (blockiert auf externe OT-Tabelle) · **Food-Bridging** (Semi-Metric kürzester Pfad = Kontrast-Generator, NICHT Kosinus) · **Buch-Räder → scharfe `method='book'`-Vektoren** (hebt ρ-Deckel 0,54→0,60+) · Süße/Salz-Achsen sauber (USDA FoodData Central).

> Reihenfolge-Logik: Station 1 (Funktion) ist unabhängig; Station 2 ist der billigste Wert (Coverage); Station 3–5 sind der Produkt-Tiefgang (R6); Station 6 ist der Genauigkeits-Hebel (teils auf Datenbeschaffung wartend). „Keine Erfindungen" gilt durchgehend.

## Lesehilfe

| Feld | Bedeutung |
|---|---|
| **Größe** | S = Stunden · M = 1–2 Tage · L = 3–5 Tage · XL = >1 Woche |
| **Hängt an** | Harte Abhängigkeit — vorher nicht starten |
| **DoD** | Checkliste; alle Punkte erfüllt = Paket fertig |

### Globale DoD (gilt für JEDES Feature-Paket, zusätzlich zur Paket-DoD)

- [ ] Team-Scoping (`team_id`) + D1-Vererbung wo relevant
- [ ] Tool-fähig: Aktion ist als MCP-Tool aufrufbar oder bewusst als UI-only begründet (Dev-Modul-Discussion)
- [ ] KI-Schreibpfade: immer `status=draft` + `created_via`-Lineage, Freigabe nur menschlich
- [ ] `php -l` + Blade-Kompilierung grün, Pest-Tests für neue Services
- [ ] Lokal-verifiziert (UI-Klick → DB bewiesen). ⚠️ Migration 2026-07-11: Daten-Wahrheit wandert Sandbox-SQLite → **lokales MySQL (Kanon)**; bis abgeschlossen SQLite-Fallen UND MySQL-Zielverhalten mitdenken (siehe README-Architektur-Update + `_MEMORY_FoodBrain.md`)
- [ ] Committed + gepusht auf Modul-main, Dev-Modul-Issue aktualisiert
- [ ] Keine Core-/UI-/Fremdmodul-Änderung ohne Abstimmung (Goldene Regeln)

### Abhängigkeits-Kette (kritischer Pfad)

```
R0 Fundament ──► R1 Masse (994 VK) ──► R2 Wirtschaftlichkeit ──► R6 Alleinstellung
                     │                        ▲
                     ├──► R3 Digitales Foodbook│
                     ├──► R5 Compliance        │
                     └──► R4 Geführte Planung ─┘  (R4 liefert das Soll-Gerüst = Prompt-Material für R6 Brief→Konzept)
```

**Warum diese Reihenfolge:** Ohne Masse (R1) rechnen alle Features auf 5 Testgerichten — Preis-Alarm, Foodbook-Filter,
Coverage-Checks sind erst mit ~1.000 VK-Gerichten beweisbar. R4 vor R6, weil das Planungs-Gerüst die Messlatte ist,
gegen die die KI in R6 baut. R3 und R5 sind nach R1 parallelisierbar (unabhängige Datenpfade).

**Erweiterungen (Brainstorm 2026-07-04):** R2.4–R2.7 (Assemblierung, Auto-Pricing, Gericht-Bewertung, Benchmark) hängen an R1 und schärfen die Wirtschaftlichkeits-Maschine — R2.6 entkoppelt R2.3 sogar von der offenen Verkaufsdaten-Quelle. R6.8–R6.10 sind die **Pairing-Offense** (Graph als Waffe statt Wächter) auf dem R6-Track. R6.10 + der **Ausblick-Track N0–N2** (Nachbar-Modul Einkauf/Lager/Produktion/Event) hängen am Core-Contract (Q1/N0) — der ist damit vom „nice to have" zum Gründungsakt geworden. Die **FA-seitigen Planungs-Blätter (R7)** hängen nur an R1 und sind die Vorstufe, die N0 de-riskt: erst liefert FA die Blätter als Tools, dann kapselt der Contract sie — Berechnetes bleibt FA, operativer Zustand wird Nachbar-Modul. Der **Warum-Layer (R6-Querschnitts-DoD + R6.11)** hängt an **Q4** (Evidenz-Abdeckung) — ohne Evidenz-Fundament baut er auf Sand; der **A-Track** (Academy-Training) konsumiert ihn wie der N-Track den Contract. Die **Pairing-Offense (R6.8–R6.10) + Kohäsion** hängen an **Q5** (Konnektivität) — Baseline-Messung 2026-07-04 zeigt: Graph/GP-Erdung stark (98 %), aber Kohärenz nur 0,2 % berechnet und Rezept-Reichweite 60 % → Q5 ist die eigentliche Vorarbeit für R6.

---

## R0 — Fundament sichern *(sofort; alles hier blockiert Sichtbarkeit oder Datenvertrauen)*

### R0.1 Deploy auf demo.bhgdigital.de — Owner: Martin + Dominique · Größe S · ✅ **ABGESCHLOSSEN 2026-07-13 (inkl. DATEN)**

**DoD:**
- [x] Code live: demo läuft auf HEAD (519d7a6 inkl. R4/R6.1 — `concepts.GENERATE`/`coverage.GET` im Tool-Katalog live verifiziert); Schema-Reset + Frisch-Migrate durch Martin
- [x] Alle Modul-Migrationen fehlerfrei durch — `migrate:status` 0 pending, inkl. der 5 Migrationen vom 2026-07-13 (Forge-Deploy migriert jetzt automatisch)
- [x] MCP listet die FA-Tools (40+, Registry live geprüft)
- [x] Smoke: `foodbooks.POST` → `foodbook_kapitel` → `foodbook_blocks.POST` legt Draft-Foodbook mit echtem Gericht an (FB #9 auf demo; `recipes.POST`-Schreibpfad war schon durch R0.2-E2E bewiesen)
- [x] Queue-Worker läuft (2 Worker: database + attachments, per ps verifiziert)
- [x] **BONUS — Daten-Import (Etappe 2, war der eigentliche Rest):** `fa_master_export_2026-07-13.sqlite` (HEAD-Schema, R1.2-Preise) via `import-master --team=6 --fresh` auf demo — dry-run-gecheckt, Row-Count-Gate, Transport-Dateien wieder gelöscht. Live: 7.943 GPs, 3.220 Rezepte, 929 VK-Gerichte MIT Presentations+Preisen, 2.265 Basisrezepte in der UI. SSH-Zugang Dominique eingerichtet (Forge, Key auf BHG.DIGITAL.DEV1 = 49.13.90.76).

### R0.2 MCP-Darreichungs-Nachzug M1–M6 · Größe M · Hängt an: nichts (parallel zu R0.1) · ✅ **ABGESCHLOSSEN 2026-07-12**

Die Tools waren darreichungs-blind — für externe LLM-Clients existierte das neue Verkaufs-Modell nicht. Jetzt behoben.

**DoD:**
- [x] `verkaufsrezepte.SEARCH`/`GET` liefern Formen je Gericht (inkl. EK/VK je Form, Standard-Marker) — `presentations[]` via `FoodAlchemistTool::darreichungenSummary`
- [x] `kalkulation.GET` rechnet über den `DarreichungResolver`, nicht über `recipes.vk_netto` — `KalkulationService::recipeHk` (concept/paket liefen schon so)
- [x] `concepts.GET/POST` + `concept_slots.POST` können Facetten (Servierform/Eventtyp/Momente/Saisons) und Slot-Darreichung lesen/setzen (Slug/Name→id-Resolver)
- [x] `recipes.POST` erzeugt automatisch eine Standard-Darreichung (`created_via=mcp`) — `DarreichungService::ensureStandard`
- [x] GL-07-Klassifikator kennt die Bauart-Regel (E7: „Wie gebaut?", nie „Wo eingesetzt?") + nur aktive Hauptgruppen — Prompt + Aktiv-Filter; nebenbei latenter MySQL-`||`-Bug im Taxonomie-Label gefixt
- [x] E2E: MCP baut Konzept mit Buffet-Form → Resolver zieht Buffet-Preis (2,32 statt 25) — Pest `McpDarreichungenTest` + MySQL-Smoke (Beweis wie Phase 5)

> **✅ Abschluss 2026-07-12 (gepusht, Commit `d5409a6`):** 38 Tools darreichungs-fähig. ⚠️ Zwei-Darreichungen-Fall im automatisierten Test nur auf MySQL abbildbar (In-Memory-SQLite behandelt den partiellen Ein-Standard-Index wie ein volles `unique(recipe_id)` — R0.5-Testbasis); Beweis darum per MySQL-Smoke. Detail: Memory `project_fa_mcp_schreibkaskade`.

### R0.3 Datenqualitäts-Kaskade (Ampel + bottom-up Remediation) · Größe L · Hängt an: nichts · 🟢 **Etappe 1 GEBAUT 2026-07-14 (lokal, verifiziert am Master)**

**Neuzuschnitt 2026-07-14 (Dominique):** Statt Top-down-Flickerei die ganze Kaskade **bottom-up** heilen — Lieferantenartikel → GP → Basisrezept → VK-Gericht — plus Anker-Erdung + volle Anreicherung. Die „unbepreisten Ketten" oben sind Symptome von GP/LA-Lücken unten. Ausführungsplan (2 Etappen, KI-Schritte lokal via OpenAI): siehe Session-Memory `project_datenqualitaet_kaskade_2026-07-14` (folgt) + Plan-Datei. Die 2 WaWi-Ära-Punkte (FA↔WaWi-EK-Divergenz, nutri-Sync 235) sind **obsolet gestrichen** (FA=Master, WaWi eingefroren, kein Sync mehr).

**FA-native Commands (neu, thin wrappers um bestehende Services):**
| Command | wrappt | Zweck |
|---|---|---|
| `foodalchemist:data-quality {--team --json --signals}` | neuer `DataQualityService` | Ampel: per-Ebene-Counts (LA/GP/BR/VK/Quer); `--signals` schreibt Lücken über `SignalService` in die ReviewQueue-Inbox (dedup, MCP-sichtbar via `signale.SEARCH`); schedulebar |
| `foodalchemist:lead-la-repick {--team --used-only --apply}` | `LeadLaService::applyLeadLa` | chirurgischer Lead-Repick nur wo aktueller Lead nicht auflöst + ein bepreister LA existiert |
| `foodalchemist:gp-allergen-backfill {--chunk --apply}` | `GpAggregateService::allergenKonfidenz` | persistiert NUR Allergen-Metadaten (`allergens_source/_confidence/_aggregated_at`), NIE die Wert-Spalten (Override-Schutz); Konflikte → Signal |
| `foodalchemist:recompute {--all\|--recipe= --propagate --apply}` | `RecipeRecomputeService::recomputeAll` | fehlender Bulk-Recompute (war nur Golden-Test); propagiert geheilte GP-Preise nach oben |

**Etappe-1-DoD (deterministisch, kein LLM):**
- [x] **Mess-Ampel** gebaut (`DataQualityService` + Command + 3 Signal-Typen `AnkerFehlt`/`ServierformUnbestimmt`/`EkKetteUnvollstaendig`); 12 Befunde als dedup'te Signale am Master
- [x] **Lead-LA-Repick:** 90 GP-Leads gefixt (auflösend 4.900 → 4.990); 405 echte Lücken sauber als „Park" erkannt (kein bepreister LA → Sourcing = Etappe 2)
- [x] **GP-Allergen-Backfill:** „ohne Konfidenz" **6.947 → 0**; 289 Allergen-Konflikte (LA↔LA) als Signal; Wert-Spalten nachweislich unberührt (Guard-Test)
- [x] **Bulk-Recompute** gelaufen (3.218 Rezepte, 0 Zyklen); EK propagiert
- [x] Backups vor jedem Apply (`PRE_DQ_CASCADE` voll + `PRE_P3` gps); 13 neue Pest-Tests grün
- [ ] `unbestimmt`-Servierformen (329) kuratiert → **Etappe 2** (KI je Gericht)
- [ ] Rest-Stubs fb2027 (12) + tentative-in-Rezept (27) + itemisierte 405-Park-Sourcing-Liste → Review/Etappe 2
- [~] Anker-Erdung (84 GP + 91 BR + 151 VK) + volle Anreicherung → **Etappe 2** (lokaler OpenAI-Provider)
- ~~FA↔WaWi-EK-Divergenz~~ · ~~nutri-Sync 235~~ — obsolet (FA=Master)

> **Ehrlicher Befund:** Der große EK-Rest-Stau (219 VK / 788 BR teil-unbepreist) hängt strukturell an den **405 Park-GPs** (kein bepreister LA irgendwo) → LA-Sourcing = Etappe 2, nichts, was Lead-Repick/Recompute deterministisch heben könnte. Etappe 1 hat die deterministischen Free-Wins gehoben. Master-Daten-Heilung → demo per Re-Export + `import-master` (separat).

### R0.4 Skill-Infrastruktur (Phase D abschließen) · Größe S · **Entscheid: Dominique/Martin (S3)**

**DoD:**
- [ ] S3-Credentials-Entscheid gefallen, Obsidian-Vault mit `skills_enabled` auf office.bhgdigital.de existiert
- [ ] `foodalchemist.foodbook_anlegen` hochgeladen, via `skill_registry.SEARCH` auffindbar
- [ ] Ein externer LLM-Client hat den Skill einmal komplett durchlaufen (7 Schritte) → Draft-Foodbook entstanden

### R0.5 Testbasis reparieren · Größe S · ✅ **ABGESCHLOSSEN 2026-07-12** (Suite grün: 621, 620 ✓ / 1 begründet skipped)

**DoD:**
- [x] Pest-Runner-Problem gelöst (`tests/bootstrap.php` strippt das `15_GITHUB`-Segment; Suite läuft) — Standard dokumentiert (`_SANDBOX_NOTES.md`)
- [x] `DarreichungService` + `DarreichungResolver` haben Tests — `DarreichungServiceTest` (ensureStandard-Idempotenz/Ein-Standard, Resolver `standardFuer` + Fallback, Money-Path Preis-Wahrheit) + `McpDarreichungenTest` (M1–M4, Facetten, Fallback). ⚠️ Delta-Mischpreis + Zwei-Darreichungen-Fall (Buffet gewinnt) auf In-Memory-SQLite nicht abbildbar (partieller Ein-Standard-Index) → MySQL-Smoke (R0.2)
- [x] Money-Path-Regression: „Preis kommt aus der Standard-Darreichung, recipes.sales_net spiegelt" automatisiert (SQLite-tragfähig); der spezifische Zwei-Zeilen-Beweis (Buffet 2,32 € statt Standard 25 €) = MySQL-Smoke (SQLite-Grenze dokumentiert)

> **✅ Abschluss 2026-07-12:** Ganze FA-Pest-Suite von **26 rot → 0 rot** (621 Tests, 1 begründet skipped = Panel-KI-Marketing M6-05). Root-Cause fast durchgängig **English-Rename-Drift auf der Test-Seite** (Allergen-Keys, Kosten-Keys, Blade-Attribute, Result-Shape) — Produktivcode kanonisch, Fixes daher Test-seitig; 3 Diagnose-Subagenten + manuelle Cluster-Arbeit. **2 echte Code-Bugs mitbehoben:** `FoodAlchemistRecipeFeedback` fehlte `LogsActivity` (R2.6-Regression), `RecipeGeneratorService` Default-AK-Fallback jetzt Klasse-vor-Hauptgruppe. Detail: Memory `project_fa_r05_testbasis_2026-07-12`.

### R0.6 Komfort-Nachzüge A3 + A5 · Größe S · *optional, lückenfüllend*

**DoD:**
- [ ] A3: Kernrezept-Änderung erzeugt „Varianten prüfen"-Hinweis an allen Nicht-Standard-Darreichungen
- [ ] A5: Behälter/Regeneration/Vehikel je Darreichung im Darreichungen-Tab editierbar (Spalten existieren)
- [ ] **A6 Multi-Geschirr je Gericht (Modell-Erweiterung, Größe M):** heute nur EIN `serving_vehicle_vocab_id` pro Darreichung — reale Gerichte brauchen mehrere Geschirr-Teile (Bowl-Beispiel: 4 Teile, 2 davon für eine Sauce = Saucenbecher + Deckel). → **Geschirr-Bedarfs-Liste je Gericht** (n Positionen, Menge, optional „gehört zu Komponente X"), statt Einzel-Slot. Vokabular (Saucenbecher/Deckel/Salatschale/Schraubglas) als Geschirr anlegen. fb2027-Import: Verpackungs-Zeilen stehen solange auf `match_method='ignored'`. Passt zu R7 „Geschirr: Bedarf hier".

---

## R1 — Masse: Foodbook-2027 Phase 2 *(größter Hebel — alles Weitere braucht diese Daten)*

### R1.1 994 VK-Gerichte FA-nativ erstellen (mit Rezeptur + Mengen) · Größe L · Hängt an: R0.3 · ✅ **ABGESCHLOSSEN 2026-07-05**

**Ziel (Dominique):** Die 994 VK-Gerichte des Foodbook 2027 mit vollständiger **Rezeptur** anlegen — Inhalt = bestehende
Basisrezepte + direkte GPs, mit den korrekten Mengen. Direkt in die FA-Master-DB. **Kein Import, kein Sync** — es gibt nichts
zu promoten (die VK-Gerichte existieren noch nicht) und WaWi ist eingefroren (`chmod 444`, read-only Archiv).

**Quelle:** zwei PDFs im Foodbook-2027-Ordner (gleicher Menü-Export, 1.068 Seiten) — `A7716CF7_menu_…` (1 Portion) +
`große mengen…A7716CF7…` (Ansatz). Aus derselben Quelle kam auch ein Teil der Basisrezepte. Parser-Pipeline ist gehärtet
(Block-Bleed-Fix, 203c-Bio-Abwertung, 260-Mengen-Präfix-Fix) → für die FA-native Erstellung wiederverwenden, Schreibziel =
FA-Englisch-Schema, Recompute via `artisan`. Das ist das „disziplinierte Python-Fenster auf der Master-DB", das der
Migrationsplan erlaubt (wie der 105-Klassifikator) — kein WaWi-Auftauen.

**Mengen-Regel (Dominique):** Gerichte als **1 Portion** (`portionen=1`). **Mengen = Ansatz-PDF ÷ Portionszahl**,
das 1-Portions-PDF nur als Kreuz-Check — Lehre aus 271: die 1-Portions-Werte sind gerundet (Präzisionsverlust bei Gewürzen).

**DoD:**
- [x] Alle 994 VK-Rezepte in FA angelegt: `is_sales_recipe=1`, `status=review`, `created_via` gesetzt, `herkunft`-Slug, `portionen=1`
- [x] **Vollständige Rezeptur je Gericht:** Komponenten gegen **bestehende Basisrezepte gematcht** (`referenced_recipe_id`, kein Dubletten-Neubau), direkte Zutaten GP-gematcht (`gp_id`); ungemappte Zutaten = 0
- [x] Mengen aus dem Ansatz-PDF abgeleitet (skaliert auf 1 Portion), nicht aus den gerundeten 1-Portions-Werten
- [x] 0 zirkuläre Wrapper/Stub-Paare, 0 verwaiste Refs — **74 self-ref/leere Basisrezepte als Wurzel identifiziert + aufgelöst** (Skript 294)
- [x] Jedes VK-Gericht hat genau 1 Standard-Darreichung; Servierform `fingerfood`/`unbestimmt` → Review-Queue (Rest-Kuration R1.2)
- [x] Neue tentative GPs nur durch Review-Gate (kein stilles Anlegen — GPs bleiben kuratiert)
- [x] `artisan recompute` grün: **EK-Abdeckung 977/994 = 98,3 %; alle Einzelgerichte 100 % gekostet** (Rest = 15 Pakete + 2 Lunchpakete → Concepter-Composition); Allergen-/Zusatzstoff-/Nährwert-Aggregation vollständig
- [x] FA-Backup vor Lauf (`PRE_FB2027_VK` + zahlreiche `PRE_*`), Läufe resumefähig (Cache-Tabellen), idempotent

> **✅ Session-Abschluss 2026-07-05:** 994 VK-Gerichte FA-nativ angelegt (Skript 280) → EK von 860 → **977/994 (+117)**. Kette lückenlos: alle Einzelgerichte + alle 50 Paket-Komponenten existieren & gekostet.
> **Meilensteine der Session:** (1) **Wurzel-Reparatur** — 74 kaputte Basisrezepte (Self-Ref/leer, Slug-Bridge-Bug) gefunden & befüllt/gemergt; (2) **Import-Audit** — Necta-1494-Export vollständig & 1:1 (6 Tabellen matchen Manifest; „fehlende" Preise = quellseitige Status-2-Lücken, nicht Import); (3) **belegte → Fresh Company** (Skript 295), **Fertigsalate/Desserts/Snacks → GP**, **UniPek-Banichka-Filo** (8 GPs); (4) **GP-LA-Matching** der neuen GPs (94/118) + 20 Dubletten gemergt.
> **Skripte:** 280 (Anlage), 288 (GP-LA), 293 (Dedup), 294 (Broken-Basisrezepte), 295 (belegt→FC), 296 (Einzelgerichte-Match). **2 wiederkehrende Fallen dokumentiert** (Memory `project_fb2027_vk_anlage.md`): INSERT-Param-Reihenfolge (match_method-Korruption crasht Recompute, `try/catch` schluckt es); `lead_la` ohne `supplier_item_structures`-Zeile → Preis löst nicht auf (EK=0).

### R1.2 VK-Kuration: Servierformen + Klassen + W% · Größe L (verteilt) · Hängt an: R1.1

**DoD:**
- [ ] `unbestimmt`-Servierformen der 994 kuratiert (GL-07/Bauart-Regel als Vorschlag, Mensch entscheidet) — *teilweise; Rest offen*
- [x] Speisen-Klassen vergeben (nur aktive 11 HGs) — Skript 289 (dish_class HG×Diät)
- [ ] W%-Ampel übers neue Portfolio gesichtet; Ausreißer > 35 % geflaggt und entschieden — *offen (jetzt möglich, EK steht)*
- [x] Anreicherung gelaufen (Beschreibung/Kochanweisung, Niveau/Sektor, Anker, Pairing, Sensorik) — FA-nativ (Skripte 290/292)

> **Stand 2026-07-05:** Klassifikation + Anreicherung durch (994). Offen bleibt die **Servierform-Rest-Kuration** (`unbestimmt`) und der **W%-Ampel-Durchgang** — beides jetzt sinnvoll, da die EK-Basis vollständig steht.
>
> **Stand 2026-07-12 (VK-Baseline gesetzt):** Prämisse aus Discussion #17 korrigiert — das **Verkaufs-Foodbook-PDF liefert KEINE Pro-Gericht-VK** (bepreist Konzepte pro Person; verifiziert). Stattdessen **Cost-plus-Auto-VK** gesetzt: quantity_per_unit_g = yield×1000 + Aufschlagsklasse **Bankett 260 %** + Recompute → **870/929 Gerichte bepreist** (vorher 3), deterministisch, auto-mode (überschreibbar), Backup `PRE_R12_AUTOVK`. 32 Review-Fälle → `00_INBOX/_R12_VK_Review_2026-07-12.md`. **⚠️ Konsequenz für R2.1:** Cost-plus-VK folgt dem EK (Marge konstant) → der Preis-Alarm greift erst mit **fixem Kundenpreis** = die PDF-Konzept-Preis-Ebene (Discussion-#17-P3, an Konzepte/Pakete). Empfehlung dort: P3 von „optional" auf „R2.1-Voraussetzung" hochstufen.
>
> **DoD-Ergänzung:** [x] VK-Baseline je Gericht gesetzt (Cost-plus) · [ ] W%-Ampel (unter Cost-plus konstant → erst mit Fix-Preisen aussagekräftig) · [ ] Konzept-/Pro-Person-Preise (→ P3).

---

## R2 — Wirtschaftlichkeits-Maschine *(Horizont 1 — macht das System unverzichtbar)*

### R2.1 Preis-Alarm + Marge-Impact · Größe L · Hängt an: R1 (sonst rechnet er auf Testdaten) · ✅ **ABGESCHLOSSEN 2026-07-12** (gegen bepreiste Masse verifiziert)

**DoD:**
- [x] Trigger: LA-Preisänderung > X % (Schwelle team-konfigurierbar in `settings`) erzeugt Signal — `SignalDetektorService::preisSprungMargeImpact`, `TeamSettingsService::preisAlarmSchwellePct` (Default 15 %)
- [x] Impact-Ansicht: „betroffen: N Rezepte, M Konzepte, Marge-Delta in € und W%-Punkten" — klickbar bis ins Gericht (Signal-Payload + Impact-Block im ReviewQueue-Blade, Gericht-Links → Verkaufs-Browser)
- [x] Impact rechnet über Lead-LA-Logik UND zeigt, wenn ein Nicht-Lead-LA günstiger geworden ist (`guenstigereAlternative`, Chance-Fall)
- [x] Signal reversibel/abhakbar (bestehendes Signale-Muster), via `signale.SEARCH` MCP-sichtbar
- [x] Test: synthetischer Preissprung +25 % auf Massen-GP (Salz #13195, Reichweite 275 bepreiste Gerichte) → **Detektor-Signal `n_gerichte=275` == rekursive-CTE-Hand-Query 275 (MATCH ✓)**, gegen die 868-bepreiste FA-Voll-Masse, Transaktion zurückgerollt
- [x] Läuft automatisch beim Preis-Import — via Scheduler (`SignaleDetektorCommand`); engerer Event-Hook in `PriceService::createFor` bewusst nicht (feuert pro Zeile im Bulk)

> **✅ Abschluss 2026-07-12:** R2.1 war seit 2026-07-06 gebaut+gepusht, aber nur auf Testdaten belegt. Jetzt **gegen die R1.2-bepreiste Masse** (868/929 Gerichte, DB `foodalchemist_full` aus `fa_mysql_FULL_2026-07-12.sql.gz`) verifiziert: Betroffenen-Zahl exakt gegen Hand-Query bewiesen. Ehrlicher Nebenbefund: bei einem billigen Commodity (Salz) ist das Marge-Delta ≈ 0 € trotz +25 % — das Tool erfindet keinen Impact (exposure-korrekt). Detail: Memory `project_fa_r2_scharfstellen_2026-07-12`.

### R2.2 Was-wäre-wenn-Simulation · Größe L · Hängt an: R2.1 (nutzt dieselbe Impact-Rechnung) · ✅ **ABGESCHLOSSEN 2026-07-12** (UI-Panel + Massen-Perf)

**DoD:**
- [x] Szenario definierbar: Warengruppe ODER Einzelartikel ODER GP ± X % — UI-Panel `Kalkulation/Simulation` in der Kalkulations-Werkstatt (WG-Dropdown, GP-Schnellsuche, Artikel-id) + MCP-Tool
- [x] Portfolio-Antwort: Marge-Delta gesamt + Top-20-Betroffene, ohne Echtdaten zu verändern (reine Lese-Simulation) — KPI-Kacheln + Top-Tabelle, read-only-Marker
- [~] Ersatzvorschläge aus `component_equivalents` inline — Strecke steht (Panel zeigt Vorschläge); Katalog aktuell dünn befüllt (1 Zeile) → oft leer. Voll-Ausbau + „Tausch spart Y €"/Klick-Übernahme = R6.3/R6.8-Track
- [x] Simulation als MCP-Tool (`simulation.POST`, read-only-Semantik) — `SimulationPostTool`
- [x] Performance: Portfolio-Simulation über ~1.000 Gerichte < 10 s — **WG-Extremfall (Convenience, 1538 Lead-GPs → 599 betroffene Gerichte / 1392 Rezepte) = 8,7 s** gegen die Voll-Masse. **Speicher-Peak 245 → 111 MB** (Cache-Eviction im `MargeImpactService`/`SignalDetektorService`: schwere Rezept-Modelle nach dem Memoisieren freigegeben — kein Recompute, Ergebnis byte-identisch) → jetzt sogar unter 128 M, Server-Risiko behoben

> **✅ Abschluss 2026-07-12:** R2.2-Service+MCP-Tool waren seit 2026-07-06 da; das **fehlende UI-Panel** ist jetzt gebaut (Kalkulations-Werkstatt) und gegen die bepreiste Masse Perf-verifiziert. 4 neue Pest-Tests (`SimulationPanelTest`) grün. Speicher-Footprint bei Mega-WG von 245 → 111 MB gesenkt (result-preserving). Detail: Memory `project_fa_r2_scharfstellen_2026-07-12`.

### R2.3 Menu-Engineering mit Ist-Zahlen · Größe XL · Hängt an: R1 + **Vorentscheid Datenquelle**

⚠️ **Offene Vorfrage (vor Baustart klären):** Woher kommen Verkaufs-/Bankettdaten, seit Necta raus ist?
Realistisch: CSV/Excel-Export aus Bankettprofi o. ä. Format-Spec MUSS vor dem Bau stehen — sonst bauen wir einen Import ins Blaue.

**DoD:**
- [ ] Import-Format-Spec dokumentiert (Docs im Dev-Modul) + Beispieldatei eines echten Caterers erfolgreich geladen
- [ ] Matching Verkaufsposition → VK-Gericht mit Review-Queue für Unmatched (kein stilles Raten — Wording-Matcher-Muster aus Skript 250 wiederverwenden)
- [ ] Stars/Renner/Schläfer/Penner-Matrix (Popularität × DB) je Konzept/Zeitraum
- [ ] DB-Ranking + W%-Ampeln übers Portfolio, filterbar nach Facetten
- [ ] Mindestens 1 echter Datensatz eines BHG-Caterers durchgelaufen, Ergebnis mit Dominique plausibilisiert

### R2.4 Marge-optimale Menü-Assemblierung · Größe XL · Hängt an: R1 + R4.1

Aus dem Portfolio *lösen* statt *raten*: gegeben Rahmen (Preis/Gäste/Constraints) → DB-maximale Gericht-Kombination.

**DoD:**
- [ ] Solver: Zielpreis p. P. + Gästezahl + Coverage-Constraints (Diät-Quoten, Gang-/Stations-Gerüst, Preisspannen) → DB-maximale Kombination aus dem VK-Portfolio
- [ ] Nur echte VK-Gerichte, keine Halluzination; Slot ohne zulässigen Treffer bleibt leer + Begründung
- [ ] Lösung erklärt sich: welche Constraints bindend, wie weit vom Optimum bei Lockerung X
- [ ] Als MCP-Tool (`assemblierung.POST`, read-only-Semantik) — KI kann Varianten durchspielen
- [ ] Übernahme nur explizit (Konzept `status=draft`), kein Auto-Commit
- [ ] Performance: Portfolio ~1.000 Gerichte < 15 s
- [ ] Test: kleiner Constraint-Satz mit hand-gerechneter Optimallösung exakt reproduziert

### R2.5 Saison-Auto-Pricing (intern-vorschlagend) · Größe M · Hängt an: R2.1 + R3.1

Löst den Vertrauensbruch durch **Trennung**: interne Live-Marge vs. veröffentlichter, freigegebener VK.

**DoD:**
- [ ] Saubere Trennung: interne Marge (EK live aus Resolver) ↔ veröffentlichter VK = freigegebener Snapshot
- [ ] Trigger: Marge verlässt team-konfigurierbares Zielband → Signal „VK-Anpassung empfohlen: N Gerichte, Richtung + Delta" (Signale-Muster aus R2.1)
- [ ] Freigabe menschlich + als Batch: ein Klick veröffentlicht neuen VK-Snapshot; kein stiller Kunden-Preissprung
- [ ] Kundensicht (R3.2) zeigt ausschließlich freigegebenen VK; Verfügbarkeit/Allergene bleiben live
- [ ] Leitplanken konfigurierbar: Mindestmarge, max. VK-Delta je Freigabe
- [ ] Test: EK-Sprung → Signal korrekt; OHNE Freigabe bleibt veröffentlichter VK unverändert (Response-Grep Kundensicht)

### R2.6 Feedback je Gericht/Rezept (Küche · Kunde · Event) · Größe M · Hängt an: nichts (FA-nativ; sinnvoll ab R1)

Feedback-Tab am Gericht UND am Basisrezept — **zwei Zwecke**: (1) Popularität für Menu-Engineering OHNE Verkaufsdaten-Import (entkoppelt R2.3), (2) **Küchenmitarbeiter-Feedback als Entwicklungs-Motor** — die Küche bewertet/kommentiert Rezepte & Gerichte aus der Praxis (Machbarkeit, Aufwand, Geschmack, Gäste-Reaktion), auf dieser Basis werden sie iterativ weiterentwickelt. Der Koch, der es kocht, ist die ehrlichste Quelle.

**DoD:**
- [x] Feedback-Tab am Gericht **und am Basisrezept**: Einträge mit Quelle (**Küche** · Kunde · Event), Score, Kommentar, optional Kontext — geteiltes `FeedbackPanel` in VkModal + RecipeModal
- [x] Küchen-Feedback strukturiert: Achsen Machbarkeit/Aufwand · Geschmack · Gäste-Reaktion (nur bei quelle=kueche); Score = Mittel aus Machbarkeit/Geschmack/Gäste, wenn nicht gesetzt
- [x] Aggregation: Ø-Score + Verteilung je Quelle + jüngste Kommentare, sichtbar in **Verkauf- und Rezept-Browser (Badge)** + **internem Foodbook (Menü-Ansicht)** + **im Editor**; on-read (kein Recompute)
- [x] Speist die Popularitäts-Achse des Menu-Engineering (R2.3) — Feedback als eigene Quelle, entkoppelt R2.3 vom offenen Verkaufsdaten-Import
- [x] „Weiterentwickeln"-Brücke: 1 Klick → Draft-Rezept-Iteration (via `RecipeService::duplicate` + status=draft), Lineage `feedback.spawned_recipe_id`, idempotent
- [x] MCP: `foodalchemist.recipe_feedback.POST` (created_via=mcp) + `.SEARCH` (Aggregat read-only), Quelle inkl. `kueche` — registriert
- [x] Team-Scoping + D1: **vertikaler Scope** (Ancestry ∪ Descendants) — Kind bewertet eigenständig + sieht geerbtes, Eltern sieht Kinder aggregiert, Geschwister isoliert
- [x] Test: 3 Feedback-Einträge (Küche/Kunde/Event) → korrekter Ø + korrekte Team-Sichtbarkeit — Pest `FeedbackTest` (7 Tests) + `SimulationPanelTest`-Muster

> **✅ Abschluss 2026-07-12:** FA-nativ gebaut (Tabelle `foodalchemist_recipe_feedback`, `FeedbackService`, Enum `FeedbackQuelle`, 2 MCP-Tools, geteiltes `FeedbackPanel` in beiden Editoren, Badges in beiden Browsern + Foodbook). 7 neue Pest-Tests grün, 0 Regressionen (Adjazenz-Suite). Drive-by-Fund: pränataler English-Rename-Drift (`diaetform`→`diet_form`, `is_organic/is_regional`→`tag_is_organic/tag_is_regional`) im VK-Editor-Pfad gefixt (VkModal 500te auf MySQL). ⚠️ **Offener Rest des Drift-Clusters** (nicht in R2.6-Scope): `IngredientEditor` GP-Bio/Regional-Filter (Zeile 219/220), `FoodAlchemistGp`-fillable, `ImportSliceCommand` nutzen weiter `is_organic/is_regional` → eigener Cleanup. Detail: Memory `project_fa_r26_feedback_2026-07-12`.

### R2.7 Portfolio-Benchmark (BHG-intern) · Größe M · Hängt an: R1 · ✅ **ABGESCHLOSSEN 2026-07-12**

Multi-Tenant *aggregieren* statt nur *trennen* — Netzwerk-Effekt, der mit jedem Caterer stärker wird.

**DoD:**
- [x] Kennzahlen je Team aggregiert: EK-Abdeckung, Allergen-Konfidenz „hoch", Formen-Vollständigkeit, Ø-Wareneinsatz, Ø-Bewertung, Gericht-Zahl — `BenchmarkService::kpisFuerTeam`
- [x] Vergleich Team vs. anonymisierter Peer-Median — nur Aggregat, keine Fremd-Gericht-Details, keine Peer-Namen (Leak-Grep-Test grün)
- [x] Datenschutz-Grenze: nur innerhalb der Root-Team-Kette (`netzTeamIds` = Root + Descendants); kein Cross-Kunde
- [x] Als Dashboard-Kachel (eigen vs. Peer-Median, Farbcode besser/schlechter) + MCP-Tool `foodalchemist.benchmark.GET` (read-only)
- [x] Extern-Benchmark bewusst NICHT enthalten
- [x] Test: `BenchmarkTest` (5) — 1-Peer- + 2-Peer-Median exakt, Einzel-Gastronom (0 Peers), Leak-Grep

> **✅ Abschluss 2026-07-12:** `BenchmarkService` + `BenchmarkGetTool` + Dashboard-Kachel. Peer = andere Teams derselben Root-Kette MIT Portfolio (n_dishes>0, anonym). Ø-W% engine-agnostisch in PHP gerechnet (SQLite-decimal-TEXT-Falle). 5 Pest-Tests grün, 0 Regressionen. Detail: Memory `project_fa_r26_feedback_2026-07-12`.

---

## R3 — Digitales Foodbook *(vorgezogen — interner Use Case zuerst; parallelisierbar zu R2 nach R1)*

### R3.1 Web-Foodbook intern · Größe XL · Hängt an: R1 · 🟢 **intern-Dokument GEBAUT 2026-07-13 (lokal ungepusht)**

> **Richtungs-Entscheid Dominique 2026-07-13 (#501-konform):** Das „interne Foodbook" ist **kein Standalone-Livewire-View** (der wurde in #501 bewusst gelöscht), sondern das **aufgewertete Dokument** — navigierbar/klickbar + Marge. Der Editor bleibt die Bau-/Filterfläche. Die *externe* Sicht (R3.2) wird eine eigene, gebrandete **Web-Seite** (Bilder/KI, Preise pro Person, kein Pax) — größerer Neubau.

**DoD:**
- [x] Navigierbares/klickbares Dokument: **Navleiste** (Kapitel-Sprungziele, klickbar in HTML UND PDF via Anker) + Kapitel-Baum mit Tiefe. Volltextsuche = Editor/Browser-Sache (Dokument ist Lese-/Versand-Fassung)
- [x] **Interne Sicht zeigt EK/VK/W% pro Person** je Kapitel + Gesamt (`dokumentDaten($intern=true)` → `/foodbooks/{id}/dokument?intern=1`, Kunde/Intern-Umschalter, „INTERN"-Badge + „nicht weitergeben"-Fuß). Marge NIE im Kundendokument (per-Test bewiesen: Kundensicht ohne `ek_pro_person`)
- [x] Preise/W% live aus der bestehenden Kaskade (`kapitelAggregat`/`gesamt`, Resolver) — kein Snapshot
- [ ] Facetten-Filter (Servierform/Eventtyp/Saison/Diät/Allergen) — **offen** (gehört eher zur R3.2-Web-Seite / einem filterbaren Foodbook-Browser; Taxonomie-Modelle da, nicht am Foodbook verdrahtet)
- [ ] Lasttest 500+ Blöcke < 3 s — offen (Dokument rendert derzeit voll; relevant erst bei der Web-Seite mit Lazy-Load)
- [x] Test: interne Projektion (EK/W%/Anker) + Blade-Render intern vs. Kunde — `FoodbookServiceTest` (2 neue Tests) grün; Editor-Link „Dokument (intern)"

### R3.2 Kunden-Ansicht = externe Web-Seite · Größe L · Hängt an: R3.1 · 🟢 **v1 layout-first GEBAUT 2026-07-14 (lokal)**

> **Block C der Ausgabe-Schicht (Dominique):** die *externe* Sicht ist eine eigene **gebrandete Web-Seite** (Bilder/KI, Preise pro Person, kein Pax), NICHT nur ein Doc-Toggle. v1 = Layout/Struktur (auth-gated), Bilder + per-Kunde-CI + Share-Link folgen.

**DoD:**
- [x] Eigenständige Kunden-Web-Seite `/foodbooks/{id}/praesentation` (Livewire-Full-Page): Hero + Kapitel (Konsumententitel + Preis pro Person) + Wording-Gericht-Zeilen + Preis-Fuß/MwSt. Serverseitige Kunden-Projektion (`dokumentDaten intern=false`) → **EK/W%/Interna nie im Response** (nicht CSS-versteckt)
- [x] Wording über WordingResolver-Kette; **Kunden-CI (Brand/Farben) offen** (Foodbook hat nur `writingStyle`, keine Brand-Relation → neutrale Gestaltung v1)
- [ ] **Bilder** (Hero/Gericht) — Platzhalter „Bild folgt"; echte Bilder = Iteration (kein Gericht-Bild-Feld; #461 Hero-Medien)
- [ ] Share-Link-Konzept entschieden (signierter Gast-Link vs. Kunden-Login — Discussion Martin, Core-Auth) — **aktuell auth-gated**; Entscheid offen
- [x] Sichtbarkeits-Test: Response zeigt Preis pro Person, aber nachweislich **kein „Wareneinsatz"/„INTERN"** (Response-Grep) — `FoodbookServiceTest`
- Editor-Link „Präsentation" neben „Dokument" / „Dokument (intern)"

---

## R4 — Geführte Planung *(die Vault-Skill-Kaskade wird Produkt; Fundament für R6 Brief→Konzept)* · ✅ **KOMPLETT 2026-07-13** (R4.4 mit benannter Teil-Abweichung → R6.3)

### R4.1 Planungs-Gerüst-Datenmodell (Canvas-Ausbau) · Größe L · Hängt an: R0 (Facetten live) · ✅ **ABGESCHLOSSEN 2026-07-13**

Kern des Pakets: Das Gerüst ist **strukturierte Daten**, kein Freitext-Canvas — sonst kann R4.2 nichts messen und R6 nichts prompten.

**DoD:**
- [x] Datenmodell: Mengengerüst (n Gerichte je Kapitel/Gang inkl. Diät-Quoten), Preisarchitektur (Anker, Spannen, Zielpreis p. P.), Kunden-Politik (No-Gos, Allergen-Linie), Saison-Abdeckung, Dramaturgie (Gang-Folge/Buffet-Stationen als Slot-Gerüst-Regel) — 3 Tabellen `planning_frames` (Kopf + Preis p. P.) / `planning_frame_slots` (Dramaturgie + Mengen + Preis je Slot) / `planning_frame_rules` (diet_quota gegen `diet_form`-Vokabular · season_coverage · nogo_ingredient/nogo_allergen (EU-14-Keys) · allergen_line; je Frame oder je Slot)
- [x] Am Foodbook UND am Konzept anhängbar (ein Gerüst, zwei Konsumenten) — owner polymorph, unique je Owner
- [x] Erfassungs-UI im Canvas-Kontext; jedes Feld optional (Gerüst wächst, zwingt nicht) — Trait `ManagesPlanningFrame` + Partial `planning/partials/frame-board` im Foodbook-Editor (Modal neben Leitidee-Canvas) und Concepter-Konzept-Tab
- [x] MCP: `foodalchemist.planning.GET/PUT` — PUT übersetzt ein Brief in EINEM Call (head + slots + rules deklarativ/idempotent), Lineage `created_via=mcp_tool` + draft, status-Freigabe bleibt menschlich; GET liefert zusätzlich `prompt_kontext` (fertiger R6-KI-Block)
- [x] Migration bestehender food_dna-Canvas-Werte kollisionsfrei — Canvas-Tabellen/-Templates unangetastet (Prosa bleibt Kontext-Ebene), per Test bewiesen

### R4.2 Soll/Ist-Coverage live · Größe L · Hängt an: R4.1 · ✅ **ABGESCHLOSSEN 2026-07-13**

**DoD:**
- [x] Coverage-Engine: vergleicht Foodbook-/Konzept-Ist gegen Gerüst-Soll je Dimension (Menge/Diät/Preis/Saison/Dramaturgie) — `CoverageService`, plus No-Gos (Zutat-Namens-Match über Gericht + direkte Zutaten, Allergen über EU-14-Felder); Diät-Ist über `dish_classes.diet_form` + spec-Flag-Fallback; Slot-Scope via chapter_id > Label-Match, ehrliche Degradation bei fehlendem Ist-Bezug/unbestimmter Diät
- [x] Live-Anzeige beim Befüllen — Coverage-Panel im Concepter-Aufbau-Tab UND im Foodbook-Editor (aufklappbar, bei Rot offen), nicht in einem Report versteckt
- [x] Coverage als MCP-Tool abrufbar (`foodalchemist.coverage.GET`) — dieselbe Messlatte für Mensch und KI, mit Aufruf-Pflicht-Hinweis nach KI-Befüllung
- [x] Ampel-Logik erfüllt/teilerfüllt/verletzt (+info für nicht messbare allergen_line) — Lücken-Klick: Concepter setzt den neuen Diät-Filter des Gericht-Pickers (`pickDiaet`, diet_form-Achse), Foodbook verlinkt den VK-Browser klassen-gefiltert
- [x] Test: absichtlich verletztes Gerüst zeigt exakt die erwarteten Warnungen (Positiv- + Negativ-Test, `CoverageTest`)

### R4.3 Phasen-Status je Foodbook/Konzept · Größe M · Hängt an: R4.1 · ✅ **ABGESCHLOSSEN 2026-07-13**

**DoD:**
- [x] Statusmaschine: Kontext → Struktur → Befüllung → Kalkulation → Freigabe (`phase`-Spalte an Foodbook + Konzept, ergänzt draft/aktiv) — `PhaseService` + Stepper-Partial in beiden Editoren
- [x] Gate: Freigabe nur ohne rote Coverage-Ampeln — Override mit Pflicht-Begründung, durabel protokolliert (`phase_override_note/_at` am Objekt + ActivityLog wo vorhanden; Sandbox-Stub-sicher). Rückwärts-Übergänge frei
- [x] Phase sichtbar in beiden Browser-Listen (Badge) + filterbar (`?phase=`-URL-Filter)
- [x] MCP: `foodalchemist.phase.PUT` (kontext…kalkulation) + Phase in `foodbook.GET`/`concepts.GET`; Freigabe doppelt gesichert menschlich (Schema-Enum + Service-Guard `via=mcp`)

### R4.4 Zutaten-/Artikel-Tausch im Concepter · Größe M · Hängt an: R1 (+ Varianten-Mechanik) · *(Dominique-Wunsch 2026-07-06)*

Die Zeilen-Funktionen des Zutaten-Editors (**⇄ Produkt tauschen, ♻ Äquivalenz-Ersatz, 📦 GP-Peek, 📖 Rezept einsehen**) existieren bereits in Basisrezept- **und** Verkauf-/Gericht-Editor (geteilter `IngredientEditor`) — **fehlen aber im Concepter**: dort werden Gerichte nur in Slots gesetzt (Darreichung/Geschirr/Wording/Facetten), die Zutaten-Zeilen eines Gerichts sind nicht bedienbar.

> ⚠️ **Scope-Entscheid vor Baustart (Sparring):** Ein Tausch im Concepter darf **nicht** das global geteilte VK-Gericht mutieren (es hängt in N anderen Konzepten/Foodbooks). → **konzept-lokale Variante** über die vorhandene `varianteAnlegen`-Mechanik (Slot-Variante am Kerngericht), NICHT direkte Bearbeitung des Quell-Gerichts. „Tauschen" im Concepter = „für dieses Konzept variieren".

**Status: ✅ ABGESCHLOSSEN 2026-07-13** *(mit einer benannten Teil-Abweichung, s. u.)*

**DoD:**
- [~] Gericht-Baum im Concepter zeigt die Zutaten-Zeilen (read-first, 🧾-Toggle je Gericht-Slot) — Zeilen-Aktionen: ♻ Äquivalenz-Ersatz (mit Ziel-Name), 📖 Sub-Rezept-Peek, 🔒 swap_locked-Anzeige. **Rest-Parität zum `IngredientEditor` (⇄ Produkt-/LA-Tausch, 📦 GP-Peek) bewusst offen → gehört zur R6.3-Tausch-Strecke** (dort kommen Kosten-Kontext + Caveats dazu)
- [x] ♻ Ersatz erzeugt/nutzt eine **Slot-Variante** (konzept-lokal, `ConceptVariantService`): Voll-Kopie per replicate (VK-/Allergen-/EK-Felder + Zutaten + Darreichungen), Quell-Gericht unangetastet — „variiert"-Badge + „↩ Original"-Rücksetzen (räumt die Variante weg)
- [x] EK/Marge des Slots rechnet live gegen die Variante (Slot referenziert sie; Recompute beim Anlegen + Tausch; Test: 500 g Butter→Margarine = EK 6,00 → 2,00 €)
- [x] Kein stiller Global-Edit — Varianten sind katalog-unsichtbar (`variant_source_recipe_id`-Filter in VK-Browser + allen Gericht-Pickern); globale Änderung = bewusst Verkauf-Editor
- [x] MCP: `foodalchemist.concept_slot_variante.POST` (variieren | ingredient_id-Tausch | zuruecksetzen); Test: Tausch in Konzept A ändert Konzept B / Quell-Gericht nachweislich nicht (`SlotVarianteTest`)

---

## R5 — Deklaration & Compliance *(Horizont 2 — parallelisierbar nach R1, hoher Vertriebswert)*

### R5.1 Buffet-Kärtchen & LMIV-Etiketten · Größe M · Hängt an: R1 (sinnvoll erst mit Masse)

**DoD:**
- [ ] Knopfdruck am Gericht/Konzept/Foodbook: druckfähige Buffet-Kärtchen (Name, Allergene, Zusatzstoff-Fußnoten) + LMIV-Etikett (Zutatenliste absteigend, Allergene hervorgehoben, Nährwerte je 100 g)
- [ ] Datenquelle ist ausschließlich die deklarationsfeste Aggregation (ALL-MAXIMAL + Konfidenz) — Gerichte mit `unbekannt`-Allergen-Konfidenz werden BLOCKIERT, nicht schöngedruckt
- [ ] Layout im Kunden-CI (Brand-Anbindung), PDF-Export
- [ ] Fachliche Abnahme: 10 Etiketten von Dominique gegen Regelwerk geprüft
- [ ] Als MCP-Tool verfügbar (`etiketten.POST` o. ä.)

### R5.2 CO₂e je Gericht/Konzept + Bio-%/Regional-% · Größe L · Hängt an: R1

**DoD:**
- [ ] CO₂e-Faktorquelle entschieden und dokumentiert (z. B. Eaternity/Klimatarier-Faktoren je GP-Warengruppe — Lizenz-/Quellen-Entscheid Dominique)
- [ ] Faktor am GP, Aggregation über Rezeptbaum analog Allergen-Logik (inkl. Konfidenz: geschätzt vs. belegt)
- [ ] Bio-%/Regional-% aus spec-Feldern aggregiert und am Gericht/Konzept angezeigt
- [ ] Ausschreibungs-tauglicher Export (Kennzahlen-Block je Konzept)
- [ ] Kein Greenwashing-Default: fehlender Faktor = „nicht bewertet", nie 0

### R5.3 HACCP-Doku generiert · Größe M · Hängt an: R1.2 (Regenerations-Daten je Darreichung gepflegt)

**DoD:**
- [ ] HACCP-Dokument je Gericht/Konzept aus Regenerations-/Kerntemperatur-Daten generiert
- [ ] Gerichte ohne Regenerations-Daten erscheinen als Lücken-Liste (Ampel), nicht mit Platzhaltern
- [ ] Vorlage mit einem BHG-Küchenleiter fachlich abgenommen
- [ ] PDF-Export + Ablage am Konzept

---

## R6 — Alleinstellung ausspielen *(Horizont 3 — hat kein Wettbewerber; braucht R1 + R4)*

> **Warum-Layer (Querschnitts-DoD für R6, hängt an Q4):** Jeder suggestionserzeugende R6-Output (R6.1 Konzept, R6.3/R6.8 Substitution, R6.4 Idee, R6.11 Hypothese) trägt eine **zitierte Begründung** — Mechanismus + Quelle + Evidenz-Stufe (aus Q4). Kein Beleg → als Hypothese (T3/T0) markiert, nie als Fakt. Kann als Begründungstext in Foodbook/Kundensicht (R3, im Kunden-Wording) einfließen. Gilt zusätzlich zur jeweiligen Paket-DoD.
>
> **Konnektivität (Pairing-Offense R6.8–R6.10 + Kohäsion, hängt an Q5):** Diese Pakete brauchen graph-*erreichbare* Gerichte — ohne Anker-Erdung (Q5) sieht der Graph das Gericht nicht. Baseline 2026-07-04: Kohärenz nur **0,2 %** berechnet, Rezept-Anker-Reichweite **60 %** → Q5 ist harte Voraussetzung, nicht Kür. → **Update 2026-07-12:** Die **Graph-Dichte/Coverage-Seite ist gelöst** (Station 2: 37 %→58 %, ~179k Kanten, global). Von Q5 offen bleiben nur noch (a) der **Kohärenz-Score-Batch-Lauf** (KI-Judge, blockiert auf echten Gemini-Provider) und (b) **Zutaten-Anker-Reichweite** (60 %). Damit sind R6.8–R6.10 **halb entblockt** — die Dichte steht, es fehlt der Score-Lauf.

### R6.1 Brief → fertiges Konzept mit Kohäsions-Beweis · Größe XL · Hängt an: R1 ✅, R4 ✅, R0.2 ✅ · **GEBAUT 2026-07-13 — offen: Blindtest**

**DoD:**
- [x] Input: Planungs-Gerüst (R4.1) oder Freitext-Brief → Konzept ausschließlich aus echten VK-Gerichten. `ConceptGeneratorService`: Brief → KI baut NUR das Gerüst (`concept.brief_geruest`, KI-Werte werden defensiv sanitized); die Gericht-Auswahl selbst ist **deterministisch**: harte Filter aus den Gerüst-Regeln (No-Gos/Allergene/Preisrahmen), Diät-Quoten zuerst, Ranking Slot-Semantik (Label↔Speisen-HG) → Pairing-Kanten-Gewinn → Anker-Dichte → Preis-Anker-Nähe. Slot ohne Treffer bleibt LEER mit Begründung (Protokoll + `slot.note`, im Editor sichtbar) — nie halluziniert
- [x] Pairing-Graph prüft die Menüfolge: `PairingService::menuCohesion` (Gericht = Komponente, Anker-Union) → Score + Graph-Abdeckung + schwächstes Paar + ehrlich unbewertete Paare; als Kohäsions-Panel im Concepter (on-demand) + im Generator-Ergebnis. Smoke am Dev-MySQL: Score 99–100 bei 81 % Graph-Abdeckung
- [x] Coverage-Check (R4.2) läuft automatisch — das Gerüst wird als Kopie ans generierte Konzept gehängt (`kopiereZu`), dieselbe Messlatte wie für menschliche Konzepte (Smoke: meldet ehrlich `verletzt` wo das Sortiment die Vorgaben nicht hergibt)
- [x] Ergebnis immer `status=draft` + `created_via`-Lineage (`concept_generator_ui|mcp`, `concept_generator_brief_*`; neue Spalte `concepts.created_via`)
- [ ] **Blindtest (Dominique): 3 echte Kunden-Briefs → mindestens 2 von 3 „mit Anpassung verwendbar".** UI: Concepts-Browser „✨ Konzept aus Brief" (braucht echten LLM-Provider — Dev-`fake` echo taugt nicht) bzw. Foodbook-Gerüst „✨ Konzept aus diesem Gerüst" (läuft OHNE KI). MCP: `foodalchemist.concepts.GENERATE`. ⚠ Hinweis: auf der kleinen Dev-Fixture (31 VK) ist der Pool dünn — Blindtest gegen den Master-Bestand (994 VK) fahren

### R6.2 Angebots-Funnel-Anfang (Brief-Parser) · Größe L · Hängt an: R6.1

**DoD:**
- [ ] Kunden-Anfrage (Mail-Text/Formular) → strukturiertes Event-Brief (Anlass, Gäste, Budget, Diät-Anforderungen, Termin) mit Konfidenz je Feld
- [ ] Unsichere Felder als Rückfrage-Liste, nicht geraten
- [ ] Brief mündet direkt in R6.1 (ein Klick: Brief → Konzept-Vorschlag)
- [ ] Grenze eingehalten: Angebots-FÜHRUNG bleibt Event-Modul — FA liefert Brief + Konzept zu (Zuarbeits-Schnittstelle dokumentiert)

### R6.3 „Kosten senken"-Assistent · Größe M · Hängt an: R2.2

**DoD:**
- [ ] Je Gericht/Konzept: Top-Kostentreiber-Komponenten absteigend
- [ ] Substitutions-Vorschläge aus Äquivalenz-Katalog + Substitutions-Wissen, IMMER mit Caveats (Sensorik/Allergen-Änderung/Qualität)
- [ ] Allergen-Neuberechnung im Vorschlag sichtbar BEVOR getauscht wird
- [ ] Übernahme nur explizit je Vorschlag (kein Bulk-Auto-Tausch), `swap_locked` respektiert

### R6.4 Ideen-Labor · Größe L · Hängt an: R1, R4.2 (Lücken-Begriff kommt aus Coverage)

**DoD:**
- [ ] Kreuzung Trend-Feed (Pulse) × Pairing-Graph × Portfolio-Lücken → konkrete Gericht-/Konzept-Vorschläge
- [ ] Frage beantwortbar: „Was fehlt uns zum Sommer-Trend X?" — Antwort referenziert echte GPs/Anker, keine Fantasie-Zutaten
- [ ] Vorschlag → 1 Klick → Draft-Rezept via bestehender `recipes.POST`-Strecke
- [ ] Wissens-Lineage: jeder Vorschlag nennt Trend-Quelle + Pairing-Kanten

### R6.5 Kunden-DNA als Steuerungsobjekt · Größe L · Hängt an: R3.2, R4.1

**DoD:**
- [ ] Kundenprofil (Vorlieben, No-Gos, CI, Schreibstil) als eigenes Objekt, an Konzept/Foodbook anhängbar
- [ ] Färbt nachweislich: Wording (Resolver-Kette), Gericht-Vorschläge (No-Go-Filter), Design (CI)
- [ ] No-Gos wirken hart: verbotene Zutat/Allergen erscheint nie in Vorschlägen (Testfall)
- [ ] Speist R4.1-Kunden-Politik automatisch vor

### R6.6 Konzept-Validator-Ausbau · Größe M · Hängt an: R6.5

**DoD:**
- [ ] `ConcepterBewertungService` erweitert: Machbarkeits-Check (unbepreiste Ketten, fehlende Formen, Regenerations-Lücken) + Zielgruppen-Check gegen Kunden-DNA
- [ ] Ergebnis als Score + konkrete Findings-Liste (klickbar), nicht nur Zahl
- [ ] Läuft automatisch bei Phasen-Übergang Kalkulation → Freigabe (R4.3-Gate)

### R6.7 Sensorik-Radar über die Menüfolge · Größe M · Hängt an: R1.2 (Sensorik-Daten der Masse)

**DoD:**
- [ ] Balance-Analyse über Gang-/Stations-Folge: Textur- und Geschmacks-Häufung erkannt (z. B. „3× Creme hintereinander", „alles säurelastig")
- [ ] Warnungen im Concepter inline, mit Vorschlag aus `suggest` (Pairing-Graph)
- [ ] Sensorik-Daten-Abdeckung als Ampel — Radar schweigt ehrlich bei dünner Datenlage statt zu raten

### R6.8 Aroma-treue Substitution · Größe M · Hängt an: R6.3 (nutzt dessen Tausch-Strecke)

Der Pairing-Graph offensiv: Ersatz, der den Geschmack *erhält*, nicht nur den Preis senkt.

**DoD:**
- [ ] Ersatz-GP nach Kanten-Überlappung im Anker-Graph gerankt, nicht nur nach Äquivalenz/Preis
- [ ] Ausgabe zeigt: erhaltene vs. verlorene Aroma-Brücken + Kohäsions-Delta fürs Gesamtgericht
- [ ] Mit R6.3-Kosten kombiniert: „billiger UND aroma-treu" vs. Trade-off sichtbar
- [ ] Allergen-Neuberechnung im Vorschlag VOR Tausch (wie R6.3), `swap_locked` respektiert
- [ ] MCP-Tool (`substitution.SUGGEST`, Modus `flavor`), read-only bis expliziter Tausch
- [ ] Test: bekannter Klassiker-Tausch (z. B. Estragon↔Kerbel) rankt vor aroma-fernem, gleich teurem Ersatz

### R6.9 Dish-Reverse-Engineering · Größe L · Hängt an: R1 (Portfolio zum Nachbauen)

Fremdes Gericht → Aroma-Skelett → Nachbau aus eigenem Bestand.

**DoD:**
- [ ] Input Text/fremde Karte → Zerlegung in GPs (Matching gegen Stamm, Unmatched als Review-Queue, kein Raten)
- [ ] Aroma-Skelett aus dem Pairing-Graph extrahiert (tragende Anker + Verbund-Kanten)
- [ ] Rekonstruktion aus eigenem VK-Portfolio: „nächstes Gericht bei uns" + Lücken („dieser Anker fehlt im Bestand")
- [ ] Ergebnis mündet per Klick in R6.4 Ideen-Labor / `recipes.POST`-Draft
- [ ] Foto-Input als Ausbaustufe markiert (hängt an Multimodal-Provider-Entscheid Martin) — Textpfad zuerst
- [ ] Test: 3 bekannte Gerichte reverse-engineered → Zerlegung von Dominique plausibilisiert

### R6.10 Überschuss-zu-Gericht · Größe M · Hängt an: Q1 (Core-Contract) + Pairing-Graph

Erster bidirektionaler Contract-Fall: Lager meldet Überschuss, FA schlägt Verwertung vor.

**DoD:**
- [ ] Input: Überschuss-Bestand eines GP über den Core-Contract (aus Nachbar-Modul, NICHT FA-eigene Lagerhaltung)
- [ ] Graph schlägt Gerichte/Konzepte, die den GP geschmacklich *tragen* (Anker-Relevanz, nicht bloß „enthält")
- [ ] Vorschlag mit Verwertungs-Menge + Kohäsions-Begründung; Draft-Konzept per Klick
- [ ] Grenze gewahrt: FA rechnet/schlägt vor, Bestandsführung + Bestellung bleiben Nachbar-Modul
- [ ] FA-seitige Logik baubar + testbar mit Mock-Bestand; produktiv erst mit Q1/Nachbar-Modul (N-Track)
- [ ] Test: Mock-Überschuss rein → sinnvoller Gericht-Vorschlag raus (erster Contract-Beweis in Rückrichtung)

### R6.11 Hypothesen- & Widerspruchs-Modus (R&D) · Größe M · Hängt an: Q4 + Pairing-Graph

Der Warum-Layer offensiv: nicht erklären, was ist — sondern erforschen, was sein könnte.

**DoD:**
- [ ] Hypothesen-Modus: „paare X ungewöhnlich" → Kandidaten gerankt nach geteilten Volatil-Klassen, mit Mechanismus + Evidenz-Stufe — Experiment mit Absicht statt Zufall
- [ ] Widerspruchs-Detektor: Domain-Doc ⇄ Graph-Kante uneinig → als R&D-Frage surfacen (nicht still auflösen) + in die Research-Queue (Q4)
- [ ] Ergebnis immer mit Evidenz-Stufe; T3/T0 klar als Hypothese, nie als Fakt
- [ ] Vorschlag → 1 Klick → Draft-Rezept (`recipes.POST`) oder Lab-Journal-Eintrag
- [ ] Als MCP-Tool (`knowledge.HYPOTHESIZE` o. ä.), read-only bis Draft
- [ ] Test: bekannter strittiger Fall (Domain-Doc vs. Graph) wird korrekt als offene Frage geflaggt, nicht willkürlich entschieden

---

## Querschnitt (phasenunabhängig, aber terminkritisch)

### Q1 Core-Contract-Discussion an Martin — **VOR Event-Modul-Bau** · Größe S (Discussion, nicht Code)

**DoD:**
- [ ] Discussion im Dev-Modul: Interface-Entwurf `Konzept + Gästezahl → skalierte Komponenten-Mengen, Lead-LA-Bestellvorschlag je Lieferant, Arbeitszeiten, Regenerations-Parameter`
- [ ] Explizit als Resolver-Interface in `Platform\Core\Contracts` vorgeschlagen (nie Model-Zugriff)
- [ ] Martin hat geantwortet/entschieden BEVOR irgendwer Event-Modul-Code schreibt — sonst ist die Modul-Grenze Makulatur

### Q2 Eingangs-Schnittstelle Preise/Kataloge (Ex-Necta) · Größe M · laufend

**DoD:**
- [ ] Bestehende Import-Pipeline als reine EINGANGS-Schnittstelle dokumentiert (kein VK-Rückweg — FA ist Master)
- [ ] Katalog-Import-Lücken geschlossen (z. B. Grønn → entsperrt Petersilienöl 7900)
- [ ] Preis-Import triggert R2.1-Alarm

### Q3 KVP-Betrieb (Arbeitsprinzip aus GOALS)

**DoD (Dauerzustand, quartalsweise geprüft):**
- [ ] Jeder Live-Test-Reibungspunkt wird binnen Session Fix oder Dev-Modul-Issue — keine mündlichen „merken wir uns"
- [ ] Datenqualitäts-Signale (EK-Lücken, fehlende Formen, unbepreiste Ketten) laufen automatisch, Ampel im Dashboard sichtbar
- [ ] Regelwerke schlagen Memory schlagen Code — bei jedem Konflikt dokumentiert entschieden

### Q4 Evidenz-Abdeckung & Anreicherung (Wissensbasis) · Größe M (Aufbau) + laufend · **Fundament für den Warum-Layer (R6/R6.11)**

Der Warum-Layer ist nur so gut wie seine Evidenz. Statt dünne Datenlage zu verstecken: sichtbar machen, ehrlich abstufen, gezielt schließen, durch Nutzung verdicken.

**DoD:**
- [ ] **Evidenz-Ampel:** Abdeckungs-Index über Anker-GPs / Pairing-Kanten / Domain-Konzepte — je Knoten Anzahl + Qualitätsstufe der Belege, KI vs. verifiziert (Heatmap weißer Flecken; spiegelt die Datenqualitäts-Ampel)
- [ ] **Evidenz-Stufen definiert:** T1 verifizierte Primärquelle + Graph-Kante · T2 Graph-Kante quantitativ ODER aktiviertes Destillat · T3 einzelnes KI-Destillat = Hypothese · T0 nichts = still. Layer nennt IMMER die Stufe
- [ ] **Zwei Evidenz-Typen anerkannt:** quantitativ (geteilte Volatile im Ahn-Graph/FlavorDB2) UND prosaisch (Docs) — starke Graph-Kante ohne Prosa ist NICHT „dünn"
- [ ] **Lücken treiben Recherche:** Ampel erzeugt die Research-Queue — `food_research` / `109_destill_pdf` / Trend-Pulse werden auf weiße Flecken gezielt statt breit gestreut
- [ ] **Flywheel:** menschliche Bestätigung/Korrektur (inkl. „warum ging's / ging's nicht" aus R2.6-Bewertungen) wird zum verifizierten T1-Eintrag — tacit → explicit
- [ ] Ehrliche Degradation: bei T0/T3 sagt der Layer „dünne Evidenz / Hypothese", nie ein erfundener Mechanismus

> **Abgrenzung zu Q5:** Q4 = *ist die Aussage belegt* (Evidenz-Qualität). Q5 = *sieht der Graph das Gericht überhaupt* (Konnektivität/Reichweite). Konnektivität geht Evidenz voraus.

### Q5 Graph-Konnektivität & Mapping-Reichweite (Anker-Erdung) · Größe M · laufend · **Fundament für Pairing-Offense (R6.8–R6.10) + Kohäsion**

**Baseline gemessen 2026-07-04 (WaWi-DB) — was wir für dünn hielten, ist es nicht; dünn ist woanders:**

> *Werte unten = alte WaWi-DB. **FA-Master 2026-07-12: 1.000 Anker / ~179k Kanten (global, `team_id=NULL`)** nach Station 2 — die Graph-Dichte/Coverage-Seite (37 %→58 %) ist damit erledigt. Offen bleibt der Kohärenz-**Score-Lauf** (Zeile unten) + Anker-Reichweite.*

| Kennzahl | Ist | Urteil |
|---|---|---|
| GP-Erdung (approved mit Anker) | 6.679 / 6.802 = 98 % | ✅ stark |
| Genutzte GPs mit Anker | 1.674 / 1.735 = 96 % | ✅ stark |
| Zutaten-Mapping Coverage | 13.410 / 13.423 = 99,9 % | ✅ voll |
| Kanten-Graph | 23.951 Kanten / 767 Anker (~62/Anker) | ✅ gesund |
| Mapping-Qualität `gemini_proposed` unverifiziert | ~64 % aller Zutaten | ⚠️ Vertrauen dünn |
| Rezepte mit Anker-Mapping | 1.383 / 2.322 = 60 % | ⚠️ ~940 graph-blind |
| Rezepte mit Kohärenz-Score | 5 / 2.322 = 0,2 % | 🔴 Feature leer |

**DoD (nach Hebel sortiert):**
- [~] **Kohärenz-Score über das Portfolio berechnen** (heute 0,2 % = 0 Zeilen in `recipe_culinary_coherence`): Batch-Compute für alle Gerichte mit Ankern; Ziel ≥ 90 % der VK-Gerichte mit Score. **Größter Einzel-Hebel.** → **Stand 2026-07-12:** Graph-Dichte-Vorarbeit erledigt (Station 2, Coverage 58 %) → der Lauf liefert jetzt *belastbare* Scores. ABER: der Score ist ein **KI-Judge** (`CoherenceService::judge` via Gemini, 1 Call/Gericht) → **blockiert auf echten Gemini-Provider** (Dev = `FOODALCHEMIST_AI_PROVIDER=fake`). Batch-Command + Real-Lauf im Gemini-Env stehen aus.
- [~] **Rezept-Anker-Reichweite schließen** (heute 60 %): erst „sollte-Anker-haben"-Menge bestimmen (echte Gerichte, nicht triviale Ein-Zutat-Sub-Rezepte), dann Lücke erden → ~100 % der should-have. → **Stand 2026-07-12:** die **molekulare** Route ist ausgereizt (FooDB deckt die recipe-relevanten Reste nicht — Exoten kommen kuratiert übers Buch). Reichweite-Schließen heißt ab jetzt **Anker-Erdung der Zutaten** (Zutat→Anker-Auflösung), nicht mehr FooDB-Mapping.
- [ ] **Mapping-Qualität heben** (heute ~64 % unverifiziert): Verifikations-Ampel „% verifiziert" je Rezept; `gemini_proposed`-Zutaten nutzungspriorisiert auf `manual`/verifiziert heben (Muster Skript 215, §2-Kontext, Review-Gate)
- [ ] **NICHT blanket erweitern:** Kanten-Graph + GP-Erdung sind stark (98 %/gesund) → keine Blanket-Ausweitung; nur gemessene weiße Flecken aus FlavorDB2/Ahn ergänzen (fließt in Q4). Docs (836) bleiben niedrigste Prio.
- [ ] **Priorisierung nach Nutzung × Dünne:** erst Rezepte/GPs, die in vielen VK-Gerichten hängen
- [ ] Nach jedem Lauf: Count vorher/nachher auf `gp_anker_mapping`/`recipe_anker_mapping` (Lehre aus dem Subquery-Unfall)

---

## Bewusste NICHT-Ziele (Erinnerung — Grenze aus GOALS)

Produktion, Einkauf, Lager, Lieferscheine, Rechnungskontrolle: **nicht bauen**, auch nicht „nur ein kleines Feature davon".
FA rechnet, das Event-Modul führt aus. Geschirr: Bedarf hier, Beschaffung dort. Angebots-Führung: Event-Modul, FA liefert zu.

---

## R7 — Operative Planungs-Blätter (FA-seitig) *(die „linke Spalte": Berechnetes gehört FA; Vorstufe zum Nachbar-Modul)*

Reine Kaskaden-Ausgaben — Konzept + Gäste → Mengen/Listen/Blätter. Kein Modul, kein Contract; zugleich die Vorstufe, die N0 de-riskt (der Contract kapselt später genau diese Tools).

### R7.1 Blätter als read-only FA-Tools · Größe M · Hängt an: R1 (+ Darreichungs-Resolver) · 🟢 **GEBAUT 2026-07-13/14 (gepusht; nur echtes Step-Grouping offen — datenmodell-blockiert)**

**Kern-Entscheid Dominique 2026-07-13:** „so wie das Rezept in FA angelegt ist" — VK-Gericht linear auf die Menge skaliert, **Basisrezepte in GANZEN Ansätzen** (nicht runter-fraktioniert; man kocht keinen 20-g-Ansatz), Bedarf über Ziele **vor** der Rundung zusammengeführt. Skalierung frei wählbar: **Personen ODER Portionen** (Default 100). `PlanungsblattService` explodiert den Rezeptbaum über `RecipeRecomputeService::bruttoMasseG` (neuer Public-Helper, T1-Roh-Eingangsmasse) — eine Rechen-Wahrheit, kein Neubau.

**DoD:**
- [~] `produktionsblatt.GET`: Konzept/Gericht + Menge → skalierte Rezepturen über den Rezeptbaum. **Rezept-orientiert** (Top-Gericht + Basisrezepte in ganzen Ansätzen, „benötigt gesamt"-Vermerk) = Übergabe zum Nachbauen/Anlegen. **Zubereitungs-Freitext (`preparation`) jetzt je Rezept ausgegeben.** ⚠ Echtes „gruppiert nach Zubereitungsschritt" bleibt offen — Datenmodell hat keine strukturierten Steps (nur Freitext); bräuchte ein Schritt-Modell. Diese eine Zeile ist der einzige offene R7.1-Punkt.
- [x] `bestellvorschlag.GET`: Bedarf je GP → Lead-LA je Lieferant (`LeadLaService::rangliste`), gruppiert nach Lieferant, mit EK-Summe + **Ausweichquelle** (Rang 2 der Kette; Voll-Substitution = R6.3/R6.8)
- [x] `einkaufsliste.GET`: über mehrere Konzepte / ein Event aggregiert, Mengen zusammengeführt (Merge VOR Ansatz-Rundung = weniger Verschnitt)
- [x] Arbeitszeiten + Regenerations-Parameter je Darreichung: Arbeitszeit (je Rezept × Ansätze) **+ Regenerationstemp/-zeit/Kerntemp + Gerät/Behälter warm+kalt/Vehikel + Arbeitszeit-Zuschlag** der Standard-Darreichung im Produktionsblatt (Vokabel-Namen aufgelöst)
- [x] Strikt read-only, rein rechnend — kein Bestand, keine Bestellung, kein Schreib-Zustand
- [x] PDF/Export je Blatt (DomPDF, `/blaetter/dokument?typ=produktion|bestellung|einkauf&…&pdf=1`, Druck-HTML + istPdf-Flag) — alle drei inkl. Einkaufsliste
- [x] Test: Konzept/Gericht × Menge → Blätter gegen Hand-Rechnung (Skalierung + Ganze-Ansätze-Rundung 1,5→2 + Lead-LA-Gruppierung + Konzept×Pax) + Blätter-Filter — `PlanungsblattServiceTest` (8 Tests) grün, Voll-Suite grün, 0 Regressionen
- **Neu:** UI `/blaetter` (Sidebar „Planung") mit **Blätter-Filter** (Mehrfach-Auswahl Produktion/Bestellung/Einkauf — steuert welche Blätter erzeugt/gezeigt werden, Dominique-Wunsch 2026-07-14), 3 MCP-Tools (`produktionsblatt`/`bestellvorschlag`/`einkaufsliste.GET`, `read_only`) registriert (MCP-Lockstep)

---

## R8 — GP-Kuration FA-nativ *(LA-First ins Produkt holen; WaWi ist eingefroren)*

Die LA-First-GP-Kuration lebte im WaWi (jetzt read-only Archiv). Mit FA als Master muss die Kuration ins Produkt — als bediente UI-Strecke statt Python-Skript.

### R8.1 LA-Multi-Select → Bulk-GP-Erstellung/Matching · Größe L · Hängt an: nichts (FA-nativ)

In der Lieferantenartikel-Liste mehrere LAs markieren → **ein Bulk-Run** legt daraus GPs an bzw. matched sie gegen bestehende (approved) GPs. Bringt den LA-First-Workflow (Items strukturieren → tentative GPs → Review → approved) FA-nativ in die UI.

**DoD:**
- [ ] LA-Browser mit Mehrfach-Auswahl (Checkbox/Range) + Bulk-Aktion „GP erstellen / matchen"
- [ ] Bulk-Run über bestehende Queue-Strecke (`foodalchemist_bulk_runs`/Autopilot, Issue #403) — asynchron, Fortschritt sichtbar, resumefähig
- [ ] Matching gegen **approved** GPs zuerst (nur Neues wird tentative) — spiegelt LA-First-Kernprinzip; Regelwerk_Grundprodukte + Regelwerk_Lieferantenartikel maßgeblich
- [ ] Ergebnis ist **staging/Review-gated**: neue GPs = `status=tentative`/Proposal (`foodalchemist_gp_new_proposals`), kein stilles Anlegen; Mensch gibt frei (analog `gp_proposals.POST`)
- [ ] Lead-LA-Setzung + §8-Pflichtangaben-Prüfung im Lauf (Lead-LA-Heuristik `pick_lead_la`)
- [ ] Confidence + Begründung je Vorschlag (KI-gestützt, Muster Klassifikator 105/`gps.MATCH`)
- [ ] MCP: als Tool aufrufbar (`gps.bulk_match.POST` o. ä., staging-only) — KI-Client kann denselben Lauf triggern
- [ ] Team-Scoping + D1; Test: N markierte LAs → korrekte tentative-GP/Match-Verteilung gegen Hand-Prüfung

## R9 — Lieferanten-Management *(kommerzielle Beziehungs-Ebene — heute nicht steuerbar; Dominique-Wunsch 2026-07-05)*

**Ziel (Dominique):** Die Beziehung zu einem Lieferanten aktiv **steuern** — Verträge, Konditionen, Absprachen, Zusagen, wer wofür Lead ist. Heute passiert das mündlich/verstreut und ist im System nicht führbar. Die **Lead-Lieferanten-Zuordnung** (`lead_la`, `pick_lead_la`) ist bereits ein kleiner Teil davon — R9 macht daraus eine bediente, nachvollziehbare Steuerungs-Strecke.

**Vorhandener Kern (Startpunkt, kein Neubau):** `lead_la_supplier_item_id` + `pick_lead_la`-Heuristik (Lead je GP), `supplier_priorities` (Umsatz-Ranking, Import Skript 92 aus Rückvergütungs-Forecast), `stamm_lieferant` + `stamm_lieferant_wg` (Lieferant×Warengruppe-Matrix). → R9 bündelt und bedient das, statt es in Skripten/Heuristik zu lassen.

> ⚠️ **Scope-Grenze (vor Baustart entscheiden — Sparring):** R9 ist die **kommerzielle/strategische Beziehungs-Ebene** (Konditionen, Verträge, Absprachen, Lead-Zuordnung, Volumen-Auswertung) — **NICHT** operatives Bestellen/Wareneingang/Lieferscheine/Rechnungskontrolle. Letzteres bleibt bewusstes NICHT-Ziel (s. o.) bzw. Nachbar-Modul (N-Track). Die Linie: **R9 pflegt „mit wem zu welchen Bedingungen", der N-Track/Event-Modul führt „was wann bestellt" aus.** Diese Abgrenzung ist der erste zu klärende Punkt.

### R9.1 Lieferanten-Stammblatt + Absprachen-Log · Größe L · Hängt an: nichts (FA-nativ)

**DoD:**
- [ ] Lieferanten-Detailseite: Kontakte, Rollen, Status (aktiv/Zweitquelle/gesperrt), Warengruppen-Abdeckung (aus `stamm_lieferant_wg`)
- [ ] **Absprachen-/Zusagen-Log** je Lieferant: datierte Einträge (Konditionszusage, Sonderpreis, Liefertreue-Absprache …), Wiedervorlage/Erinnerung, Autor
- [ ] **Vertrags-/Dokumenten-Ablage** je Lieferant (Rahmenvertrag, Konditionsblatt, Preisliste) mit Laufzeit + Kündigungsfrist → Fristen-Signal (Muster R2.1-Signale)
- [ ] Konditionen strukturiert hinterlegbar: Rückvergütung/Bonus %, Zahlungsziel, Mindestbestellwert, Frei-Haus-Grenze — feeds späterer EK-/Marge-Betrachtung
- [ ] Team-scoped, LogsActivity, MCP-Tools (`suppliers.GET/PUT`, `supplier_agreements.POST`)

### R9.2 Lead-Lieferant-Steuerung als bediente Strecke · Größe M · Hängt an: R9.1 + R1

**DoD:**
- [ ] Lead-LA je GP/Warengruppe **sichtbar + überschreibbar** in der UI (heute nur Heuristik/Skript) — mit Begründungs-Vermerk bei manuellem Override
- [ ] Vorschlag = `pick_lead_la` (Vollständigkeit > Aktualität > Preis-pro-Einheit); Mensch bestätigt/übersteuert, Entscheid wird protokolliert
- [ ] Zweit-/Ausweichquelle je GP hinterlegbar (Ausfall-Absicherung)
- [ ] Auswertung: Volumen/Umsatz je Lieferant (`supplier_priorities`) × Konditionen → „wo lohnt Bündelung / Nachverhandlung"
- [ ] Test: Override setzt Lead korrekt, Recompute nutzt neuen Lead-EK; Historie nachvollziehbar

---

## Ausblick-Track — Nachbar-Modul (Einkauf/Lager/Produktion/Event) *(außerhalb des kritischen FA-Pfads; eigenes Package, eigene Roadmap)*

Kein FA-Paket — hier nur die Andock-Bedingungen, damit FA-seitig heute nichts verbaut wird. Details → GOALS „Ausblick: Nachbar-Module". FA baut/ändert dieses Modul NICHT; es ist ein Geschwister-Modul, das FA über Core-Contracts konsumiert.

### N0 Core-Contract fixieren (= Q1) · Größe S · Hängt an: nichts · **Gate für alles Weitere**

Identisch mit Q1. Ohne entschiedenen Contract kein Modul-Code — sonst Model-Durchgriff und die Grenze ist Makulatur. **De-riskt durch R7:** die Ausgaben existieren dann schon als FA-Tools — N0 kapselt sie nur als `Platform\Core\Contracts`-Interface, erfindet nichts.

### N1 Modul-Gerüst + Contract-Konsument · Größe L · Hängt an: N0

**DoD (Skizze, wird eigene Roadmap):**
- [ ] `platforms-<produktion|event>` aus `module-template` erzeugt, im Dev-Modul als eigenes Package registriert
- [ ] Verbraucht den FA-Contract: Konzept + Gästezahl → skalierte Komponenten-Mengen + Lead-LA-Bestellvorschlag (nur lesend gegen FA, kein Model-Durchgriff)
- [ ] Kein eigener kulinarischer Rechenkern — jede Küchen-/Preis-Frage geht an FA
- [ ] Grenze dokumentiert: Ausführung (Bestellung/Lager/Belege) hier, Rechnen bei FA

### N2 Bidirektional: Überschuss-Rückkanal · Größe M · Hängt an: N1 + R6.10

**DoD (Skizze):**
- [ ] Nachbar-Modul meldet Überschuss-Bestand über den Contract → FA (R6.10) liefert Verwertungs-Gericht
- [ ] Erster produktiver Beweis des Contracts in BEIDE Richtungen

---

## Ausblick-Track — Academy als Wissens-Konsument (Training/R&D-Frontend) *(außerhalb des kritischen FA-Pfads; Modul Academy konsumiert FA)*

Gleiches Muster wie der N-Track: FA liefert den **Warum-Motor** (`knowledge.EXPLAIN` + Q4-Evidenz), das **Academy-Modul** (existiert auf office.bhgdigital.de, Lernpfad-Infra da) baut daraus Training. FA baut KEIN Training-Frontend.

### A1 Portfolio-Training · Größe L · Hängt an: R6-Warum-Layer + Q4

**DoD (Skizze, wird eigene Roadmap):**
- [ ] Micro-Lessons aus dem *eigenen* Bestand („warum funktioniert euer Renner") — personalisiert, zitiert (Evidenz-Stufe sichtbar)
- [ ] Konsumiert `knowledge.EXPLAIN` von FA — kein eigener Wissens-Motor im Academy-Modul
- [ ] Reduziert Key-Person-Risiko: tacit chef knowledge → explizit + abfragbar

### A2 Skill-Check / Quiz · Größe M · Hängt an: A1

**DoD (Skizze):**
- [ ] Gericht zeigen → „was trägt hier das Aroma?" → gegen den Graph benotet
- [ ] Onboarding-Pfad neue Küchenkräfte (Academy-Lernpfad-Infrastruktur nutzen)

---

## Meilenstein-Übersicht

| Meilenstein | Inhalt | Beweis („Demo-Satz") |
|---|---|---|
| **M-A: Live & vertrauenswürdig** | R0 komplett | „Ein externer LLM-Client legt auf demo ein Foodbook mit Darreichungspreisen an; alle Ampeln grün." |
| **M-B: Masse drin** | R1 komplett | „~1.000 VK-Gerichte mit Formen, Preisen, Allergenen — in WaWi und FA identisch." |
| **M-C: Unverzichtbar** | R2.1 + R2.2 | „Butterpreis +20 % → das System sagt in Sekunden, welche 87 Gerichte es trifft und was der Tausch spart." |
| **M-D: Portfolio lebt** | R3 komplett | „Der Caterer blättert im Web-Foodbook, filtert vegan+Herbst+Buffet — Preise live, Kunde sieht dieselbe Seite ohne Interna." |
| **M-E: Geführt statt gefühlt** | R4 komplett | „Das Foodbook meldet beim Befüllen selbst: HG vegan fehlt, Preisspanne gerissen, Herbst leer." |
| **M-F: Compliance auf Knopfdruck** | R5 komplett | „LMIV-Etikett, CO₂e und HACCP je Konzept — generiert, nicht gebastelt." |
| **M-G: Alleinstellung** | R6.1 + R6.2 | „Kunden-Mail rein → strukturiertes Brief → Konzept aus echten Gerichten mit Kohäsions-Beweis, gemessen an der Kunden-Messlatte." |
| **M-H: Aroma-Offense** | R6.8 + R6.9 | „Butter wird knapp → das System schlägt den aroma-treuen Ersatz vor, der die Menüfolge nicht bricht; ein Trend-Gericht wird aus unserem Bestand nachgebaut." |
| **M-O: Operativ anschlussfähig** | R7 | „Konzept + 120 Gäste → Einkaufsliste, Bestellvorschlag je Lieferant und Produktionsblatt fallen hinten raus — noch ohne Bestands-Modul, rein gerechnet." |
| **M-N: Contract lebt** | N1 (+ R6.10/N2) | „Das Event-/Produktions-Modul fragt FA: 120 Gäste → skalierte Mengen + Bestellvorschlag; Überschuss zurück → Verwertungs-Gericht. FA rechnet, das Nachbar-Modul führt aus." |
| **M-W: Wissen erklärt sich** | R6-Warum-Layer + Q4 (+ R6.11) | „Jeder Vorschlag kommt mit zitierter Begründung und Evidenz-Stufe; wo die Datenlage dünn ist, sagt das System es — und legt die Lücke in die Research-Queue." |

---

## Changelog

- 2026-07-14 (5): **DQ-Ampel in den geplanten Scheduler eingehängt.** `DataQualityService::emittiereSignale()` läuft jetzt als 8. Detektor in `SignalDetektorService::laufen()` mit → der bestehende `signale-detektor`-Scheduler (auf demo aktiv) füllt die DQ-Signale (Anker/Servierform/EK-Kette/Allergen-Konfidenz) automatisch, kein Extra-Job/launchd nötig. `gp_ohne_lead`-Signal aus der Ampel entfernt (Detektor `datenqualitaetGpLa` besitzt den Befund → kein Doppel). Verifikation via demo-MCP `signale.SEARCH`: vor dem Deploy nur alte Typen (preis/marge/wareneinsatz), meine neuen Typen erscheinen nach Deploy + nächstem Scheduler-Tick.
- 2026-07-14 (4): **R0.3 neu geschnitten zur Datenqualitäts-Kaskade + Etappe 1 GEBAUT (lokal, verifiziert am Master).** Bottom-up-Remediation LA→GP→Basisrezept→VK statt Top-down. 4 neue Commands (`data-quality`/`lead-la-repick`/`gp-allergen-backfill`/`recompute`) + `DataQualityService` + 3 Signal-Typen. Am Master `foodalchemist_full` appliziert+verifiziert: 90 Lead-LAs gefixt (auflösend 4.900→4.990), GP-Allergen-Konfidenz 6.947→0 (nur Metadaten, Wert-Spalten unberührt — Override-Schutz), Bulk-Recompute 3.218/0 Zyklen, 12 Datenqualitäts-Signale in der Inbox (reisen per Re-Export nach demo). 13 neue Pest-Tests grün. Ehrlicher Befund: EK-Rest-Stau (219 VK/788 BR teil-unbepreist) hängt an 405 Park-GPs (kein bepreister LA) → LA-Sourcing = Etappe 2 (lokaler OpenAI-Provider: Anker-Erdung + Serving-Form-KI + GP-Lücken-Match). 2 WaWi-Ära-DoD-Punkte obsolet gestrichen. Gelernt: `allergens_source` ist varchar(16) → `derivat` statt `derivat_inherited`; `loestAuf` (Preiszeile) ≠ Recompute-`vergleichspreis` (braucht qty+unit) — grobe „teil-unbepreist"-Metrik ist gegenüber verstreuten GP-Fixes unempfindlich.
- 2026-07-14 (3): **R7.1 Rest-Punkte geschlossen + Blätter-Filter.** Produktionsblatt zeigt jetzt Regenerations-/Behälter-/Vehikel-Parameter der Standard-Darreichung + Arbeitszeit-Zuschlag (Vokabel-Namen aufgelöst) + `preparation`-Freitext je Rezept. Einkaufsliste-PDF-Route (`typ=einkauf`) ergänzt. Neuer **Blätter-Filter** auf `/blaetter` (Mehrfach-Auswahl Produktion/Bestellung/Einkauf — steuert, welche Blätter erzeugt/gezeigt werden; Dominique-Wunsch). Einziger offener R7.1-Punkt bleibt echtes „Zubereitungsschritt"-Grouping (kein Schritt-Datenmodell). Tests erweitert (Filter + Regeneration/Einkauf-Blade), Voll-Suite grün.
- 2026-07-14 (2): **R3.2 externe Web-Seite v1 (layout-first) GEBAUT (lokal).** Block C der Ausgabe-Schicht: Livewire-Full-Page `/foodbooks/{id}/praesentation` (auth-gated) rendert die serverseitige Kunden-Projektion (`dokumentDaten intern=false`, EK-frei) als gebrandete Seite — Hero, Kapitel + Preis pro Person, Wording-Zeilen, Preis-Fuß/MwSt, Bild-Platzhalter. Editor-Link „Präsentation". Kein Pax (Preise pro Person). Test `FoodbookServiceTest` (EK-Leak-Guard: kein „Wareneinsatz"/„INTERN"). Offen: echte Bilder (kein Gericht-Bild-Feld, #461), per-Kunde-CI (keine Brand-Relation), öffentlicher Share-Link (= Martin/Core-Auth). Damit A→B→C v1 komplett; Feinschliff (Bilder/CI/Share-Link/Facetten) = Folge-Iterationen.
- 2026-07-14: **R3.1 intern-Dokument GEBAUT (lokal, ungepusht).** Das interne Foodbook = aufgewertetes **Dokument** (nicht der in #501 gelöschte Standalone-View, Entscheid Dominique): `FoodbookService::dokumentDaten($intern)` liefert EK/VK/W% pro Person je Kapitel + Gesamt + Kapitel-Anker; Blade `dokumente/foodbook` bekam **Navleiste** (klickbar HTML+PDF), Marge-Spalten (nur intern, NIE im Kundendokument), Kunde/Intern-Umschalter, „INTERN"-Badge. Route `?intern=1`, Editor-Link „Dokument (intern)". 2 neue Pest-Tests, Suite grün. Teil der R3+R7-Ausgabe-Schicht (Block B von A→B→C); als Nächstes Block C = externe gebrandete Web-Seite (Bilder/KI, pro Person, Share-Link = Martin). Offen R3.1: Facetten-Filter + Lasttest (gehören zur Web-Seite).
- 2026-07-13 (4): **R7.1 Operative Planungs-Blätter GEBAUT (lokal, ungepusht).** `PlanungsblattService` (Explosions-Engine über den Rezeptbaum) + 3 read-only MCP-Tools (`produktionsblatt`/`bestellvorschlag`/`einkaufsliste.GET`) + UI `/blaetter` (Sidebar „Planung") + DomPDF-Blätter + `RecipeRecomputeService::bruttoMasseG` (neuer Public-Helper). Kern-Entscheid Dominique: „so wie das Rezept angelegt ist" — VK linear, Basisrezepte in GANZEN Ansätzen, Merge vor Rundung, Skalierung Personen ODER Portionen. Ausweichquelle aus der Lead-Kette (Voll-Substitution → R6.3/R6.8). 8 neue Pest-Tests, Voll-Suite **678/679** (1 Skip), 0 Regressionen. Offen: „gruppiert nach Zubereitungsschritt" (keine strukturierten Steps im Datenmodell), Regenerations-/Behälter-Params je Darreichung im Blatt, Einkaufsliste-PDF-Route. Gelernt: Blade kompiliert `@directive` NICHT, wenn ein Wortzeichen direkt davorsteht (`\B@`-Regex) → `min@endif` blieb literal; Pest-Harness registriert Closure-`dokument`-Routen nicht (Blade per View-Render testen, nicht per HTTP-`get`).
- 2026-07-13 (3): **R6.1 GEBAUT (Blindtest offen)** — `ConceptGeneratorService`: Gerüst-Pfad (deterministisch, ohne KI lauffähig) + Brief-Pfad (KI übersetzt Brief→Gerüst via neuem Prompt `concept.brief_geruest`, Werte sanitized; Auswahl bleibt deterministisch = „Keine Erfindungen"). Slot-Semantik-Ranking (Label↔Speisen-HG via recipes.dish_main_group_id, Modell A) vor Pairing-Kanten-Gewinn. `PairingService::menuCohesion` + Kohäsions-Panel im Concepter; Gerüst-Kopie ans Konzept (`kopiereZu`) → Auto-Coverage. UI-Einstiege: Concepts-Browser (Brief-Modal) + Foodbook (aus Gerüst); MCP `concepts.GENERATE`. Neue Spalten: `concepts.created_via`, `concept_slots.note` (Leer-Begründung). 9 neue Tests, Suite 668/669 grün, MySQL-Smoke (Fixture) mit Draft-Aufräumung. Gelernt: Collection::merge renummeriert Integer-Keys (put() nutzen); Dev-Fixture hat nur 31 VK — Blindtest braucht Master.
- 2026-07-13 (2): **R4 KOMPLETT — R4.2 Coverage + R4.3 Phasen + R4.4 Slot-Varianten** (ein Zug nach R4.1, Entscheid Dominique „R4 komplett fertig"). R4.2: `CoverageService` misst Foodbook-/Konzept-Ist gegen das Gerüst (Menge/Diät/Preis/Saison/Dramaturgie/No-Gos, Ampeln + ehrliche Degradation), live in beiden Editoren, Lücken-Klick → Diät-gefilterte Gericht-Suche (neuer `pickDiaet`-Filter), MCP `coverage.GET`. R4.3: Phasen-Statusmaschine mit Freigabe-Gate gegen rote Ampeln (Override durabel protokolliert), Browser-Badges + Filter, MCP `phase.PUT` (Freigabe menschlich). R4.4: konzept-lokale Slot-Varianten (`ConceptVariantService`, Voll-Kopie + Katalog-Filter), 🧾 Zutaten-Baum im Concepter mit ♻ Äquivalenz-Tausch, MCP `concept_slot_variante.POST`; Rest-Parität der Zeilen-Aktionen → R6.3. 26 neue Tests, Gesamt-Suite 663/664 grün, MySQL-Kanon migriert (000020/000030) + Smoke (Coverage-Befunde + Gate auf FB 1). **Damit ist R6.1 nur noch durch R0.2 ✅ gedeckt → Brief→Konzept ist entblockt.**
- 2026-07-13: **R4.1 Planungs-Gerüst abgeschlossen** (Einstieg in den R4-Track als R6.1-Vorarbeit, Entscheid Dominique). Strukturierte Soll-Ebene neben dem Freitext-Canvas: `planning_frames`/`_slots`/`_rules` (Mengengerüst + Diät-Quoten, Preisarchitektur p. P. + je Slot, No-Gos/Allergen-Linie, Saison, Dramaturgie), Service mit D1-Write-Guard + deklarativem `replaceStructure`, UI in Foodbook-Editor + Concepter, MCP `planning.GET/PUT` im Lockstep (Brief→Gerüst in einem Call, `prompt_kontext` fürs R6-Prompting). 15 neue Pest-Tests (inkl. UI-Klick→DB via Livewire-Host + Kollisionsfreiheits-Beweis), MySQL-Kanon migriert + Smoke. Nächster Schritt: R4.2 Soll/Ist-Coverage misst gegen dieses Gerüst.

- 2026-07-12: **R0.2 abgeschlossen + Wissens-Modul komplett** (gepusht `178d299..d5409a6`). R0.2 MCP-Darreichungs-Nachzug M1–M6: die 38 Tools sind darreichungs-fähig (recipes.POST→Standard-Darreichung, SEARCH/GET liefern Formen, kalkulation.GET über Resolver, Concept-Facetten + Slot-Darreichung, Klassifikator Bauart-Regel + nur aktive HGs; latenter MySQL-`||`-Bug gefixt). Wissens-Modul #469: Import-Guard (`imported_hash`, App-wins) + Browser-Semantiksuche (alle Kategorien) + v3 MCP-Schreiben (`knowledge.POST/PUT`, `created_via`). Tests grün; Buffet-Preis-Beweis per MySQL-Smoke. Offen: demo-Deploy (R0.1, Martin) macht beides live sichtbar.
- 2026-07-05: **Zwei neue Pakete (Dominique).** (1) **R8.1 LA-Multi-Select → Bulk-GP-Erstellung/Matching** — LA-First-Kuration FA-nativ ins Produkt (mehrere LAs markieren → Bulk-Run legt tentative GPs an / matched gegen approved), neues Paket R8. (2) **R2.6 erweitert** von „Kunden-/Event-Bewertung" auf **Feedback je Gericht/Rezept (Küche · Kunde · Event)** — explizit Küchenmitarbeiter-Feedback als Entwicklungs-Motor (Rezepte auf Praxis-Basis weiterentwickeln), Feedback auch am Basisrezept + „Weiterentwickeln"-Brücke zur Rezept-Iteration. — Kontext: DB komplett auf Englisch gezogen (Batch 3, Commit 72ca7f1) + Migration-Drift-Deploy-Blocker gefixt (4bdb308); Master-Roadmap als Doc #227 im Dev-Modul gespiegelt.
- 2026-07-04 (Nachtrag 4, **R1 auf FA-nativ umgestellt**): Nach dem WaWi-Freeze (FA = alleinige Master-DB) ist Import/Sync obsolet. **R1.1 neu:** „994 VK-Gerichte FA-nativ erstellen (mit Rezeptur + Mengen)" aus den zwei Foodbook-2027-PDFs (1 Portion + Ansatz) — Komponenten gegen **bestehende Basisrezepte** + GPs gematcht, Mengen = Ansatz ÷ Portionszahl, Recompute via `artisan` inklusive. Altes **R1.2 (FA-Sync/ImportSliceCommand) gestrichen**; alte **R1.3 Kuration → R1.2** (Quer-Refs R5.3/R6.7 nachgezogen). Vorbedingung geprüft: Basisrezepte tragfähig (2.250, referentielle Integrität sauber, EK 95,5 %, Allergen-Konfidenz 92 % medium; Rest-To-Dos = R0.3-Ampel). Anlass: Klärung Dominique — die VK-Gerichte sind noch nicht erstellt, sie kommen (wie ein Teil der Basisrezepte) aus den zwei PDFs.
- 2026-07-04: Brainstorm-Erweiterung (Dominique + Cooking Jarvis). Neu: **R2.4** Marge-optimale Assemblierung, **R2.5** Saison-Auto-Pricing (intern-vorschlagend, entkoppelt vom Kunden-Preis), **R2.6** Kunden-/Event-Bewertung je Gericht (ersetzt Produktions-Feedback-Loop), **R2.7** Portfolio-Benchmark BHG-intern; **R6.8** Aroma-treue Substitution, **R6.9** Dish-Reverse-Engineering, **R6.10** Überschuss-zu-Gericht; **Ausblick-Track N0–N2** (Nachbar-Modul Einkauf/Lager/Produktion/Event, gated an Q1); Meilensteine M-H + M-N. Abhängigkeits-Kette + GOALS Horizont 1/3 + GOALS-Sektion „Ausblick: Nachbar-Module" entsprechend ergänzt.
- 2026-07-04 (Nachtrag): Kern-Entscheid „berechnete Blätter = FA, operativer Zustand = Nachbar-Modul, zwei Zeitpunkte". Neu: **R7** Operative Planungs-Blätter FA-seitig (`produktionsblatt.GET`/`bestellvorschlag.GET`/`einkaufsliste.GET`, read-only) als Vorstufe, die N0 de-riskt; Meilenstein M-O. GOALS Horizont 1 + Ausblick-Sektion entsprechend präzisiert.
- 2026-07-04 (Nachtrag 3, gemessen statt geraten): **Q5** Graph-Konnektivität & Mapping-Reichweite eingezogen, mit **echter Baseline** aus der WaWi-DB. Befund korrigiert die Annahme: Kanten-Graph (23.951/767) + GP-Erdung (98 %) sind stark — dünn sind Kohärenz-Score (0,2 % berechnet), Rezept-Anker-Reichweite (60 %) und Mapping-*Vertrauen* (~64 % unverifizierte Gemini-Vorschläge). Priorität: Kohärenz-Lauf > Reichweite > Mapping-Verifikation > kein Blanket-Graph-/Doc-Ausbau. R6-Header + Abhängigkeits-Note um Q5 ergänzt.
- 2026-07-04 (Nachtrag 2, „erklärendes Geschmacks-Gehirn", Option c): **Warum-Layer** als Querschnitts-DoD für R6 (zitierte Begründung + Evidenz-Stufe je Vorschlag) + **R6.11** Hypothesen- & Widerspruchs-Modus (R&D) + **Q4** Evidenz-Abdeckung & Anreicherung als Fundament (Evidenz-Ampel, T0–T3-Stufen, Lücken-treibt-Recherche, Flywheel über R2.6) + **A-Track** (Academy konsumiert `knowledge.EXPLAIN`); Meilenstein M-W. GOALS Horizont 3 + KVP-Prinzip ergänzt. Fix für „dünne Datenlage": sichtbar machen statt verstecken, ehrlich abstufen, gezielt schließen, durch Nutzung verdicken.
- 2026-07-03: Erstfassung aus GOALS.md (Stand gleicher Tag) + Projekt-Memory (FB2027, MCP-Kaskade, Darreichungen-Umbau). Autor: Cooking Jarvis + Dominique.
