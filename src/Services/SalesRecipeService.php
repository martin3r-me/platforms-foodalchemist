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
                'ingredients.gp:id,name', 'ingredients.referencedRecipe:id,name',
            ])
            ->find($id);
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
