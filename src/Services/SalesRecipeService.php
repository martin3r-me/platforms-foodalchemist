<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistDishClass;
use Platform\FoodAlchemist\Models\FoodAlchemistDishMainGroup;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;

/**
 * M6-03 / D-6 §3.1: VK-Sicht aufs geteilte Rezept-Modell — erzwingt den
 * `verkauf()`-Scope in JEDER Query (Scope-Härte §7.8). Aggregate kommen aus
 * der D-5-Recompute-Pipeline; VK-Mathematik ausschließlich via MargeService.
 * Suche greift auch über Marketing-Namen + Kunden-Wordings (§4.1).
 */
class SalesRecipeService
{
    public function __construct(private MargeService $marge)
    {
    }

    public function paginateBrowser(array $filters, Team $team, int $perPage = 100): LengthAwarePaginator
    {
        // Modell A (Regelwerk_Verkaufsgerichte v1.1): HG = Kategorie (recipes.dish_main_group_id),
        // Klasse = Diätform (recipes.dish_class_id) — beide Achsen unabhängig filterbar.
        return FoodAlchemistRecipe::visibleToTeam($team)->verkauf()
            ->with(['speisenKlasse:id,label,diet_form', 'speisenHauptgruppe:id,code,label'])
            ->when(($filters['search'] ?? '') !== '', function ($q) use ($filters) {
                // Multi-Wort: jedes Token muss treffen (Name / Standard-Wording / Marketing /
                // Kunden-Wording). §4.1 — Treffer dürfen über die Felder verteilt sein.
                foreach (\Platform\FoodAlchemist\Support\Suche::tokens($filters['search']) as $token) {
                    $t = '%' . $token . '%';
                    $q->where(fn ($w) => $w
                        ->whereRaw('LOWER(name) LIKE ?', [$t])
                        ->orWhereRaw('LOWER(COALESCE(sales_wording_standard, \'\')) LIKE ?', [$t])
                        ->orWhereRaw('LOWER(COALESCE(marketing_text, \'\')) LIKE ?', [$t])
                        ->orWhereExists(fn ($e) => $e->from('foodalchemist_recipe_customer_names AS cn')
                            ->whereColumn('cn.recipe_id', 'foodalchemist_recipes.id')->whereNull('cn.deleted_at')
                            ->whereRaw('(LOWER(cn.customer_name) LIKE ? OR LOWER(cn.marketing_name) LIKE ?)', [$t, $t])));
                }
            })
            ->when($filters['hauptgruppe'] ?? null, fn ($q, $hg) => $q->where('dish_main_group_id', $hg))
            ->when($filters['class'] ?? null, fn ($q, $k) => $q->where('dish_class_id', $k))
            ->when(($filters['status'] ?? '') !== '', fn ($q) => $q->where('status', $filters['status']))
            ->when(($filters['geschmack'] ?? '') !== '', fn ($q) => $q->where('taste_direction', $filters['geschmack']))
            ->orderBy('name')
            ->paginate($perPage);
    }

    /** 16 VK-Hauptgruppen mit Codes (aktive zuerst nach sort_order, dann Code). */
    public function dishMainGroups(Team $team): Collection
    {
        return FoodAlchemistDishMainGroup::visibleToTeam($team)
            ->where('is_inactive', false)->orderBy('sort_order')->orderBy('code')->get();
    }

    /** @return array<int, int> recipe-Counts je VK-Hauptgruppe (Modell A: direkt über dish_main_group_id) */
    public function hauptgruppenCounts(Team $team): array
    {
        return FoodAlchemistRecipe::visibleToTeam($team)->verkauf()
            ->whereNotNull('dish_main_group_id')
            ->groupBy('dish_main_group_id')
            ->pluck(DB::raw('COUNT(*) AS n'), 'dish_main_group_id')
            ->map(fn ($n) => (int) $n)->all();
    }

    /** @return array<int, int> recipe-Counts je Diät-Klasse (Modell A: global, 4 flache Klassen) */
    public function klassenCounts(Team $team): array
    {
        return FoodAlchemistRecipe::visibleToTeam($team)->verkauf()
            ->whereNotNull('dish_class_id')
            ->groupBy('dish_class_id')
            ->pluck(DB::raw('COUNT(*) AS n'), 'dish_class_id')
            ->map(fn ($n) => (int) $n)->all();
    }

