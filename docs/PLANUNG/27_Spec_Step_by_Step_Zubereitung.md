# Spec 27 — Step-by-Step-Zubereitung (strukturierte Schritte + Fotos)

> **Status:** Phase 1 + 2 **GEBAUT** (2026-07-31) · Phase 3 + 4 in Arbeit.
> **Entschieden mit Dominique (2026-07-31):** Weg **B** (strukturierte Schritte, keine Markdown-Kopplung), Fotos **many-to-many**;
> **alle vier Phasen** in einer Session; Phase 4 = Produktionsblatt + Produktionsauftrag + eigene Druckansicht „Anleitung",
> jeweils mit Schalter **nur Text / mit Fotos**; Markdown bleibt als Spiegel + Eingabeweg, die Textarea entfällt.

## 0. Umsetzungs-Stand (2026-07-31)

| Phase | Stand | Artefakte |
|---|---|---|
| 1 Datenlayer | ✅ | Migration `2026_07_31_000005_create_foodalchemist_recipe_steps_tables.php` · `FoodAlchemistRecipeStep` · `RecipeStepService` · `foodalchemist:steps-backfill` · `RecipeStepBackfillTest` (12) + `RecipeStepParserTest` (10) |
| 2 Editor-UI | ✅ | `StepEditor` (Livewire, server-seitig) · `step-editor.blade.php` · `partials/step-photo-pool.blade.php` (Media-Pool = Neubau) · Umbau Zubereitungs-Tab + Detail-Panel · `RecipeStepEditorTest` (11) |
| 3 KI + Spiegel | ⏳ | Prompt-Key `recipe.steps`, MCP `recipe_steps.GET/PUT` |
| 4 Produktionsdruck | ⏳ | Schritt-Karten in `blatt`/`produktionsauftrag` + neue Ansicht `anleitung`, `?fotos=0|1` |

**Korrekturen gegenüber dem ursprünglichen Entwurf** (im Code so umgesetzt):
- Die Foto-Spalte heißt **`schritt_nr`**, nicht `step`.
- Der Pivot heißt **`foodalchemist_recipe_step_photo_links`** — `..._recipe_step_photo` hätte sich von der bestehenden Tabelle `..._recipe_step_photos` nur durch ein `s` unterschieden.
- **Foodbook rendert `preparation` gar nicht** (geprüft) → Phase 4 ist der Produktionsdruck, kein Foodbook.
- Es gab **keinen** Media-Pool im FA-UI — er ist neu gebaut.
- Der Editor ist **server-seitig** (nicht Alpine-Array wie die Zutaten), weil Foto-Verknüpfungen echte Schritt-IDs brauchen; Reorder nutzt den bestehenden `ReordersLists`-Trait + `reorder-cell`-Partial.

---

## 1. Ziel & Anlass

Heute ist die Zubereitung **ein Markdown-Textfeld** (`recipes.preparation`, `##`-Phasen + nummerierte Schritte). Schritt-Fotos sind **separate DB-Zeilen** mit einer harten `Schritt-Nr` (`step`-int); die Kopplung Text↔Foto passiert **nur über diese Zahl**. Die Editor-Vorschau zeigt Fotos als **Block hinter dem Text**, gruppiert je Schritt — nicht inline am Schritt. Und die Schritt-Nr muss **von Hand getippt** werden.

**Wunsch:** Pro Schritt = **Nummer + Text + Foto(s) zusammen** = eine echte Schritt-für-Schritt-Anleitung, die man abarbeiten kann. Reorder-fest, Foto klebt am Schritt (nicht an einer Nummer).

**Ist-Code (Anker):**
- Editor: `resources/views/livewire/recipes/recipe-modal.blade.php` → Tab „Zubereitung": Markdown-Textarea (~`form.preparation`), Vorschau-Tab (`vorschauZubereitung()`, `$zubereitungVorschau`), Abschnitt **Schritt-Fotos** (~Z. 293 ff.: `fotoSchritt` / `fotoUpload` / `fotoCaption` / `fotoHochladen` / `fotoLoeschen`, `$schrittFotos` gruppiert nach `step`).
- KI-Zubereitung: Button `ai_zubereitung` → schreibt Markdown in `form.preparation` (Muster wie `SensorikService::bewerteRezept` → `AiGatewayService::propose(...)` über den **Core-LLM-Contract**, s. [[feedback_fa_llm_core_contract]]).
- Regelwerk: `07_WISSEN/.../Regelwerke/Regelwerk_Basisrezepte.md` — u.a. §9 **EINBAHN-Sync SQL→MD** (`recipes.notizen_manual`-Prinzip). `preparation` ist heute ein solches gespiegeltes/gepflegtes Feld.

