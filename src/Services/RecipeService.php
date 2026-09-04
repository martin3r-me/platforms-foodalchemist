<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\MatchMethod;
use Platform\FoodAlchemist\Enums\RecipeStatus;
use Platform\FoodAlchemist\Models\FoodAlchemistComponentEquivalent;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeCategory;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeMainGroup;
use Platform\FoodAlchemist\Services\Ai\PoolEmbeddingService;
use Platform\FoodAlchemist\Support\TeamScope;

/**
 * M4-04/05 / D-5 §3.1 (Listen-Teil): Basisrezept-Browser — Scope basis() wird
 * in JEDER Query erzwungen (D-6/SalesRecipeService bekommt seine eigene Sicht).
 * CRUD/Zutaten-Mutationen folgen mit M4-06/07 (jede Mutation → recomputeAndPropagate).
 */
class RecipeService
{
    /**
     * Filterschlüssel der Taxonomie-Achse (MVP-042/043). Eine Facette rechnet immer OHNE die
     * eigene Achse — sonst zählt sie die Auswahl, die sie gerade ersetzen soll. „Ohne Kategorie"
     * gehört zur selben Achse wie Hauptgruppe/Kategorie: die drei schließen sich gegenseitig aus.
     */
    private const TAXONOMIE_ACHSE = ['hauptgruppe' => 1, 'category' => 1, 'ohne_kategorie' => 1];

    /**
     * Gesamtzähler („Alle Hauptgruppen") aus DERSELBEN Query wie die Tabelle (MVP-042).
     *
     * Vorher summierte die View die Hauptgruppen-Facetten — und verlor damit jedes Rezept ohne
     * Kategorie (Tabelle 64, Baum 62). Eine Summe von Facetten ist nie die Gesamtmenge, sobald
     * eine Facette nicht alle Zeilen abdeckt.
     */
    public function gesamtCount(Team $team, array $filters = []): int
    {
        return $this->browserQuery($team, array_diff_key($filters, self::TAXONOMIE_ACHSE))->count();
    }

    /** Eigener Arbeitsvorrat: Rezepte ohne Kategorie — im Baum sonst unerreichbar (MVP-042). */
    public function ohneKategorieCount(Team $team, array $filters = []): int
    {
        return $this->browserQuery($team, array_diff_key($filters, self::TAXONOMIE_ACHSE))
            ->whereNull('foodalchemist_recipes.category_id')
            ->count();
    }

    /** Hauptgruppen-Zähler in einer GROUP-BY-Query (Baum links, P-1). */
    public function hauptgruppenCounts(Team $team, array $filters = []): array
    {
        return $this->browserQuery($team, array_diff_key($filters, self::TAXONOMIE_ACHSE))
            ->join('foodalchemist_recipe_categories AS k', 'k.id', '=', 'foodalchemist_recipes.category_id')
            ->selectRaw('k.main_group_id, COUNT(*) AS n')
            ->groupBy('k.main_group_id')
            ->pluck('n', 'k.main_group_id')
            ->all();
    }

    /**
     * Kategorie-Zähler der gewählten Hauptgruppe (zweite Baum-Ebene).
     *
     * `$filters` ist Pflicht-Kontext, nicht Kür (MVP-043): vorher rechnete die Methode mit
     * `browserQuery($team, [])` und verwarf Suche, Status, Geschmack und Fertigung. Der Zähler
     * versprach dann 1 Treffer, der Klick auf `?status=review&hg=31&kat=189` lieferte 0.
     */
    public function kategorieCounts(Team $team, int $mainGroupId, array $filters = []): array
    {
        return $this->browserQuery($team, array_diff_key($filters, self::TAXONOMIE_ACHSE))
            ->join('foodalchemist_recipe_categories AS k', 'k.id', '=', 'foodalchemist_recipes.category_id')
            ->where('k.main_group_id', $mainGroupId)
            ->selectRaw('foodalchemist_recipes.category_id, COUNT(*) AS n')
            ->groupBy('foodalchemist_recipes.category_id')
            ->pluck('n', 'foodalchemist_recipes.category_id')
            ->all();
    }

    public function mainGroups(Team $team): Collection
    {
        return FoodAlchemistRecipeMainGroup::visibleToTeam($team)
            ->orderBy('sort_order')->orderBy('code')->get();
    }

    /** Tabelle (M4-04): Name·HG·Kategorie·Geschmack·Fertigung·Status·Zutaten·Yield·Allergen-Konf. */
    public function paginateBrowser(array $filters, Team $team, int $perPage = 100): LengthAwarePaginator
    {
        return $this->browserQuery($team, $filters)
            ->with('category:id,main_group_id,label')
            ->orderBy('foodalchemist_recipes.name')
            ->paginate($perPage)
            ->withQueryString();
    }

    /** Panel (M4-05): Detail inkl. Zutaten (GP-Links), Eignungen, Equipment. */
    public function detail(Team $team, int $id): ?FoodAlchemistRecipe
    {
        return FoodAlchemistRecipe::visibleToTeam($team)->basis()
            ->with([
                'kategorie:id,main_group_id,label',
                'ingredients.gp:id,name,main_ingredient_slug,lead_la_supplier_item_id,piece_default_g', // Zeilen-EK braucht Lead+Stückgewicht (T3)
                'ingredients.unit:id,slug,display_de,dimension,default_in_g,default_in_ml', // T1-Kaskade braucht die Faktoren
                'ingredients.referencedRecipe:id,name,ek_per_kg_eur',
                'equipment',
                'niveauEignungen',
                'sektorEignungen',
            ])
            ->find($id);
    }

    /**
     * M6-04 / D-6 §6 (VK-Parität): sicht-NEUTRALES Detail für den geteilten
     * Zutaten-Editor — ein Editor für beide Sichten. Die Sicht-Services
     * (detail()/SalesRecipeService::detail()) bleiben strikt gescoped (§7.8);
     * NUR der Editor lädt hierüber.
     */
    public function detailAnySicht(Team $team, int $id): ?FoodAlchemistRecipe
    {
        return FoodAlchemistRecipe::visibleToTeam($team)
            ->with([
                'kategorie:id,main_group_id,label',
                'ingredients.gp:id,name,main_ingredient_slug,lead_la_supplier_item_id,piece_default_g',
                'ingredients.unit:id,slug,display_de,dimension,default_in_g,default_in_ml',
                // yield_kg/yield_pieces: g/Stück fürs Live-Rechnen im Zutaten-Editor
                // (Stück-Sub — spiegelt RecipeRecomputeService::grammFaktor)
                'ingredients.referencedRecipe:id,name,ek_per_kg_eur,yield_kg,yield_pieces',
                'equipment',
                'niveauEignungen',
                'sektorEignungen',
            ])
            ->find($id);
    }

    public function statusCounts(Team $team): array
    {
        return FoodAlchemistRecipe::visibleToTeam($team)->basis()
            ->selectRaw('status, COUNT(*) AS n')->groupBy('status')->pluck('n', 'status')->all();
    }

    // ── M4-06: CRUD (D-5 §3.1) ──────────────────────────────────────────

