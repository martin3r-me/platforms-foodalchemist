---
typ: Domänen-Spec
domaene: D-4
stand: 2026-06-10
status: ausgearbeitet
mvp: MVP
---

# D-4 — KI-Infrastruktur

> **Services (stateless):** `AiGatewayService` (⚠D3, hinter `AiGatewayContract`), `SemanticLayerBridge`, `AiProposalService`, `KnowledgeContextService` (⚠D4)
> **Hängt ab von:** querschnittlich (alle KI-Features in D-1…D-8 konsumieren diese Domäne) · **MVP (⚠D5):** ja
> **Kurzbeschreibung:** Das KI-Fundament: EIN Gateway-Interface für alle ~90 KI-Features, Hüllen-Resolver (GL-06), generischer Vorschlags-Lebenszyklus (GL-07), Wissenskontext (GL-13), Tiering (V-01), Queue-Bulk (V-15), Audit/Kosten (V-09/V-16) — plus das KI-Cockpit als Pflege-UI.

## 1. Scope & Ressourcen

17 Alt-Commands in 15 Ressourcen-Gruppen (aus `03_FEATURE_INVENTAR.md`, Domänen-Filter D-4).

| Ressource | Alt-Commands | Ziel `Service::methode` | Livewire | MVP |
|---|---|---|---|---|
| ai_layers / ai_layer (CRUD) | `list_ai_layers`, `get_ai_layer`, `create_ai_layer`, `delete_ai_layer`, `update_ai_layer_meta` | `SemanticLayerBridge::list()`, `::find(id)`, `::create(dto)`, `::delete(id)`, `::updateMeta(id, dto)` | `Ki\Huellen\Index`, `\Show` | MVP |
| ai_layer_version | `create_ai_layer_version` | `SemanticLayerBridge::createVersion(layerId, dto)` — append-only, `UNIQUE(layer_id, semver)` (GL-06 Inv. 3) | `Ki\Huellen\Show` (Versions-Editor) | MVP |
| active_ai_layer_version | `set_active_ai_layer_version` | `SemanticLayerBridge::activateVersion(layerId, versionId)` — nur Zeiger-Flip | `Ki\Huellen\Show` | MVP |
| resolve_ai_layers | `resolve_ai_layers` | `SemanticLayerBridge::resolveForCall(module, customerRef?, skillRef?, fieldTarget?)` (GL-06 §3) | intern + „Resolver-Vorschau" im Cockpit | MVP |
| dryrun_ai_layer | `dryrun_ai_layer` | `AiGatewayService::dryRun(layerId, sampleTargetId)` — echter LLM-Call gegen Beispiel-GP, persistiert NUR `ai_call_log` (`feature='layer_dryrun'`), nie Fachwerte | `DryRunModal` | MVP |
| call_recency_stats | `ai_call_recency_stats` | `AiProposalService::recencyStats(filter)` — Calls/Accepts/Rejects pro Feature + Zeitraum | `Ki\Kosten` (V-09-Dashboard) | MVP |
| gemini_get_status / test_connection | `gemini_get_status`, `gemini_test_connection` | `AiGatewayService::status()`, `::testConnection()` (Mini-Ping, Tier B) | `Ki\Einstellungen` | MVP |
| gemini_set_api_key | `gemini_set_api_key` | **entfällt als Klartext-Write** — Key liegt im Plattform-Secret-Store (Gesamt-Audit H1, `02_DATENMODELL` C); Cockpit zeigt nur „Key hinterlegt: ja/nein" | `Ki\Einstellungen` (Admin) | MVP |
| gemini_set_model | `gemini_set_model` | ersetzt durch **Tier-Konfiguration** (V-01): `AiGatewayService::setTierModel(tier, model)` statt Blanket-Modell | `Ki\Einstellungen` | MVP |
| plan_dishes | `ai_plan_dishes` | `AiProposalService::propose('plan_dishes', …)` — Generator (Hüllen + GL-13-Wissen); **fachliche Detail-Spec → D-5/D-6** (Zielobjekte sind Rezepte); hier nur als Referenz-Konsument des Gateways gelistet | D-5 | MVP |
| fertigungstiefe (accept) | `accept_fertigungstiefe` | **→ D-5** (Zielfeld `recipes.fertigungstiefe`) — Inventar-Klassifikations-Artefakt, s. D-3 §1 Re-Homing | D-5 | MVP |
| stk_default_g (reject) | `reject_stk_default_g` | **→ D-3** (Lebenszyklus-Splitter, Heimat `GpService`) | D-3 | MVP |

