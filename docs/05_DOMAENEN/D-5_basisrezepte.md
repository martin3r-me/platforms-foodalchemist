---
typ: Domänen-Spec
domaene: D-5
stand: 2026-06-10
status: ausgearbeitet
mvp: MVP
---

# D-5 — Basisrezepte

> **Services (stateless):** `RecipeService`, `RecipeRecomputeService`, `IngredientMatchService`, `RecipeGeneratorService`
> **Hängt ab von:** D-3 (GPs), D-4 (KI-Infrastruktur) · **MVP-Status (⚠D5):** MVP
> **Kurzbeschreibung:** Produktionsrezepte (Herzstück der App, 74 Alt-Commands — größte Domäne). Sub-Rezept-Hierarchie + Recompute (GL-02), Zutat→GP/Sub-Matching (GL-04), KI-Rezept-Generator mit Accept-Transaktion (V-07), Rollen-Modell (V-21), Reuse-at-Generation (V-04).
> **Normativ für Fachregeln:** `regelwerke/Regelwerk_Basisrezepte.md` — §1 Naming (`Typ: Bezeichnung`), §4 Sub-Rezept-Hierarchie (Auto-Stub + draft, max. 3 Ebenen), §5 Default-GPs, §10 Anti-Patterns. Bei Konflikt mit Alt-Code: Regelwerk bzw. Golden-Test gewinnt (Hierarchie siehe GL-04 Kopf).

## 1. Scope & Ressourcen

74 Commands aus `03_FEATURE_INVENTAR.md` (Filter D-5), hier in 9 Ressourcen-Cluster gebündelt. Die Spalte „Inventar-Stämme" ist die Rückverfolgung in die generierte Tabelle.

| # | Cluster | Inventar-Stämme | Muster | GL | Ziel |
|---|---|---|---|---|---|
| 1 | **Rezept-CRUD & Liste** | `recipe` (create/get/update/delete), `recipes`, `recipes_for_picker`, `duplicate_recipe`, `recipe_status`, `recipe_template`, `bulk_pending_recipes` | CRUD/Spezial | GL-02 (Recompute-Trigger) | `RecipeService` + Livewire `Basisrezepte/Index` |
| 2 | **Zutaten-Verwaltung** | `add_recipe_ingredient`, `recipe_ingredient` (update/delete), `recipe_ingredients`, `reorder_recipe_ingredients`, `ingredient_rolle` | CRUD/Spezial | GL-02, GL-04 | `RecipeService` (Zutaten-API) |
| 3 | **Sub-Rezept-Hierarchie** | `sub_recipe_stub`, `generator_stub` (delete), `recipe_parents`, `recipe_subtree_depth`, `inspect_subrecipe_link`, `recipe_graph` | CRUD/Spezial | GL-02 §3.5 (Guards) | `RecipeService` |
| 4 | **Recompute** | `recompute_all_recipes` | Spezial | **GL-02** (komplett) | `RecipeRecomputeService` + Queue-Job |
| 5 | **Matching** | `match_single_ingredient` | Spezial | **GL-04** (komplett, 96 Golden-Tests) | `IngredientMatchService` |
| 6 | **Generator** | `recipe` (`ai_generate_recipe`), `extract_recipe`, `recipe_proposal` (accept/reject), `recipe_component_suggestions`, `revise_recipe` | KI-Lebenszyklus | GL-04, GL-06, GL-07, GL-13 | `RecipeGeneratorService` + Livewire `RecipeGenerator` |
| 7 | **Klassifikation & Eignungen** | `recipe_hauptgruppe(n)` (CRUD), `recipe_niveau` (6 Cmds), `recipe_sektor` (6 Cmds), `recipe_fertigungstiefe`, `sub_rezept_typ` (4 Cmds), `sub_rezept_typen`, `remove_recipe_niveau`, `remove_recipe_sektor` | CRUD/KI-Lebenszyklus | GL-06, GL-07 | `RecipeService` + `AiProposalService` (D-4) |
| 8 | **KI-Anreicherung (Feld-Ebene)** | `describe_recipe`/`recipe_description`, `recipe_name` (normalize/accept), `recipe_zubereitung`, `recipe_eigenschaften`, `recipe_garverlust` (3 Cmds), `equipment`/`recipe_equipment`, `review_recipe`/`apply_recipe_review_change` | KI-Lebenszyklus | GL-06, GL-07 | `AiProposalService`-Instanzen (§5) |
| 9 | **Komposition-Spezial** | `recipe_culinary_coherence_compute/get`, `recipe_plate_suggestion_compute/get` | Spezial | GL-06 | `RecipeService` (persistierte KI-Caches) |

