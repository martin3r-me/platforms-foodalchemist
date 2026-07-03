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
        return FoodAlchemistRecipe::visibleToTeam($team)->verkauf()
            ->with(['speisenKlasse:id,bezeichnung,dish_main_group_id', 'speisenKlasse.hauptgruppe:id,code,bezeichnung'])
            ->when(($filters['search'] ?? '') !== '', function ($q) use ($filters) {
                $s = '%' . mb_strtolower($filters['search']) . '%';
                $q->where(fn ($w) => $w
                    ->whereRaw('LOWER(name) LIKE ?', [$s])
                    ->orWhereRaw('LOWER(COALESCE(vk_wording_standard, \'\')) LIKE ?', [$s])
                    ->orWhereRaw('LOWER(COALESCE(marketing_text, \'\')) LIKE ?', [$s])
                    ->orWhereExists(fn ($e) => $e->from('foodalchemist_recipe_customer_names AS cn')
                        ->whereColumn('cn.recipe_id', 'foodalchemist_recipes.id')->whereNull('cn.deleted_at')
                        ->whereRaw('(LOWER(cn.customer_name) LIKE ? OR LOWER(cn.marketing_name) LIKE ?)', [$s, $s])));
            })
            ->when($filters['hauptgruppe'] ?? null, fn ($q, $hg) => $q
                ->whereIn('speisen_klasse_id', FoodAlchemistDishClass::where('dish_main_group_id', $hg)->pluck('id')))
            ->when($filters['klasse'] ?? null, fn ($q, $k) => $q->where('speisen_klasse_id', $k))
            ->when(($filters['status'] ?? '') !== '', fn ($q) => $q->where('status', $filters['status']))
            ->when(($filters['geschmack'] ?? '') !== '', fn ($q) => $q->where('geschmacksrichtung', $filters['geschmack']))
            ->orderBy('name')
            ->paginate($perPage);
    }

    /** 16 VK-Hauptgruppen mit Codes (aktive zuerst nach sort_order, dann Code). */
    public function dishMainGroups(Team $team): Collection
    {
        return FoodAlchemistDishMainGroup::visibleToTeam($team)
            ->where('is_inactive', false)->orderBy('sort_order')->orderBy('code')->get();
    }

    /** @return array<int, int> recipe-Counts je VK-Hauptgruppe (über die Klassen) */
    public function hauptgruppenCounts(Team $team): array
    {
        return FoodAlchemistRecipe::visibleToTeam($team)->verkauf()
            ->join('foodalchemist_dish_classes AS dc', 'dc.id', '=', 'foodalchemist_recipes.speisen_klasse_id')
            ->whereNotNull('dc.dish_main_group_id')
            ->groupBy('dc.dish_main_group_id')
            ->pluck(DB::raw('COUNT(*) AS n'), 'dc.dish_main_group_id')
            ->map(fn ($n) => (int) $n)->all();
    }

    /** @return array<int, int> recipe-Counts je Klasse einer HG */
    public function klassenCounts(Team $team, int $hauptgruppeId): array
    {
        return FoodAlchemistRecipe::visibleToTeam($team)->verkauf()
            ->join('foodalchemist_dish_classes AS dc', 'dc.id', '=', 'foodalchemist_recipes.speisen_klasse_id')
            ->where('dc.dish_main_group_id', $hauptgruppeId)
            ->groupBy('foodalchemist_recipes.speisen_klasse_id')
            ->pluck(DB::raw('COUNT(*) AS n'), 'foodalchemist_recipes.speisen_klasse_id')
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
                'speisenKlasse:id,bezeichnung,diaetform,dish_main_group_id',
                'speisenKlasse.hauptgruppe:id,code,bezeichnung',
                'aufschlagsklasse',
                'vkEinheit:id,slug,display_de',
                'ingredients' => fn ($q) => $q->whereNull('deleted_at')->orderBy('position'),
                // M9-01e: Bio-/Regional-Anteil braucht die GP-Tags; Nährwert-Faktor die Einheit
                'ingredients.gp:id,name,is_organic,is_regional', 'ingredients.referencedRecipe:id,name',
                'ingredients.einheit:id,slug,display_de,default_in_g,default_in_ml',
            ])
            ->find($id);
    }

    // ── M6-04: Editor-Schreibpfade (V-07: Mehr-Zeilen-Writes in Transaktionen) ──

    /** Erlaubte VK-Feldgruppen (V-12: Policy-Grenze mitten durchs geteilte Modell). */
    private const VK_FELDER = [
        'name', 'vk_wording_standard', 'speisen_klasse_id', 'aufschlagsklasse_id', 'mwst_satz',
        'vk_netto', 'vk_einheit_vocab_id', 'vk_anzahl_einheiten', 'vk_menge_pro_einheit_g',
        'behaelter_warm_vocab_id', 'behaelter_warm_anzahl', 'behaelter_kalt_vocab_id', 'behaelter_kalt_anzahl',
        'servier_vehikel_vocab_id', 'geschmacksrichtung',
        // M9-01: Voll-Editor-Parität — Eigenschaften, Texte, Plating, Notizen
        'marketing_text', 'beschreibung', 'arbeitszeit_min', 'temperatur', 'funktion',
        'fertigungstiefe', 'plating_text', 'notizen_manual',
        'nebenkosten_eur',                                            // M12: Energie/Nebenkosten je Charge (HK2)
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
            foreach (['vk_wording_standard' => 'vk_wording', 'marketing_text' => 'marketing_text', 'plating_text' => 'plating'] as $feld => $praefix) {
                if (array_key_exists($feld, $update) && $update[$feld] !== $recipe->{$feld}) {
                    $update["{$praefix}_quelle"] = 'manual';
                    $update["{$praefix}_ai_confidence"] = null;
                }
            }
            // brutto konsistent halten, wenn netto/mwst manuell gesetzt werden (User-Hoheit, I9)
            $netto = array_key_exists('vk_netto', $update) ? $update['vk_netto'] : $recipe->vk_netto;
            $mwst = array_key_exists('mwst_satz', $update) ? $update['mwst_satz'] : $recipe->mwst_satz;
            if ($netto !== null && $mwst !== null) {
                $update['vk_brutto'] = round((float) $netto * (1 + (float) $mwst / 100), 2);
            } elseif ($netto === null) {
                $update['vk_brutto'] = null;
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
                'menge_pro_einheit_g' => $update['vk_menge_pro_einheit_g'] ?? $recipe->vk_menge_pro_einheit_g,
                'einheit_vocab_id' => $update['vk_einheit_vocab_id'] ?? $recipe->vk_einheit_vocab_id,
                'anzahl_einheiten' => $update['vk_anzahl_einheiten'] ?? $recipe->vk_anzahl_einheiten,
                'aufschlagsklasse_id' => $update['aufschlagsklasse_id'] ?? $recipe->aufschlagsklasse_id,
            ], 'fa_ui');
        }
        $map = [
            'vk_menge_pro_einheit_g' => 'menge_pro_einheit_g',
            'vk_einheit_vocab_id' => 'einheit_vocab_id',
            'vk_anzahl_einheiten' => 'anzahl_einheiten',
            'aufschlagsklasse_id' => 'aufschlagsklasse_id',
            'behaelter_warm_vocab_id' => 'behaelter_warm_vocab_id',
            'behaelter_kalt_vocab_id' => 'behaelter_kalt_vocab_id',
            'servier_vehikel_vocab_id' => 'servier_vehikel_vocab_id',
        ];
        $dUpdate = [];
        foreach ($map as $von => $nach) {
            if (array_key_exists($von, $update)) {
                $dUpdate[$nach] = $update[$von];
            }
        }
        if (array_key_exists('vk_netto', $update)) {
            $dUpdate['vk_netto'] = $update['vk_netto'];
            $dUpdate['preis_modus'] = $update['vk_netto'] !== null ? 'manuell' : 'auto';
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
            $vk = $recipes->create($team, ['name' => $name, 'ist_verkaufsrezept' => true]);
            $gramm = $basis->yield_kg !== null ? round((float) $basis->yield_kg * 1000, 1) : 1000.0;
            $einheitG = \Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit::visibleToTeam($team)->where('slug', 'g')->value('id');

            return $recipes->syncIngredients($team, $vk->id, [[
                'raw_text' => $basis->name,
                'display_name' => $basis->name,
                'menge' => $gramm,
                'einheit_vocab_id' => $einheitG,
                'referenced_recipe_id' => $basis->id,
                'match_method' => 'recipe_ref',
            ]]);
        });
    }

    /** Leeres Verkaufsrezept (Gericht) ohne erste Komponente — Komponenten/Stück-Basisrezepte kommen im Editor dazu. */
    public function createLeer(Team $team, string $name): FoodAlchemistRecipe
    {
        return app(RecipeService::class)->create($team, ['name' => $name, 'ist_verkaufsrezept' => true]);
    }

    // V-19: Regen-Programme (zeilenbasiert)

    public function upsertRegeneration(Team $team, int $recipeId, array $in, ?int $id = null): void
    {
        $recipe = FoodAlchemistRecipe::visibleToTeam($team)->verkauf()->findOrFail($recipeId);
        $werte = [
            'komponente_label' => trim((string) ($in['komponente_label'] ?? '')) ?: 'Gesamt',
            'geraet_vocab_id' => $in['geraet_vocab_id'] ?? null,
            'temp_c' => $in['temp_c'] ?? null,
            'dauer_min' => $in['dauer_min'] ?? null,
            'kerntemp_c' => $in['kerntemp_c'] ?? null,
            'hinweis' => $in['hinweis'] ?? null,
            'quelle' => 'manual', 'ai_confidence' => null, 'ai_begruendung' => null,      // manual gewinnt (GL-07)
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
        $anzahl = $r->vk_anzahl_einheiten !== null ? (int) $r->vk_anzahl_einheiten : null;
        $mengeProEinheitG = $r->vk_menge_pro_einheit_g !== null
            ? (float) $r->vk_menge_pro_einheit_g
            : ($r->yield_kg !== null && $anzahl !== null && $anzahl > 0 ? round((float) $r->yield_kg * 1000 / $anzahl, 1) : null);

        $verkauftAls = $anzahl !== null || $mengeProEinheitG !== null ? [
            'anzahl' => $anzahl,
            'einheit' => $r->vkEinheit?->display_de ?? $r->vkEinheit?->slug ?? 'Einheit',
            'g_pro_einheit' => $mengeProEinheitG,
            'yield_kg' => $r->yield_kg !== null ? (float) $r->yield_kg : null,
        ] : null;

        $formelFehlt = false;
        $vk = ['vk_netto' => null, 'quelle' => 'leer', 'vorschlag' => null];
        try {
            $vk = $this->marge->effektiverVk(
                $r->vk_netto !== null ? (float) $r->vk_netto : null,
                $r->ek_per_kg_eur !== null ? (float) $r->ek_per_kg_eur : null,
                $mengeProEinheitG,
                $r->aufschlagsklasse,
                $r->mwst_satz !== null ? (float) $r->mwst_satz : null,
            );
        } catch (\Platform\FoodAlchemist\Exceptions\FormelNichtDefiniertException) {
            $formelFehlt = true;                                     // W-1: UI kennzeichnet, kein Crash
            if ($r->vk_netto !== null) {
                $vk = ['vk_netto' => (float) $r->vk_netto, 'quelle' => 'manuell', 'vorschlag' => null];
            }
        }

        $mwst = $r->mwst_satz !== null ? (float) $r->mwst_satz : (float) ($r->aufschlagsklasse->mwst_satz ?? 19);

        return [
            'verkauft_als' => $verkauftAls,
            'vk' => $vk,
            'vk_brutto' => $vk['vk_netto'] !== null ? round($vk['vk_netto'] * (1 + $mwst / 100), 2) : null,
            'mwst_satz' => $mwst,
            'marge' => $this->marge->marge($vk['vk_netto'], $r->ek_total_eur !== null ? (float) $r->ek_total_eur : null),
            'pro_einheit' => $this->marge->proEinheit($vk['vk_netto'], $anzahl, $mwst),
            'formel_fehlt' => $formelFehlt,
        ];
    }
}
