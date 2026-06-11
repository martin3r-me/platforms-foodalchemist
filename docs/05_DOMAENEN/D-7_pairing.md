---
typ: Domänen-Spec
domaene: D-7
stand: 2026-06-10
status: ausgearbeitet
mvp: Phase 2
---

# D-7 — Pairing / Flavor-Graph

> **Services (stateless):** PairingService
> **Hängt ab von:** D-3 (GPs), D-5 (Rezepte/Zutaten), D-4 (KI-Lebenszyklus GL-07, Wissen GL-13) · **MVP-Status (⚠D5):** Phase 2
> **Kurzbeschreibung:** Anker-Graph (767 Anker, 23.951 Kanten), deterministische Kohäsions- und Vorschlags-Queries, Netz-Visualisierung. **Die komplette Fachlogik steht normativ in [GL-10](../04_GRUNDLOGIKEN/GL-10_pairing_kohaesion.md)** (Schwellen, Gewichte, Pseudocode, Golden-Tests T1–T9) — diese Spec ergänzt nur Ressourcen-Schnitt, Service-API, UI und Verbesserungen. Nichts aus GL-10 wird hier dupliziert.

**Phase-2-Pragmatik:** Die *Daten* (Tabellen + Seed) sind global und gehören in den MVP — sie sind FK-Ziele und ändern sich nicht mehr (GL-10 §6, 02_DATENMODELL §A.4). Service + UI können komplett nachgezogen werden, ohne Schema-Änderung.

## 1. Scope & Ressourcen

27 Ist-Commands (Inventar-Filter D-7) → Ressourcen-Gruppen:

| Ressource | Ist-Commands | Muster | GL | Ziel |
|---|---|---|---|---|
| GP-Anker (Cap 1–3) | `list_gp_ankers`, `set_gp_anker`, `remove_gp_anker` | CRUD | GL-10 | `PairingService` + Anker-Picker |
| GP-Anker KI | `ai_infer_gp_ankers`, `accept_gp_ankers`, `reject_gp_ankers`, `clear_gp_ankers` | KI-Lebenszyklus | GL-06/07/10/13 | über `AiProposalService` (D-4) |
| Rezept-Anker (Cap 1–5) | `list_recipe_ankers`, `set_recipe_anker`, `remove_recipe_anker` | CRUD | GL-10 | analog GP |
| Rezept-Anker KI | `ai_infer_recipe_ankers`, `accept_recipe_ankers`, `reject_recipe_ankers`, `clear_recipe_ankers` | KI-Lebenszyklus | GL-06/07/10 | analog GP |
| Rezept-Pairings (Partner, ≠ Anker!) | `get_recipe_pairings`, `add_recipe_pairing`, `remove_recipe_pairing` | CRUD | GL-10 | `PairingService` |
| Rezept-Pairings KI | `ai_suggest_pairings`, `accept_pairings`, `reject_pairings` | KI-Lebenszyklus | GL-06/07/10/13 | über `AiProposalService` |
| Graph-Queries (read-only) | `recipe_cohesion`, `pairing_anker_neighbors`, `pairing_bridge`, `recipes_sharing_pairings` | Spezial | GL-10 | `PairingService` |
| Lookups | `list_pairing_anker`, `get_gp_pairing_info` | CRUD | GL-10 | `PairingService` |
| Streuner | `list_produkttypen` | CRUD | — | gehört fachlich zu D-1/D-3 (Lookup mit `warengruppe`-Param, `review=1` im Inventar) — beim Implementieren dorthin ziehen |

