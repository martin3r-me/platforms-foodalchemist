<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\MatchBand;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\Matching\TokenEngine;

/**
 * M3-08: MatchService v1 — GP-Vorschläge für UNGEMAPPTE LAs (LA-Verknüpfen, M3-11-Bulk).
 *
 * Zwei Stufen:
 *  1. exact: identische EAN (packaging/ordering) bzw. supplier+article_number eines
 *     BEREITS GEMAPPTEN LAs ⇒ dessen GP mit Score 1.0 (sicherste Quelle — Dubletten).
 *  2. fuzzy: GL-04-Kern (TokenEngine) — F1 über Designation↔GP-Name + Slug-Pfade
 *     + Name-Containment-Floor; Bänder nach §4.1 (0.85/0.70/0.50), < 0.50 fliegt raus.
 *
 * Voller GL-04-Port (Pools, Aliasse, Tiebreaker, 96 Golden) folgt in M4-09.
 */
class MatchService
{
    public function __construct(private TokenEngine $engine)
    {
    }

    /**
     * @return Collection<int, array{gp: FoodAlchemistGp, score: float, band: MatchBand, methode: string}>
     */
    public function vorschlaegeFuerLa(FoodAlchemistSupplierItem $la, Team $team, int $limit = 5): Collection
    {
        $vorschlaege = $this->exactDubletten($la, $team);

        if ($vorschlaege->count() < $limit) {
            $gesehen = $vorschlaege->pluck('gp')->pluck('id')->all();
            foreach ($this->fuzzyByName($la, $team, $limit, $gesehen) as $treffer) {
                $vorschlaege->push($treffer);
            }
        }

        return $vorschlaege
            ->sortBy([fn ($a, $b) => $b['score'] <=> $a['score'], fn ($a, $b) => $a['gp']->id <=> $b['gp']->id])
            ->take($limit)
            ->values();
    }

    // ── Stufe 1: exakte Dubletten-Brücke ────────────────────────────────

    private function exactDubletten(FoodAlchemistSupplierItem $la, Team $team): Collection
    {
        $out = collect();

        $kandidatenQuery = function () use ($la) {
            return DB::table('foodalchemist_supplier_items AS i')
                ->join('foodalchemist_supplier_item_structures AS s', 's.supplier_item_id', '=', 'i.id')
                ->where('i.id', '!=', $la->id)
                ->whereNotNull('s.gp_id')
                ->whereNull('i.deleted_at')->whereNull('s.deleted_at');
        };

        $eans = array_values(array_filter([$la->ean_packaging, $la->ean_ordering]));
        $gpIds = [];
        if ($eans !== []) {
            $gpIds['exact_ean'] = $kandidatenQuery()
                ->where(fn ($q) => $q->whereIn('i.ean_packaging', $eans)->orWhereIn('i.ean_ordering', $eans))
                ->distinct()->pluck('s.gp_id');
        }
        if ($la->article_number !== null && $la->article_number !== '') {
            $gpIds['exact_artno'] = $kandidatenQuery()
                ->where('i.supplier_id', $la->supplier_id)
                ->where('i.article_number', $la->article_number)
                ->distinct()->pluck('s.gp_id');
        }

        $gesehen = [];
        foreach ($gpIds as $methode => $ids) {
            foreach (FoodAlchemistGp::visibleToTeam($team)->whereIn('id', $ids)->get() as $gp) {
                if (isset($gesehen[$gp->id])) {
                    continue;
                }
                $gesehen[$gp->id] = true;
                $out->push(['gp' => $gp, 'score' => 1.0, 'band' => MatchBand::Exact, 'methode' => $methode]);
            }
        }

        return $out;
    }

    // ── Stufe 2: fuzzy GL-04-Kern ───────────────────────────────────────

    /** @param array<int> $ausschluss bereits exakt getroffene GP-Ids */
    private function fuzzyByName(FoodAlchemistSupplierItem $la, Team $team, int $limit, array $ausschluss = []): array
    {
        $query = $this->engine->tokenize((string) $la->designation);
        if ($query === []) {
            return [];
        }

        $treffer = [];
        FoodAlchemistGp::visibleToTeam($team)
            ->whereNotIn('id', $ausschluss)
            ->select('id', 'name', 'hauptzutat_slug', 'warengruppe_code', 'status', 'team_id')
            ->orderBy('id')                                            // Inv. 7: deterministische Iteration
            ->chunk(2000, function ($gps) use (&$treffer, $query) {
                foreach ($gps as $gp) {
                    $score = $this->engine->matchScore($query, null, $this->engine->tokenize($gp->name), $gp->hauptzutat_slug);
                    if ($score < 0.90 && $this->engine->headMatchesQuery($gp->name, $query)) {
                        $score = 0.90;                                 // NAME_CONTAINMENT_FLOOR (4.4o)
                    }
                    if ($score >= 0.50) {
                        $treffer[] = ['gp' => $gp, 'score' => round($score, 4), 'band' => MatchBand::fuerScore($score), 'methode' => 'fuzzy_name'];
                    }
                }
            });

        usort($treffer, fn ($a, $b) => [$b['score'], $a['gp']->id] <=> [$a['score'], $b['gp']->id]);

        return array_slice($treffer, 0, $limit);
    }
}