**Abgrenzungen:** `recipe_kategorie`-CRUD + KI-Klassifikation gehören zu **D-1** (Taxonomie-Pflege), werden aber vom Editor dieser Domäne konsumiert. `ai_verteile_rollen` ist in **D-3** inventarisiert, das UI lebt im Rezept-Editor (VK-Zutatenliste, → D-6 §4). **Pairing-bezogene Editor-Blöcke (Anker, Pairings, Kohäsions-Anzeige, Netz) sind MVP** (User-Entscheid 2026-06-11, Produktvision: Foodpairing+KI im Rezept-Workflow = Kern-Differenzierung) — Logik-Spec bleibt GL-10/`PairingService` (D-7); nur die eigenständige Graph-*Exploration* (Bridge, verwandte Rezepte, Anker-Browser) ist Phase 2. Details: D-6 §5.x.

## 2. Datenmodell-Ausschnitt

Vollständiges Mapping in `02_DATENMODELL.md` (§B). Hier nur das D-5-Relevante:

### 2.1 `foodalchemist_recipes` — EIN Modell, zwei Service-Sichten

D-5 und D-6 teilen sich **eine** Tabelle und **ein** Eloquent-Model `Recipe`. Die Trennung läuft über das Flag `ist_verkaufsrezept` (bool):

- **D-5-Sicht (Basisrezept, `ist_verkaufsrezept = false`):** Produktionsrezept mit Yield in kg, Zubereitungs-Anleitung, Sub-Rezept-Fähigkeit. Nur Basisrezepte sind als Sub-Rezept referenzierbar (GL-04 §2.2 Pool-Filter).
- **D-6-Sicht (Verkaufsrezept, `ist_verkaufsrezept = true`):** dieselben Aggregations-Spalten plus VK-Spaltenblock (→ D-6 §2).

Umsetzung: **keine** zwei Models, sondern Eloquent-Scopes `Recipe::basis()` / `Recipe::verkauf()`; `RecipeService` (D-5) und `SalesRecipeService` (D-6) erzwingen ihren Scope in jeder Query. Gemeinsame Logik (Zutaten, Recompute, Matching, Editor-Kern) existiert genau einmal in D-5-Services — D-6 ruft sie. Das spiegelt die Alt-App (gemeinsamer `RecipeEditor`, zwei Listen-Module) und verhindert die dort entstandene VK-Paritäts-Schuld (→ D-6 §6).

**D-5-relevante Spaltengruppen** (Quelle `recipes`, 1.407 Zeilen):

| Gruppe | Spalten (Auszug) | Anmerkung |
|---|---|---|
| Identität | `recipe_key` (slug, Regelwerk §1.7), `name`, `herkunft` (§1.6), `kategorie_id` (FK recipe_categories), `ist_verkaufsrezept` | `status`-Enum: `stub` / `draft` / `review` / `approved` (CHECK → PHP-Enum) |
| Aggregate (GL-02/01/08/09) | `yield_kg`, `ek_total_eur`, `ek_per_kg_eur`, `n_zutaten_total`, `n_zutaten_ungemappt`, `ek_n_ingredients_*`, 14 Allergen-Spalten, `allergene_konfidenz`, Nährwert-Block, 18 Zusatzstoff-Spalten, `spec_is_vegan` u.a. | nur von `RecipeRecomputeService` beschrieben; **neu: `yield_kg_manual`** (GL-02 A-3, Vorrang via COALESCE) |
| Inhalt | `beschreibung`, `zubereitung` (Markdown), `notizen_manual` (Regelwerk §9.1 — überlebt jede Generierung), `geschmacksrichtung`, `sub_rezept_typ_id`, `fertigungstiefe`, `arbeitszeit_min`, `temperatur`, `funktion` | KI-fähige Felder tragen Lineage-Tripel `_quelle`/`_ai_confidence`/`_ai_begruendung` (GL-07) |
| Team | `team_id` NOT NULL (⚠D1: Rezepte sind immer team-eigen), ⚠D2: BHG-Bibliothek geht als **Snapshot-Kopie** an Teams, nie als Live-Referenz | Seed → `07_MIGRATION_SEED.md` |

### 2.2 `foodalchemist_recipe_ingredients` (Quelle `recipe_ingredients`, 9.590 Zeilen)

Kernspalten: `recipe_id`, `position`, `raw_text`, `display_name`, `menge`, `menge_max`, `einheit_vocab_id` (FK, NOT NULL), `is_optional`, `note` (Regelwerk §2: Verarbeitung wandert hierher), `putzverlust_pct`, `garverlust_pct` (+ Lineage), **`gp_v2_id` XOR `referenced_recipe_id`** (GP- oder Sub-Rezept-Verknüpfung), `match_method` (Enum, Vokabular = GL-04 §2.3 — Enum-Cast verhindert den `override_sub`-Bug A-10), `match_confidence`.

