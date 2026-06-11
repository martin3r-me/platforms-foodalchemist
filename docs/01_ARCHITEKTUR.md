---
typ: Ziel-Architektur
stand: 2026-06-10
status: E2 — Architektur-Rahmen steht; Domänen-Detail folgt in E4
---

# 01 — Ziel-Architektur: Food Alchemist als office.bhg-Modul

> **Einordnung.** Food Alchemist ist der **Rewrite** der Tauri-App „Cooking Jarvis" als Plattform-Modul (`martin3r/platform-foodalchemist`). Kein Code-Port: Rust-Logik → PHP-Services (normiert über `04_GRUNDLOGIKEN/`), React-UI → Livewire/Blade mit `x-ui-*`. **Parität ist Mindestlatte (Golden-Tests), Verbesserung ist Programm (`10_VERBESSERUNGS_REGISTER.md`).**
>
> **Verbindlicher Rahmen:** `GIT.HUB/CLAUDE.md` + `module-template/LLM_GUIDE.md`. Goldene Regeln gelten: nur im eigenen Modul-Ordner arbeiten, Core/UI-Modul tabu.
> **✅ Dev-bestätigt (Martin, 2026-06-11):** Das Modul-Template ist **gesetzt** — es ist der Kern, wie das Modul aufgebaut sein soll. Die Template-Anatomie (§2) ist damit keine Arbeits-Annahme mehr, sondern verbindliche Bau-Vorgabe. Bei Pattern-Zweifeln gewinnt das Template gegen jede „elegantere" Alternative.

## 0. Produktvision (Nordstern für alle Scope-Entscheidungen)

> **„Dem User ermöglichen, seine Rezepte optimal zu verwalten und zu bearbeiten — Foodpairing und KI helfen dabei enorm. Beim Erstellen von VK-Rezepten (Artikeln) hilft das System bzw. automatisiert das händische Anlegen fast vollständig."** (Dominique, 2026-06-11)

Die zwei größten Pain-Points der realen Welt, an denen sich jeder Scope-Streit entscheidet:
1. **Basisrezept-Pflege/-Anreicherung** — der Enrich-Orchestrator + Feld-KIs + Pairing-Grounding sind deshalb MVP-Kern, nicht Beiwerk.
2. **Händisches Anlegen von VK-Rezepten** — Generator + Klassifikations-KIs + Foodpairing-Unterstützung automatisieren diesen Fluss (D-6 §5.x).

Konsequenz: Bei MVP-Abwägungen gewinnt, was diese beiden Flüsse beschleunigt. Foodpairing ist dabei **Workflow-Bestandteil** (MVP), nicht Explorations-Spielzeug (das ist Phase 2, D-7).

## 1. Schichten-Modell

```
Livewire-Components (UI, team-scoped)      MCP-Tools (LLM-Zugriff)
        │                                          │
        └────────────► Services (stateless) ◄──────┘   ← Geschäftslogik = GL-Specs
                            │
                       Eloquent Models                  ← team_id, UuidV7, SoftDeletes, LogsActivity
                            │
                  foodalchemist_*-Tabellen             ← 02_DATENMODELL
```

**Eiserne Regeln:**
- **Tools rufen Services, nie Models** (Plattform-Gebot). Livewire ruft ebenfalls Services für alles Nicht-Triviale — die Geschäftslogik existiert genau einmal.
- **Team-Scoping überall**: `Auth::user()->currentTeam` → `where('team_id', $team->id)`; globale Stammdaten zusätzlich `orWhereNull('team_id')` über einen gemeinsamen Scope (`TeamOrGlobalScope`) ⚠D1.
- **Schreib-Operationen mit Multi-Step-Charakter laufen in DB-Transaktionen** (V-07) — der Accept-Bug-Katalog der Alt-App (5 Folge-Bugs im Accept-Pfad) ist die Mahnung.
- **Fehler als typisierte Exceptions** mit Fehler-Code-Envelope (V-06).

## 2. Modul-Anatomie (Template-konform)