Dazu gehören OHNE Alt-Command (neu im Ziel): die generischen GL-07-Endpunkte des `AiProposalService` (werden von allen Domänen instanziiert, nicht 90× kopiert), die Bulk-Run-Steuerung (V-15) und das Kosten-Dashboard (V-09/V-16).

## 2. Datenmodell-Ausschnitt

| Quelle (Ist) | Ziel | Disposition |
|---|---|---|
| `ai_layer` (14), `ai_layer_version` (21) | **`core.semantic_layer` der Plattform ⚠D3** | `02_DATENMODELL` C: 1:1-Pattern-Match erwartet; Hüllen-Texte (GL-06 §4.3-Inventar) werden als Seed übernommen. Solange die Plattform-API nicht bestätigt ist: Modul-Tabellen `foodalchemist_ai_layers` / `_ai_layer_versions` mit identischem Schema (GL-06 §2) — das Binding tauscht später, nicht die Spec |
| `ai_call_log` (7.903) | `foodalchemist_ai_call_log` | **+ `user_id` + `team_id`** (Kosten-/Audit-Dimension, V-09/V-16); Schema GL-07 §2 (`feature`, `layers_used` jsonb, `prompt_hash`, `response_summary`, tokens, `model`, `target_table/_id`, `accepted_at`/`rejected_at`, `error`, `elapsed_ms`) + NEU `knowledge_used` jsonb (GL-13 §6) + `tier` + `correlation_id` (V-09). Bestand → BHG-Team |
| `app_settings` (Gemini-Key, Modell) | Plattform-Settings + **Secret-Store** | Key NIE wieder als Klartext-Zeile (H1); Tier-Map in `config/foodalchemist.php` |
| `embedding` (9.017) | **Re-Embed-Job** im Ziel | Vektor-Blobs werden nicht ETLt (Modell/Dimension wechselt ggf.); Queue-Job (V-15), Ziel-Speicher pgvector vs. Tabelle = Detailfrage beim Port |
| Vault-Markdown (`vault_context.rs`) | `foodalchemist_knowledge_documents` / `_knowledge_aliases` / `_knowledge_routings` ⚠D4 | Schema-Vorschlag GL-13 §4.3; Import-Kommando `foodalchemist:knowledge-import`; team_id nullable (⚠D1: NULL = global/BHG-kuratiert) |

Scoping: Hüllen + Wissen sind globale Stammdaten (⚠D1); `ai_call_log` ist team-eigen (`team_id` NOT NULL) — jeder Call wird dem auslösenden Team/User zugerechnet.

## 3. Services & Methoden (Signaturen-Niveau)

### 3.1 `AiGatewayContract` (⚠D3 — das zentrale Interface)

Alle KI-Features aller Domänen rufen ausschließlich dieses Interface. Fällt D3 anders aus (Plattform-Service existiert), tauscht NUR das Binding im ServiceProvider — kein Call-Site ändert sich. Deshalb hier konkret:

```php
interface AiGatewayContract
{
    /** Freitext-Antwort (lange Einzeltexte: Beschreibung, Zubereitung, Marketing). */
    public function call(string $prompt, AiCallOptions $options): AiResult;

    /** JSON-Mode mit Schema-Validierung — Standardpfad für alle Label-/Struktur-Features. */
    public function callJson(string $prompt, array $schema, AiCallOptions $options): AiJsonResult;

    /** Tool-/Function-Calling (Phase 2: Chat D-8, MCP-Brücke V-14). */
    public function callWithTools(string $prompt, array $tools, AiCallOptions $options): AiToolResult;
}

final class AiCallOptions
{
    public string  $feature;              // PFLICHT — Schlüssel für Tier-Lookup (V-01) + ai_call_log.feature
    public ?AiTier $tier = null;          // 'A'|'B'|'C' — expliziter Override; Default aus config pro Feature
    public string  $module;               // kanonischer Modul-Key (Enum, GL-06 §6) für den Hüllen-Resolver
    public ?string $fieldTarget = null;   // "tabelle.feldname" → Field-Hülle (GL-06 Scope 5)
    public ?string $customerRef = null;   // Team-Slug (GL-06 Scope 3; Ist ungenutzt, Platzhalter ⚠D3-Detail)
    public ?string $system = null;        // Ad-hoc-System-Prompt — kommt IMMER NACH den Hüllen (GL-06 GT-06-9)
    public array   $contextRefs = [];     // GL-13-Wissensanforderung: ['beschreibung'=>…, 'stil'=>…, 'hauptzutat_slugs'=>[…]]
    public ?string $targetTable = null;   // Audit-Ziel (ai_call_log)
    public ?int    $targetId = null;
    public float   $temperature = 0.1;    // Default Label-Features; Generatoren setzen höher
    public ?int    $maxOutputTokens = null;
    public array   $attachments = [];     // Vision-Pfad (Tier C): PDF-/Bild-Inhalte (ai_extract_recipe)
}

final class AiResult        // AiJsonResult erweitert um ->json (validiert), AiToolResult um ->toolCalls
{
    public string $text;
    public string $model;          public AiTier $tier;
    public int    $tokensIn;       public int $tokensOut;
    public int    $elapsedMs;      public int $retries;        // Degenerations-Retries (V-02)
    public int    $callLogId;      // PFLICHT im DTO — Accept/Reject stempeln darüber (GL-07 Inv. 1)
    public array  $layersUsed;     // [{key, semver}] (GL-06 Inv. 7)
    public array  $knowledgeUsed;  // [{slug, version}] (GL-13 §6 — NEU)
}
```

**Interne Pflichten der `AiGatewayService`-Implementierung (Arbeits-Annahme D3 b/c):** (1) Hüllen via `SemanticLayerBridge` resolven und als systemInstruction komponieren (GL-06 §3); (2) Wissen via `KnowledgeContextService` in den User-Prompt mischen (GL-13 §4.1-Routing); (3) Tier→Modell-Mapping (V-01); (4) Rate-Limit + Retry; (5) Degenerations-Schutz mit steigender Temperatur für ALLE langen Textfelder (V-02); (6) `ai_call_log`-Write VOR Rückgabe — **auch im Fehlerpfad** (try/finally, `error`-Spalte, GL-07 §4.3); (7) `prompt_hash` = SHA-256 des vollen Prompts; (8) Billables-Hook pro Call (V-16).

### 3.2 Übrige Services