**Cross-Domain-Hinweise:**
- `recipe_graph` (Datenlieferant der Netz-Visualisierung) ist im Inventar **D-5/MVP** klassifiziert — die zugehörige *Visualisierung* (§4) ist aber Phase 2. Empfehlung: Query-Methode gleich in den `PairingService` legen (liest nur Pairing-Tabellen), D-5 referenziert sie.
- `recipe_culinary_coherence_compute/_get` (Gemini-Judge, zweite Achse) liegt im Inventar in **D-5/MVP**; die Abgrenzungs-Regel (Aroma-Score und Judge-Score nie verrechnen) steht in GL-10 §1 und gilt auch für die UI hier.
- Reverse-Lookup `anker-gps` („wer trägt Aroma X") existiert heute nur im CLI `232_query_pairing.py` — im Ziel als Service-Methode mitbauen (GL-10 §3.4).

## 2. Datenmodell-Ausschnitt

Vollständige Tabellen-Disposition inkl. Zeilenzahlen: GL-10 §2 + 02_DATENMODELL §A.4/§B. Hier nur das Scoping-Bild:

```
GLOBAL (⚠D1: team_id NULL, BHG-kuratiert, Seed im MVP)
  foodalchemist_vocab_pairing_anker      767  Anker-Vokabular; knowledge_document_id statt file_path (⚠D4)
  foodalchemist_pairing_anker_edges   23.951  Graph; typ klassisch/modern/kontrast; UNIQUE(a,b,typ); BIDIREKTIONAL (Invariante GL-10 §2.4)
  foodalchemist_gp_ankers              9.509  GP→Anker, rolle='kern', Cap 3, zeilenbasierte Lineage (GL-07 §4.2)
  foodalchemist_flavor_*               FlavorDB-Basis (1.529 Ingredients) — nur Seed, keine aktive Logik

TEAM (team_id NOT NULL — hängen an team-eigenen Rezepten, ON DELETE CASCADE)
  foodalchemist_recipe_anker_mapping   3.575  Rezept→Anker, Cap 5
  foodalchemist_recipe_prozess_anker     303  Prozess-/Kocharomen (röstaromen/karamell/rauch/ferment)
  foodalchemist_recipe_pairings       24.616  kuratierte PARTNER (typ klassisch/kontrast/verbund/trinitas ≠ Kanten-typ!)
  foodalchemist_recipe_culinary_coherence  Judge-Cache (components_hash, score, schwachstelle)
```

Konsequenz des Mischbetriebs: Graph + GP-Anker sind für alle Teams identisch lesbar; Kohäsion/Suggest/Bridge laufen immer im Team-Scope der Rezepte, lesen aber den globalen Graph (`TeamOrGlobalScope` aus 01_ARCHITEKTUR §1 greift nur auf den Rezept-Seiten der Joins).

## 3. Services & Methoden

EIN `PairingService`; KI-Vorschläge laufen über den generischen `AiProposalService` (GL-07), Wissens-Grounding über `KnowledgeContextService` (GL-13). Methoden (PHP-Skizze, Verhalten je GL-10-§):

```php
// Anker-Pflege (Caps + Override-First als Service-Regel, nicht nur DB-Check)
listGpAnkers(int $gpId): Collection                     // GL-10 §2, inkl. quelle/confidence je Zeile
setGpAnker(int $gpId, int $ankerVocabId): void          // Cap 3 → PairingCapExceededException (V-06)
removeGpAnker(int $gpId, int $ankerVocabId): void
listRecipeAnkers / setRecipeAnker / removeRecipeAnker   // analog, Cap 5

// Rezept-Pairings (Partner-Liste)
getRecipePairings(int $recipeId): Collection
addRecipePairing(int $recipeId, int $ankerVocabId, ?string $typ): void
removeRecipePairing(int $recipeId, int $ankerVocabId): void

// Graph-Queries (alle read-only, reine SQL/PHP-Arithmetik, KEIN KI-Call)
cohesion(int $recipeId): CohesionResult                 // GL-10 §3.2 — score/min_score/coverage/fit/orphans/weakest_pair
cohesionForComponents(array $labels): CohesionResult    // Ad-hoc-Variante (CLI-Parität `--components`)
suggest(int $recipeId): SuggestResult                   // GL-10 §3.3 — KLASSIKER + SIGNATURE Top 8
bridge(int $recipeA, int $recipeB): BridgeResult        // GL-10 §3.4
neighbors(string $slug, ?string $typ, int $limit = 30): Collection
relatedRecipes(int $recipeId, int $minShared = 2, int $limit = 10): Collection
ankerGps(string $slug, int $limit = 40): Collection     // Reverse-Lookup (heute CLI-only)
graphData(int $recipeId, ...): RecipeGraphData          // Datenlieferant für §4 (Ist: recipe_graph, D-5)

// KI-Lebenszyklus (Instanzen des GL-07-Patterns, zeilenbasiert)
proposeGpAnkers(int $gpId): AiProposal                  // Grounding: max 3 Dokus × 1.400 Z. (GL-13 §4.1)
proposeRecipeAnkers(int $recipeId): AiProposal          // ohne Vault-Grounding (Inventar: vault=0)
proposePairings(int $recipeId): AiProposal              // Grounding: max 5 Dokus × 1.200 Z.
// accept/reject/clear → generisch im AiProposalService (GL-07 §3, Tabelle 4.2: manual überlebt)

// Graph-Pflege (Admin)
importEdges(KnowledgeImport $src): ImportReport         // idempotent, schreibt IMMER beide Richtungen (§6)
```

Slug-Matching (`anker_slug_matches`, `best_identity_anchor`, `fold`/`build_anchor_index`) wird als interne, pure PHP-Klasse implementiert — exakt nach GL-10 Tabellen 2/3 + T1–T3, damit die Golden-Tests direkt darauf laufen.

## 4. Livewire-Komponenten & UI-Fluss

D-7 hat **keinen eigenen Sidebar-Hauptbereich als Pflicht** — Pairing dockt an die Editoren von D-3/D-5/D-6 an (so auch die Alt-App). Sidebar-Eintrag „Pairing-Graph" (01_ARCHITEKTUR §2, Gruppe Komposition) ist ein Explorations-Einstieg (Anker-Suche → Nachbarn → GPs).

| Komponente (Alt-App-Vorbild) | Ziel (Livewire) | Inhalt |
|---|---|---|
| `CohesionBlock.tsx` | `Pairing\CohesionPanel` (eingebettet im Rezept-Editor) | score/min_score/coverage, fit je Komponente, Orphan-Badges, weakest_pair als „Warum"; Coverage < 30 % als „dünne Datenlage" kennzeichnen (GL-10 T6/§6) |
| `RelatedRecipesBlock.tsx` | `Pairing\RelatedRecipes` | verwandte Rezepte + shared_slugs-Chips, Link je Treffer (V-17: echte URL) |
| `AnkerPickerModal` / `GpAnkerPickerModal` / `RecipeAnkerPickerModal` | `Pairing\AnkerPickerModal` | Vokabular-Suche, Cap-Anzeige (2/3 belegt), quelle-Badge (manual/ki/auto) |
| `GpAnkerAutopilotModal` / `RecipeAnkerAutopilotModal` | Queue-Job + Fortschritts-Panel (V-15) | Bulk-Inferenz, Review danach über Review-Queue (V-10) |
| `PairingModal.tsx` | `Pairing\SuggestModal` | KLASSIKER/SIGNATURE-Listen aus `suggest()` |
| `PairingGraphModal.tsx` | **SVG-Insel** (s.u.) | Netz-Visualisierung |

**Netz-Visualisierung (Konzept, kein Code-Port):** Die Alt-App nutzt ein **deterministisches Radial-Layout** statt Force-Simulation — Quell-Rezept im Zentrum, Pairing-Anker auf innerem Ring (alphabetisch → stabile Winkel), verwandte Rezepte auf äußerem Ring, optionale Vorschlags-Satelliten pro Anker; Aroma-Brücken (Anker↔Anker-Kanten) default aus, per Hover transient sichtbar; Zoom/Pan via viewBox, Knoten per Drag manuell verschiebbar. Das ist im Web **als Canvas/SVG-Insel innerhalb einer Livewire-Seite** umzusetzen: Livewire/Service liefert einmalig `graphData()` als JSON, ein kleines Alpine-/Vanilla-JS-Modul rendert SVG und handhabt Hover/Drag/Zoom rein clientseitig — **kein Livewire-Roundtrip pro Interaktion**. Klick auf Rezept-Knoten navigiert auf dessen Route (V-17). Das deterministische Layout ist bewusst beizubehalten (reproduzierbare Screenshots, kein Physik-Geruckel).

**UI-Fluss (typisch):** Rezept-Editor → Section „Pairing" (Section-Header-Pattern: KI-Aktionen rechts) → CohesionPanel zeigt Orphan → AnkerPicker oder KI-Inferenz → Accept im Review-Modal (Konfidenz + Begründung sichtbar, editierbar, Gap-Surfacing `unknown_slugs`) → Kohäsion aktualisiert sich.

## 5. KI-Features dieser Domäne

Alle drei folgen exakt GL-07 (zeilenbasierte Variante, Tabelle 4.2); Hüllen-Komposition GL-06; Modell-Tier nach V-01 (Details in 06_KI_SPEZIFIKATION, E4):

| Feature | Kontext | Zieltabelle | Grounding (GL-13 §4.1) | Tier-Vorschlag |
|---|---|---|---|---|
| `proposeGpAnkers` | GP-Name, hauptzutat_slug, Anker-Vokabular; deterministischer `best_identity_anchor` läuft VOR der KI (quelle=`auto`, conf 1.0), Gemini füllt nur Rest-Slots bis Cap 3 | `gp_ankers` | ✅ max 3 Pairing-Dokus × 1.400 Z. | B (Mechanik-Label) |
| `proposeRecipeAnkers` | Rezeptname + Zutaten + bestehende GP-Anker der Zutaten | `recipe_anker_mapping` | — (nur Hüllen + DB) | B |
| `proposePairings` | Rezept + Zutaten + Vokabular | `recipe_pairings` | ✅ max 5 Pairing-Dokus × 1.200 Z. | B |

Invarianten aus GL-07, die hier besonders zählen: Gap-Surfacing (unbekannte Slugs → `unknown_slugs`, nie schreiben), Cap-Budget beim Accept (manuelle Zeilen zählen mit, GT-07-5), `clear_*` = Voll-Reset auch manueller Zeilen. Der Kohäsions-Score selbst ist **kein** KI-Feature (deterministisch); der kulinarische Judge (zweite Achse) lebt in D-5.

## 6. Verbesserungen gegenüber Ist

| Ref | Verbesserung |
|---|---|
| **Symmetrie-Reparatur** (neu — V-Nummer beim Register-Nachtrag vergeben) | ~175 der 23.951 Kanten haben keine Gegenrichtung (Alt-Datenrest); `recipe_component_suggestions` setzt Symmetrie voraus (GL-10 §2.4). **Beim Seed reparieren** (fehlende Gegenrichtungen erzeugen) + `importEdges()` schreibt fortan IMMER beide Richtungen — eine der beiden Strategien aus GL-10 konsequent, wir wählen „beidseitig speichern". Verifikation: `SELECT count(*) FROM edges e WHERE NOT EXISTS (Gegenrichtung)` = 0 als Seed-Gate. |
| **Embedding-Re-Compute-Hook** (neu — dito) | Fließen Anker-Slugs in Embedding-Texte von Rezepten/GPs ein, muss nach Anker-(Re-)Mapping (Accept, Bulk-Lauf, clear) ein Re-Embed-Job für die betroffene Entität getriggert werden — Hook analog GL-02-Kaskade, Job-Infrastruktur aus D-4/V-15 (02_DATENMODELL §C: Embeddings werden ohnehin neu berechnet, nicht ETLt). |
| **Graph-Pflege-Pipeline** statt One-Shot-Parser | Heute synct `_oneshot_F_2_parse_anker_edges.py` Markdown→DB manuell. Ziel: idempotenter Admin-Import-Job aus den `knowledge_documents` (⚠D4) mit Diff-Report (neue/entfallene Kanten), Audit via `LogsActivity`. |
| V-15 | Anker-Autopiloten (GP + Rezept) als Queue-Jobs mit Fortschritt/Resume statt UI-blockierender Modal-Loops. |
| V-06 / V-07 | Cap-Verletzung als typisierte Exception; Accept (DELETE ki-Zeilen + INSERTs + Log-Stempel) in EINER Transaktion (heute Einzel-Executes). |
| V-10 | KI-inferierte Anker mit niedriger Konfidenz landen in der Review-Queue statt nur im Modal-Moment. |
| GL-10 §6 Ideen (Kandidaten, nicht Pflicht) | (a) `bridge_strength` gewichtet statt LIMIT-30-gedeckelt; (b) `relatedRecipes` zusätzlich über Kern-Anker; (c) Coverage-Warnschwelle in der UI (§4, übernommen). |

## 7. Akzeptanzkriterien & Golden-Tests

1. **GL-10 T1–T9 grün** als PHPUnit-Datasets (Daten-Stand 2026-06-10; T4–T8 sind reale DB-Resultate). Bei Widerspruch gilt GL-10-Rangfolge: Testfall > Entscheidungstabelle > Pseudocode.
2. **Caps als Service-Regel:** 4. GP-Anker bzw. 6. Rezept-Anker → typisierte Exception, kein Insert (GT-07-5-Budget beim KI-Accept).
3. **Manual überlebt:** KI-Re-Run/Accept löscht nie `quelle='manual'`-Zeilen (GL-07 Tabelle 4.2).
4. **Symmetrie-Gate:** Nach Seed + nach jedem `importEdges()` existiert zu jeder Kante die Gegenrichtung (Verifikations-Query aus §6 = 0).
5. **Kohäsion deterministisch & schnell:** identischer Input ⇒ identischer Output, kein KI-Call, keine Schreiboperation; Richtwert < 200 ms für ein 10-Komponenten-Rezept (reine Index-Queries).
6. **Graph-UI:** Radial-Layout stabil (gleiches Rezept ⇒ gleiche Knoten-Positionen), Interaktion ohne Server-Roundtrip, Rezept-Klick navigiert per URL (V-17).
7. **Neutral-Semantik:** `neutral`-gemappte Komponenten erscheinen in der UI als „bewusst kernlos", nie als Orphan (GL-10 T9).
8. **Scoping:** Team A sieht in relatedRecipes/bridge ausschließlich eigene Rezepte; der globale Graph ist für alle identisch.