    /** @return array<string, int> */
    public function statusCounts(Team $team): array
    {
        return FoodAlchemistRecipe::visibleToTeam($team)->verkauf()
            ->groupBy('status')->pluck(DB::raw('COUNT(*) AS n'), 'status')->map(fn ($n) => (int) $n)->all();
    }

    /** Detail STRIKT im verkauf()-Scope (Basisrezepte liefern null — §7.8). */
    public function detail(Team $team, int $id): ?FoodAlchemistRecipe
    {
        return FoodAlchemistRecipe::visibleToTeam($team)->verkauf()
            ->with([
                'speisenKlasse:id,label,diaetform,dish_main_group_id',
                'speisenKlasse.hauptgruppe:id,code,label',
                'aufschlagsklasse',
                'vkEinheit:id,slug,display_de',
                'ingredients' => fn ($q) => $q->whereNull('deleted_at')->orderBy('position'),
                // M9-01e: Bio-/Regional-Anteil braucht die GP-Tags; Nährwert-Faktor die Einheit
                'ingredients.gp:id,name,is_organic,is_regional', 'ingredients.referencedRecipe:id,name',
                'ingredients.unit:id,slug,display_de,default_in_g,default_in_ml',
            ])
            ->find($id);
    }

    // ── M6-04: Editor-Schreibpfade (V-07: Mehr-Zeilen-Writes in Transaktionen) ──

    /** Erlaubte VK-Feldgruppen (V-12: Policy-Grenze mitten durchs geteilte Modell). */
    private const VK_FELDER = [
        'name', 'sales_wording_standard', 'dish_class_id', 'markup_class_id', 'vat_rate',
        'sales_net', 'sales_unit_vocab_id', 'sales_unit_count', 'sales_quantity_per_unit_g',
        'container_warm_vocab_id', 'container_warm_count', 'container_cold_vocab_id', 'container_cold_count',
        'serving_vehicle_vocab_id', 'taste_direction',
        // M9-01: Voll-Editor-Parität — Eigenschaften, Texte, Plating, Notizen
        'marketing_text', 'description', 'work_time_min', 'temperature', 'function',
        'production_depth', 'plating_text', 'notes_manual',
        'additional_costs_eur',                                            // M12: Energie/Nebenkosten je Charge (HK2)
    ];

    public function updateVk(Team $team, int $id, array $in): FoodAlchemistRecipe
    {
        $recipe = FoodAlchemistRecipe::visibleToTeam($team)->verkauf()->findOrFail($id);
        if (! $recipe->isOwnedBy($team)) {
            throw new \RuntimeException('Geerbtes Rezept — VK-Pflege nur durchs Besitzer-Team (D1).');
        }

        return DB::transaction(function () use ($team, $recipe, $in) {
            $update = array_intersect_key($in, array_flip(self::VK_FELDER));
            // Wording/Marketing/Plating manuell editiert → Lineage auf manual (GL-07)
            foreach (['sales_wording_standard' => 'vk_wording', 'marketing_text' => 'marketing_text', 'plating_text' => 'plating'] as $feld => $praefix) {
                if (array_key_exists($feld, $update) && $update[$feld] !== $recipe->{$feld}) {
                    $update["{$praefix}_quelle"] = 'manual';
                    $update["{$praefix}_ai_confidence"] = null;
                }
            }
            // brutto konsistent halten, wenn netto/mwst manuell gesetzt werden (User-Hoheit, I9)
            $netto = array_key_exists('sales_net', $update) ? $update['sales_net'] : $recipe->sales_net;
            $mwst = array_key_exists('vat_rate', $update) ? $update['vat_rate'] : $recipe->vat_rate;
            if ($netto !== null && $mwst !== null) {
                $update['sales_gross'] = round((float) $netto * (1 + (float) $mwst / 100), 2);
            } elseif ($netto === null) {
                $update['sales_gross'] = null;
            }
            $update['last_modified_by'] = 'vk_editor';
            $recipe->update($update);

            // Umbau-Spec Phase 5: Standard-Darreichung synchron halten — Preis-Wahrheit
            // liegt an der Darreichung, die Legacy-Spalten sind Anzeige-/Kompat-Schicht.
            $this->syncStandardDarreichung($team, $recipe->refresh(), $update);

            return $recipe->refresh();
        });
    }