**Port-Pflichten:** (a) die drei toten Spalten `prozent_garverlust`/`prozent_in_produkt`/`menge_in_g_computed` NICHT migrieren (GL-02 A-6); (b) **V-21:** `rolle` (Enum: `aroma_treiber`/`komponente`/`beilage`/`garnitur`) + `ist_wertgebend` (bool) von Anfang an als Spalten + Pflege-UI — in der Alt-App tot (0/1.380 gepflegt), weil ohne UI eingeführt.

### 2.3 Satelliten & Vokabulare

| Tabelle (Ziel) | Inhalt | Owner |
|---|---|---|
| `foodalchemist_recipe_equipment` | M:N Rezept ↔ `vocab_kochequipment` | D-5 (Vocab-CRUD: D-1, V-20) |
| `foodalchemist_recipe_niveau_eignung` / `_sektor_eignung` | Eignungs-Zeilen (Niveau/Sektor × Konfidenz + Lineage) | D-5 |
| `foodalchemist_recipe_plate_suggestion` / `_culinary_coherence` | persistierte KI-Resultate (compute/get-Paar) | D-5 |
| `foodalchemist_recipe_main_groups` / `_recipe_categories` | Produktions-Taxonomie 23 HG + 139 Sub (CRUD in D-1; **E5-Klärfall** Taxonomie v2, 02_DATENMODELL A.3) | D-1 |
| `foodalchemist_vocab_sub_rezept_typ` | Sub-Typ-Tags (GL-04 Sub-Typ-Boost; Tagging lückenhaft → GL-04 W-5) | D-1 |
| `foodalchemist_gp_count_unit_defaults` + `gps.stk_default_g` | Stückgewichte für Yield/Kosten (GL-02 T1) | D-3 |

## 3. Services & Methoden

Eiserne Regel (01_ARCHITEKTUR §1): Livewire und MCP-Tools rufen **nur** diese Services. Alle Multi-Step-Writes in `DB::transaction()` (V-07). Fehler als typisierte Exceptions (V-06).

### 3.1 `RecipeService`

```php
// CRUD + Liste (Scope basis() erzwungen)
list(RecipeFilter $f): LengthAwarePaginator        // HG/Kategorie/Status/Geschmack/Suche
get(int $id): RecipeDetail                          // inkl. Aggregaten, Parents, Equipment
create(RecipeInput $in): Recipe                     // recipe_key aus name (Regelwerk §1.7)
update(int $id, RecipeInput $in): Recipe            // → recompute (3.2), wenn relevante Felder
duplicate(int $id, string $newName): Recipe         // Kopie inkl. Zutaten, status='draft'
delete(int $id): void                               // blockt bei referenzierenden Eltern (typisierte Exception, V-06)
setStatus(int $id, RecipeStatus $s): void

// Zutaten (jede Mutation → recomputeAndPropagate, EINE Transaktion, V-07)
addIngredient(int $recipeId, IngredientInput $in): RecipeIngredient
updateIngredient(int $ingredientId, IngredientInput $in): RecipeIngredient
deleteIngredient(int $ingredientId): void
reorderIngredients(int $recipeId, array $orderedIds): void
setIngredientRolle(int $ingredientId, ?Rolle $rolle, ?bool $istWertgebend): void   // V-21

// Sub-Rezept-Hierarchie
createSubRecipeStub(string $name, int $teamId): StubResult        // Regelwerk §4/F4.1: status='stub',
        // idempotent (Dedupe by name), markiert last_modified_by='generator_stub'; Eltern → 'draft'
deleteGeneratorStub(int $recipeId): void          // Guard: nur stub + generator-markiert + 0 Zutaten + 0 Refs
inspectSubrecipeLink(int $parentId, int $subId): LinkInspection   // GL-02 §3.5: Zyklus + projizierte Tiefe;
        // Ziel-Verhalten: Tiefe > 3 BLOCKT (GL-02 A-5), nicht nur Warnung
getParents(int $recipeId): Collection             // ↑-Navigation
getSubtreeDepth(int $recipeId): int
```

### 3.2 `RecipeRecomputeService` — Implementierung von GL-02 (nicht duplizieren)

```php
recomputePipeline(int $recipeId): void   // GL-02 §1: Yield → Allergene (GL-01) → Zusatzstoffe (GL-09)
                                          //   → Kosten → Nährwerte (GL-08) → Spec-Flags; EINE Transaktion (V-07)
recomputeAndPropagate(int $recipeId): void   // GL-02 §3.3 (BFS hoch, best effort); Kandidat: Queue-Job
recomputeAll(): void                          // GL-02 §3.4 Kahn-Topo-Sort; als Queue-Job (V-15) mit Fortschritt
assertNoCycle(int $parentId, int $subId): void   // GL-02 §3.5 / I2
```