---

## 2. Getroffene Entscheidungen

| Frage | Entscheidung |
|---|---|
| Markdown evolvieren (A+) vs. strukturierte Schritte (B) | **B** — eigene `recipe_steps`-Tabelle |
| Foto↔Schritt-Kardinalität | **many-to-many** — ein Foto kann an **mehreren** Schritten hängen, ein Schritt mehrere Fotos |
| `recipes.preparation` | **bleibt** als gerenderter **Spiegel** (Schritte→Markdown, EINBAHN) → Konsumenten unangetastet |
| **OFFEN — vor Bau klären:** Anleitung nur im **Editor**, oder auch **Foodbook/Druck** als Karten? | ⛔ **noch nicht entschieden** → bestimmt, ob **Phase 4** dazugehört |

---

## 3. Datenmodell

### 3.1 Neue Tabellen (Laravel-Migration in `database/migrations/`)

**`foodalchemist_recipe_steps`**
- `id`, `recipe_id` (FK → `foodalchemist_recipes`), `team_id`
- `position` (int, Sortierung), `phase` (string nullable — z. B. „Mise en Place" / „Finish")
- `text` (Anweisung, plain/markdown-lite)
- optional später: `arbeitszeit_min`, `kerntemp` etc. (jetzt NICHT, YAGNI)
- `created_at/updated_at`, `deleted_at`, `uuid`

**`foodalchemist_recipe_step_photo`** (Pivot, M:N)
- `id`, `step_id` (FK → `recipe_steps`), `photo_id` (FK → bestehendes Foto-Modell)
- `position` (int — Reihenfolge des Fotos innerhalb des Schritts)
- `created_at/updated_at` (Pivot braucht kein Soft-Delete; harte Löschung beim Entkoppeln)

**Fotos** bleiben rezept-weite Media (bestehendes Foto-Modell). Die harte `step`-Spalte am Foto **entfällt als Kopplung** (Spalte kann bleiben bis Migration verifiziert, dann in einem Folge-Schritt droppen). Ein Foto **ohne** Pivot-Eintrag = „allgemein" (Hero/Ergebnis).

### 3.2 ⚠️ PFLICHT: Trait-Vertrag

`RecipeStep` **muss** alle vier Traits tragen — sonst kippt `tests/Feature/PolicyTest.php` (`Trait_Vertrag: ALLE Models tragen ...`):
```
use LogsActivity, BelongsToTeamHierarchy, HasUuidV7, SoftDeletes;
```
(Genau dieser Test flaggt aktuell 3 Bestell-/Rückvergütungs-Modelle, die das NICHT tun — nicht zum Vorbild nehmen.)

Das Pivot-Modell (falls als eigenes Model, nicht nur Pivot) braucht die Traits nur, wenn `PolicyTest` es als Model erfasst — prüfen; reiner Pivot via `belongsToMany(...)->withPivot('position')` reicht meist.

---

## 4. Migration / Backfill (One-Shot, deterministisch)

Command `php artisan foodalchemist:migrate-steps` (Muster wie bestehende FA-Migrations-Skripte, `--dry-run/--apply/--verify`, DB-Backup vorher):

1. **Parse** `recipes.preparation` je Rezept:
   - `## <Titel>` → neue Phase (Wert = Titel), folgende Schritte erben sie.
   - `1.` / `2.` … (nummerierte Zeile) → ein `recipe_step` (position = laufend, text = Zeileninhalt ohne Nummer).
   - Reine Fließtext-Zeilen ohne Nummer unter einer Phase → an den vorigen Schritt anhängen ODER eigener Schritt (Regel im Skript festlegen; konservativ: an vorigen Schritt).
