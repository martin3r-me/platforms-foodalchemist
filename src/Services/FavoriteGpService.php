<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Support\TeamScope;

/**
 * 06·H2 — Kuratierung der Favoriten-GPs (kuratierter Haus-Standard, opt-in
 * KI-Baustein). Hybrid: Auto-Score schlägt vor (Rangliste), Mensch pinnt/excludet.
 * Alle Mutationen laufen hier (kein Roh-SQL für Schreibwege).
 *
 * 2026-07-20 (Dominique): Pool auf JEDEN approved GP geöffnet (früher nur
 * Convenience-getaggte, §4). Convenience bleibt ein GP-Tag — der Generator kann
 * die Favoriten optional darauf verengen (Favoriten ∩ tag_is_convenience).
 *
 * Auto-Score je GP (Spec §5): Verwendungshäufigkeit × Lieferanten-Priorität
 * (Lead-LA-Supplier in der Prioritätskette) × Lead-LA-Vollständigkeit.
 * Gewichte bewusst konservativ; am realen Bestand nachkalibrierbar.
 */
class FavoriteGpService
{
    /** Gewichte des Auto-Scores (Spec §10: am Bestand kalibrieren). */
    public const W_USAGE = 2.0;      // je Rezept-Verwendung

    public const W_LEAD = 3.0;       // Lead-LA gesetzt (Vollständigkeit)

    public const W_PRICE = 2.0;      // Lead-LA löst auf einen Preis auf

    public const W_PRIORITY_MAX = 5.0; // Bonus, wenn Lead-Supplier ganz oben in der Prioritätskette

    public function __construct(private readonly TeamSettingsService $settings) {}

    /**
     * Auto-Score-Rangliste aller approved GPs, bereits gepinnte oben markiert.
     * Absteigend nach Score. `$search` (Name-LIKE) + `$pinnedOnly` filtern
     * server-seitig — gepinnte Favoriten sind IMMER dabei (sonst wären
     * niedrig gescorte Favoriten unauffindbar).
     *
     * @return Collection<int, array{gp_id:int, name:string, usage:int, has_lead_la:bool, has_price:bool, priority_pos:?int, score:float, is_favorite:bool, is_convenience:bool, favorite_rank:?int}>
     */
    public function suggest(?Team $team, int $limit = 300, ?string $search = null, bool $pinnedOnly = false): Collection
    {
        $prioritaeten = $team !== null ? $this->settings->leadLaPrioritaeten($team) : [];
        $prioPos = array_flip(array_values(array_map('intval', $prioritaeten))); // supplier_id => index

        $suche = $search !== null ? trim($search) : '';
        $rows = $this->favoritesBaseQuery($team)
            ->when($suche !== '', fn ($q) => $q->where('g.name', 'like', '%' . $suche . '%'))
            // gepinnt IMMER mitnehmen (auch bei Suche/Score-Cap) — via OR-Gruppe
            ->when($pinnedOnly, fn ($q) => $q->where('g.is_favorite', true))
            ->select([
                'g.id', 'g.name', 'g.lead_la_supplier_item_id', 'g.is_favorite', 'g.favorite_rank', 'g.tag_is_convenience',
            ])
            ->selectSub(
                DB::table('foodalchemist_recipe_ingredients')
                    ->whereColumn('gp_id', 'g.id')->whereNull('deleted_at')
                    ->selectRaw('COUNT(*)'),
                'usage',
            )
            ->selectSub(
                DB::table('foodalchemist_supplier_items AS li')
                    ->whereColumn('li.id', 'g.lead_la_supplier_item_id')->whereNull('li.deleted_at')
                    ->selectRaw('li.supplier_id'),
                'lead_supplier_id',
            )
            ->selectSub(
                DB::table('foodalchemist_prices AS p')
                    ->whereColumn('p.supplier_item_id', 'g.lead_la_supplier_item_id')->whereNull('p.deleted_at')
                    ->selectRaw('COUNT(*)'),
                'price_rows',
            )
            ->get();

        $mapped = $rows->map(function ($r) use ($prioPos) {
            $usage = (int) $r->usage;
            $hasLead = $r->lead_la_supplier_item_id !== null;
            $hasPrice = ((int) $r->price_rows) > 0;
            $priorityPos = ($r->lead_supplier_id !== null && isset($prioPos[(int) $r->lead_supplier_id]))
                ? (int) $prioPos[(int) $r->lead_supplier_id]
                : null;

            $priorityBonus = $priorityPos !== null
                ? max(0.0, self::W_PRIORITY_MAX - $priorityPos)
                : 0.0;

            $score = $usage * self::W_USAGE
                + ($hasLead ? self::W_LEAD : 0.0)
                + ($hasPrice ? self::W_PRICE : 0.0)
                + $priorityBonus;

            return [
                'gp_id' => (int) $r->id,
                'name' => (string) $r->name,
                'usage' => $usage,
                'has_lead_la' => $hasLead,
                'has_price' => $hasPrice,
                'priority_pos' => $priorityPos,
                'score' => round($score, 2),
                'is_favorite' => (bool) $r->is_favorite,
                'is_convenience' => (bool) $r->tag_is_convenience,
                'favorite_rank' => $r->favorite_rank !== null ? (int) $r->favorite_rank : null,
            ];
        });

        // Score-Cap (der Pool ist der ganze approved-Bestand → Rangliste, nicht alles),
        // aber gepinnte Favoriten IMMER behalten (sonst fällt ein niedrig gescorter
        // Favorit aus der Liste und wäre nicht mehr entfernbar).
        $pinned = $mapped->where('is_favorite', true)->sortByDesc('score');
        $rest = $mapped->where('is_favorite', false)->sortByDesc('score')
            ->take(max(0, $limit - $pinned->count()));

        return $pinned->concat($rest)->sortByDesc('score')->values();
    }