```php
class SemanticLayerBridge {   // GL-06; Ziel core.semantic_layer ⚠D3, bis dahin Modul-Tabellen
    public function resolveForCall(string $module, ?string $customerRef, ?string $skillRef, ?string $fieldTarget): ResolvedLayers;
    public function list(LayerFilter $f): Collection;
    public function find(int $id): LayerDetailDto;                 // inkl. Versions-Historie
    public function create(LayerWriteDto $dto): Layer;             // validiert enabled_modules gegen Modul-Enum (§6)
    public function updateMeta(int $id, LayerMetaDto $dto): Layer; // title/status/enabled_modules/notes — NIE Inhalt
    public function createVersion(int $layerId, VersionDto $dto): LayerVersion; // rendert + cached rendered_block
    public function activateVersion(int $layerId, int $versionId): void;
    public function delete(int $id): void;                         // nur draft/archived
}

class AiProposalService {     // GL-07 — EINMAL generisch, pro Feature instanziiert (Feature-Registry in config)
    public function propose(string $feature, int|array $target, array $input = []): ProposalDto;
    public function accept(string $feature, int $callLogId, array $reviewedValues): AcceptResult;  // Override-First + TX + Stempel + Propagation
    public function reject(int $callLogId): void;                  // nur rejected_at
    public function clear(string $feature, int $targetId): void;   // Fachwert + Lineage → NULL
    public function recencyStats(StatsFilter $f): Collection;
    public function dispatchBulk(string $feature, array $targetIds, User $by): BulkRun; // V-15 → Queue-Job
}

class KnowledgeContextService {   // GL-13; Quelle foodalchemist_knowledge_* ⚠D4 (Repository-Pattern: Quelle austauschbar)
    public function contextFor(string $feature, string $beschreibung, ?string $stil = null, array $hauptzutatSlugs = []): KnowledgeBlock;
    public function import(string $exportPath): ImportReport;      // Artisan foodalchemist:knowledge-import (Upsert per slug)
}
```

## 4. Livewire-Komponenten & UI-Fluss (KI-Cockpit)