2. **Fotos verknüpfen:** bestehende Fotos mit `step`-int → Pivot-Link zum Schritt gleicher `position`; `step = 0/NULL` → **kein** Link (bleibt „allgemein").
3. **Idempotent:** re-runbar (skip, wenn Rezept schon Schritte hat), ~1369 Rezepte.
4. Deterministisch, **keine KI** im Backfill (reine Regex-Parse) → reproduzierbar, kein Provider nötig.

---

## 5. `preparation`-Spiegel (EINBAHN Steps→Markdown)

Damit **Foodbook-Export, Wording, `vorschauZubereitung`** unverändert weiterlaufen: nach jedem Schritt-Schreiben `recipes.preparation` aus den Schritten **rendern** (Phasen als `##`, Schritte nummeriert). Schritte werden Master, `preparation` ist der Lese-Spiegel — spiegelt das §9-EINBAHN-Prinzip. **Konsumenten bleiben also unberührt** (kein Big-Bang).

Native Schritt-Karten in Foodbook/Druck = **Phase 4**, nur falls Entscheidung „auch Foodbook" fällt.

---

## 6. Editor-UX (Phase 2)

Im „Zubereitung"-Tab die Markdown-Textarea durch eine **Schritt-Liste** ersetzen (oder daneben; Markdown-Schnellschreiben evtl. als Import-Hilfe behalten):
- Schritt-**Zeilen** wie die Zutaten-Zeilen: Drag-Reorder (`⠿` + ▲▼, Muster aus `ingredient-editor` / `zutaten-kern`), **Phase**-Select, **Text**-Feld.
- Je Zeile **Foto-Thumbs** + „**＋ Foto**": aus dem **Media-Pool** des Rezepts wählen **ODER** hochladen. Ein Foto **mehrfach** verlinkbar (M:N) — Auswahl per Klick, keine Nummer tippen.
- **Media-Pool** je Rezept (alle Fotos, auch „allgemein"/Hero).
- Vorschau/Ausgabe: pro Schritt eine **Karte** = Nummer-Badge + Text + Foto(s) inline → die eigentliche Anleitung.
- Style konsistent zum Master-Editor (dark-canvas, 13px, rohes CSS statt nicht-kompilierter arbitrary Tailwind-Klassen — s. [[project_fa_editor_redesign_dark]]).
- **Alpine/Livewire-Fallen** beachten: `wire:key` gegen Morph-Desync, kein geklebtes `@if` → [[feedback_blade_alpine_livewire_gotchas]].

---

## 7. KI (Phase 3)

`ai_zubereitung` schreibt künftig **Schritte** (Phase + Schritte-Array) statt Markdown-Blob:
- Neues `propose('recipe.steps', ...)` über den **Core-LLM-Contract** (nie modul-lokaler Direktcall — [[feedback_fa_llm_core_contract]]).
- Grounding-Kontext (Zutaten + Mengen) wie gehabt; Ausgabe strukturiert (`[{phase, text}]`).
- GL-07: Vorschlag, nichts auto-persistieren; „Übernehmen" schreibt Schritte → Spiegel-Sync.
- **Keine Erfindungen** bei Daten/Science → [[feedback_no_inventions_data_science]].

---

## 8. Phasen

1. **Datenlayer** — Migration-Schema + `RecipeStep`-Model (mit den 4 Traits) + Pivot + deterministischer Backfill-Command. Tests: Model-Trait-Vertrag, Backfill-Parse-Golden.
2. **Editor-UI** — Schritt-Editor + Media-Pool + M:N-Verlinkung + Karten-Vorschau.
3. **KI + Spiegel** — `recipe.steps`-propose, Steps→Markdown-Sync, Konsumenten-Regression.
4. *(optional, abhängig von der offenen Entscheidung)* **Foodbook/Druck** rendert Schritt-Karten **nativ**.

Jede Phase: Pest grün (`./fa_test.sh`), MCP-Tools im Lockstep mitziehen ([[feedback_mcp_lockstep]]), bei Commit ROADMAP/Doku + Office-Board ([[feedback_fa_commit_sync_roadmap_office]]).

---

## 9. Pflicht-Regeln (nicht verhandelbar)

- Neue Models: **LogsActivity · BelongsToTeamHierarchy · HasUuidV7 · SoftDeletes** (PolicyTest).
- **Nur** `platforms-foodalchemist` + Sandbox anfassen ([[feedback_keine_fremdmodul_aenderungen]]); Build/Test/Daten nur auf `demo`/Sandbox ([[feedback_mcp_demo_only]]).
- Vor „gefixt/verifiziert": real gegen echte Daten messen ([[feedback_verify_before_claiming]]).
- Regelwerk_Basisrezepte lesen (Naming, §9 EINBAHN) bevor am `preparation`-Master gearbeitet wird.

---

## 10. Startpunkt für die neue Session

> „Setze Spec 27 Phase 1 um: `recipe_steps`-Migration + `RecipeStep`-Model (mit den 4 Pflicht-Traits) + M:N-Pivot `recipe_step_photo` + deterministischer Backfill-Command (`--dry-run/--apply/--verify`, Backup). Danach `./fa_test.sh`. Vorher die offene Entscheidung klären: Schritt-Karten auch im Foodbook/Druck (= Phase 4) oder nur im Editor?"