Preisquellen (`lead_price_per_g` etc.) kommen aus `PriceService` (D-2, GL-11). VK-Felder schreibt der Recompute **niemals** (GL-02 I9).

### 3.3 `IngredientMatchService` — Port von GL-04 (96 Golden-Tests = Abnahme)

```php
match(string $name, ?string $slug, MatchMode $mode, VariantPref $pref,
      bool $preferRaw, BioPref $bio): IngredientMatch          // GL-04 §3.8; Konstanten §2.4 Nr. 8
candidatesFor(string $name, ?string $slug, int $k): array      // GL-04 §3.9 Shortlist (LLM-Disambiguierung)
isSubRezeptKandidat(string $name): bool                        // GL-04 §3.5 (steuert Hard-Stop-Button)
matchSingleIngredient(string $name, ?string $slug, bool $preferSubRecipe): IngredientMatchResult
        // Inline-Edit-API: Parameter-Mapping GL-04 §4.4 Aufrufer 2
```

Port-Pflichten aus GL-04: stabile `ORDER BY id ASC`-Iteration (W-4), `match_method`-Enum-Cast (W-6), `name_normalized`-Indexspalte via Observer (W-2). V-04/V-05 → §6.

### 3.4 `RecipeGeneratorService`

```php
generate(GeneratorContext $ctx): RecipeProposal      // KI-Vorschlag (GL-06-Hüllen, GL-13-Wissen,
        // V-04-Reuse-Inventur VOR der Benennung); pro Zutat-Zeile: match() aus 3.3 + Generator-Gates
        // (SQL-Exakt-Name-Override, P4 Hauptzutat-Konsistenz, P9 Anti-Collapse — GL-04 §3.10, hier Domänen-Pflicht)
extract(ExtractInput $in): RecipeProposal            // Foto/PDF/Text → Vorschlag (Vision-Pfad, D-4-Gateway)
acceptProposal(AcceptInput $in): AcceptResult        // EINE DB-Transaktion (V-07, §6.1!): Rezept-INSERT
        // (status='draft') ODER targetRecipeId-Merge in Stub + Zutaten-Zeilen + recomputeAndPropagate
rejectProposal(int $callLogId): void                 // GL-07 Reject (nur Stempel)
componentSuggestions(int $recipeId): array
revise(int $recipeId, string $anweisung): RecipeProposal   // freie KI-Überarbeitung, Vorschau → Accept
```

KI-Feld-Lebenszyklen (describe, name, zubereitung, eigenschaften, garverlust, equipment, niveau, sektor, sub_rezept_typ, fertigungstiefe) laufen **nicht** über eigene Service-Methoden, sondern über die generische `AiProposalService`-Instanziierung aus D-4 (GL-07 — einmal bauen, pro Feature konfigurieren). D-5 liefert nur die Feature-Configs (§5).

## 4. Livewire-Komponenten & UI-Fluss

Die Alt-App-UX ist hier am reichsten — sie ist die Vorlage (als Konzept, nicht als Code; 01_ARCHITEKTUR §5). Jede Entität bekommt eine URL (V-17): `foodalchemist/basisrezepte` + `…/basisrezepte/{recipe}` — der Alt-App-Tradeoff „Tab-Wechsel verliert den offenen Editor" entfällt damit strukturell.

### 4.1 `Basisrezepte/Index`