    /** VK-Felder des Legacy-Editors in die Standard-Darreichung spiegeln (eine Wahrheit). */
    private function syncStandardDarreichung(Team $team, FoodAlchemistRecipe $recipe, array $update): void
    {
        $standard = $recipe->standardDarreichung()->first();
        if ($standard === null && $recipe->darreichungen()->exists()) {
            return; // Varianten ohne Standard-Flag: nichts raten
        }
        if ($standard === null) {
            // Selbstheilung: VK-Gericht ohne Darreichung (z. B. createFromBasis-Altbestand)
            $unbestimmt = \Platform\FoodAlchemist\Models\FoodAlchemistServierform::where('code', 'unbestimmt')->value('id');
            if ($unbestimmt === null) {
                return;
            }
            $standard = app(DarreichungService::class)->anlegen($team, $recipe->id, (int) $unbestimmt, [
                'quantity_per_unit_g' => $update['sales_quantity_per_unit_g'] ?? $recipe->sales_quantity_per_unit_g,
                'unit_vocab_id' => $update['sales_unit_vocab_id'] ?? $recipe->sales_unit_vocab_id,
                'unit_count' => $update['sales_unit_count'] ?? $recipe->sales_unit_count,
                'markup_class_id' => $update['markup_class_id'] ?? $recipe->markup_class_id,
            ], 'fa_ui');
        }
        $map = [
            'sales_quantity_per_unit_g' => 'quantity_per_unit_g',
            'sales_unit_vocab_id' => 'unit_vocab_id',
            'sales_unit_count' => 'unit_count',
            'markup_class_id' => 'markup_class_id',
            'container_warm_vocab_id' => 'container_warm_vocab_id',
            'container_cold_vocab_id' => 'container_cold_vocab_id',
            'serving_vehicle_vocab_id' => 'serving_vehicle_vocab_id',
        ];
        $dUpdate = [];
        foreach ($map as $von => $nach) {
            if (array_key_exists($von, $update)) {
                $dUpdate[$nach] = $update[$von];
            }
        }
        if (array_key_exists('sales_net', $update)) {
            $dUpdate['sales_net'] = $update['sales_net'];
            $dUpdate['price_mode'] = $update['sales_net'] !== null ? 'manuell' : 'auto';
        }
        if ($dUpdate !== []) {
            app(DarreichungService::class)->aktualisieren($team, $standard->id, $dUpdate);
        }
    }

    /**
     * DoD M6-04: VK anlegen AUS Basisrezept — neues VK-Rezept mit dem
     * Basisrezept als erster Komponente (eine Charge = yield in g), danach
     * GL-02-Recompute über den D-5-Sync (eine Regel-Stelle).
     */
    public function createFromBasis(Team $team, int $basisRecipeId, string $name): FoodAlchemistRecipe
    {
        $basis = FoodAlchemistRecipe::visibleToTeam($team)->basis()->findOrFail($basisRecipeId);
        $recipes = app(RecipeService::class);

        return DB::transaction(function () use ($team, $basis, $name, $recipes) {
            $vk = $recipes->create($team, ['name' => $name, 'is_sales_recipe' => true]);
            $gramm = $basis->yield_kg !== null ? round((float) $basis->yield_kg * 1000, 1) : 1000.0;
            $einheitG = \Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit::visibleToTeam($team)->where('slug', 'g')->value('id');

            return $recipes->syncIngredients($team, $vk->id, [[
                'raw_text' => $basis->name,
                'display_name' => $basis->name,
                'quantity' => $gramm,
                'unit_vocab_id' => $einheitG,
                'referenced_recipe_id' => $basis->id,
                'match_method' => 'recipe_ref',
            ]]);
        });
    }

    /** Leeres Verkaufsrezept (Gericht) ohne erste Komponente — Komponenten/Stück-Basisrezepte kommen im Editor dazu. */
    public function createLeer(Team $team, string $name): FoodAlchemistRecipe
    {
        return app(RecipeService::class)->create($team, ['name' => $name, 'is_sales_recipe' => true]);
    }

    // V-19: Regen-Programme (zeilenbasiert)