    /** Regelwerk §1.7: slug(name) — ä→ae/ö→oe/ü→ue/ß→ss, Sonderzeichen→_, kollabiert. */
    public function rezeptKey(string $name): string
    {
        $s = mb_strtolower(trim($name));
        $s = strtr($s, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
        $s = preg_replace('/[^\p{L}\p{N}]+/u', '_', $s);

        return trim(preg_replace('/_+/', '_', $s), '_');
    }

    /**
     * Anlage (status draft, key nach §1.7 + §1.8-Diskriminator: bei Kollision
     * +_slug(kategorie), dann _2/_3 …). Läuft die Pipeline einmal an (leere Aggregate).
     */
    public function create(Team $team, array $in): FoodAlchemistRecipe
    {
        $name = trim($in['name'] ?? '');
        if ($name === '') {
            throw new \RuntimeException('Rezept-Name ist Pflicht (§1).');
        }
        // MVP-044 (P0): Kategorie ist eine REFERENZ und wird autorisiert, bevor sie irgendwo
        // landet — vorher übernahmen Create und Update die rohe ID aus dem client-kontrollierten
        // Formular, die einzige Prüfung war die Options-Liste im Browser. Geprüft wird
        // Sichtbarkeit, nicht Eigentum: die geerbte Master-Kategorie muss nutzbar bleiben.
        $kategorieId = TeamScope::referenz(
            FoodAlchemistRecipeCategory::class, $in['category_id'] ?? null, $team, 'Kategorie'
        );

        $key = $this->rezeptKey($name);
        if ($this->keyVergeben($team, $key)) {                     // §1.8: Kategorie als Diskriminator
            $kategorie = $kategorieId !== null
                ? FoodAlchemistRecipeCategory::visibleToTeam($team)->find($kategorieId)?->label
                : null;
            if ($kategorie !== null) {
                $key = $this->rezeptKey($name) . '_' . $this->rezeptKey($kategorie);
            }
            $basis = $key;
            for ($n = 2; $this->keyVergeben($team, $key); $n++) {  // identische Duplikate: _2-Suffix
                $key = "{$basis}_{$n}";
            }
        }

        $recipe = FoodAlchemistRecipe::create([
            'team_id' => $team->id,
            'recipe_key' => $key,
            'name' => $name,
            'origin_source' => ($in['origin_source'] ?? '') ?: null,
            'category_id' => $kategorieId,
            'is_sales_recipe' => (bool) ($in['is_sales_recipe'] ?? false),
            'status' => 'draft',
            'taste_direction' => ($in['taste_direction'] ?? '') ?: null,
            'production_depth' => ($in['production_depth'] ?? '') ?: null,
            'work_time_min' => $in['work_time_min'] ?? null,
            // Stufe 3 — Planer-Felder (Create-Parität mit update(), sonst still verworfen bei Anlage).
            'setup_time_min' => $in['setup_time_min'] ?? null,
            'variable_work_time_min' => $in['variable_work_time_min'] ?? null,
            'variable_work_time_basis' => $in['variable_work_time_basis'] ?? null,
            'standzeit_min' => $in['standzeit_min'] ?? null,
            'max_vorlauf_tage' => $in['max_vorlauf_tage'] ?? null,
            'batch_max_kg' => $in['batch_max_kg'] ?? null,
            'batch_max_pieces' => $in['batch_max_pieces'] ?? null,
            'default_station_id' => TeamScope::referenz(\Platform\FoodAlchemist\Models\FoodAlchemistProductionStation::class, $in['default_station_id'] ?? null, $team, 'Posten'),
            'yield_kg_manual' => $in['yield_kg_manual'] ?? null,
            'yield_pieces' => ($in['yield_pieces'] ?? '') !== '' ? ($in['yield_pieces'] ?? null) : null,
            'description' => ($in['description'] ?? '') ?: null,
            // #509 Create-Parität: dieselben Fachfelder wie update() — sonst verwirft
            // die Anlage still, was der Nutzer im Anlege-Modal getippt hat (D-5 §4.2).
            'temperature' => ($in['temperature'] ?? '') ?: null,
            'function' => ($in['function'] ?? '') ?: null,
            'preparation' => ($in['preparation'] ?? '') ?: null,
            'notes_manual' => ($in['notes_manual'] ?? '') ?: null,
            'last_modified_by' => 'editor',
            'created_via' => ($in['created_via'] ?? '') ?: null,     // Phase A: mcp | editor | import | generator
        ]);
        // Equipment (§4.2.6): M:N-Sync wie update(), nur wenn übergeben
        if (array_key_exists('equipment_ids', $in) && is_array($in['equipment_ids'])) {
            $recipe->equipment()->sync(array_map('intval', $in['equipment_ids']));
        }
        $this->schritteAusMarkdown($recipe, $in);
        app(RecipeRecomputeService::class)->recomputePipeline($recipe->id);

        return $recipe->refresh();
    }

    /** Edit: recipe_key bleibt STABIL (Referenzen/Slugs); Recompute bei kalkulations-relevanten Feldern. */
    public function update(Team $team, int $id, array $in): FoodAlchemistRecipe
    {
        $recipe = FoodAlchemistRecipe::visibleToTeam($team)->findOrFail($id);
        if ((int) $recipe->team_id !== (int) $team->id) {
            throw new \RuntimeException('Geerbtes Rezept — Pflege nur durchs Besitzer-Team (D1).');
        }
        $name = trim($in['name'] ?? $recipe->name);
        if ($name === '') {
            throw new \RuntimeException('Rezept-Name ist Pflicht (§1).');
        }

        $altManual = $recipe->yield_kg_manual;
        $recipe->update([
            'name' => $name,
            'origin_source' => array_key_exists('origin_source', $in) ? (($in['origin_source'] ?? '') ?: null) : $recipe->origin_source,
            // D-6: Gericht-Flag im Editor umschaltbar (Create-Parität mit create():189 — vorher
            // war die „Gericht"-Checkbox im Stammdaten-Tab inert, der Toggle wurde still verworfen).
            'is_sales_recipe' => array_key_exists('is_sales_recipe', $in) ? (bool) $in['is_sales_recipe'] : $recipe->is_sales_recipe,
            'category_id' => array_key_exists('category_id', $in)
                ? TeamScope::referenz(FoodAlchemistRecipeCategory::class, $in['category_id'], $team, 'Kategorie')
                : $recipe->category_id,
            'taste_direction' => array_key_exists('taste_direction', $in) ? (($in['taste_direction'] ?? '') ?: null) : $recipe->taste_direction,
            'production_depth' => array_key_exists('production_depth', $in) ? (($in['production_depth'] ?? '') ?: null) : $recipe->production_depth,
            'work_time_min' => array_key_exists('work_time_min', $in) ? $in['work_time_min'] : $recipe->work_time_min,
            // Stufe 3 — Auto-Produktionsplaner: Default-Posten, Vorproduzierbarkeit, Rüstzeit, Topf-Deckel.
            'setup_time_min' => array_key_exists('setup_time_min', $in) ? $in['setup_time_min'] : $recipe->setup_time_min,
            'variable_work_time_min' => array_key_exists('variable_work_time_min', $in) ? $in['variable_work_time_min'] : $recipe->variable_work_time_min,
            'variable_work_time_basis' => array_key_exists('variable_work_time_basis', $in) && in_array($in['variable_work_time_basis'], ['kg', 'piece', 'portion'], true)
                ? $in['variable_work_time_basis'] : $recipe->variable_work_time_basis,
            'standzeit_min' => array_key_exists('standzeit_min', $in) ? $in['standzeit_min'] : $recipe->standzeit_min,
            'max_vorlauf_tage' => array_key_exists('max_vorlauf_tage', $in) ? $in['max_vorlauf_tage'] : $recipe->max_vorlauf_tage,
            'batch_max_kg' => array_key_exists('batch_max_kg', $in) ? $in['batch_max_kg'] : $recipe->batch_max_kg,
            'batch_max_pieces' => array_key_exists('batch_max_pieces', $in) ? $in['batch_max_pieces'] : $recipe->batch_max_pieces,
            'default_station_id' => array_key_exists('default_station_id', $in)
                ? TeamScope::referenz(\Platform\FoodAlchemist\Models\FoodAlchemistProductionStation::class, $in['default_station_id'], $team, 'Posten')
                : $recipe->default_station_id,
            'yield_kg_manual' => array_key_exists('yield_kg_manual', $in) ? $in['yield_kg_manual'] : $recipe->yield_kg_manual,
            'yield_pieces' => array_key_exists('yield_pieces', $in) ? (($in['yield_pieces'] ?? '') !== '' ? $in['yield_pieces'] : null) : $recipe->yield_pieces,
            'description' => array_key_exists('description', $in) ? (($in['description'] ?? '') ?: null) : $recipe->description,
            // UI-Audit (D-5 §4.2): Eigenschaften/Zubereitung/Notizen/Status im Editor pflegbar
            'temperature' => array_key_exists('temperature', $in) ? (($in['temperature'] ?? '') ?: null) : $recipe->temperature,
            'function' => array_key_exists('function', $in) ? (($in['function'] ?? '') ?: null) : $recipe->function,
            'preparation' => array_key_exists('preparation', $in) ? (($in['preparation'] ?? '') ?: null) : $recipe->preparation,
            'notes_manual' => array_key_exists('notes_manual', $in) ? (($in['notes_manual'] ?? '') ?: null) : $recipe->notes_manual,
            'status' => array_key_exists('status', $in) && in_array($in['status'], ['stub', 'draft', 'review', 'approved', 'archived'], true)
                ? $in['status'] : $recipe->status,
            'version' => $recipe->version + 1,
            'last_modified_by' => 'editor',
        ]);
        // Equipment (§4.2.6): M:N-Sync, nur wenn übergeben
        if (array_key_exists('equipment_ids', $in) && is_array($in['equipment_ids'])) {
            $recipe->equipment()->sync(array_map('intval', $in['equipment_ids']));
        }
        $this->schritteAusMarkdown($recipe, $in);
        if (array_key_exists('yield_kg_manual', $in) && $in['yield_kg_manual'] !== $altManual) {
            app(RecipeRecomputeService::class)->recomputeAndPropagate($recipe->id); // ek/kg-Nenner (A-3)
        }

        return $recipe->refresh();
    }

    /**
     * Spec 27: Schreibwege dürfen weiter Markdown liefern (Generator, MCP recipes.POST/PUT,
     * Import) — Master sind aber die Schritte. Kommt `preparation` mit Inhalt an, wird es
     * hier deterministisch in Schritte geparst und der Spiegel daraus neu gerendert.
     *
     * Vorhandene Schritte bleiben unangetastet: sie sind die feinere Wahrheit (inkl.
     * Foto-Verknüpfungen). Wer sie ersetzen will, nutzt `recipe_steps.PUT` bzw. den
     * Schritt-Editor (RecipeStepService::sync / ausMarkdown mit ueberschreiben: true).
     *
     * ⚠️ Wichtig: wird der Markdown-Write deshalb verworfen, muss der Spiegel aus den
     * Schritten NEU gerendert werden — sonst stünde im Feld der abgelehnte Text und
     * Produktionsdruck/Suche zeigten etwas, das die Anleitung nicht sagt.
     */
    private function schritteAusMarkdown(FoodAlchemistRecipe $recipe, array $in): void
    {
        if (! array_key_exists('preparation', $in) || trim((string) ($in['preparation'] ?? '')) === '') {
            return;
        }

        $svc = app(RecipeStepService::class);
        if ($svc->ausMarkdown($recipe, (string) $in['preparation']) === 0) {
            $svc->spiegele($recipe);
        }
    }

    /** Kopie inkl. Zutaten, status draft (D-5 §3.1). */
    public function duplicate(Team $team, int $id, string $neuerName): FoodAlchemistRecipe
    {
        $original = FoodAlchemistRecipe::visibleToTeam($team)->with('ingredients')->findOrFail($id);

        return DB::transaction(function () use ($team, $original, $neuerName) {
            $kopie = $this->create($team, [
                'name' => $neuerName,
                'category_id' => $original->category_id,
                'origin_source' => $original->origin_source,
                'taste_direction' => $original->taste_direction,
                'production_depth' => $original->production_depth,
                'is_sales_recipe' => $original->is_sales_recipe,
                'description' => $original->description,
            ]);
            foreach ($original->ingredients as $z) {
                $kopie->ingredients()->create([
                    ...$z->only(['position', 'gp_id', 'referenced_recipe_id', 'raw_text', 'display_name',
                        'quantity', 'quantity_max', 'unit_vocab_id', 'trimming_loss_pct', 'cooking_loss_pct',
                        'is_optional', 'klammer_note', 'note', 'match_method', 'match_confidence',
                        'role', 'is_value_relevant', 'calc_mode']),
                    'team_id' => $team->id,
                ]);
            }
            app(RecipeRecomputeService::class)->recomputePipeline($kopie->id);

            return $kopie->refresh();
        });
    }

    /**
     * Löschen (Soft-Delete) — blockt bei JEDER harten Referenz, nicht mehr nur bei Eltern-Zeilen
     * (2026-09-04). Vorher fielen Ersatz-Verknüpfungen, direkt gepinnte Ausgabe-Positionen und
     * Zeilen in offenen Produktionsaufträgen still weg. Die Bilanz kommt aus `referenzen()` und
     * ist dieselbe, die Panel und Editor anzeigen — eine Regel, ein Text (V-06).
     */
    public function delete(Team $team, int $id): void
    {
        $recipe = FoodAlchemistRecipe::visibleToTeam($team)->findOrFail($id);
        if ((int) $recipe->team_id !== (int) $team->id) {
            throw new \RuntimeException('Geerbtes Rezept — Löschen nur durchs Besitzer-Team (D1).');
        }
        $ref = $this->referenzen($id);
        if ($ref['blocker'] > 0) {
            $eltern = $recipe->parentIngredients()->whereNull('deleted_at')->with('recipe:id,name')
                ->get()->pluck('recipe.name')->filter()->unique();
            throw new \RuntimeException('Löschen blockiert — wird referenziert: '
                . implode(', ', $ref['blocker_teile'])
                . ($eltern->isNotEmpty() ? ' — als Sub-Rezept in: ' . $eltern->implode(', ') : '')
                . '. Erst umhängen („in allen Verwendungen ersetzen"), dann löschen.');
        }
        DB::transaction(function () use ($recipe) {
            $recipe->ingredients()->delete();
            $recipe->delete();
        });
    }

    /** Spec 43 (Bild-Epic): Gericht-Foto (Titelbild eines Rezepts) ablegen. Gibt den Pfad zurück. */
    public function storeDishImage(Team $team, int $id, \Illuminate\Http\UploadedFile $file): string
    {
        $recipe = FoodAlchemistRecipe::visibleToTeam($team)->findOrFail($id);
        app(\Platform\FoodAlchemist\Services\FoodAlchemistMediaService::class)->delete($recipe->image_context_file_id, (string) $recipe->image_path, $team);
        $media = app(\Platform\FoodAlchemist\Services\FoodAlchemistMediaService::class)->storeImage(
            $file, $team, 'foodalchemist.recipe', $id, "foodalchemist/recipe/{$id}",
        );
        $recipe->update(['image_context_file_id' => $media['context_file_id'], 'image_path' => $media['path']]);

        return $media['path'];
    }

    public function clearDishImage(Team $team, int $id): void
    {
        $recipe = FoodAlchemistRecipe::visibleToTeam($team)->findOrFail($id);
        app(\Platform\FoodAlchemist\Services\FoodAlchemistMediaService::class)->delete($recipe->image_context_file_id, (string) $recipe->image_path, $team);
        $recipe->update(['image_context_file_id' => null, 'image_path' => null]);
    }

    /** M4-12: Template-Flag togglen (Vorlagen für Instanziierung — D-5 §1). */
    public function setTemplate(Team $team, int $id, ?bool $istTemplate = null): FoodAlchemistRecipe
    {
        $recipe = FoodAlchemistRecipe::visibleToTeam($team)->findOrFail($id);
        if ((int) $recipe->team_id !== (int) $team->id) {
            throw new \RuntimeException('Geerbtes Rezept — Pflege nur durchs Besitzer-Team (D1).');
        }
        $recipe->update(['is_template' => $istTemplate ?? ! $recipe->is_template]);

        return $recipe->refresh();
    }

    /** M4-12: Bulk-Status (Browser-Leiste). @param array<int> $ids */
    public function bulkStatus(Team $team, array $ids, string $status): int
    {
        if (\Platform\FoodAlchemist\Enums\RecipeStatus::tryFrom($status) === null) {
            throw new \RuntimeException("Unbekannter Status [{$status}].");
        }

        return FoodAlchemistRecipe::visibleToTeam($team)
            ->where('team_id', $team->id)                          // D1: nur eigene
            ->whereIn('id', $ids)
            ->update(['status' => $status, 'last_modified_by' => 'editor']);
    }

    public function setStatus(Team $team, int $id, string $status): FoodAlchemistRecipe
    {
        $recipe = FoodAlchemistRecipe::visibleToTeam($team)->findOrFail($id);
        if (\Platform\FoodAlchemist\Enums\RecipeStatus::tryFrom($status) === null) {
            throw new \RuntimeException("Unbekannter Status [{$status}].");
        }
        $recipe->update(['status' => $status, 'last_modified_by' => 'editor']);

        return $recipe->refresh();
    }

    // ── M4-10: Sub-Rezept-Hierarchie (D-5 §3.1, Regelwerk BR §4) ────────

    /**
     * Stub-Anlage (F4.1): idempotent per Token-Set-Namensgleichheit (wie der
     * GL-04-Alias-Resolver) — existiert das Rezept, kommt es zurück (neu=false).
     * Neue Stubs: status=stub, last_modified_by=generator_stub; ein Eltern-Rezept
     * im Stub-Status wird auf draft gehoben (es hat jetzt echten Inhalt).
     *
     * @return array{recipe: FoodAlchemistRecipe, neu: bool}
     */
    public function createSubRecipeStub(Team $team, string $name, ?int $parentId = null): array
    {
        $name = trim($name);
        if ($name === '') {
            throw new \RuntimeException('Stub-Name ist Pflicht.');
        }

        $treffer = $this->findByTokenSet($team, $name);
        if ($treffer !== null) {
            return ['recipe' => $treffer, 'neu' => false];         // idempotent (Dedupe by name)
        }

        $stub = $this->create($team, ['name' => $name]);
        $stub->update(['status' => 'stub', 'last_modified_by' => 'generator_stub']);

        if ($parentId !== null) {
            $parent = FoodAlchemistRecipe::visibleToTeam($team)->find($parentId);
            if ($parent !== null && $parent->status->value === 'stub') {
                $parent->update(['status' => 'draft']);            // Eltern → draft (hat jetzt Inhalt)
            }
        }

        return ['recipe' => $stub->refresh(), 'neu' => true];
    }

    /**
     * Bestands-Reuse-Lookup (Token-Set-Namensgleichheit, wie der GL-04-Alias-Resolver): findet ein
     * bestehendes Basisrezept mit identischem Token-Set zum gegebenen Namen — ODER null. Read-only,
     * legt NICHTS an (im Gegensatz zu {@see createSubRecipeStub}). Der eine Ort, an dem „Bestand zuerst"
     * VOR der Neu-Anlage prüft, ob die Komponente schon existiert (Reuse-Gate der Kaskade).
     */
    public function findByTokenSet(Team $team, string $name): ?FoodAlchemistRecipe
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }
        $engine = app(\Platform\FoodAlchemist\Services\Matching\TokenEngine::class);
        $zielTokens = $engine->tokenize($name);
        sort($zielTokens);
        if ($zielTokens === []) {
            return null;
        }
        foreach (FoodAlchemistRecipe::visibleToTeam($team)->basis()->orderBy('id')->cursor() as $r) {
            $tokens = $engine->tokenize((string) $r->name);
            sort($tokens);
            if ($tokens === $zielTokens) {
                return $r;
            }
        }