- Tabelle: Name · Hauptgruppe · Kategorie · Status · Geschmack (Pill) · Fertigung · Yield · Zutaten (mit „n ungemappt"-Badge) · Allergen-Konfidenz.
- Filter: Hauptgruppe → Kategorie (kaskadierend), Status, Geschmacksrichtung; Volltextsuche. Filter-Reset setzt ALLE Filter zurück (dokumentierter Alt-Bug).
- Aktionen: „Neues Rezept", „✨ Generator", Bulk-Anreicherung (Queue-Job V-15 statt UI-Loop), Stub-Review-Queue (alle `status='stub'` — Review-Queues als First-Class-Views, V-10-Geist).

### 4.2 `Basisrezepte/Editor` (geteilt mit D-6, Sichten via `ist_verkaufsrezept`)

Aufbau in Sektionen mit dem **Section-Header-Pattern** (Titel links, KI-/Hilfs-Aktionen rechts — `x-ui-panel` + Slot):

1. **Aktionsleiste (sticky):** Speichern (Cmd+S, Validierungs-Zeile sichtbar statt nur Tooltip), Löschen, bei `status='stub'`: „✨ Ausrezeptieren" (öffnet Generator mit `targetRecipeId` = dieser Stub), „✨ Alles anreichern" (→ 4.4; nur auf gespeichertem Stand), Dirty-Indikator.
2. **Stammdaten:** Name (Pflicht-Hint: Regelwerk-§1-Syntax `Typ: Bezeichnung (Variante)`, Title Case) · Herkunft (eigenes Feld, §1.6 — nie im Namen) · Hauptgruppe/Kategorie · Status · Geschmacksrichtung (Select = manueller Override). Header-Aktionen: „✨ Name putzen" (`ai_normalize_recipe_name` — erzwingt §1.2-Typ-Vokabular aus `lookup_recipe_typ`), „✨ Kategorie" (D-1-Klassifikation), „✨ Fertigung".
   - **↑ Verwendet-in-Chips:** klickbare Eltern-Rezepte (`getParents`) — Navigation nach oben, mit Dirty-Guard.
3. **Zutaten** (Kern der Domäne):
   - Tabelle mit **Drag-Sort** (Reorder persistiert via `reorderIngredients`), Inline-Edit für Menge/Einheit/Garverlust, QS-Badge (quantum satis), Optional-Flag, **Rolle-Spalte (V-21)** pro Zeile editierbar.
   - Verknüpfungs-Zelle pro Zeile: GP-Verknüpfung mit **GP-Schnellansicht** (Peek-Modal ohne Editor-Verlust) ODER Sub-Rezept mit **↗-Link** (öffnet das Sub-Rezept = Navigation nach unten; zusammen mit den ↑-Chips bidirektional) ODER ungemappt (rot) mit „🔍 suchen": Inline-Suche, vorbefüllt mit dem Zutat-Text, gerankte GP- + Sub-Rezept-Treffer, Klick verknüpft/tauscht (`match_method` → `override_gp`/`override_subrecipe`).
   - Ausgeklappte Zeile: alle Felder inkl. `raw_text`, `note`, Match-Methode/-Konfidenz, „eingestellt"-Hinweis bei discontinued-LA.
   - Header-Aktionen: „🧑‍🍳 Copilot" (`ai_review_recipe` → Befund-Liste, pro Befund `apply_recipe_review_change`), „✨ KI-Überarbeiten" (`revise` mit freier Anweisung, Vorschau → Übernehmen).
   - Darunter read-only die **Aggregations-Sicht**: Yield/EK-Kacheln + 14 Allergen-Pills (mit Konfidenz-Badge) + 18 Zusatzstoff-Pills — direkt aus den GL-01/02/09-Spalten, nie editierbar.
4. **Eigenschaften:** Arbeitszeit/Temperatur/Funktion (+ ✨-Vorschlag, der auch die Geschmacksrichtung mit-inferiert — gefaltet, kein eigener Button).
5. **Zubereitung:** Markdown-Editor + „✨ Zubereitung" (aus Zutaten generieren/strukturieren; Degenerations-Schutz V-02 im Gateway).
6. **Equipment:** M:N-Auswahl + ✨-Vorschlag.
7. **Notizen:** `notizen_manual` — manuelle Insel, wird von keinem KI-Accept überschrieben (Regelwerk §9.1).
8. **Workflow/Status:** Meta (angelegt/geändert/Quelle), Status-Wechsel.

### 4.3 Generator-Workflow (`RecipeGenerator`-Komponente)

Der wichtigste Fluss der Domäne. Ablauf (Alt-App-verifiziert, inkl. aller Korrektur-Schleifen):

```
Kontext-Hooks setzen ──► KI-Vorschlag ──► Zeilen-Matching ──► Inline-Korrektur ──► Accept (Transaktion) ──► Post-Accept-CTA
```

1. **Kontext-Hooks (Achsen):** Beschreibung (Freitext) + Convenience-Level (`from_scratch`/`teil_convenience`/`voll_convenience`), Bio-Präferenz (Default **konventionell** — Bio leakt nie), Qualitäts-Niveau, Aroma-Richtung, Diät-Tags, Bestand-Modus (Hybrid/Nur Bestand/Komplett neu — steuert V-04-Reuse-Intensität), Kompositions-Stil, Sektor. Hook→Engine-Mapping: GL-04 §4.4. Bei `targetRecipeId` (Stub ausrezeptieren): Beschreibung mit Stub-Namen vorbefüllt + Banner.
   **Küchen-Profil (Soft-Default-Schicht, Nachtrag Verifikation 2026-06-11):** Zusätzlich injiziert der Generator VOR den Hooks ein Mandanten-Profil (`build_kuechen_profil_block`, commands.rs:12590 — Slugs restaurant/grosskueche/catering/hotel/boutique_patisserie → Chargengrößen-/Convenience-/Technik-Tendenz; **explizite Hooks haben Vorrang**). Heute globales `app_settings.kuechen_typ` → **im Ziel ein Team-Profil** (jeder Caterer konfiguriert seinen Küchen-Typ, der Generator passt sich teamweise an — natürlicher Multi-Tenancy-Gewinn).
   **Prompt-Reihenfolge (Ist, paritäts-relevant):** Vault-Wissen → Pairing-Block (SQL-Anker-Graph primär, MD-Fallback) → Küchen-Profil → RICHTUNG-Hooks → Task-Prompt → §1-Typ-Vokabular (nur VK) → VERFÜGBARE BAUSTEINE (V-04, max 15, Convenience-Gate GL-04 §6.5) → BESCHREIBUNG. (commands.rs:20711-20749; Ziel-Ordnung: 06_KI §6.)
2. **Vorschlag:** `generate()` liefert Kopf (Name, Yield, Arbeitszeit, Begründung), Zubereitung und Zutat-Zeilen, jede Zeile bereits mit Match-Ergebnis (GL-04) + Status-Ampel (exact ✅ / fuzzy 🟢🟡 / no_match 🔴, Schwellen GL-04 §4.1).
3. **Inline-Edit (volle Parität, kein Re-Run für Kleinigkeiten):** Kopf-Felder + Zubereitung editierbar; pro Zutat Menge/Einheit/Verarbeitung inline, Zeile löschen, Zeile hinzufügen, Umbenennen mit **Re-Match on blur** (`matchSingleIngredient`).
4. **Hard-Stop-Zeilen (no_match):** Button-Wahl über `isSubRezeptKandidat`: „✨ Basisrezept anlegen" (primär bei Zubereitungs-Charakter, mit „GP?"-Fallback-Link) sonst „GP anlegen" (mit „Sub?"-Fallback). 
   - **Inline-GP-Anlage:** GP-Vorschlags-Modal (D-3 `ai_suggest_gp` mit Existenz-Check) → GP wird angelegt, Zeile auf `gp` gepatcht.
   - **Inline-Stub-Anlage:** `createSubRecipeStub` (idempotent) → Zeile auf `sub_recipe`, Stub landet in `createdStubs`-Tracking.
   - **Template-Instanziierung:** materialisiert eine Vorlage als echtes Sub-Rezept (kein leerer Stub).
   - **Undo pro Zeile (↶):** Zeile zurück auf no_match; ein frisch angelegter, nicht mehr referenzierter Stub wird via `deleteGeneratorStub` aufgeräumt (Guard, s. §3.1) — gilt auch beim Tausch/Löschen der Zeile.