    public function upsertRegeneration(Team $team, int $recipeId, array $in, ?int $id = null): void
    {
        $recipe = FoodAlchemistRecipe::visibleToTeam($team)->verkauf()->findOrFail($recipeId);
        $werte = [
            'component_label' => trim((string) ($in['component_label'] ?? '')) ?: 'Gesamt',
            'device_vocab_id' => $in['device_vocab_id'] ?? null,
            'temp_c' => $in['temp_c'] ?? null,
            'duration_min' => $in['duration_min'] ?? null,
            'core_temp_c' => $in['core_temp_c'] ?? null,
            'note' => $in['note'] ?? null,
            'source' => 'manual', 'ai_confidence' => null, 'ai_reasoning' => null,      // manual gewinnt (GL-07)
            'updated_at' => now(),
        ];
        if ($id !== null) {
            DB::table('foodalchemist_recipe_regenerations')->where('id', $id)->where('recipe_id', $recipe->id)->update($werte);

            return;
        }
        DB::table('foodalchemist_recipe_regenerations')->insert($werte + [
            'uuid' => (string) \Symfony\Component\Uid\UuidV7::generate(),
            'team_id' => $recipe->team_id,
            'recipe_id' => $recipe->id,
            'sort_order' => (int) DB::table('foodalchemist_recipe_regenerations')
                ->where('recipe_id', $recipe->id)->whereNull('deleted_at')->max('sort_order') + 1,
            'created_at' => now(),
        ]);
    }

    public function deleteRegeneration(Team $team, int $recipeId, int $id): void
    {
        FoodAlchemistRecipe::visibleToTeam($team)->verkauf()->findOrFail($recipeId);
        DB::table('foodalchemist_recipe_regenerations')->where('id', $id)->where('recipe_id', $recipeId)
            ->update(['deleted_at' => now()]);
    }

    /** @param list<int> $ids neue Reihenfolge */
    public function reorderRegenerations(Team $team, int $recipeId, array $ids): void
    {
        FoodAlchemistRecipe::visibleToTeam($team)->verkauf()->findOrFail($recipeId);
        DB::transaction(function () use ($recipeId, $ids) {
            foreach (array_values($ids) as $i => $id) {
                DB::table('foodalchemist_recipe_regenerations')
                    ->where('id', (int) $id)->where('recipe_id', $recipeId)->update(['sort_order' => $i]);
            }
        });
    }

    // Verwendungsnachweise (Kunde × Marketing-Name, team-eigen)

    public function addCustomerName(Team $team, int $recipeId, string $kunde, string $marketingName, ?string $note = null): void
    {
        $recipe = FoodAlchemistRecipe::visibleToTeam($team)->verkauf()->findOrFail($recipeId);
        DB::table('foodalchemist_recipe_customer_names')->updateOrInsert(
            ['recipe_id' => $recipe->id, 'customer_name' => trim($kunde)],
            ['uuid' => (string) \Symfony\Component\Uid\UuidV7::generate(), 'team_id' => $team->id,
                'marketing_name' => trim($marketingName), 'note' => $note, 'deleted_at' => null,
                'updated_at' => now(), 'created_at' => now()],
        );
    }

    public function deleteCustomerName(Team $team, int $recipeId, int $id): void
    {
        FoodAlchemistRecipe::visibleToTeam($team)->verkauf()->findOrFail($recipeId);
        DB::table('foodalchemist_recipe_customer_names')->where('id', $id)->where('recipe_id', $recipeId)
            ->update(['deleted_at' => now()]);
    }

    /**
     * VK-Layer lösen (D-6): entfernt NUR das Verkaufsgericht selbst — die referenzierten
     * Basisrezepte und GPs bleiben unangetastet (sie sind eigene Datensätze, das Gericht
     * hält nur Zutaten-Verweise darauf). Gelöscht werden die recipe-Row (verkauf-Scope),
     * ihre Zutaten-Verweise und die VK-Facetten (Darreichungen inkl. Deltas, Kunden-Wordings,
     * Regenerationen, Niveau-/Sektor-Eignungen). Alles Soft-Delete in einer Transaktion.
     *
     * Guard: hängt das Gericht noch in einem Foodbook-Block, Concept-Slot oder Speiseplan-
     * Eintrag, wird abgebrochen (kein stilles Verwaisen) — dort erst lösen.
     */
    public function deleteDish(Team $team, int $recipeId): void
    {
        $recipe = FoodAlchemistRecipe::visibleToTeam($team)->verkauf()->findOrFail($recipeId);
        if ((int) $recipe->team_id !== (int) $team->id) {
            throw new \RuntimeException('Geerbtes Gericht — Löschen nur durchs Besitzer-Team (D1).');
        }

        // Referenz-Guard — Schema::hasTable-gesichert, weil nicht jede Umgebung alle Module
        // ausgerollt hat (z. B. Speiseplan-Tabellen fehlen auf der aktuellen Master-DB).
        $refs = [];
        foreach ([
            [\Platform\FoodAlchemist\Models\FoodAlchemistFoodbookBlock::class, 'Foodbook'],
            [\Platform\FoodAlchemist\Models\FoodAlchemistConceptSlot::class, 'Konzept'],
            [\Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplanEintrag::class, 'Speiseplan'],
        ] as [$model, $label]) {
            $table = (new $model)->getTable();
            if (! \Illuminate\Support\Facades\Schema::hasTable($table)) {
                continue;
            }
            if (($n = $model::where('sales_recipe_id', $recipe->id)->count()) > 0) {
                $refs[] = "{$n}× {$label}";
            }
        }
        if ($refs !== []) {
            throw new \RuntimeException('Gericht wird noch verwendet (' . implode(', ', $refs) . ') — dort erst entfernen.');
        }

        DB::transaction(function () use ($recipe) {
            $darIds = $recipe->darreichungen()->pluck('id');
            if ($darIds->isNotEmpty()) {
                \Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichungDelta::whereIn('presentation_id', $darIds)->delete();
            }
            $recipe->darreichungen()->delete();
            $recipe->customerNames()->delete();
            $recipe->regenerations()->delete();
            $recipe->niveauEignungen()->delete();
            $recipe->sektorEignungen()->delete();
            $recipe->ingredients()->delete();
            $recipe->delete();
        });
    }