        return null;
    }

    /**
     * Guard-Löschung (F4.1): NUR stub + generator-markiert + 0 Zutaten + 0 Referenzen.
     */
    public function deleteGeneratorStub(Team $team, int $id): void
    {
        $recipe = FoodAlchemistRecipe::visibleToTeam($team)->findOrFail($id);
        if ($recipe->status->value !== 'stub') {
            throw new \RuntimeException('Kein Stub — normales Löschen verwenden (delete()).');
        }
        if ($recipe->last_modified_by !== 'generator_stub') {
            throw new \RuntimeException('Stub ist nicht generator-markiert — manuell prüfen.');
        }
        if ($recipe->ingredients()->whereNull('deleted_at')->exists()) {
            throw new \RuntimeException('Stub hat bereits Zutaten — kein automatisches Löschen.');
        }
        if ($recipe->parentIngredients()->whereNull('deleted_at')->exists()) {
            throw new \RuntimeException('Stub wird referenziert — erst bei den Eltern lösen.');
        }
        $recipe->delete();
    }

    /** ↑-Navigation: Rezepte, die dieses als Sub referenzieren (fürs Panel „Verwendet in"). */
    public function getParents(Team $team, int $id): Collection
    {
        return FoodAlchemistRecipe::visibleToTeam($team)
            ->whereIn('id', \Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient::where('referenced_recipe_id', $id)
                ->whereNull('deleted_at')->distinct()->pluck('recipe_id'))
            ->orderBy('name')
            ->get(['id', 'name', 'status', 'team_id', 'is_sales_recipe']);
    }

    /**
     * Ausgabe-Ebenen, die direkt auf ein Rezept zeigen. Sie hängen ALLE an der GERICHT-Spalte
     * (`sales_recipe_id`, D-PLAN-1) — ein Basisrezept steht in einem Foodbook also nur ÜBER das
     * Gericht, das es als Komponente führt. Genau deshalb reicht dem Tausch die Komponenten-Zeile:
     * die Ausgaben folgen von selbst. Gezählt wird hier trotzdem, weil die Spalte technisch jede
     * Rezept-ID annimmt — wer ein Basisrezept direkt in eine Ausgabe pinnt, soll es nicht unter
     * den Füßen verlieren.
     */
    private const AUSGABE_REFERENZEN = [
        'foodalchemist_foodbook_blocks' => 'sales_recipe_id',
        'foodalchemist_menu_card_items' => 'sales_recipe_id',
        'foodalchemist_menu_plan_entries' => 'sales_recipe_id',
        'foodalchemist_offer_blocks' => 'sales_recipe_id',
        'foodalchemist_concept_slots' => 'sales_recipe_id',
    ];

    /**
     * Dasselbe für Tabellen, die der Paket-Umbau fachlich abgelöst hat, physisch aber noch nicht
     * gedroppt sind (`packages`/`package_dishes`, ex-`bausteine`). Solange sie stehen, können sie
     * Zeilen halten — deshalb mitzählen, aber per hasTable geprüft, damit der Drop nichts bricht.
     */
    private const AUSGABE_REFERENZEN_LEGACY = [
        'foodalchemist_package_dishes' => 'sales_recipe_id',
    ];

    /**
     * Lösch-Bilanz eines Rezepts — bewusst UNGESCOPED, wie `GpService::referenzen()`.
     *
     * Der Grund ist die Vererbungsrichtung: ein Master-Rezept ist für KIND-Teams sichtbar, deren
     * Rezepte sind für den Master aber unsichtbar. Eine team-gefilterte Zählung würde dem Master
     * erlauben, ein Rezept zu löschen, an dem ein Kind-Team hängt. (Der Tausch bleibt gescoped —
     * dort wird geschrieben, hier nur gezählt: `verwendungsBilanz()`.)
     *
     * `blocker_teile` sind die harten Gründe im Klartext; `produktion_historie` und `instanzen`
     * stehen nur zur Information (abgeschlossene Aufträge bleiben lesbar, Herkunfts-Zeiger fallen
     * beim Löschen auf NULL).
     *
     * @return array{eltern_zeilen:int, eltern:int, ersatz:int, ausgaben:int, produktion_offen:int,
     *               produktion_historie:int, instanzen:int, blocker:int, blocker_teile:list<string>}
     */
    public function referenzen(int $id): array
    {
        $eltern = FoodAlchemistRecipeIngredient::where('referenced_recipe_id', $id);

        // Ausgabe-Ebenen in EINEM Roundtrip — das Panel rendert die Bilanz bei jedem Tastendruck
        // im Tausch-Suchfeld neu. Tabellen-/Spaltennamen kommen aus der Konstante, nicht aus Input.
        $tabellen = self::AUSGABE_REFERENZEN;
        foreach (self::AUSGABE_REFERENZEN_LEGACY as $tabelle => $spalte) {
            if (Schema::hasTable($tabelle)) {
                $tabellen[$tabelle] = $spalte;
            }
        }
        $summanden = [];
        $bindings = [];
        foreach ($tabellen as $tabelle => $spalte) {
            $summanden[] = "(SELECT COUNT(*) FROM {$tabelle} WHERE {$spalte} = ? AND deleted_at IS NULL)";
            $bindings[] = $id;
        }
        $ausgaben = (int) DB::selectOne('SELECT ' . implode(' + ', $summanden) . ' AS n', $bindings)->n;

        $produktion = DB::table('foodalchemist_production_order_lines as l')
            ->join('foodalchemist_production_orders as o', 'o.id', '=', 'l.production_order_id')
            ->where('l.recipe_id', $id)->whereNull('l.deleted_at')->whereNull('o.deleted_at')
            ->selectRaw("SUM(CASE WHEN o.status IN ('planned', 'in_progress') THEN 1 ELSE 0 END) AS offen")
            ->selectRaw("SUM(CASE WHEN o.status IN ('done', 'cancelled') THEN 1 ELSE 0 END) AS historie")
            ->first();

        $ref = [
            'eltern_zeilen' => (clone $eltern)->count(),
            'eltern' => (clone $eltern)->distinct()->count('recipe_id'),
            'ersatz' => FoodAlchemistComponentEquivalent::where(fn (Builder $w) => $w
                ->where(fn (Builder $q) => $q->where('source_kind', FoodAlchemistComponentEquivalent::KIND_RECIPE)->where('source_id', $id))
                ->orWhere(fn (Builder $q) => $q->where('alt_kind', FoodAlchemistComponentEquivalent::KIND_RECIPE)->where('alt_id', $id)))
                ->count(),
            'ausgaben' => $ausgaben,
            'produktion_offen' => (int) ($produktion->offen ?? 0),
            'produktion_historie' => (int) ($produktion->historie ?? 0),
            'instanzen' => FoodAlchemistRecipe::where('instantiated_from_recipe_id', $id)->count(),
        ];

        $ref['blocker_teile'] = array_values(array_filter([
            $ref['eltern_zeilen'] > 0 ? "{$ref['eltern_zeilen']} Zeile(n) in {$ref['eltern']} Rezept(en)" : null,
            $ref['ersatz'] > 0 ? "{$ref['ersatz']} Ersatz-Verknüpfung(en)" : null,
            $ref['ausgaben'] > 0 ? "{$ref['ausgaben']} Ausgabe-Position(en) (Foodbook/Speisekarte/Speiseplan/Angebot/Konzept)" : null,
            $ref['produktion_offen'] > 0 ? "{$ref['produktion_offen']} Zeile(n) in offenen Produktionsaufträgen" : null,
        ]));
        $ref['blocker'] = $ref['eltern_zeilen'] + $ref['ersatz'] + $ref['ausgaben'] + $ref['produktion_offen'];

        return $ref;
    }

    // ── Rezept-Tausch (Dominique 2026-09-04) — Pendant zu GpService::ersetzeInRezepten ──
    //
    // Unterschied zum GP-Katalog: Rezepte sind team-besessen (D1). Umgehängt wird deshalb NUR
    // in EIGENEN Eltern; geerbte Master-/Seed-Rezepte, die dieses Rezept ebenfalls einsetzen,
    // bleiben unberührt und werden gezählt zurückgemeldet (nichts still überspringen).

    /**
     * Verwendungs-Bilanz als Komponente — Datenbasis des Tausch-Blocks (Panel + Editor).
     * „eigen" = umhängbar · „fremd" = sichtbar, aber read-only (Master/Seed, D1).
     *
     * @return array{zeilen: int, rezepte: int, fremd_zeilen: int, fremd_rezepte: int}
     */
    public function verwendungsBilanz(Team $team, int $id): array
    {
        $q = DB::table('foodalchemist_recipe_ingredients as ri')
            ->join('foodalchemist_recipes as r', 'r.id', '=', 'ri.recipe_id')
            ->where('ri.referenced_recipe_id', $id)
            ->whereNull('ri.deleted_at')
            ->whereNull('r.deleted_at');
        $zeilen = TeamScope::applyVisible($q, 'r.team_id', $team)
            ->get(['ri.id as zeile_id', 'r.id as parent_id', 'r.team_id']);
        $eigen = $zeilen->filter(fn ($z) => (int) $z->team_id === (int) $team->id);
        $fremd = $zeilen->filter(fn ($z) => (int) $z->team_id !== (int) $team->id);

        return [
            'zeilen' => $eigen->count(),
            'rezepte' => $eigen->pluck('parent_id')->unique()->count(),
            'fremd_zeilen' => $fremd->count(),
            'fremd_rezepte' => $fremd->pluck('parent_id')->unique()->count(),
        ];
    }

    /**
     * Dieses Rezept in ALLEN eigenen Verwendungen (Gerichte + Basisrezepte, die es als Sub
     * führen) durch ein anderes ersetzen — Dubletten-Bereinigung bzw. globaler Komponenten-
     * Wechsel. Menge, Einheit, Rolle und Verlust-Overrides der Zeile bleiben stehen; getauscht
     * wird allein die Komponenten-FK (wie beim GP-Tausch). `match_method` wandert bewusst auf
     * `override_subrecipe`: die Zeile zeigt jetzt dorthin, weil ein Mensch es angeordnet hat
     * (dieselbe Provenienz wie der Hard-Stop-Resolver) — ein stehengelassenes
     * `gemini_proposed` wäre ein falsches Etikett.
     *
     * Zyklen/Selbstreferenz fängt pruefeVerknuepfung je Eltern-Rezept; die betroffene Menge
     * wird EINMAL topologisch neu gerechnet (V-049).
     *
     * @return array{zeilen: int, rezepte: int, fremd_rezepte: int, zyklus: list<string>, doppelt: list<string>}
     */
    public function ersetzeInVerwendungen(Team $team, int $vonId, int $nachId): array
    {
        $von = FoodAlchemistRecipe::visibleToTeam($team)->find($vonId);
        $nach = FoodAlchemistRecipe::visibleToTeam($team)->find($nachId);
        if ($von === null || $nach === null) {
            throw new \RuntimeException('Quell- oder Ziel-Rezept nicht gefunden.');
        }
        if ((int) $von->id === (int) $nach->id) {
            throw new \RuntimeException('Quelle und Ziel sind identisch.');
        }
        if ($nach->status === RecipeStatus::Deprecated) {
            throw new \RuntimeException('Ziel-Rezept ist „Veraltet" — kein gültiges Ersetzungsziel.');
        }

        $bilanz = $this->verwendungsBilanz($team, (int) $von->id);
        $zeilen = FoodAlchemistRecipeIngredient::where('referenced_recipe_id', $von->id)
            ->whereIn('recipe_id', FoodAlchemistRecipe::query()->where('team_id', $team->id)->select('id'))
            ->get(['id', 'recipe_id']);
        if ($zeilen->isEmpty()) {
            throw new \RuntimeException($bilanz['fremd_zeilen'] > 0
                ? "Verwendet nur in {$bilanz['fremd_rezepte']} geerbten Rezept(en) — die sind für dieses Team read-only (D1)."
                : 'Wird in keinem Rezept als Komponente verwendet — nichts zu tauschen.');
        }

        $elternIds = $zeilen->pluck('recipe_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
        $namen = FoodAlchemistRecipe::whereIn('id', $elternIds)->pluck('name', 'id');
        $schonDrin = FoodAlchemistRecipeIngredient::where('referenced_recipe_id', $nach->id)
            ->whereIn('recipe_id', $elternIds)->distinct()->pluck('recipe_id')
            ->map(fn ($id) => (int) $id)->all();

        $recompute = app(RecipeRecomputeService::class);
        $erlaubt = [];
        $zyklus = [];
        foreach ($elternIds as $pid) {
            $pruefung = $recompute->pruefeVerknuepfung($pid, (int) $nach->id);
            if ($pruefung['erlaubt']) {
                $erlaubt[] = $pid;
            } else {
                $zyklus[] = ($namen[$pid] ?? "#{$pid}") . " ({$pruefung['grund']})";
            }
        }
        if ($erlaubt === []) {
            throw new \RuntimeException('Tausch abgelehnt — würde einen Zyklus erzeugen: ' . implode(', ', $zyklus) . '.');
        }

        $zeilenIds = $zeilen->whereIn('recipe_id', $erlaubt)->pluck('id')->all();
        $anzahl = DB::transaction(function () use ($zeilenIds, $nach) {
            $n = FoodAlchemistRecipeIngredient::whereIn('id', $zeilenIds)
                ->update(['referenced_recipe_id' => $nach->id, 'match_method' => MatchMethod::OverrideSubrecipe->value]);
            $this->loeseRegenerationsOverrides($zeilenIds);

            return $n;
        });

        $recompute->recomputeMany($erlaubt);

        return [
            'zeilen' => (int) $anzahl,
            'regen_overrides_geloest' => $this->zuletztGeloest,
            'rezepte' => count($erlaubt),
            'fremd_rezepte' => $bilanz['fremd_rezepte'],
            'zyklus' => $zyklus,
            'doppelt' => collect($schonDrin)->intersect($erlaubt)
                ->map(fn ($id) => $namen[$id] ?? "#{$id}")->values()->all(),
        ];
    }

    /** Zahl der beim letzten Tausch geloesten Regenerations-Overrides (Rueckmeldung ans UI). */
    private int $zuletztGeloest = 0;

    /**
     * Spec 51: Ein Tausch haengt die Zutatenzeile IN PLACE auf ein anderes Rezept um — dieselbe
     * Zeilen-Id, anderer Inhalt. Ein Regenerations-Override an dieser Id wuerde das ueberleben und
     * danach still etwas anderes beschreiben: »Kombidaempfer 140 °C, 12 min« stuende dann an einer
     * Komponente, fuer die das nie jemand entschieden hat.
     *
     * Deshalb wird der Override geloest, nicht mitgeschleift. Die Komponente gibt wieder den Ton
     * an — das ist der Zustand, den jemand beim naechsten Blick erwartet, und die Kaskade zeigt
     * ihn als »geerbt« an. Wer erneut abweichen will, sieht die Zeile und entscheidet neu.
     *
     * @param  list<int>  $ingredientIds
     */
    private function loeseRegenerationsOverrides(array $ingredientIds): void
    {
        $this->zuletztGeloest = $ingredientIds === [] ? 0 : (int) DB::table('foodalchemist_recipe_regenerations')
            ->whereIn('ingredient_id', $ingredientIds)
            ->whereNull('deleted_at')
            ->update(['deleted_at' => now()]);
    }

    // ── M9-01k: Sektor-/Niveau-Eignung pflegen (Zeile = geeignet; unique recipe+slug) ──

    private const EIGNUNG_TABELLEN = [
        'level' => ['tabelle' => 'foodalchemist_recipe_level_suitability', 'spalte' => 'level_slug', 'slugs' => ['haute_cuisine', 'gehoben', 'klassisch']],
        'sektor' => ['tabelle' => 'foodalchemist_recipe_sector_suitability', 'spalte' => 'sector_slug', 'slugs' => ['business', 'care', 'crew', 'event_privat', 'kita_schule', 'restaurant']],
    ];

    /** @return array<string, array> Vokabular fürs UI */
    public static function eignungVokabular(): array
    {
        return self::EIGNUNG_TABELLEN;
    }

    public function setzeEignung(Team $team, int $recipeId, string $typ, string $slug, string $source = 'manual', ?float $confidence = null, ?string $grund = null): void
    {
        $meta = self::EIGNUNG_TABELLEN[$typ] ?? throw new \RuntimeException("Unbekannter Eignungs-Typ [{$typ}].");
        if (! in_array($slug, $meta['slugs'], true)) {
            throw new \RuntimeException("Unbekannter {$typ}-Slug [{$slug}].");
        }
        $recipe = FoodAlchemistRecipe::visibleToTeam($team)->findOrFail($recipeId);
        if (! $recipe->isOwnedBy($team)) {
            throw new \RuntimeException('Geerbtes Rezept — Pflege nur durchs Besitzer-Team (D1).');
        }

        // unique(recipe, slug) gilt inkl. soft-deleted ⇒ vorhandene Zeile reaktivieren
        $vorhanden = DB::table($meta['tabelle'])->where('recipe_id', $recipeId)->where($meta['spalte'], $slug)->first();
        $werte = ['source' => $source, 'ai_confidence' => $confidence, 'ai_reasoning' => $grund, 'deleted_at' => null, 'updated_at' => now()];
        if ($vorhanden !== null) {
            DB::table($meta['tabelle'])->where('id', $vorhanden->id)->update($werte);
        } else {
            DB::table($meta['tabelle'])->insert($werte + [
                'uuid' => (string) \Illuminate\Support\Str::uuid7(),
                'team_id' => $team->id, 'recipe_id' => $recipeId, $meta['spalte'] => $slug, 'created_at' => now(),
            ]);
        }
    }

    public function entferneEignung(Team $team, int $recipeId, string $typ, string $slug): void
    {
        $meta = self::EIGNUNG_TABELLEN[$typ] ?? throw new \RuntimeException("Unbekannter Eignungs-Typ [{$typ}].");
        $recipe = FoodAlchemistRecipe::visibleToTeam($team)->findOrFail($recipeId);
        if (! $recipe->isOwnedBy($team)) {
            throw new \RuntimeException('Geerbtes Rezept — Pflege nur durchs Besitzer-Team (D1).');
        }
        DB::table($meta['tabelle'])->where('recipe_id', $recipeId)->where($meta['spalte'], $slug)
            ->update(['deleted_at' => now(), 'updated_at' => now()]);
    }

    // ── M4-07/08: Zutaten-Editor (P-8) ──────────────────────────────────

    /**
     * Voll-Sync der Zutatenliste (eine Transaktion, V-07): Reihenfolge = Array-
     * Reihenfolge, fehlende Zeilen werden gelöscht, id-lose angelegt. Danach
     * GENAU EIN recomputeAndPropagate. XOR gp/sub wird hier erzwungen (D-5 §2.2).
     *
     * @param array<int, array> $zeilen
     */
    public function syncIngredients(Team $team, int $recipeId, array $zeilen): FoodAlchemistRecipe
    {
        $recipe = FoodAlchemistRecipe::visibleToTeam($team)->findOrFail($recipeId);
        if ((int) $recipe->team_id !== (int) $team->id) {
            throw new \RuntimeException('Geerbtes Rezept — Zutaten-Pflege nur durchs Besitzer-Team (D1).');
        }

        DB::transaction(function () use ($team, $recipe, $zeilen) {
            $vorhanden = $recipe->ingredients()->get()->keyBy('id');
            $behalten = [];

            foreach (array_values($zeilen) as $i => $z) {
                $gpId = ($z['gp_id'] ?? null) !== null && $z['gp_id'] !== '' ? (int) $z['gp_id'] : null;
                $subId = ($z['referenced_recipe_id'] ?? null) !== null && $z['referenced_recipe_id'] !== '' ? (int) $z['referenced_recipe_id'] : null;
                if ($gpId !== null && $subId !== null) {
                    throw new \RuntimeException('Zutat darf nicht GP UND Sub-Rezept zugleich sein (XOR, D-5 §2.2).');
                }

                // E3 (#508): Re-Grounding — eine Zeile OHNE GP/Sub (typisch KI-überarbeitet,
                // sonst roh als 'unmatched' verloren) läuft durch den GL-04-Resolver. Nur
                // zuversichtliche Treffer (matchIngredient hält die Schwelle); sonst bleibt
                // sie unmatched → Hard-Stop-UI. Auto-gegroundete Subs werden zyklus-geprüft
                // und bei Ablehnung stillschweigend verworfen (kein Throw fürs Auto-Grounding).
                $groundedMethod = null;
                $groundedConfidence = null;
                if ($gpId === null && $subId === null && ($z['auto_ground'] ?? true)) {
                    $groundName = trim((string) ($z['display_name'] ?? '')) ?: trim((string) ($z['raw_text'] ?? ''));
                    if ($groundName !== '') {
                        $treffer = app(IngredientMatchService::class)->matchIngredient(
                            $team, $groundName, $z['hauptzutat_slug'] ?? ($z['slug'] ?? null),
                        );
                        if ($treffer['target'] === 'gp') {
                            $gpId = (int) $treffer['gp_id'];
                            $groundedMethod = 'gp_v2_fk';
                            $groundedConfidence = round((float) $treffer['score'], 3);
                        } elseif ($treffer['target'] === 'sub_recipe') {
                            $cand = (int) $treffer['recipe_id'];
                            if ($cand !== $recipe->id
                                && app(RecipeRecomputeService::class)->pruefeVerknuepfung($recipe->id, $cand)['erlaubt']) {
                                $subId = $cand;
                                $groundedMethod = 'recipe_ref';
                                $groundedConfidence = round((float) $treffer['score'], 3);
                            }
                        }

                        // 07·M2: Bestand-Miss, aber passende LA vorhanden → LA-First-Mint
                        // (geteilte Doktrin, schließt die Revise-Lücke: E3 matchte nur, mintete
                        // nicht). Mint ist tentative + LA-verknüpft; keine LA → bleibt unmatched
                        // (Hard-Stop / Sourcing-Wunsch beim Aufrufer). Provenienz wie im Generator.
                        if ($gpId === null && $subId === null) {
                            $mint = app(LaFirstGpService::class)->mintFromLa(
                                $team, $groundName, $z['hauptzutat_slug'] ?? ($z['slug'] ?? null),
                            );
                            if ($mint !== null) {
                                $gpId = $mint->id;
                                $groundedMethod = 'gemini_proposed';   // LA-First-Mint-Provenienz (gültiger MatchMethod-Case)
                                $groundedConfidence = null;
                            }
                        }
                    }
                }

                if ($subId !== null) {
                    if ($subId === $recipe->id) {
                        throw new \RuntimeException('Selbstreferenz — ein Rezept kann sich nicht selbst enthalten (GL-02 §3.5).');
                    }
                    $pruefung = app(RecipeRecomputeService::class)->pruefeVerknuepfung($recipe->id, $subId);
                    if (! $pruefung['erlaubt']) {
                        throw new \RuntimeException("Sub-Rezept-Verknüpfung abgelehnt: {$pruefung['grund']}.");
                    }
                }
                $quantity = (float) str_replace(',', '.', (string) ($z['quantity'] ?? 0));
                if ($quantity <= 0) {
                    throw new \RuntimeException('Menge muss > 0 sein (Zeile ' . ($i + 1) . ').');
                }

                $attrs = [
                    'position' => $i + 1,                              // Reorder = Array-Reihenfolge
                    'gp_id' => $gpId,
                    'referenced_recipe_id' => $subId,
                    'raw_text' => trim((string) ($z['raw_text'] ?? '')) ?: ($z['display_name'] ?? 'Zutat'),
                    'display_name' => ($z['display_name'] ?? '') ?: null,
                    'quantity' => $quantity,
                    'quantity_max' => ($z['quantity_max'] ?? '') !== '' && $z['quantity_max'] !== null ? (float) str_replace(',', '.', (string) $z['quantity_max']) : null,
                    'unit_vocab_id' => (int) $z['unit_vocab_id'],
                    'cooking_loss_pct' => ($z['cooking_loss_pct'] ?? '') !== '' && $z['cooking_loss_pct'] !== null ? (float) str_replace(',', '.', (string) $z['cooking_loss_pct']) : null,
                    'cooking_loss_source' => ($z['cooking_loss_source'] ?? null) ?: null,   // M4-11: ki|manual (GL-07)
                    'trimming_loss_pct' => ($z['trimming_loss_pct'] ?? '') !== '' && ($z['trimming_loss_pct'] ?? null) !== null ? (float) str_replace(',', '.', (string) $z['trimming_loss_pct']) : null,
                    'is_optional' => (bool) ($z['is_optional'] ?? false),
                    'note' => ($z['note'] ?? '') ?: null,
                    'role' => ($z['role'] ?? '') ?: null,            // V-21
                    'is_value_relevant' => (bool) ($z['is_value_relevant'] ?? false),
                ];

                // E3: gegroundete Zeilen tragen die Resolver-Provenienz (gp_v2_fk|recipe_ref)
                // + Konfidenz — auch beim UPDATE einer zuvor 'unmatched' Bestands-Zeile.
                if ($groundedMethod !== null) {
                    $attrs['match_method'] = $groundedMethod;
                    $attrs['match_confidence'] = $groundedConfidence;
                }

                $id = ($z['id'] ?? null) !== null && $vorhanden->has((int) $z['id']) ? (int) $z['id'] : null;
                if ($id !== null) {
                    $vorhanden[$id]->update($attrs);
                    $behalten[] = $id;
                } else {
                    $neu = $recipe->ingredients()->create([...$attrs,
                        'team_id' => $team->id,
                        // Fallback-Provenienz, wenn der Aufrufer keine Methode mitgibt.
                        //
                        // Bis 2026-09-03 stand hier bei gesetztem gp_id `manual` — also
                        // »ein Mensch hat das gewählt«. Bei KI-erzeugten Rezepten ist das
                        // FALSCHE Provenienz: 3.099 Zeilen tragen `manual`, mindestens 112
                        // davon in Rezepten mit created_via=mcp. Beim Debuggen des
                        // TK-Apfel-Falls (Gericht 3689) hat dieses Etikett meine Diagnose
                        // zweimal in die falsche Richtung geschickt — es verdeckt genau,
                        // WELCHER Mechanismus den GP gewählt hat.
                        //
                        // `gp_v2_fk` ist NICHT neu erfunden, sondern das Etikett, das dieser
                        // Service für genau diesen Fall schon benutzt: Zeile 601 setzt es als
                        // `$groundedMethod`, und der Kommentar bei 662 nennt es ausdrücklich
                        // »die Resolver-Provenienz (gp_v2_fk|recipe_ref)«. Der Fallback folgt
                        // damit dem hier geltenden Vokabular statt ein neues zu öffnen. Auf
                        // recipe_ingredients entscheidet niemand an `manual` (die
                        // Schutzregel in SalesImportService:162 gilt für
                        // foodalchemist_sales_facts, eine andere Tabelle) — der Wechsel ist
                        // daher verhaltensneutral. Bestehende Zeilen bleiben unangetastet:
                        // ein Backfill würde echte Handarbeit mit-überschreiben.
                        'match_method' => $attrs['match_method']
                            ?? ($subId !== null ? 'recipe_ref' : ($gpId !== null ? 'gp_v2_fk' : 'unmatched')),
                    ]);
                    $behalten[] = $neu->id;
                }
            }

            $recipe->ingredients()->whereNotIn('id', $behalten)->delete();
        });

        app(RecipeRecomputeService::class)->recomputeAndPropagate($recipe->id);

        $recipe = $recipe->refresh();
        // A4: Recall-Index nachziehen — die Top-Zutaten-Namen sind Teil des Rezept-Embed-Texts
        // ({@see PoolEmbeddingService::recipeEmbedText}); eine Zutaten-Änderung driftet ihn sonst
        // still (der Rezept-Observer feuert nur auf Rezept-Felder, nicht auf die Zutaten-Zeilen).
        // Idempotent via source_hash (nur echter Text-Change embeddet neu), async, no-op ohne Provider.
        app(PoolEmbeddingService::class)->queueRecipe($recipe);

        return $recipe;
    }

    /**
     * P-8-Picker: GPs der Team-Kette + Basisrezepte (ohne das Rezept selbst) — Auto-Fill-Daten
     * (ek_pro_g für die Client-Live-Summe) inklusive.
     */
    public function sucheZutatenZiel(Team $team, string $suche, int $ohneRecipeId, int $limit = 8): array
    {
        if (trim($suche) === '') {
            return [];
        }
        $recompute = app(RecipeRecomputeService::class);

        $gps = \Platform\FoodAlchemist\Support\Suche::like(
            FoodAlchemistGp::visibleToTeam($team), 'name', $suche)   // Multi-Wort: jedes Token muss treffen
            ->orderBy('name')->limit($limit)
            ->get(['id', 'name', 'lead_la_supplier_item_id', 'piece_default_g', 'team_id'])
            ->map(fn ($gp) => [
                'type' => 'gp', 'id' => $gp->id, 'name' => $gp->name,
                'ek_pro_g' => $recompute->preisProGrammPublic($gp, $team),
                // Stückgewicht fürs Live-Rechnen: ohne das bleibt eine stk-Zeile im Client
                // ohne Gramm-Faktor (Anteil %/Yield/EK leer), obwohl der Server sie kennt.
                'g_pro_stueck' => $gp->piece_default_g !== null ? (float) $gp->piece_default_g : null,
                'url' => \Platform\FoodAlchemist\Support\Sprungziel::gp($gp->id),  // R5: Sprung-Ziel
            ]);

        $subs = \Platform\FoodAlchemist\Support\Suche::like(
            FoodAlchemistRecipe::visibleToTeam($team)->basis()
                ->where('id', '!=', $ohneRecipeId), 'name', $suche)
            ->orderBy('name')->limit($limit)
            ->get(['id', 'name', 'ek_per_kg_eur'])
            ->map(fn ($r) => [
                'type' => 'sub', 'id' => $r->id, 'name' => '↳ ' . $r->name,
                'ek_pro_g' => $r->ek_per_kg_eur !== null ? ((float) $r->ek_per_kg_eur) / 1000 : null,
                'url' => \Platform\FoodAlchemist\Support\Sprungziel::rezept($r->id),
            ]);

        return $gps->concat($subs)->take($limit)->values()->all();
    }

    private function keyVergeben(Team $team, string $key): bool
    {
        return FoodAlchemistRecipe::where('team_id', $team->id)->where('recipe_key', $key)->exists();
    }

    private function browserQuery(Team $team, array $filters): Builder
    {
        return FoodAlchemistRecipe::visibleToTeam($team)->basis()
            ->when(($filters['search'] ?? '') !== '', function (Builder $q) use ($filters) {
                // Multi-Wort: jedes Token muss treffen (Name ODER recipe_key)
                foreach (\Platform\FoodAlchemist\Support\Suche::tokens($filters['search']) as $token) {
                    $q->where(fn (Builder $w) => $w
                        ->whereRaw('LOWER(foodalchemist_recipes.name) LIKE ?', ['%' . $token . '%'])
                        ->orWhereRaw('LOWER(foodalchemist_recipes.recipe_key) LIKE ?', ['%' . $token . '%']));
                }
            })
            ->when(($filters['hauptgruppe'] ?? null) !== null && $filters['hauptgruppe'] !== '', fn (Builder $q) => $q
                ->whereIn('category_id', DB::table('foodalchemist_recipe_categories')
                    ->where('main_group_id', (int) $filters['hauptgruppe'])->pluck('id')))
            ->when(($filters['category'] ?? null) !== null && $filters['category'] !== '', fn (Builder $q) => $q
                ->where('category_id', (int) $filters['category']))
            ->when($filters['ohne_kategorie'] ?? false, fn (Builder $q) => $q
                ->whereNull('foodalchemist_recipes.category_id'))       // MVP-042: eigener Arbeitsvorrat
            ->when(($filters['status'] ?? '') !== '', fn (Builder $q) => $q->where('status', $filters['status']))
            ->when(($filters['geschmack'] ?? '') !== '', fn (Builder $q) => $q->where('taste_direction', $filters['geschmack']))
            ->when(($filters['fertigung'] ?? '') !== '', fn (Builder $q) => $q->where('production_depth', $filters['fertigung']))
            ->when($filters['nur_templates'] ?? false, fn (Builder $q) => $q->where('is_template', true));  // R6: Template-Filter
    }
}