5. **Accept:** `acceptProposal` in **einer DB-Transaktion** (V-07 — Pflicht, siehe §6.1): Rezept-INSERT `status='draft'` ODER `targetRecipeId`-Merge (UPDATE Kopf + DELETE/INSERT Zutaten — Name/Kategorie/ist_vk bleiben) + alle Zutat-Zeilen (`match_method` aus dem GL-04-Enum: `gp_v2_fk`/`recipe_ref`, nie Fantasiewerte) + `recomputeAndPropagate` (im Merge-Fall erben Eltern sofort die Allergene des gefüllten Stubs).
6. **Post-Accept-CTA** (bewusst KEIN Auto-Anreichern — „nie Auto-Persistenz"): grünes Panel „✓ {Rezept} angelegt (draft), N Sub-Stub(s) leer" mit: „✨ öffnen & anreichern" (Editor + Orchestrator 4.4), pro Stub „📘 ausrezeptieren" (Generator erneut, `targetRecipeId` = Stub), „Fertig". Editing nach Accept gesperrt.

**Workflow rund (Abnahme-Szenario):** Mornay generieren → Bechamel-Auto-Stub → Accept → Stub öffnen → „✨ Ausrezeptieren" → Generator füllt dieselbe ID → Mornay-Aggregationen aktualisieren sich via Propagation (GL-02 GT-2-Mechanik).

### 4.4 „Alles anreichern"-Orchestrator (Basis-Variante)

Sequenzieller Lauf aller Feld-KIs auf einem gespeicherten Rezept; Ergebnisse als Review-Liste, einzeln oder „Alle übernehmen". **13 Schritte (code-verifiziert, Alt-App):** Name (Vorschlag) · Beschreibung · Zubereitung · Eigenschaften · Equipment · Garverlust (pro Zutat — vom „Alle übernehmen" ausgenommen, kein Auto-Überschreiben) · Geschmacksrichtung · **Pairing (MVP — User-Entscheid 2026-06-11: Anreicherung ist der größte Pain-Point, der Pairing-Schritt bleibt im Orchestrator; GL-10-Grounding)** · Sub-Rezept-Typ · Sektor-Eignung · Niveau-Eignung · Kategorie · Fertigungstiefe. Rate-limited, mit Modell-Fallback bei Überlastung (V-02-Verwandtschaft). Ziel-Umsetzung: **Queue-Job (V-15)** mit Schritt-Status-Polling in Livewire statt blockierender Modal-Schleife; Accept-Phase bleibt interaktiv (GL-07). VK-Variante (6 Schritte) → D-6 §4.