    /** @return list<string> Autocomplete, team-scoped (§7.7) */
    public function distinctCustomerNames(Team $team): array
    {
        return DB::table('foodalchemist_recipe_customer_names')
            ->where('team_id', $team->id)->whereNull('deleted_at')
            ->distinct()->orderBy('customer_name')->pluck('customer_name')->all();
    }

    /**
     * VERKAUFT-ALS-Box + Marge-Cockpit in einem Aufruf (alles abgeleitet, GL-02 I9):
     * g/Einheit = Primär-Eingabe oder aus Yield/Anzahl; VK-Daten via MargeService.
     *
     * @return array{verkauft_als: ?array, vk: array, marge: ?array, pro_einheit: ?array, formel_fehlt: bool}
     */
    public function cockpit(FoodAlchemistRecipe $r): array
    {
        $anzahl = $r->sales_unit_count !== null ? (int) $r->sales_unit_count : null;
        $mengeProEinheitG = $r->sales_quantity_per_unit_g !== null
            ? (float) $r->sales_quantity_per_unit_g
            : ($r->yield_kg !== null && $anzahl !== null && $anzahl > 0 ? round((float) $r->yield_kg * 1000 / $anzahl, 1) : null);

        $verkauftAls = $anzahl !== null || $mengeProEinheitG !== null ? [
            'anzahl' => $anzahl,
            'unit' => $r->vkEinheit?->display_de ?? $r->vkEinheit?->slug ?? 'Einheit',
            'g_pro_einheit' => $mengeProEinheitG,
            'yield_kg' => $r->yield_kg !== null ? (float) $r->yield_kg : null,
        ] : null;

        $formelFehlt = false;
        $vk = ['sales_net' => null, 'source' => 'leer', 'vorschlag' => null];
        try {
            $vk = $this->marge->effektiverVk(
                $r->sales_net !== null ? (float) $r->sales_net : null,
                $r->ek_per_kg_eur !== null ? (float) $r->ek_per_kg_eur : null,
                $mengeProEinheitG,
                $r->aufschlagsklasse,
                $r->vat_rate !== null ? (float) $r->vat_rate : null,
            );
        } catch (\Platform\FoodAlchemist\Exceptions\FormelNichtDefiniertException) {
            $formelFehlt = true;                                     // W-1: UI kennzeichnet, kein Crash
            if ($r->sales_net !== null) {
                $vk = ['sales_net' => (float) $r->sales_net, 'source' => 'manuell', 'vorschlag' => null];
            }
        }

        $mwst = $r->vat_rate !== null ? (float) $r->vat_rate : (float) ($r->aufschlagsklasse->vat_rate ?? 19);

        return [
            'verkauft_als' => $verkauftAls,
            'vk' => $vk,
            'sales_gross' => $vk['sales_net'] !== null ? round($vk['sales_net'] * (1 + $mwst / 100), 2) : null,
            'vat_rate' => $mwst,
            'marge' => $this->marge->marge($vk['sales_net'], $r->ek_total_eur !== null ? (float) $r->ek_total_eur : null),
            'pro_einheit' => $this->marge->proEinheit($vk['sales_net'], $anzahl, $mwst),
            'formel_fehlt' => $formelFehlt,
        ];
    }
}