```
platforms-foodalchemist/
├── composer.json                          # martin3r/platform-foodalchemist, Platform\FoodAlchemist\
├── config/foodalchemist.php              # routing, guard, navigation, sidebar (s.u.), billables (s.u.)
├── database/migrations/                   # foodalchemist_* (aus 02_DATENMODELL), nummeriert
├── database/seeders/                      # Vokabular-Seeds + Bestandsdaten-Import (07_MIGRATION_SEED)
├── resources/views/livewire/…             # Blade, x-ui-*-Komponenten, Modals IN <x-ui-page>
├── routes/web.php                         # Route::get pro Bereich; Prefix/Middleware via ModuleRouter
└── src/
    ├── FoodAlchemistServiceProvider.php   # Boot-Sequenz nach Template (unverändert lassen)
    ├── Enums/                             # Status-, Allergen-, MatchMethod-Enums (aus CHECKs)
    ├── Models/                            # pro Tabelle; Traits: LogsActivity + UuidV7 + SoftDeletes
    ├── Services/                          # pro Domäne (s. §3) — Heimat der GL-Implementierungen
    ├── Livewire/<Bereich>/                # Index/Show/Edit + Modals pro Domäne
    ├── Jobs/                              # Bulk-KI, Recompute-Kaskaden, Re-Embed (V-15)
    ├── Policies/                          # Rollen/Rechte (V-12)
    └── Tools/                             # MCP-Tools (Phase 2 voll, MVP: Read-Tools) — rufen Services
```

### config: Navigation & Sidebar (Entwurf)

```php
'navigation' => ['route' => 'foodalchemist.dashboard', 'icon' => 'heroicon-o-beaker', 'order' => 40],
'sidebar' => [
  ['group' => 'Stammdaten', 'items' => [Lieferanten, Artikel & Preise, Grundprodukte, Vokabulare]],
  ['group' => 'Rezepte',    'items' => [Basisrezepte, Verkaufsrezepte, Speisen-Klassen]],
  ['group' => 'KI',         'items' => [Review-Queue (V-10), Bulk-Läufe, KI-Kosten (V-09)]],
  // Phase 2: ['group' => 'Komposition', 'items' => [Foodbooks, Pairing-Graph, Chat]]
  // dynamic-Beispiel nach planner.php-Vorbild: Foodbooks team_based als dynamische Liste
],
```

### Billables-Kandidaten (V-16, Vorbild `planner.php`)

| Billable | type | Begründung |
|---|---|---|
| KI-Aufrufe (`ai_call_log`) | per_item | verursachergerechte KI-Kosten pro Team |
| Rezepte (`recipes`) | per_item | optionale Mengen-Staffel |

## 3. Domänen-Schnitt & Implementierungs-Reihenfolge

| # | Domäne | Services | Livewire-Bereich | MVP ⚠D5 | abhängig von |
|---|---|---|---|---|---|
| D-1 | Vokabulare & Lookups | `VocabularyService` | Vokabular-Verwaltung | ✅ | — |
| D-2 | Lieferanten & LA | `SupplierService`, `SupplierItemService`, `PriceService`, `LaGpMatchService` | Lieferanten, Artikel, Review-Queue | ✅ | D-1 |
| D-3 | Grundprodukte | `GpService`, `GpNamingService`, `GpAggregationService` | GP-Browser/-Editor | ✅ | D-1, D-2 |
| D-4 | KI-Infrastruktur | `AiGatewayService` (⚠D3), `SemanticLayerBridge`, `AiProposalService`, `KnowledgeContextService` (⚠D4) | KI-Cockpit | ✅ | querschnittlich |
| D-5 | Basisrezepte | `RecipeService`, `RecipeRecomputeService`, `IngredientMatchService` | Basisrezepte | ✅ | D-3, D-4 |
| D-6 | Verkaufsrezepte | `SalesRecipeService`, `MargeService`, `SpeisenKlassenService` | Verkaufsrezepte | ✅ | D-5 |
| D-7 | Pairing/Flavor-Graph | `PairingService` | Pairing-Ansichten | Phase 2 | D-3, D-5 |
| D-8 | Foodbook & Chat | `FoodbookService`, `ChatService` | Foodbook, Chat | Phase 2 | D-6, alle |

**Reihenfolge:** D-1 → D-2 → D-3 → D-4 → D-5 → D-6 → {D-7, D-8}. D-4 ist CRUD-arm und kann parallel zu D-3 starten. **Empfohlener Einstieg: Vertical Slice D-1 + GP-Browser-Teil von D-3** (Migration → Model → Seed → Livewire-Index/Show) — validiert Template-Pattern, Scoping ⚠D1 und Seed-ETL am lebenden Objekt, bevor die Masse kommt.

## 4. KI-Architektur (Detail in `06_KI_SPEZIFIKATION.md`)