## 5. KI-Features dieser Domäne

Alle Features folgen GL-07 (propose/accept/reject/clear, Lineage-Tripel, `ai_call_log`-Pflicht) und GL-06 (Hüllen-Komposition global→modul→team→feld). Tier nach V-01: **A** = Qualität (teures Modell), **B** = Mechanik-Label (billig). Prompt-Inventar im Detail → `06_KI_SPEZIFIKATION.md`.

| Feature (Alt-Command) | Zielfelder | Kontext-Besonderheit | Tier |
|---|---|---|---|
| `ai_generate_recipe` / `ai_extract_recipe` | kompletter Proposal (kein Direkt-Write) | GL-13-Wissen + V-04-Reuse-Inventur + Achsen-Hooks; extract zusätzlich Vision | **A** |
| `ai_revise_recipe` | Proposal-Diff (Vorschau) | bestehender Stand + freie Anweisung | A |
| `ai_review_recipe` (Copilot) | Befund-Liste → `apply_recipe_review_change` einzeln | liest Vokabulare zur Validierung | A |
| `ai_describe_recipe` | `beschreibung` | Regelwerk §8/F8.3 nüchtern-sachlich; F8.4: auch für Stubs (tentative) | A |
| `ai_normalize_recipe_name` | `name` | erzwingt §1.2-Typ-Vokabular (`lookup_recipe_typ` pro Hauptgruppe in den Prompt); VK-Variante = Pipe-Stil (D-6) | B |
| `ai_generate_zubereitung` | `zubereitung` | Degenerations-Schutz (V-02) — langes Einzeltext-Feld | A |
| `ai_suggest_recipe_eigenschaften` | `arbeitszeit_min`, `temperatur`, `funktion` (+ Geschmacksrichtung gefaltet) | — | B |
| `ai_infer_recipe_garverlust` | `recipe_ingredients.garverlust_pct` pro Zeile | zeilenbasierter Accept (GL-07 §3); nie Auto-Bulk | B |
| `ai_suggest_equipment` | `recipe_equipment`-Zeilen | Gap-Surfacing: unbekanntes Gerät melden, nicht erzwingen | B |
| `ai_infer_recipe_niveau` / `_sektor` | Eignungs-Zeilen + Konfidenz | zeilenbasiert | B |
| `ai_infer_sub_rezept_typ` | `sub_rezept_typ_id` | füllt zugleich die GL-04-W-5-Tagging-Lücke | B |
| `ai_infer_fertigungstiefe` (D-3-inventarisiert, Editor hier) | `fertigungstiefe` | — | B |
| `recipe_culinary_coherence_compute` / `recipe_plate_suggestion_compute` | persistierte Result-Tabellen | compute/get-Paar = gecachter KI-Befund (kein Lebenszyklus-Accept, Re-Compute überschreibt) | A |

## 6. Verbesserungen gegenüber Ist

### 6.1 V-07 — Accept in Transaktion (die Mahnung)

Der Accept-Pfad des Generators produzierte in der Alt-App **5 Folge-Bugs in Serie** (alle Altlasten, erst nach Freischalten des Pfads erreichbar; Memory-Katalog 2026-05-27): (1) 34 Spalten / 33 Werte im recipes-INSERT, (2) verschobene Parameter-Reihenfolge → `ai_confidence` bekam einen String, (3) `allergene_konfidenz='unbekannt'` gegen CHECK (`unknown` erwartet), (4) drei falsche Spaltennamen im ingredients-INSERT (`name_raw`/`einheit_text`/`verarbeitung`), (5) `match_method='ai_generator_*'` gegen CHECK + `einheit_vocab_id` NOT NULL ohne Fallback. **Lehre für den Port:** Accept = eine `DB::transaction()` über Eloquent-Models mit Enum-/Value-Casts (kein Positions-SQL — die Bug-Klassen 1–5 werden strukturell unmöglich), Einheiten-Auflösung mit definierter Fallback-Kette, PHPUnit-Test „Accept schlägt fehl → kein halbes Rezept in der DB".

### 6.2 Weitere