Ist-UI (`Ki.tsx`): zwei Sub-Tabs „Einstellungen" (Bridge/Key/Modell) + „Hüllen" (Layer-CRUD nach BHG-Office-Pattern). Ziel erweitert das zum **KI-Cockpit** (Sidebar-Gruppe „KI", `01_ARCHITEKTUR` §2):

| Komponente | Route | Inhalt |
|---|---|---|
| `Ki\Einstellungen` | `/foodalchemist/ki/einstellungen` | Gateway-Status (Verbindungstest, Key-hinterlegt-Anzeige — kein Klartext-Input, H1), Tier→Modell-Matrix (V-01, Admin-only via Policy V-12), Rate-Limit-Anzeige |
| `Ki\Huellen\Index` | `/foodalchemist/ki/huellen` | Hüllen-Tabelle (scope, scope_ref, key, status, aktive semver, enabled_modules); Filter nach Scope/Status; Anlage-Modal mit Modul-Enum-Validierung |
| `Ki\Huellen\Show` | `/foodalchemist/ki/huellen/{layer}` | Versions-Historie (append-only), Editor für neue Version (perspektive/ton/heuristiken/negativ_raum → rendered_block-Vorschau), Zeiger-Flip „Version aktivieren", `DryRunModal` (echter Call gegen Beispiel-Datensatz, Diff alte vs. neue Version) |
| `Ki\ReviewQueue` | `/foodalchemist/ki/review` | V-10: domänen-übergreifende Queue offener Vorschläge (propose ohne accept/reject), `needs_review`-Objekte, mit Zählern pro Feature |
| `Ki\BulkRuns` | `/foodalchemist/ki/bulk` | V-15: laufende/abgeschlossene Bulk-Jobs mit Fortschritt (n/total), Fehlerliste pro Item, Aktionen Pause/Resume/Abbruch; Start-Dialog (Feature + Ziel-Filter + Kosten-Schätzung aus Tier×Item-Zahl) |
| `Ki\Kosten` | `/foodalchemist/ki/kosten` | V-09/V-16: Auswertung auf `ai_call_log` — Tokens/Kosten pro Team, Feature, Modell, Zeitraum; Accept-/Reject-Quoten (Hüllen-Qualitäts-Signal); Resolver-Vorschau („welche Hüllen zieht Feature X?") |

UI-Fluss Hüllen-Pflege: Hülle anlegen (draft) → Version verfassen → Dry-Run gegen Beispiel → Status `pilot` → Beobachten über Accept-Quote im Kosten-Dashboard → `production`. Inhalts-Änderung = IMMER neue Version + Zeiger-Flip (GL-06 GT-06-10), nie Edit in place.

## 5. KI-Features dieser Domäne (das Cockpit selbst)

D-4 erzeugt selbst kaum Fachwerte — seine „Features" sind Infrastruktur-Funktionen:

| Feature | Hülle/Kontext | Zielfelder | Lebenszyklus | Tier |
|---|---|---|---|---|
| **Hüllen-Verwaltung** | keine (deterministisches CRUD); der Versions-Editor zeigt die Resolver-Vorschau (GL-06 §3) live | `ai_layers` / `_versions` | kein GL-07 (kein Vorschlag) — Audit via `LogsActivity` | — |
| **Dry-Run** (`layer_dryrun`) | exakt die zu testende Hüllen-Komposition + Beispiel-Datensatz | KEINE — nur `ai_call_log`-Zeile | propose-only, niemals accept | wie Ziel-Feature |
| **Call-Log-Auswertung** | — (reine SQL-Aggregation) | Dashboard-Reads auf `ai_call_log` (`feature`, `tier`, tokens, `accepted_at`/`rejected_at`, `error`) | — | — |
| **Bulk-Run-Steuerung** (V-15) | pro Item identisch zum Einzel-Feature (gleiche Hüllen, gleiches Wissen) | pro Item der GL-07-Lebenszyklus des jeweiligen Features — Bulk ändert das Pattern NICHT (GL-07 §6): Schreibpflicht + Override-First pro Item, Accept bleibt ein Accept pro Datensatz | per-Item | per Feature |
| **Verbindungstest** (`gateway_ping`) | minimaler Prompt | keine | — | B |
| `plan_dishes` (Inventar D-4) | global + module + GL-13 (Cross-Cutting + Domains aus Brief) | Rezept-Entwürfe → fachliche Spec in D-5/D-6 | propose → Review | A |

## 6. Verbesserungen gegenüber Ist (Bau-Aufträge)

| Ref | Bau-Auftrag |
|---|---|
| **V-01 — Per-Feature-Modell-Tiering** | Config-Map `feature → tier` + `tier → model` statt Blanket-Modell. Default-Zuordnung: Tier A (Qualität: Generatoren, Naming, Allergen-Inferenz, plan_dishes), Tier B (Mechanik-Labels: Tags, Domain, stk_default, Sektor/Niveau, Ping), Tier C (Vision: `ai_extract_recipe`). Hüllen bleiben modellagnostisch (GL-06 §6); `tier` wird im Call-Log persistiert. |
| **V-02 — Degenerations-Schutz generalisieren** | Ist: steigende-Temperatur-Retry nur bei `zubereitung`. Ziel: im Gateway für ALLE langen Einzeltext-Felder (Erkennung: Wiederholungs-/Längen-Heuristik), max. N Retries, `retries` im Result + Log. Greift in der Propose-Phase, ändert das GL-07-Pattern nicht. |
| **V-09 — Observability** | Strukturierte Logs mit `correlation_id` pro Call-Kette (propose→accept), Kosten-Dashboard pro Team/Feature/Modell auf `ai_call_log`, Accept-/Reject-Quoten als Hüllen-Qualitäts-Signal. Fehlerpfad-Logging-Pflicht: auch Netz-/JSON-Parse-Fehler erzeugen eine Log-Zeile mit `error` (GL-07 §4.3 — Ist-Lücke). |
| **V-15 — Bulk als Queue-Jobs** | Laravel-Queue statt UI-blockierender Loops/launchd-Skripte: Fortschritt, Resume (verarbeitet nur Rest), Abbruch, Fehler-Isolation pro Item; Datenbefüllung bleibt UI-Feature, nie Skript-only. |
| **V-16 — Billables** | `billables`-Config (Vorbild `planner.php`): KI-Aufrufe `per_item` auf `ai_call_log` — verursachergerechte Kosten pro Team; Zähler-Hook im Gateway (§3.1 Pflicht 8). |
| **Lineage-Vokabular vereinheitlichen (GL-07)** | Ziel-Enum **`manual\|ki\|auto`** als EIN PHP-Enum + Cast für alle `*_quelle`-Spalten aller Domänen; Seed-Migration mappt `ai_inferred→ki`, `auto_slug_match→auto`. Generische Accept-Action erzwingt außerdem `accepted_at`-Stempel (Ist-Lücke: `accept_marketing_text` u. a.) und Confidence-Clamp [0,1]. |
| **GL-06 Modul-Key-Drift bereinigen** | Ist-Befund (GL-06 §4.3): Call-Sites übergeben 5 verschiedene Modul-Werte; `"rezept"` (~20 Sites) und `"recipes"` (3 Sites) matchen keine Modul-Hülle — diese Calls liefen faktisch ohne Fach-Hülle. **Bau-Auftrag:** kanonisches Modul-Key-Enum (`grundprodukte \| basisrezepte \| verkaufsrezepte \| lieferanten \| foodbook`), beim Port alle Drift-Werte auf `basisrezepte` mappen; `SemanticLayerBridge::create/updateMeta` validiert `enabled_modules` nur gegen das Enum; das Gateway lehnt unbekannte `module`-Werte hart ab (statt still global-only zu resolven). |
| Key-Sicherheit (H1) | Gemini-/LLM-Key ausschließlich im Plattform-Secret-Store; `gemini_set_api_key`-Pfad entfällt ersatzlos. |
| Wissens-Audit (GL-13 §6) | `knowledge_used` (Slugs + Version) pro Call ins Log — heute geht `files_used` nach dem Call verloren. |

## 7. Akzeptanzkriterien & Golden-Tests

**GL-Test-Basis (PHPUnit-Datasets):** GL-06 GT-06-1…10 (Resolver gegen Seed der 14 Hüllen/21 Versionen) · GL-07 GT-07-1…11 (Lebenszyklus generisch, am GP-Tags-Beispiel) · GL-13 GT-13-1…11 (Tokenizer, Discovery, Budgets, Stil-Filter, graceful degradation).

**Abnahme-Szenarien:**

1. **Resolver-Parität:** Nach Hüllen-Seed liefert `resolveForCall('grundprodukte')` exakt 3 Hüllen in GL-06-Reihenfolge (GT-06-1); `compose` stellt Hüllen IMMER vor den Ad-hoc-System-Prompt (GT-06-9); neue Version ohne Zeiger-Flip ändert nichts (GT-06-10).
2. **Drift-Härtung:** Gateway-Call mit `module='rezept'` wirft eine typisierte Exception (V-06) statt still nur die globale Hülle zu ziehen — der Alt-Bug (GT-06-2-Verhalten) ist im Ziel ein Testfall für die Ablehnung.
3. **Fehlerpfad-Audit:** Simulierter Timeout/JSON-Parse-Fehler beim Propose → es existiert trotzdem genau eine `ai_call_log`-Zeile mit befülltem `error`, `feature`, `team_id`, `user_id`; kein Fachwert geschrieben.
4. **Bulk-Resume:** Bulk-Run über 100 Ziele wird nach 40 Items abgebrochen → Resume verarbeitet exakt die restlichen 60; jedes Item hat eine eigene Log-Zeile; Items mit `quelle='manual'` wurden übersprungen (Override-First pro Item, GL-07).
5. **Kosten-Abrechnung:** Summe Tokens/Kosten im Dashboard == Summe der Log-Zeilen pro Team/Feature; jeder Call inkrementiert den Billables-Zähler genau einmal (auch Dry-Runs); `tier` im Log entspricht der Config bzw. dem Override.
6. **Dry-Run-Sicherheit:** `dryRun` gegen ein Produktiv-GP erzeugt Log-Zeile `feature='layer_dryrun'`, verändert aber keinerlei Fachdaten — DB-Diff vor/nach ist leer bis auf `ai_call_log`.
