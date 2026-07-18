<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeMainGroup;

/**
 * M4-04/05 / D-5 §3.1 (Listen-Teil): Basisrezept-Browser — Scope basis() wird
 * in JEDER Query erzwungen (D-6/SalesRecipeService bekommt seine eigene Sicht).
 * CRUD/Zutaten-Mutationen folgen mit M4-06/07 (jede Mutation → recomputeAndPropagate).
 */
class RecipeService
{
    /** Hauptgruppen-Zähler in einer GROUP-BY-Query (Baum links, P-1). */
    public function hauptgruppenCounts(Team $team, array $filters = []): array
    {
        return $this->browserQuery($team, array_diff_key($filters, ['hauptgruppe' => 1, 'category' => 1]))
            ->join('foodalchemist_recipe_categories AS k', 'k.id', '=', 'foodalchemist_recipes.category_id')
            ->selectRaw('k.main_group_id, COUNT(*) AS n')
            ->groupBy('k.main_group_id')
            ->pluck('n', 'k.main_group_id')
            ->all();
    }

    /** Kategorie-Zähler der gewählten Hauptgruppe (zweite Baum-Ebene). */
    public function kategorieCounts(Team $team, int $mainGroupId): array
    {
        return $this->browserQuery($team, [])
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
                'ingredients.referencedRecipe:id,name,ek_per_kg_eur',
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
        $kategorieId = $in['category_id'] ?? null;

        $key = $this->rezeptKey($name);
        if ($this->keyVergeben($team, $key)) {                     // §1.8: Kategorie als Diskriminator
            $kategorie = $kategorieId !== null
                ? \Platform\FoodAlchemist\Models\FoodAlchemistRecipeCategory::find($kategorieId)?->label
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
            'category_id' => $in['category_id'] ?? $recipe->category_id,
            'taste_direction' => array_key_exists('taste_direction', $in) ? (($in['taste_direction'] ?? '') ?: null) : $recipe->taste_direction,
            'production_depth' => array_key_exists('production_depth', $in) ? (($in['production_depth'] ?? '') ?: null) : $recipe->production_depth,
            'work_time_min' => array_key_exists('work_time_min', $in) ? $in['work_time_min'] : $recipe->work_time_min,
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
        if (array_key_exists('yield_kg_manual', $in) && $in['yield_kg_manual'] !== $altManual) {
            app(RecipeRecomputeService::class)->recomputeAndPropagate($recipe->id); // ek/kg-Nenner (A-3)
        }

        return $recipe->refresh();
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

    /** Löschen blockt, wenn Eltern dieses Rezept als Sub referenzieren (typisierte Exception, V-06). */
    public function delete(Team $team, int $id): void
    {
        $recipe = FoodAlchemistRecipe::visibleToTeam($team)->findOrFail($id);
        if ((int) $recipe->team_id !== (int) $team->id) {
            throw new \RuntimeException('Geerbtes Rezept — Löschen nur durchs Besitzer-Team (D1).');
        }
        $eltern = $recipe->parentIngredients()->whereNull('deleted_at')->with('recipe:id,name')->get();
        if ($eltern->isNotEmpty()) {
            throw new \RuntimeException('Rezept wird als Sub-Rezept referenziert von: '
                . $eltern->pluck('recipe.name')->unique()->implode(', ') . ' — erst dort lösen.');
        }
        DB::transaction(function () use ($recipe) {
            $recipe->ingredients()->delete();
            $recipe->delete();
        });
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

        $engine = app(\Platform\FoodAlchemist\Services\Matching\TokenEngine::class);
        $zielTokens = $engine->tokenize($name);
        sort($zielTokens);
        foreach (FoodAlchemistRecipe::visibleToTeam($team)->basis()->orderBy('id')->cursor() as $r) {
            $tokens = $engine->tokenize($r->name);
            sort($tokens);
            if ($tokens === $zielTokens) {
                return ['recipe' => $r, 'neu' => false];           // idempotent (Dedupe by name)
            }
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
                if ($gpId === null && $subId === null) {
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
                        'match_method' => $attrs['match_method']
                            ?? ($subId !== null ? 'recipe_ref' : ($gpId !== null ? 'manual' : 'unmatched')),
                    ]);
                    $behalten[] = $neu->id;
                }
            }

            $recipe->ingredients()->whereNotIn('id', $behalten)->delete();
        });

        app(RecipeRecomputeService::class)->recomputeAndPropagate($recipe->id);

        return $recipe->refresh();
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
                'ek_pro_g' => $recompute->preisProGrammPublic($gp),
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
            ->when(($filters['status'] ?? '') !== '', fn (Builder $q) => $q->where('status', $filters['status']))
            ->when(($filters['geschmack'] ?? '') !== '', fn (Builder $q) => $q->where('taste_direction', $filters['geschmack']))
            ->when(($filters['fertigung'] ?? '') !== '', fn (Builder $q) => $q->where('production_depth', $filters['fertigung']))
            ->when($filters['nur_templates'] ?? false, fn (Builder $q) => $q->where('is_template', true));  // R6: Template-Filter
    }
}
