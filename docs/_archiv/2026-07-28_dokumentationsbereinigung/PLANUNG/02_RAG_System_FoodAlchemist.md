# RAG-System Food Alchemist — Gesamtplan (Stand 2026-07-16)

> **Auftrag:** Das RAG-System für den Food Alchemist komplett durchgeplant — von der bestehenden Infrastruktur bis zum systemweiten semantischen Retrieval-Layer.
> **Quellen:** Dev-Modul office (Board 53: [#507](https://office.bhgdigital.de/dev) Keystone, #505 Generator-Grounding, #508 Revise), `docs/ROADMAP.md` (⭐-Update 2026-07-15, Q4/Q5), GL-04 §6.1/§6.2 (V-04/V-05), Code-Kartierung platforms-core + platforms-foodalchemist (2026-07-16).
> **Tracking:** Dev-Modul Package `platforms-food-alchemisten` (ID 23), Board 53. Diese Datei ist der Bauplan, das Dev-Modul der Tacho.

---

## ✅ BAU-STATUS 2026-07-16 — E0–E5-Harness GEBAUT + GEPUSHT (provider-agnostisch)

**Entscheid Dominique:** „alles lokal bauen, per MCP online testen sobald deployt." Umgesetzt, verifiziert, auf `main`:

| Etappe | Stand | Artefakt |
|---|---|---|
| **E0** Golden-Set | ✅ | `tests/Fixtures/SemanticGoldenSet.php` (44 Fälle + 8 Anti-Marker) + Wohlgeformtheits-Test |
| **E1** Pools embedden | ✅ | `PoolEmbeddingService`, `foodalchemist:embed --pool=`, `GpEmbeddingObserver`/`RecipeEmbeddingObserver` |
| **E2** Hybrid-Layer | ✅ | `SemanticRetrievalService` (V-04-Port) additiv in `IngredientMatchService::candidatesFor` (Flag AUS = byte-identisch, 84 Goldens grün) |
| **E3** Revise-Grounding + UI | ✅ | `RecipeService::syncIngredients`-Grounding + `RecipeModal::matchVorschau` Hard-Stop-Vorschau → **#508 DONE** |
| **E4** Suchflächen | ✅ | `gps/recipes/knowledge.SEARCH` hybrid (`via`-Marker, `semanticPoolIds`) |
| **E5** Kalibrierung | ⚙️ Harness gebaut | `foodalchemist:embed-eval` (Recall@K + Anti-Marker + Floor-Vorschlag). **Eichung SELBST offen** — läuft online nach Backfill mit echtem Key. |
| **E6** Deploy demo | ⏳ Martin | s. korrigierte Prozedur unten |

**Commits (`main`):** `9c1bae2` (E0–E4) + `ebc1aa4` (E5-Harness). **29 neue Tests, volle FA-Suite 731/732 grün, 1 vorbestehender Skip, null Regression.** ROADMAP + Dev-#507/#508 im Lockstep gezogen.

**⚠️ Deploy-Prozedur korrigiert (unser wiederkehrender Fehler):** Deploy = **`demo.bhgdigital.de/update.sh` ausführen** (git pull main → `composer update` über die **Demo-Host-App**, NICHT modul-scoped → commit + **push** → der **Server macht den Auto-Deploy selbst**). Danach serverseitig `php8.4 artisan foodalchemist:embed --pool=all` (Backfill, einziger nicht-automatischer Schritt) → `foodalchemist:embed-eval --team=<id>` (Floor messen) → `FOODALCHEMIST_SEMANTIC_POOL_FLOOR=<Vorschlag>` + `FOODALCHEMIST_SEMANTIC_SEARCH=true`. OpenAI-Key kommt via Core-Contract (Martin, 2026-07-16).

**Noch offen:** E5-Eichung *ausführen* · E6-Backfill/Flag serverseitig · E0-Core-Discussion (Global-∪-Team-Scope) an Martin · optional V-05-Decompounding/W-2 (nur falls der E5-Report ein Loch zeigt).

---

## 1. Zielbild in einem Satz

Ein **hybrider Retrieval-Layer** (lexikalisch + Embedding) über **alle FA-Suchpfade** — Wissensbasis, GPs, Basisrezepte, VK-Gerichte, Anker — der als **Recall-/Shortlist-Schicht VOR** der deterministischen Match-Logik und der LLM-Disambiguierung sitzt. Embeddings sind **nie finaler Ranker**, nie Ersatz für den Pairing-Graphen, nie Quelle von „Wissen" — sie machen nur sichtbar, was die Token-Lexik nicht sieht („Kürbispüree" ↔ „Püree: Kürbis", „Beef" ↔ „Rindfleisch", „Erdapfel" ↔ „Kartoffel").

**Warum das RAG-technisch die richtige Architektur ist:** FA hat bereits einen funktionierenden Generierungs-Stack (Prompt-Injektion via `AiGatewayService` + `KnowledgeContextService`, GL-13-Blöcke, Grounding-Hard-Stops). Was fehlt, ist nicht „RAG von null" — es fehlt die **Retrieval-Hälfte** über den GP-/Rezept-Pools. Die Augmentation-Hälfte steht.

---

## 2. Ist-Stand (code-verifiziert 2026-07-16)

### 2.1 Was steht (nicht neu bauen)

**Platform-Core — generische Embedding-Infra, komplett:**

| Baustein | Datei | Kern |
|---|---|---|
| `EmbeddingService` | `platforms-core/src/Services/EmbeddingService.php` | Facade: `embedAndStore(teamId, entityType, entityId, text)`, `embedAndStoreBatch(...)`, `search(teamId, query, entityTypes, limit, minScore)`, `queueEmbedAndStore` → Job. Skip-if-unchanged via SHA-256 `source_hash`. |
| `EmbeddingProviderRegistry` | `src/Services/EmbeddingProviderRegistry.php` | 1:1-Spiegel der LLMProviderRegistry; Default aus `config('embeddings.default_provider')`. |
| `OpenAiEmbeddingProvider` | `src/Services/OpenAiEmbeddingProvider.php` | `text-embedding-3-large`, **3072d**, default **ON**, max Batch 2048, unterstützt `dimensions`-Param. |
| `GeminiEmbeddingProvider` | `src/Services/GeminiEmbeddingProvider.php` | `gemini-embedding-001`, **768d**, L2-normalisiert, default OFF, `RETRIEVAL_QUERY/DOCUMENT`-Typen — „drop-in-kompatibel zur Cooking-Jarvis-Vorgängerlösung". |
| `MySqlJsonEmbeddingStore` | `src/Services/MySqlJsonEmbeddingStore.php` | **MySQL-JSON, Cosine in PHP** (SQL nur Pre-Filter auf team/provider/model/entity_type). Dokumentierte Grenze ~50k Vektoren je Partition; `QdrantEmbeddingStore` als Drop-in vorgesehen. |
| Tabelle `core_embeddings` | Migration 2026-06-17 | `UNIQUE(team_id, entity_type, entity_id, provider, model)` + Such-Index. `team_id` = bigint ohne FK. |

**FA-Seite — Wissens-Anbindung, gebaut (#469/#507-Teilstück):**
- `src/Services/Ai/KnowledgeEmbeddingService.php`: embeddet `knowledge_documents` (domain: Titel + 2000 Zeichen Lead; pairing: Stem + max 40 Partner) + Anker (`display_de` + Slug). 1 Vektor pro Doc — kein Sub-Chunking. Globaler Korpus → Sentinel `team_id=0`.
- Command `foodalchemist:knowledge-embed` (idempotent via source_hash), Flag `FOODALCHEMIST_SEMANTIC_SEARCH` (**default false**), config `semantic_search` (`min_score` 0.30, `anker_min_score` 0.55).
- Konsumiert an 3 Stellen: (1) `KnowledgeContextService::semanticSlugs()` als **Auffüllung** wenn Lexik < TOP_K liefert (Hot-Path, opt-in); (2) Browser-Semantiksuche `searchDocIds()`; (3) `resolveAnkerId()` für Freitext→Anker.

### 2.2 Was rein lexikalisch läuft (die Baustellen)

| Pfad | Ist-Algorithmus | Symptom |
|---|---|---|
| `IngredientMatchService` / `gps.MATCH` | `TokenEngine::matchScore` — Slug-Exact 1.0 → gestemmte Token-F1 → Prefix-Bonus; Bänder Exact ≥0.85 / FuzzyHigh ≥0.70 / FuzzyLow ≥0.50 | Demo-Beweis #507: `MATCH("Beef")` → „Corned Beef" (0.5) statt Rindfleisch. Komposita/Synonyme unsichtbar. |
| `GenerationContextService::forGeneration` (#505) | max. 6 Leit-Tokens → `candidatesFor` (Token-F1) + name-basierter Anker-Lookup | Token-blinde Reuse-Liste → KI erfindet vorhandene Komponenten neu → **GP-/Rezept-Dubletten**. |
| `RecipeService::syncIngredients` (Revise, #508) | **gar kein Matching** — reiner Persister, neue KI-Zutat → `match_method='unmatched'` | EK-/Allergen-Aggregation bricht bis zum Hand-Mapping. |
| `recipes.SEARCH` (MCP) | SQL `LIKE` auf name/recipe_key, Cap 50 | Kein Relevanz-Ranking. |
| `knowledge.SEARCH` (MCP) | Token-Overlap + Alias ×2 | Nutzt die vorhandene Semantik-Schicht NICHT. |

### 2.3 Korrektur am Issue #507 (offen, nachziehen)

Das Issue argumentiert mit **pgvector** (Analogie zu #267 Tool-Registry). Das ist ein **anderes Subsystem** — der Core-Store für Modul-Embeddings ist `MySqlJsonEmbeddingStore` (MySQL-JSON). Die FA-Umsetzung wird **nicht** gegen pgvector geplant. → E0-Aufgabe: #507-Kommentar mit dieser Korrektur.

---

## 3. Architektur-Entscheidungen

### 3.1 Festgezurrt (aus Regelwerk/ROADMAP/Referenz-App — nicht neu verhandeln)

1. **V-04-Muster (GL-04 §6.1) ist die Vorlage:** lexikalischer Pool (`candidatesFor`, Floor 0.40) ∪ semantischer Pass (Query-Embedding → Top-N über Pool-Embeddings, SEM_FLOOR ~0.55) → Hybrid-Re-Rank → Cap 15. **Additiv** — ändert keine Matcher-Schwellen, die 84 Golden-Tests bleiben unangetastet.
2. **Rollen-Invariante (DoD in jeder Etappe):** Embeddings = Recall/Shortlist VOR deterministisch + LLM-Disambig. **Nie finaler Ranker. Nie für Kontrast/Pairing** (Provenienz-Prinzip — der Anker-Graph bleibt die einzige Pairing-Wahrheit).
3. **Graceful Degradation:** kein Provider / Flag aus → rein lexikalisch, nie Fehler (Invariante 6 aus `KnowledgeContextService`).
4. **Hybrid statt Ersatz:** Slug-Exact, Artikelnummern, Aliase bleiben lexikalisch first — Embeddings ergänzen, wo Tokens blind sind.
5. **Store = MySQL-JSON** (Core-Kanon). Qdrant erst ab ~50k Vektoren je Partition — bei FA-Skala (s. §5) nicht absehbar.
6. **Keine Fremdmodul-Änderungen:** platforms-core wird nicht angefasst. Wo ein Core-Wunsch entsteht (Global-∪-Team-Suche, `searchMany`), → Discussion an Martin, FA baut solange den Workaround modulseitig.
7. **MCP im Lockstep:** jede Etappe zieht die betroffenen Tools (`gps.MATCH`, `recipes.SEARCH`, `knowledge.SEARCH`, LIST-Beschreibungen) sofort mit — kein Retrofit (Präzedenz R0.2).

### 3.2 Entscheide (Dominique, 2026-07-16: A + B GEFALLEN)

**A) Provider/Modell für die GP-/Rezept-Pools: ✅ ENTSCHIEDEN — OpenAI.**
Konsequenzen (verbindlich für E1/E2/E5):
- **Alle Floors werden NEU kalibriert** — SEM_FLOOR 0.55 aus der CJ-App gilt NICHT (war Gemini-768d-geeicht). Das Golden-Set (E0) ist damit Pflicht vor dem Scharfstellen, nicht Kür.
- **Empfehlung Implementierung:** `dimensions=768` (oder 1536) für die GP-/Rezept-Pools nutzen (Provider unterstützt den Param) — entschärft das PHP-Cosine-Perf-Risiko (§5) bei einem Key. Finale Dimension = Ergebnis der E5-Messung (Recall@15 768 vs. 1536 vs. 3072 am Golden-Set), als Config, kein Hardcode.
- Ein Provider für alles: Wissens-Korpus bleibt OpenAI (min_score 0.30 dort schon dagegen geeicht), demo braucht nur EINEN Key (`services.openai.api_key`) — deckt zugleich LLM-Provider-Frage #499-nah ab.

Ursprüngliche Optionsabwägung (Historie):

| Option | Pro | Contra |
|---|---|---|
| **Gemini 768d** (Empfehlung) | CJ-Kontinuität: **SEM_FLOOR 0.55 ist mit der Gemini-768d-Vorgängerlösung kalibriert** — Schwellen übertragbar statt Neu-Eichung. L2-normalisiert. 4× kleinere Vektoren → 4× schnellerer PHP-Cosine + weniger RAM (s. §5 Perf). Kosten ~0. | Zweiter API-Key auf demo nötig (Gemini). Etwas schwächer bei sehr feinen Nuancen. |
| OpenAI 3072d | Schon default-ON im Core; ein Key für LLM+Embeddings, stärkstes Modell. | **Alle Floors müssen neu kalibriert werden** (0.55 gilt NICHT — modellabhängig, GL-04-Warnung). 3072d-JSON = ~30 KB/Vektor → Perf-Risiko am GP-Pool (§5). |
| OpenAI mit `dimensions=768` | Ein Key + kleine Vektoren (Provider unterstützt den Param). | Trotzdem Neu-Kalibrierung; Matryoshka-Kürzung kostet etwas Qualität. |

**B) Team-Partition-Strategie: ✅ ENTSCHIEDEN — modulseitiger Merge, kein Core-Change.** `EmbeddingService::search()` nimmt genau EIN `team_id`; FA-GPs/Rezepte sind master-vererbt (`visibleToTeam` = NULL-∪-Ancestry). Der FA-seitige `SemanticRetrievalService` ruft `search()` je Partition (eigenes Team, Master 9, Sentinel 0) und merged Score-basiert (dedupe auf entity_id) — scoreseitig vergleichbar, solange **ein** Modell pro Pool gilt. Parallel: Core-Wunsch „nativer Global-/Shared-Scope + searchMany" als Discussion an Martin (blockiert nichts).

**C) Kalibrierungs-Datensatz:** ~30–50 deutsche Catering-Paare als Golden-Set (Bürgermeisterstück→Rind, Beef→Rindfleisch, Kürbispüree→Püree: Kürbis, Erdapfel→Kartoffel, + Negativ-Paare Brie/Bries, Triple Sec/Triple Chocolate aus `Anti_Marker.md`). Ohne dieses Set ist jede Floor-Diskussion Bauchgefühl.

---

## 4. Ziel-Architektur

```
                       ┌─────────────────────────────────────────────┐
                       │  FA SemanticRetrievalService (NEU, E2)      │
Query (Freitext) ──────►  1. lexikalischer Pool (candidatesFor 0.40) │
                       │  2. semantischer Pass (Query-Embedding →    │
                       │     Top-N je Pool, SEM_FLOOR config)        │
                       │  3. Hybrid-Re-Rank, Cap 15                  │
                       │  Partitionen: team ∪ master(9) ∪ global(0)  │
                       └────────────┬────────────────────────────────┘
                                    │ Shortlist (Kandidaten, nie Urteil)
        ┌───────────────┬───────────┴────────┬───────────────────┐
        ▼               ▼                    ▼                   ▼
  IngredientMatch   GenerationContext   Revise-Re-Grounding   MCP-Tools
  Service            Service (#505)      (#508)               gps.MATCH /
  (gps.MATCH,        Reuse-Inventar      via IngredientMatch  recipes.SEARCH /
  Bänder UNVERÄNDERT) VERFÜGBARE          Service              knowledge.SEARCH
                      BAUSTEINE
        │
        ▼
  deterministische Schwellen (0.85/0.70/0.50) + LLM-Disambig + Hard-Stop
  → Embeddings enden VOR dieser Linie (Invariante)
```

**Pools (entity_types in `core_embeddings`):**

| Pool | entity_type | Embed-Text | Trigger (inkrementell) | Bestand |
|---|---|---|---|---|
| Wissens-Docs | `foodalchemist_knowledge_document` | ✅ existiert (Titel + Lead 2000) | nach `knowledge-import` / `knowledge.POST/PUT` | ~1.010 |
| Anker | `foodalchemist_pairing_anker` | ✅ existiert | mit Vokabular-Pflege | ~1.000 |
| **GPs** | `foodalchemist_gp` (NEU) | §6-Name + Warengruppe + Zustand + Hauptzutat-Slug + Aliase (kompakt, 1 Vektor) | Observer auf create/update(name, status) — nur `status ∈ {approved, tentative, review}` | ~7.900 |
| **Basisrezepte** | `foodalchemist_recipe` (NEU) | Name + Kategorie/HG + Top-Zutaten-Namen (max ~8) | Observer auf Name/Kategorie-Änderung + nach `syncIngredients` | ~2.300 |
| **VK-Gerichte** | dito, Flag in metadata | Name + Speisen-Klasse + Komponenten-Namen | dito | ~930 |
| LAs | — **bewusst NICHT** in v1 | LA→GP läuft über `versucheLaZuGp` (#505 Slice 2); LA-Namen sind Lieferanten-Kauderwelsch, verrauschen den Index | (456–45k) |

**Score-Semantik im Hybrid-Re-Rank (aus V-04):** Baustein in beiden Pässen → max(lexikalisch, cosine); nur semantisch → cosine; nur lexikalisch ohne Embedding → lexical × 0.5. Ergebnis trägt **Herkunfts-Marker** (`lexical|semantic|both`) für Audit/UI.

---

## 5. Skala, Kosten, Performance (ehrlich gerechnet)

- **Volumen:** ~12–13k Vektoren gesamt → weit unter der 50k-Store-Grenze. Qdrant-Frage stellt sich nicht.
- **Embedding-Kosten Backfill:** ~13k Kurztexte ≈ <1 M Tokens ≈ **<0,15 €** (OpenAI) bzw. ~0 € (Gemini Preisstufe 1). Inkrementell: Cent-Beträge/Monat. Kein Budget-Thema.
- **⚠️ Perf-Risiko — der eine Punkt, der wehtun kann:** `MySqlJsonEmbeddingStore` lädt bei jeder Suche **alle** Kandidaten-Vektoren der Partition und rechnet Cosine in PHP. GP-Pool mit 3072d-JSON ≈ 8k × ~30 KB ≈ **~240 MB decode pro Query** — inakzeptabel im per-Zutat-Loop (`gps.MATCH` bei 30-Zutaten-Rezept = 30 Suchen). Mit 768d: ~60 MB — immer noch kein Loop-Material.
  **Mitigation (Pflicht in E2):**
  1. **Ein Pool-Load pro Request:** `SemanticRetrievalService` lädt die Partition einmal, scored dann N Queries in-memory (Rezept-Sync, Generator-Inventar). Modulseitig baubar, ohne Core-Change.
  2. **Query-Embeddings batchen** (1 API-Call für alle Zutaten einer Liste).
  3. 768d bevorzugen (Entscheid A).
  4. Messen als DoD: `gps.MATCH` einzeln < 1 s warm; 30-Zutaten-Re-Match < 10 s. Wenn gerissen → Cache-Schicht (gepackte Float-Blobs im Laravel-Cache) als E2-Nachtrag, erst dann über Core/Qdrant reden.

---

## 6. Etappenplan

> Größen nach ROADMAP-Konvention (S = Stunden, M = 1–2 Tage, L = 3–5 Tage). Globale DoD (Team-Scoping, MCP-Lockstep, draft+created_via, Pest, lokal-verifiziert, Push + Dev-Issue-Update) gilt überall zusätzlich.

### E0 — Weichen stellen · S · sofort, kein Code-Blocker

- [x] **Entscheid A: OpenAI** (ein Provider für alles; Dimension 768/1536/3072 = E5-Messung; Floors werden neu geeicht) + **Entscheid B: Multi-Partition-Merge modulseitig** — Dominique 2026-07-16
- [x] #507-Kommentar: pgvector→MySQL-JSON-Korrektur + dieser Plan verlinkt; #505/#508 auf Etappen dieses Plans gemappt (Dev-Modul 2026-07-16)
- [x] Kalibrierungs-Golden-Set (44 Paare, positiv + Anti-Marker-negativ) als Fixture angelegt — `tests/Fixtures/SemanticGoldenSet.php` (gepusht `9c1bae2`); **Pflicht-Gate vor E2-Scharfstellung**, Messung via `foodalchemist:embed-eval` (E5)
- [ ] Q1-artige Core-Discussion an Martin: Global-∪-Team-Scope + `searchMany` als Wunsch dokumentiert (blockiert nichts)

### E1 — Embedding-Ausweitung auf GP-/Rezept-Pools · M · hängt an E0

Der Kern von #507: dieselbe Pipeline, die das Wissen indiziert, auf GPs + Rezepte ziehen.

- [ ] `GpEmbeddingService` + `RecipeEmbeddingService` (oder ein generalisierter `FoodAlchemistEmbeddingService` mit Pool-Registry — Strukturentscheid beim Bau) nach Vorbild `KnowledgeEmbeddingService`: Embed-Text-Bau, Batch-Backfill, source_hash-Idempotenz
- [ ] Command `foodalchemist:embed {--pool=gps|recipes|knowledge|all} {--team=}` — Backfill + Re-Run sicher
- [ ] **Inkrementell:** Observer/Job (`queueEmbedAndStore`) bei Anlage/Umbenennung/Statuswechsel; gelöschte/merged GPs → `delete()` im Store (kein Vektor-Müll von `status='merged'`-Dubletten)
- [ ] Team-Partitionierung korrekt: Seed→0, Master→9, Team-eigene→team_id; `import-master` zerstört keine Embeddings bzw. Backfill-Command läuft danach (Doku-Hinweis im Import-Runbook)
- [ ] Pest: Embed-Text-Snapshot-Tests je Pool (Text-Drift = stiller Recall-Killer), Observer-Test, Merge/Delete-Test
- [ ] Lokal: Voll-Backfill am Master gemessen (Dauer, Kosten, Count je Partition == Erwartung)

### E2 — Hybrider Retrieval-Layer (V-04-Port) · L · hängt an E1 — **das Herzstück**

- [ ] `SemanticRetrievalService` (read-only): V-04 exakt — lexikalischer Pool ∪ semantischer Pass (SEM_FLOOR als **Config je Modell**, kein Hardcode) → Hybrid-Re-Rank, Cap 15, Herkunfts-Marker, graceful Fallback
- [ ] Multi-Partition-Merge (Entscheid B) + **Ein-Pool-Load pro Request** + Query-Batching (Perf-Mitigation §5)
- [ ] `IngredientMatchService::candidatesFor` konsumiert die Shortlist **additiv** — Bänder/Schwellen/Heuristiken byte-identisch; alle 84 Golden-Tests grün als hartes Gate
- [ ] `GenerationContextService::forGeneration` nutzt denselben Pass → schließt das offene #505-DoD „bestehende Rezepte breiter/semantisch statt lexikalisch" (Cap 30 → hybrides Inventar)
- [ ] MCP-Lockstep: `gps.MATCH` liefert Herkunfts-Marker + ehrliche Beschreibung („hybrid ab Provider aktiv"); Demo-Fall aus #507 als Test: `MATCH("Beef")` findet Rindfleisch/Roastbeef in den Kandidaten
- [ ] Kalibrierung: Golden-Set-Lauf → SEM_FLOOR für OpenAI (je Dimension) frisch geeicht + als Config dokumentiert — 0.55 aus der CJ-App ist NICHT übertragbar (war Gemini-geeicht), nur Startwert fürs Sweep
- [ ] Perf-DoD gemessen: Einzel-Match < 1 s warm, 30-Zutaten-Batch < 10 s, Speicher-Peak dokumentiert
- [ ] Flag-Architektur: `FOODALCHEMIST_SEMANTIC_SEARCH` bleibt der EINE Schalter (Wissen + GP/Rezept), Provider-Ausfall degradiert still auf Lexik

### E3 — Revise-Re-Grounding (#508) · M · unabhängig von E1/E2 lösbar, profitiert davon

Schon das **mechanische** Re-Matching schließt die Kern-Lücke — nicht auf E2 warten.

- [ ] Revidierte Zutatenliste läuft komplett durch `IngredientMatchService` (gp/sub/none) statt roh durch `syncIngredients`
- [ ] Hard-Stop-Zeile im Überarbeiten-Vorschau-UI: „GP anlegen" / „Basisrezept-Stub anlegen" (analog Generator)
- [ ] Optional: `versucheLaZuGp` (#505 Slice 2) auch im Revise-Pfad
- [ ] Pest + MySQL-Smoke: nach Revise keine `unmatched`-Zeile, wenn GP/Sub existiert; GL-07-Text-Lineage regressionfrei
- [ ] Sobald E2 live: Revise nutzt automatisch den hybriden Pass (gleicher Service — kein Extra-Aufwand)

### E4 — Such-Oberflächen hybridisieren · S–M · hängt an E1/E2

- [ ] `knowledge.SEARCH` (MCP): semantischen Pass zuschalten (die Infra konsumiert heute nur der Browser) — Ergebnis-Merge lexikalisch+semantisch, Cap bleibt
- [ ] `recipes.SEARCH` (MCP) + Rezept-/VK-Browser: LIKE-Pfad um semantische Kandidaten ergänzt (Marker in der Antwort)
- [ ] `gps.SEARCH` analog
- [ ] Tool-Beschreibungen aktualisiert (Registry-Text erklärt Hybrid + Fallback-Verhalten)

### E5 — Kalibrierung, Härtetest, V-05-Entscheid · M · hängt an E2

- [ ] Golden-Set-Report: Recall@15 lexikalisch vs. hybrid je Fallklasse (Synonym / Kompositum / Übersetzung / Anti-Marker-Negativ) — **gemessene** Verbesserung, nicht behauptete
- [ ] Anti-Marker-Negativtests: Brie↛Bries, Triple Sec↛Triple Chocolate — semantische Nähe darf die Verwechslungs-Sperren NICHT aushebeln (Shortlist ok, Match-Urteil nie)
- [ ] **V-05 Decompounding (GL-04 §6.2) nur bei Bedarf:** wenn der Report zeigt, dass Komposita-Fälle trotz Embeddings durchrutschen → Marker-basierter Query-Split als schlanke Ergänzung; sonst bewusst NICHT bauen (spezifiziert ≠ nötig)
- [ ] W-2 (normalisierte Index-Spalte) ebenso: nur wenn der Report ein Recall-Loch VOR dem Scoring zeigt
- [ ] Dubletten-Wirk-Messung: Generator-Lauf-Serie vorher/nachher — Anteil „KI erfindet existierende Komponente neu" (der eigentliche #505-Schmerz)

### E6 — Deploy demo + Betrieb · S (FA-Seite) · **extern blockiert: Martin**

- [ ] **Blocker:** Embedding-Provider-Key auf demo (`services.openai.api_key` und/oder Gemini) — derselbe Deploy-Blocker wie LLM-Coherence/#499; bis dahin läuft demo graceful lexikalisch
- [ ] Backfill auf demo (`foodalchemist:embed --pool=all`), Queue-Worker deckt `GenerateEmbeddingJob`
- [ ] `FOODALCHEMIST_SEMANTIC_SEARCH=true` auf demo; Live-Smoke: der #507-Demo-Fall (`MATCH("Beef")`) hybrid nachgestellt
- [ ] `import-master`-Runbook um Embedding-Backfill-Schritt ergänzt
- [ ] ROADMAP + Dev-Issues (#505 DoD-Rest, #507, #508) synchron nachgezogen (Commit-Sync-Regel)

---

## 7. Abhängigkeits-Bild

```
E0 (Entscheide) ──► E1 (Pools embedden) ──► E2 (Hybrid-Layer) ──► E4 (Suchflächen)
                                                   │                    E5 (Kalibrierung/Report)
E3 (#508 Revise, mechanisch) ── unabhängig ────────┴──► profitiert ab E2 automatisch
E6 (demo) ── FA-Seite jederzeit nach E1; scharf erst mit Provider-Key (Martin)
```

**Reihenfolge-Logik:** E3 zuerst oder parallel starten (kein Embedding nötig, schließt den akuten Datenbruch im Revise). E1+E2 sind der Kern von #507. E5 verhindert, dass wir „gefixt" behaupten, ohne es gemessen zu haben (Verify-before-claiming).

---

## 8. Risiken & Sparring (aktiv gegenhalten)

1. **Perf des PHP-Cosine-Stores am GP-Pool** ist das größte technische Risiko (§5) — darum Ein-Pool-Load + Batching + 768d als Pflicht-Mitigation und Perf-Zahlen als DoD, nicht als Hoffnung.
2. **Kalibrierungs-Falle:** SEM_FLOOR 0.55 gilt nur fürs CJ-Gemini-Setup. OpenAI-Floors ungeprüft übernehmen = stille Recall-/Precision-Verschiebung. Config je Modell, Golden-Set entscheidet.
3. **Scope-Creep-Gefahr „Embeddings überall":** Pairing/Kontrast, Kohärenz-Score (Q5-Judge), finale Match-Urteile bleiben embedding-frei — das ist Alleinstellung (Provenienz), kein Rückstand.
4. **Semantik-Nähe ≠ Identität:** Brie/Bries sind semantisch nah. Die Anti-Marker-/Disambiguierungs-Schicht NACH der Shortlist ist der Schutz — deshalb Invariante 2 in jeder DoD.
5. **Fremdmodul-Grenze:** Multi-Partition und searchMany-Wünsche NICHT selbst in platforms-core patchen — Discussion an Martin, modulseitiger Merge bis dahin.
6. **Embed-Text-Drift:** Ändert jemand später den Embed-Text-Bau, sind Bestand und Neuanlagen inkonsistent (source_hash rettet nur bei Re-Run). Snapshot-Tests + „nach Textbau-Änderung: Voll-Re-Embed"-Regel in die Doku.

## 9a. Terminologie-Schicht + Lernschleife für neue Namen (Weg 2 — LIVE 2026-07-19)

> **Nachtrag nach Go-Live.** Der obige Plan (E0–E6, 2026-07-16) ging von „Embeddings sind der Recall-Hebel" aus. Die B2-Floor-Eichung hat das **widerlegt**: Embedding-only findet **keinen trennbaren Floor** — bei brauchbarem Recall leaken Anti-Marker (Brie↔Bries), bei 0 Leaks stirbt der Recall (3 %). Wurzel: OpenAI 3-large staucht kurze Lebensmittel-Synonyme; echte Treffer und Verwechslungs-Fallen überlappen im Vektorraum.

**Die tatsächliche Lösung (Weg 2):** Die meisten Fehler sind **gar nicht semantisch**, sondern
- **Dialekt/Synonym** (Paradeiser=Tomate, Erdapfel=Kartoffel) → **Wörterbuch**, kein Vektor.
- **Verwechslung** (Brie/Bries, Möhre-Bries) → **Negativliste**, kein Vektor.

Beide liegen **kuratiert im Vault** (`Substitutionen.md` + `Anti_Marker.md`). → **`TerminologyService`** (S1 Alias · S2 Anti-Marker · S3 Decompounding) sitzt **VOR** dem Embedding-Pass. Embeddings bleiben additiv obendrauf.

**Gemessen (`foodalchemist:matcher-eval --team=6 --semantic`):** deterministisch 59 % · **hybrid 66 % Recall@15 · 0/8 Leaks**. Flag scharf auf demo (`FOODALCHEMIST_SEMANTIC_SEARCH=true`, floor 0.55). Smoke live bestanden. #507 = Done (Board 53).

### E7 — Lernschleife für neue Namen · M · **die offene Konsequenz**

**Problem:** Der `TerminologyService` ist der Arbeitspferd — **aber heute kuratierte PHP-Konstanten aus dem Vault.** Ein *wirklich neuer* Handelsname/Dialekt, der da nicht drinsteht, löst deterministisch **nicht** auf; Embeddings fangen ihn nur teilweise und leak-anfällig. Neue Lieferanten-Kataloge bringen genau solche neuen Namen laufend rein (via Katalog-Ingest [Spec 13] → LA-First-Mint [Spec 07]). Ohne Schleife wächst das Wörterbuch nie mit → der Recall-Gewinn erodiert mit jedem neuen Sortiment.

**Trichter heute (Ist):**
1. **Eingang:** Katalog-Ingest Kanal B (Spec 13) → neuer LA in `foodalchemist_supplier_items`.
2. **Matching LA→GP:** `candidatesFor` mit `TerminologyService` (S1/S2/S3) **+ hybrid Embeddings**.
3. **Kein GP?** `LaFirstGpService::mintFromLa` (Spec 07) → tentative GP + ReviewQueue.
4. **Index frisch:** Observer re-embeddet automatisch.

**Was fehlt (E7):**
- [ ] **S1-Alias + S3-Decompound auch im `matchIngredient`-Scoring** — aktuell nur in der Shortlist (`candidatesFor`) verdrahtet, nicht in der Entscheidung. Heißt: „Paradeiser" steht in der Kandidatenliste, gewinnt aber im Urteil noch nicht → deterministische Auflösung greift halb. (Bereits als offener #507-Punkt notiert.)
- [ ] **`TerminologyService` → DB-Tabelle + MCP** (`terminology.*`, Lockstep) statt PHP-Konstanten. **Voraussetzung** der Schleife: ohne runtime-pflegbare Tabelle ist kein Zurückschreiben möglich. Seed = **einmaliger** Import aus `Substitutionen.md`/`Anti_Marker.md`; danach ist die DB Master (s. Governance-Entscheid). Anti-Marker- und Alias-Zeilen team-partitioniert (Master ∪ Team).
- [ ] **Die eigentliche Schleife:** Unmatched/tentative neuer Name (aus ReviewQueue) → Kurator-Entscheid im Review-UI = eine von drei Aktionen: **(a) Alias** auf bestehenden GP (→ S1-Tabelle), **(b) Anti-Marker** gegen falschen Nachbarn (→ S2-Tabelle), **(c) echt neu** (GP bleibt, kein Alias). Entscheid schreibt sofort in die Terminologie-Tabelle → **beim nächsten Matching sofort wirksam**, kein Deploy.

### Governance-Entscheid (Dominique, 2026-07-19): FA = Master der Terminologie

Der Terminologie-Bestand (Aliase + Anti-Marker) wird nach dem Seed **in FA gepflegt, nicht mehr im Vault.** Die Vault-Dateien `Substitutionen.md`/`Anti_Marker.md` werden vom Wahrheits-Original zum **einmaligen Seed + generierten Spiegel** herabgestuft.

**⚠️ Nebenwirkung, die den Entscheid erst vollständig macht:** CJ selbst liest diese zwei Dateien laufend (CLAUDE.md-Pflichtregelwerk: jede Substitution/Verwechslung). Friert man sie ein, während FA über die ReviewQueue weiterlernt, **veraltet CJs eigenes Wissen** — Drift in die Gegenrichtung. Darum ist der Rückfluss **Pflicht, nicht optional:**

- [ ] **FA-DB → Vault-MD EINBAHN-Export (Pflicht):** periodischer Command spiegelt die kuratierte DB zurück nach `Substitutionen.md`/`Anti_Marker.md` — **generierte Dateien mit „manuelle Edits werden überschrieben"-Warnkopf**, exakt das EINBAHN-SQL→MD-Muster wie LAs/Rezepte/GPs. FA gewinnt bei Konflikt. Hält CJs file-basierten Workflow lebendig, ohne die Master-Rolle aufzuweichen.
- [ ] *(Prüfen)* Ob CJ die Terminologie mittelfristig direkt via `terminology.*`-MCP statt aus der MD liest — dann wäre der Export nur noch menschlicher Lesekomfort, nicht funktionskritisch. **Später-Entscheid, blockiert E7 nicht.**

> **Entscheid Dominique 2026-07-19: E7-(d) Export-Weg = SPÄTER entscheiden.** ⚠ Server-Command kann den lokalen Mac-Vault nicht schreiben (FA auf demo/Forge). Drei Optionen bleiben offen: (1) CJ liest via `terminology.*`-MCP (Vault-MD friert als Seed ein, CLAUDE.md-Anpassung); (2) lokaler CJ-Export-Command (MCP→MD-Spiegel); (3) hier offen. **E7-(a)/(b)/(c) + kleine Pools + Embed-Tiefe starten OHNE diese Entscheidung** — (d) ist der letzte Teilschritt.
- [ ] **Pest:** neuer Alias in DB → `matchIngredient` löst auf, ohne Code-Change; Anti-Marker in DB → Verwechslung bleibt gesperrt; Smoke gegen echten neuen Katalog-Namen.

**Abhängigkeit / Reihenfolge (Empfehlung Priorität):** E7 **vor** neuen RAG-Pools (Spec 15). Begründung: Der deterministische Layer ist der Motor — erst ihn lernfähig machen, dann Embed-Text-Tiefe (§5b Spec 15), dann Pools nach Wert. Neue Pools ohne Lernschleife vergrößern nur die Fläche, auf der neue Namen ungelöst durchrutschen. **LA-Pool zuletzt — NICHT weil er auf den Ingest wartet** (FA ist Master, alle LAs liegen schon da; Ingest ist Zuträger, nicht Voraussetzung), **sondern weil er mit Zehntausenden Vektoren die ~50k-Grenze des PHP-Cosine-Stores (§5) sprengt** und damit als Erster die Qdrant-/Partitionierungs-Frage real macht. Details → Spec 15 §5c.

---

## 9. Bewusste Nicht-Ziele (v1)

- Kein pgvector/Qdrant/Neuer-Store (Skala gibt es nicht her; Drop-in-Pfad existiert dokumentiert).
- Kein Fine-Tuning / keine Domain-Modelle (Issue-Frage beantwortet: Golden-Set erst messen — die V-04-Praxis in CJ zeigt, dass generische Embeddings + Lexik-Hybrid für das §6-Vokabular reichen).
- Kein Doc-Chunking des Wissens-Korpus (1 Vektor/Doc reicht für Discovery; Volltext kommt eh via `knowledge.GET`/Kontext-Budgets).
- Keine LA-Embeddings (Rauschen; LA→GP via #505-Slice-2).
- Kein Embedding-basiertes Pairing — der Anker-Graph bleibt die Waffe (R6.8–R6.10).

---

*Erstellt 2026-07-16 (Session RAG-Planung). Quellen-Verifikation: Code-Kartierung platforms-core HEAD + platforms-foodalchemist HEAD, Dev-Issues #505/#507/#508 (office, Board 53), ROADMAP.md, GL-04 §6.1–6.3, Memory `project_fa_507_semantic_search`.*