    /** Aktuell gepinnte Favoriten (nach Rang), team-sichtbar. */
    public function current(?Team $team): Collection
    {
        return $this->scopeVisible($team, FoodAlchemistGp::query())
            ->favorites()
            ->get(['id', 'name', 'favorite_rank', 'tag_is_convenience']);
    }

    /**
     * GP als Favorit pinnen (idempotent). JEDER approved GP ist pinbar
     * (§4-Convenience-Zwang 2026-07-20 fallengelassen — Dominique).
     */
    public function pin(FoodAlchemistGp $gp, ?int $rank = null): void
    {
        $gp->forceFill([
            'is_favorite' => true,
            'favorite_rank' => $rank,
        ])->save();
    }

    /** GP aus den Favoriten nehmen. */
    public function exclude(FoodAlchemistGp $gp): void
    {
        $gp->forceFill([
            'is_favorite' => false,
            'favorite_rank' => null,
        ])->save();
    }

    /**
     * Flache Reihenfolge setzen: [gp_id => rank, …]. Nur bereits gepinnte GPs.
     *
     * @param  array<int,int>  $gpIdToRank
     */
    public function reorder(array $gpIdToRank): int
    {
        $n = 0;
        DB::transaction(function () use ($gpIdToRank, &$n) {
            foreach ($gpIdToRank as $gpId => $rank) {
                $n += DB::table('foodalchemist_gps')
                    ->where('id', (int) $gpId)
                    ->where('is_favorite', true)
                    ->update(['favorite_rank' => (int) $rank, 'updated_at' => now()]);
            }
        });

        return $n;
    }

    /** Basis-Query: approved, echte (kein Platzhalter) GPs, team-sichtbar, Alias g. */
    private function favoritesBaseQuery(?Team $team)
    {
        $q = DB::table('foodalchemist_gps AS g')
            ->where('g.status', 'approved')
            ->where('g.is_platzhalter', false)
            ->whereNull('g.deleted_at');

        if ($team !== null) {
            TeamScope::applyVisible($q, 'g.team_id', $team); // NULL (global) ∪ Ancestry
        }

        return $q;
    }

    /** @return \Illuminate\Database\Eloquent\Builder */
    private function scopeVisible(?Team $team, $query)
    {
        if ($team === null) {
            return $query;
        }

        return $query->visibleToTeam($team);
    }
}