| ID | Konkretisierung in D-5 |
|---|---|
| **V-03** | Rezept-Pool-Normalisierung der 1.399 Legacy-ALL-CAPS-Namen auf §1-Syntax `Typ: Bezeichnung` als **Seed-ETL-Schritt** (Skript + Review-Liste, `07_MIGRATION_SEED.md`). Der Reuse-Unlock: `:` ist Tokenizer-Trenner → Matcher und V-05 profitieren direkt (GL-04 §6.2 Hebel 2). |
| **V-04** | Reuse-at-Generation als fester Schritt VOR der KI-Benennung im `RecipeGeneratorService` (Spec: GL-04 §6.1 — Phrasen-Extraktion, lexikalischer + semantischer Pass, Hybrid-Re-Ranking, Cap 15). Embeddings im Ziel neu berechnet (Re-Embed-Job D-4); `SEM_FLOOR` als Config-Wert. Ändert keine GL-04-Schwellen. |
| **V-05** | Matcher-Decompounding („Kürbispüree" ↔ „Püree: Kürbis") als **Feature-Flag NACH bestandener 96er-Paritäts-Suite**, exakt nach GL-04 §6.2 (Marker-Split nur Query-Seite, `max(score_original, score_variante)`, Schutzregeln). Vorbedingung für vollen Nutzen: V-03. |
| **V-21** | `rolle` + `ist_wertgebend` von Anfang an: Spalten (2.2) + Rolle-Spalte in der Zutaten-Tabelle + „🎭 Rollen verteilen"-KI (`ai_verteile_rollen`) + Seed-Gate „Rezepte ohne Rollen" als Report statt stiller NULL-Wüste. |
| **V-06 / V-17 / V-15** | Typisierte Exceptions statt String-Results; URL pro Rezept (kein Editor-Verlust bei Navigation); Bulk-KI + `recomputeAll` als Queue-Jobs mit Fortschritt/Resume. |
| **GL-02 A-3/A-5** | `yield_kg_manual` (Regelwerk F6.1) neu; Tiefe-3-Guard hart blocken statt warnen. |
| **V-22** | Seed-Gates: Rezepte ohne Zutaten/EK/Yield werden geflaggt → Review-Queue, nicht Normalzustand (GL-02 T4-Randfälle). |

## 7. Akzeptanzkriterien & Golden-Tests

1. **GL-04-Paritäts-Suite:** alle **96 Golden-Cases (GT-T01…GT-T94c)** grün in PHPUnit — das Abnahme-Kriterium für `IngredientMatchService` (Schwellen-Assertions wörtlich, nicht verschärfen; GL-04 §5-Hinweis).
2. **GL-02-Suite:** GT-1…GT-8 (reale DB-verifizierte Fälle inkl. Rundungs-Invariante I7, Topologie, Zyklen-/Tiefen-Guard, VK-I9). Verlust-Formel-Entscheid (GL-02 A-1) vor Fixierung von GT-5 einholen.
3. **GL-01/08/09:** Aggregations-Golden-Tests laufen über `recomputePipeline` mit (→ `09_TESTKATALOG.md`).
4. **Generator-E2E:** Mornay→Bechamel-Szenario (§4.3) — Stub-Anlage, Accept, Merge-Ausrezeptierung, Eltern-Propagation, Post-Accept-CTA.
5. **Transaktions-Test (V-07):** provozierter Fehler mitten im Accept (z.B. ungültige Einheit) → Rollback vollständig, kein Rezept-Torso; Enum-Cast verweigert jeden Nicht-GL-04-§2.3-`match_method`-Wert.
6. **Stub-Lebenszyklus:** `createSubRecipeStub` idempotent; `deleteGeneratorStub`-Guard löscht nie befüllte/referenzierte Rezepte; Eltern-Status wird `draft` (Regelwerk F4.1).
7. **Hierarchie-Guards:** Selbstreferenz/Zyklus abgelehnt; Kette A→B→C erlaubt, C→D blockt (projizierte Tiefe 4 — Ziel-Verhalten Block, GL-02 GT-7).
8. **UI-Verträge:** Reorder persistiert Positionen; Rolle/`ist_wertgebend` editierbar + persistent (V-21); `notizen_manual` übersteht jeden KI-Accept; Allergen-/Zusatzstoff-Pills sind read-only; Editor-URL überlebt Navigation (V-17).
9. **Naming:** „✨ Name putzen" liefert nur Typen aus `lookup_recipe_typ` der jeweiligen Hauptgruppe (Regelwerk §1.2); Herkunft landet nie im Namen (§1.6).

---

**Querverweise:** GL-01, GL-02, GL-04, GL-06, GL-07, GL-08, GL-09, GL-11 (über D-2), GL-13 · D-2 (Preise/Lead-LA), D-3 (GPs, Inline-GP-Anlage), D-4 (Gateway/Proposal-Service), D-6 (geteiltes Modell) · 08_ENTSCHEIDUNGEN ⚠D1/⚠D2 · 10_VERBESSERUNGS_REGISTER V-03/04/05/06/07/15/17/21/22.