- **`AiGatewayContract`** (✅ D3 entschieden): `call(feature, context, options): AiResult` — Fassade über dem **Plattform-`LLMProviderContract`** (zentraler Core-LLM-Service, kein eigener HTTP-Client/Key!); behält Modul-Verantwortung für Tiering (V-01), Retry/Degenerations-Schutz (V-02), Audit-Write (`foodalchemist_ai_call_log`), Prompt-Komposition.
- **Hüllen-Hybrid** (GL-06 §6): Voice-Hüllen (global/team, modul-gated) → **`core.semantic_layer`** via `SemanticLayerResolver::resolveFor(team, module)`; Field-Hüllen + TASK_PROMPTs → modul-eigene Prompt-Registry.
- **`AiProposalService`** (GL-07): EIN generischer Lebenszyklus (propose → review → accept/reject/clear) für alle ~90 KI-Features; Lineage-Felder (`_quelle`/`_ai_confidence`/`_ai_begruendung`) werden von ihm geschrieben, **Override-First** (manual schlägt KI) garantiert er zentral.
- **Bulk-Läufe als Queue-Jobs** (V-15) mit Fortschritt + Resume; nie UI-blockierend, nie Skript-only ([[feedback_user_facing_not_scripts]]-Prinzip der Alt-App gilt weiter: Datenbefüllung ist UI-Feature).
- **`KnowledgeContextService`** (GL-13, ⚠D4): liefert Wissens-Snippets (Regelwerke, Domänen, Pairing) aus `foodalchemist_knowledge_*` in Prompts.

## 5. UI-Leitplanken (aus der Alt-App übernehmen — als Konzept, nicht als Code)

- **Section-Header-Pattern**: KI-/Hilfs-Aktionen rechts im Section-Header (bewährtes UX-Muster der Alt-App) — mit `x-ui-panel` + Slot nachbauen.
- **KI-Vorschlags-Modals**: Konfidenz-Anzeige, editierbar vor Übernahme, Begründung sichtbar, Gap-Surfacing (unbekannte Vokabeln melden statt erzwingen).
- **Review-Queues als First-Class-Views** (V-10): needs_review-LAs, KI-Entwürfe, Stub-Rezepte.
- **Navigation = URLs** (V-17): jede Entität bekommt eine Route — kein Tab-State-Verlust mehr.
- Modals immer innerhalb `<x-ui-page>` (Template-Regel), Design-Tokens aus `config/ui.php` — keine eigenen Farben.
- **✅ UI-Inventar verifiziert (platforms-ui-tailwind, 2026-06-11, ~59 Komponenten):** Vorhanden und direkt nutzbar: `x-ui-modal` (Größen sm–full, persistent, Slots header/footer), `x-ui-badge` (= unsere Allergen-/Status-Pills, inkl. Counter+Icon), `x-ui-table`-Familie, `x-ui-tab`, `x-ui-input-select` (Modi dropdown/searchable/badges!), `x-ui-kanban-*` mit `wire:sortable` (= Drag&Sort für Zutaten-Listen), `x-ui-toast`, `x-ui-confirm-button`, `x-ui-dashboard-tile`, `x-ui-breadcrumb`. **Eigenbau im Modul nötig (klein):** `x-ui-markdown` (Zubereitungs-Render), Konfidenz-Bar (KI-Vorschläge), Pagination-UI, Baum-Liste (Sub-Rezept-Hierarchie). Kein Dark-Mode plattformweit.

## 6. Querschnitt

| Thema | Mechanismus |
|---|---|
| MCP-Tools (✅ Core-belegt) | `Platform\Core\Contracts\ToolContract` implementieren (getName/getDescription/getSchema/execute mit ToolContext{user,team}), Registrierung via `ToolRegistry` im ServiceProvider. **Naming: `foodalchemist.resource.VERB`** (REST-Verben GET/POST/PUT/DELETE — nicht list/create!); MCP-Name-Mapping Punkte→`__` macht der Core-Adapter automatisch. Discovery: `tools__GET(module=…)`. Vorbild: planner (43 Tools) |
| Rechte/Rollen (V-12) | Laravel Policies pro Model + `check.module.permission`; Rollen-Matrix in E4 (D-Spec) definieren |
| Audit (V-13) | `LogsActivity` auf allen Models; fachliche Lineage zusätzlich via GL-07-Felder |
| Performance | Recompute-Kaskaden (GL-02) als Jobs; team_id-Indizes auf allen Team-Tabellen; Embedding-Suche (Re-Embed statt ETL) |
| Tests | PHPUnit; Golden-Datasets aus `09_TESTKATALOG.md`; Seed-Verifikation Row-Counts |
| Beobachtbarkeit (V-09) | strukturierte Logs + KI-Kosten-Auswertung auf `ai_call_log` |
