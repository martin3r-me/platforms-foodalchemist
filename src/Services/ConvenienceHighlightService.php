<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Support\TeamScope;

/**
 * 06·H2 — Kuratierung der Convenience-Highlights (kuratierte Haus-Standard-Liste,
 * opt-in KI-Baustein). Hybrid: Auto-Score schlägt vor (Rangliste), Mensch pinnt/
 * excludet. Alle Mutationen laufen hier (kein Roh-SQL für Schreibwege).
 *
 * Auto-Score je Convenience-GP (Spec §5): Verwendungshäufigkeit × Lieferanten-
 * Priorität (Lead-LA-Supplier in der Prioritätskette) × Lead-LA-Vollständigkeit.
 * Gewichte bewusst konservativ; am realen Bestand nachkalibrierbar.
 */
class ConvenienceHighlightService
{
    /** Gewichte des Auto-Scores (Spec §10: am Bestand kalibrieren). */
    public const W_USAGE = 2.0;      // je Rezept-Verwendung

    public const W_LEAD = 3.0;       // Lead-LA gesetzt (Vollständigkeit)

    public const W_PRICE = 2.0;      // Lead-LA löst auf einen Preis auf

    public const W_PRIORITY_MAX = 5.0; // Bonus, wenn Lead-Supplier ganz oben in der Prioritätskette

    public function __construct(private readonly TeamSettingsService $settings) {}

    /**
     * Auto-Score-Rangliste der Convenience-GPs (nur `tag_is_convenience=true`),
     * bereits gepinnte oben markiert. Absteigend nach Score.
     *
     * @return Collection<int, array{gp_id:int, name:string, usage:int, has_lead_la:bool, has_price:bool, priority_pos:?int, score:float, is_highlight:bool, highlight_rank:?int}>
     */
    public function suggest(?Team $team, int $limit = 300): Collection
    {
        $prioritaeten = $team !== null ? $this->settings->leadLaPrioritaeten($team) : [];
        $prioPos = array_flip(array_values(array_map('intval', $prioritaeten))); // supplier_id => index

        $rows = $this->convenienceBaseQuery($team)
            ->select([
                'g.id', 'g.name', 'g.lead_la_supplier_item_id', 'g.is_convenience_highlight', 'g.highlight_rank',
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

        return $rows->map(function ($r) use ($prioPos) {
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
                'is_highlight' => (bool) $r->is_convenience_highlight,
                'highlight_rank' => $r->highlight_rank !== null ? (int) $r->highlight_rank : null,
            ];
        })
            ->sortByDesc('score')
            ->take($limit)
            ->values();
    }

    /** Aktuell gepinnte Highlights (nach Rang), team-sichtbar. */
    public function current(?Team $team): Collection
    {
        return $this->scopeVisible($team, FoodAlchemistGp::query())
            ->convenienceHighlights()
            ->get(['id', 'name', 'highlight_rank', 'tag_is_convenience']);
    }

    /**
     * GP als Highlight pinnen (idempotent). Soft-Regel (Spec §4): nur bei
     * tag_is_convenience=true — sonst RuntimeException (im Screen erzwungen).
     */
    public function pin(FoodAlchemistGp $gp, ?int $rank = null): void
    {
        if ($gp->tag_is_convenience !== true) {
            throw new \RuntimeException("GP {$gp->id} ist nicht als Convenience getaggt (tag_is_convenience) — nicht pinbar.");
        }
        $gp->forceFill([
            'is_convenience_highlight' => true,
            'highlight_rank' => $rank,
        ])->save();
    }

    /** GP aus den Highlights nehmen. */
    public function exclude(FoodAlchemistGp $gp): void
    {
        $gp->forceFill([
            'is_convenience_highlight' => false,
            'highlight_rank' => null,
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
                    ->where('is_convenience_highlight', true)
                    ->update(['highlight_rank' => (int) $rank, 'updated_at' => now()]);
            }
        });

        return $n;
    }

    /** Basis-Query: Convenience-GPs (approved, team-sichtbar), Alias g. */
    private function convenienceBaseQuery(?Team $team)
    {
        $q = DB::table('foodalchemist_gps AS g')
            ->where('g.tag_is_convenience', true)
            ->where('g.status', 'approved')
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
