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
        return $this->browserQuery($team, array_diff_key($filters, ['hauptgruppe' => 1, 'kategorie' => 1]))
            ->join('foodalchemist_recipe_categories AS k', 'k.id', '=', 'foodalchemist_recipes.kategorie_id')
            ->selectRaw('k.main_group_id, COUNT(*) AS n')
            ->groupBy('k.main_group_id')
            ->pluck('n', 'k.main_group_id')
            ->all();
    }

    /** Kategorie-Zähler der gewählten Hauptgruppe (zweite Baum-Ebene). */
    public function kategorieCounts(Team $team, int $mainGroupId): array
    {
        return $this->browserQuery($team, [])
            ->join('foodalchemist_recipe_categories AS k', 'k.id', '=', 'foodalchemist_recipes.kategorie_id')
            ->where('k.main_group_id', $mainGroupId)
            ->selectRaw('foodalchemist_recipes.kategorie_id, COUNT(*) AS n')
            ->groupBy('foodalchemist_recipes.kategorie_id')
            ->pluck('n', 'foodalchemist_recipes.kategorie_id')
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
            ->with('kategorie:id,main_group_id,bezeichnung')
            ->orderBy('foodalchemist_recipes.name')
            ->paginate($perPage)
            ->withQueryString();
    }

    /** Panel (M4-05): Detail inkl. Zutaten (GP-Links), Eignungen, Equipment. */
    public function detail(Team $team, int $id): ?FoodAlchemistRecipe
    {
        return FoodAlchemistRecipe::visibleToTeam($team)->basis()
            ->with([
                'kategorie:id,main_group_id,bezeichnung',
                'ingredients.gp:id,name,hauptzutat_slug,lead_la_supplier_item_id,stk_default_g', // Zeilen-EK braucht Lead+Stückgewicht (T3)
                'ingredients.einheit:id,slug,display_de,dimension,default_in_g,default_in_ml', // T1-Kaskade braucht die Faktoren
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
        $kategorieId = $in['kategorie_id'] ?? null;

        $key = $this->rezeptKey($name);
        if ($this->keyVergeben($team, $key)) {                     // §1.8: Kategorie als Diskriminator
            $kategorie = $kategorieId !== null
                ? \Platform\FoodAlchemist\Models\FoodAlchemistRecipeCategory::find($kategorieId)?->bezeichnung
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
            'herkunft' => ($in['herkunft'] ?? '') ?: null,
            'kategorie_id' => $kategorieId,
            'ist_verkaufsrezept' => (bool) ($in['ist_verkaufsrezept'] ?? false),
            'status' => 'draft',
            'geschmacksrichtung' => ($in['geschmacksrichtung'] ?? '') ?: null,
            'fertigungstiefe' => ($in['fertigungstiefe'] ?? '') ?: null,
            'arbeitszeit_min' => $in['arbeitszeit_min'] ?? null,
            'yield_kg_manual' => $in['yield_kg_manual'] ?? null,
            'beschreibung' => ($in['beschreibung'] ?? '') ?: null,
            'last_modified_by' => 'editor',
        ]);
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
            'herkunft' => array_key_exists('herkunft', $in) ? (($in['herkunft'] ?? '') ?: null) : $recipe->herkunft,
            'kategorie_id' => $in['kategorie_id'] ?? $recipe->kategorie_id,
            'geschmacksrichtung' => array_key_exists('geschmacksrichtung', $in) ? (($in['geschmacksrichtung'] ?? '') ?: null) : $recipe->geschmacksrichtung,
            'fertigungstiefe' => array_key_exists('fertigungstiefe', $in) ? (($in['fertigungstiefe'] ?? '') ?: null) : $recipe->fertigungstiefe,
            'arbeitszeit_min' => array_key_exists('arbeitszeit_min', $in) ? $in['arbeitszeit_min'] : $recipe->arbeitszeit_min,
            'yield_kg_manual' => array_key_exists('yield_kg_manual', $in) ? $in['yield_kg_manual'] : $recipe->yield_kg_manual,
            'beschreibung' => array_key_exists('beschreibung', $in) ? (($in['beschreibung'] ?? '') ?: null) : $recipe->beschreibung,
            'version' => $recipe->version + 1,
            'last_modified_by' => 'editor',
        ]);
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
                'kategorie_id' => $original->kategorie_id,
                'herkunft' => $original->herkunft,
                'geschmacksrichtung' => $original->geschmacksrichtung,
                'fertigungstiefe' => $original->fertigungstiefe,
                'ist_verkaufsrezept' => $original->ist_verkaufsrezept,
                'beschreibung' => $original->beschreibung,
            ]);
            foreach ($original->ingredients as $z) {
                $kopie->ingredients()->create([
                    ...$z->only(['position', 'gp_id', 'referenced_recipe_id', 'raw_text', 'display_name',
                        'menge', 'menge_max', 'einheit_vocab_id', 'putzverlust_pct', 'garverlust_pct',
                        'is_optional', 'klammer_note', 'note', 'match_method', 'match_confidence',
                        'rolle', 'ist_wertgebend', 'rechen_modus']),
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
            ->get(['id', 'name', 'status', 'team_id']);
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
                if ($subId !== null) {
                    if ($subId === $recipe->id) {
                        throw new \RuntimeException('Selbstreferenz — ein Rezept kann sich nicht selbst enthalten (GL-02 §3.5).');
                    }
                    $pruefung = app(RecipeRecomputeService::class)->pruefeVerknuepfung($recipe->id, $subId);
                    if (! $pruefung['erlaubt']) {
                        throw new \RuntimeException("Sub-Rezept-Verknüpfung abgelehnt: {$pruefung['grund']}.");
                    }
                }
                $menge = (float) str_replace(',', '.', (string) ($z['menge'] ?? 0));
                if ($menge <= 0) {
                    throw new \RuntimeException('Menge muss > 0 sein (Zeile ' . ($i + 1) . ').');
                }

                $attrs = [
                    'position' => $i + 1,                              // Reorder = Array-Reihenfolge
                    'gp_id' => $gpId,
                    'referenced_recipe_id' => $subId,
                    'raw_text' => trim((string) ($z['raw_text'] ?? '')) ?: ($z['display_name'] ?? 'Zutat'),
                    'display_name' => ($z['display_name'] ?? '') ?: null,
                    'menge' => $menge,
                    'menge_max' => ($z['menge_max'] ?? '') !== '' && $z['menge_max'] !== null ? (float) str_replace(',', '.', (string) $z['menge_max']) : null,
                    'einheit_vocab_id' => (int) $z['einheit_vocab_id'],
                    'garverlust_pct' => ($z['garverlust_pct'] ?? '') !== '' && $z['garverlust_pct'] !== null ? (float) str_replace(',', '.', (string) $z['garverlust_pct']) : null,
                    'putzverlust_pct' => ($z['putzverlust_pct'] ?? '') !== '' && ($z['putzverlust_pct'] ?? null) !== null ? (float) str_replace(',', '.', (string) $z['putzverlust_pct']) : null,
                    'is_optional' => (bool) ($z['is_optional'] ?? false),
                    'note' => ($z['note'] ?? '') ?: null,
                    'rolle' => ($z['rolle'] ?? '') ?: null,            // V-21
                    'ist_wertgebend' => (bool) ($z['ist_wertgebend'] ?? false),
                ];

                $id = ($z['id'] ?? null) !== null && $vorhanden->has((int) $z['id']) ? (int) $z['id'] : null;
                if ($id !== null) {
                    $vorhanden[$id]->update($attrs);
                    $behalten[] = $id;
                } else {
                    $neu = $recipe->ingredients()->create([...$attrs,
                        'team_id' => $team->id,
                        'match_method' => $subId !== null ? 'recipe_ref' : ($gpId !== null ? 'manual' : 'unmatched'),
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
        $q = '%' . mb_strtolower(trim($suche)) . '%';
        $recompute = app(RecipeRecomputeService::class);

        $gps = FoodAlchemistGp::visibleToTeam($team)
            ->whereRaw('LOWER(name) LIKE ?', [$q])
            ->orderBy('name')->limit($limit)
            ->get(['id', 'name', 'lead_la_supplier_item_id', 'stk_default_g', 'team_id'])
            ->map(fn ($gp) => [
                'typ' => 'gp', 'id' => $gp->id, 'name' => $gp->name,
                'ek_pro_g' => $recompute->preisProGrammPublic($gp),
            ]);

        $subs = FoodAlchemistRecipe::visibleToTeam($team)->basis()
            ->where('id', '!=', $ohneRecipeId)
            ->whereRaw('LOWER(name) LIKE ?', [$q])
            ->orderBy('name')->limit($limit)
            ->get(['id', 'name', 'ek_per_kg_eur'])
            ->map(fn ($r) => [
                'typ' => 'sub', 'id' => $r->id, 'name' => '↳ ' . $r->name,
                'ek_pro_g' => $r->ek_per_kg_eur !== null ? ((float) $r->ek_per_kg_eur) / 1000 : null,
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
                $such = mb_strtolower(trim($filters['search']));
                $q->where(fn (Builder $w) => $w
                    ->whereRaw('LOWER(foodalchemist_recipes.name) LIKE ?', ['%' . $such . '%'])
                    ->orWhereRaw('LOWER(foodalchemist_recipes.recipe_key) LIKE ?', ['%' . $such . '%']));
            })
            ->when(($filters['hauptgruppe'] ?? null) !== null && $filters['hauptgruppe'] !== '', fn (Builder $q) => $q
                ->whereIn('kategorie_id', DB::table('foodalchemist_recipe_categories')
                    ->where('main_group_id', (int) $filters['hauptgruppe'])->pluck('id')))
            ->when(($filters['kategorie'] ?? null) !== null && $filters['kategorie'] !== '', fn (Builder $q) => $q
                ->where('kategorie_id', (int) $filters['kategorie']))
            ->when(($filters['status'] ?? '') !== '', fn (Builder $q) => $q->where('status', $filters['status']))
            ->when(($filters['geschmack'] ?? '') !== '', fn (Builder $q) => $q->where('geschmacksrichtung', $filters['geschmack']))
            ->when(($filters['fertigung'] ?? '') !== '', fn (Builder $q) => $q->where('fertigungstiefe', $filters['fertigung']));
    }
}
