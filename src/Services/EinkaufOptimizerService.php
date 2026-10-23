<?php

namespace Platform\FoodAlchemist\Services;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistPurchaseTransaction;

/**
 * Einkauf E4 — Wareneinsatz-Optimierung auf dem echten Einkaufsjournal.
 *
 * Stellt den tatsächlich bezahlten Wareneinsatz (Ist) drei Sichten gegenüber, die die
 * TATSÄCHLICHE Einkaufsmenge je Grundprodukt zum jeweils günstigsten verfügbaren
 * Lieferanten neu bewerten:
 *   - Optimal (Listenpreis):        günstigster Vergleichspreis, ohne Rückvergütung
 *   - Optimal (inkl. Rückvergütung): günstigster EFFEKTIVER Preis (RebateService-Overlay) —
 *     ein Lieferant mit höherem Listenpreis, aber besserer Rückvergütung, kann gewinnen
 * plus die größten Einsparpotenziale je Artikel. „Lieferant ausklammern" (excludeSupplierIds)
 * erlaubt Was-wäre-wenn-Szenarien ("ohne Lieferant X").
 *
 * Nur Grundprodukte mit vergleichbarem Optimal fließen in ALLE Summen (apples-to-apples);
 * der Rest wird als `n_skipped` ehrlich ausgewiesen, nicht still verworfen. team-scoped,
 * read-only. Reuse: LeadLaService::rangliste + RebateService::enrichRangliste.
 */
class EinkaufOptimizerService
{
    private const TOP_N = 40;

    public function __construct(
        private LeadLaService $lead,
        private RebateService $rebate,
    ) {
    }

    /**
     * Jede `top`-Zeile trägt neben den Beträgen auch `cheapest_list_la_id`,
     * `cheapest_rebate_la_id`, `lead_la_id` und `lead_ist_optimal` — die Handhaben, mit denen
     * das Controlling-Zentrum die Bezugsquelle direkt umstellt (Spec 32).
     *
     * @param  list<int>  $excludeSupplierIds  Lieferanten aus dem Optimal-Kandidatenpool ausklammern
     * @return array{ist_total:float,optimal_list_total:float,optimal_rebate_total:float,saving_list:float,saving_rebate:float,saving_list_pct:?float,saving_rebate_pct:?float,top:list<array>,n_articles:int,n_skipped:int}
     */
    public function optimieren(Team $team, array $excludeSupplierIds = [], ?string $von = null, ?string $bis = null): array
    {
        $exclude = array_map('intval', $excludeSupplierIds);

        // Journal je (GP, Einheit) aggregieren — Ist-Menge + Ist-Kosten.
        $rows = FoodAlchemistPurchaseTransaction::query()
            ->where('team_id', $team->id)
            ->whereNotNull('gp_id')->whereNotNull('qty')->whereNotNull('line_total')
            ->when($von !== null, fn ($q) => $q->whereDate('purchased_at', '>=', $von))
            ->when($bis !== null, fn ($q) => $q->whereDate('purchased_at', '<=', $bis))
            ->selectRaw('gp_id, unit_code, SUM(qty) AS qty, SUM(line_total) AS ist')
            ->groupBy('gp_id', 'unit_code')
            ->get();

        $gpMap = FoodAlchemistGp::whereIn('id', $rows->pluck('gp_id')->unique()->all())->get()->keyBy('id');

        $istTotal = 0.0;
        $optListTotal = 0.0;
        $optRebTotal = 0.0;
        $out = [];
        $skipped = 0;

        foreach ($rows as $r) {
            $gp = $gpMap->get($r->gp_id);
            if ($gp === null) {
                $skipped++;

                continue;
            }
            $kette = $this->lead->rangliste($gp, $team, teamPreise: true);   // Spec 85 PR-B: Artikel und Preise des Teams
            $this->rebate->enrichRangliste($team, $kette, $gp->commodity_group_code);

            $cand = $kette->filter(fn ($la) => $la->vergleichspreis_wert !== null
                && ! in_array((int) $la->supplier_id, $exclude, true))->values();
            if ($cand->isEmpty()) {
                $skipped++;   // kein vergleichbares Optimal → ganzes GP raus (apples-to-apples)

                continue;
            }

            $effReb = fn ($la) => (float) ($la->vergleichspreis_mit_rabatt_wert ?? $la->vergleichspreis_wert);
            $cheapestList = $cand->sortBy(fn ($la) => (float) $la->vergleichspreis_wert)->first();
            $cheapestReb = $cand->sortBy($effReb)->first();

            $qty = (float) $r->qty;
            $ist = (float) $r->ist;
            $optList = $qty * (float) $cheapestList->vergleichspreis_wert;
            $optReb = $qty * $effReb($cheapestReb);

            $istTotal += $ist;
            $optListTotal += $optList;
            $optRebTotal += $optReb;

            // Spec 32: die LA-ids und der aktuell effektive Lead kommen additiv mit — ohne sie
            // endet die Analyse in einer Liste, aus der man nichts machen kann. Der Controlling-
            // Tab stellt daraus die Bezugsquelle um; wer die Zeile nur anzeigt, merkt nichts.
            $lead = $kette->first(fn ($la) => $la->gepinnt && ! $la->locked)
                ?? $kette->first(fn ($la) => ! $la->locked);

            $out[] = [
                'gp_id' => (int) $gp->id,
                'name' => $gp->name,
                'unit' => $r->unit_code,
                'qty' => round($qty, 3),
                'ist' => round($ist, 2),
                'optimal_list' => round($optList, 2),
                'optimal_rebate' => round($optReb, 2),
                'saving_rebate' => round($ist - $optReb, 2),
                'cheapest_list_supplier' => $cheapestList->supplier_name,
                'cheapest_rebate_supplier' => $cheapestReb->supplier_name,
                'cheapest_list_la_id' => (int) $cheapestList->id,
                'cheapest_rebate_la_id' => (int) $cheapestReb->id,
                'lead_la_id' => $lead !== null ? (int) $lead->id : null,
                'lead_ist_optimal' => $lead !== null && (int) $lead->id === (int) $cheapestReb->id,
            ];
        }

        usort($out, fn ($a, $b) => $b['saving_rebate'] <=> $a['saving_rebate']);

        return [
            'ist_total' => round($istTotal, 2),
            'optimal_list_total' => round($optListTotal, 2),
            'optimal_rebate_total' => round($optRebTotal, 2),
            'saving_list' => round($istTotal - $optListTotal, 2),
            'saving_rebate' => round($istTotal - $optRebTotal, 2),
            'saving_list_pct' => $istTotal > 0 ? round(($istTotal - $optListTotal) / $istTotal * 100, 1) : null,
            'saving_rebate_pct' => $istTotal > 0 ? round(($istTotal - $optRebTotal) / $istTotal * 100, 1) : null,
            'top' => array_slice($out, 0, self::TOP_N),
            'n_articles' => count($out),
            'n_skipped' => $skipped,
        ];
    }

    /**
     * Spec 85 PR-E: der Optimizer in der Team-Brille. Ein Team → wie {@see optimieren} (Zeilen mit team_id); mehrere (Brille „alle“) → je Standort
     * der EIGENE Lauf (eigenes Journal, eigene Artikel und Preise — nie eine gemeinsame Preis-Sicht), danach summiert. Die Zeilen tragen team_id und
     * Standort, `je_standort` das Potenzial je Standort.
     *
     * @param  list<int>  $excludeSupplierIds
     * @return array<string,mixed>
     */
    public function optimierenBrille(Team $viewer, array $excludeSupplierIds = [], ?string $von = null, ?string $bis = null): array
    {
        $standorte = app(StandortService::class);
        $ids = $standorte->leseTeamIds($viewer);
        $teams = Team::whereIn('id', $ids)->get()->keyBy('id');

        $teile = [];
        foreach ($ids as $id) {
            $t = $teams->get($id);
            if ($t === null) {
                continue;
            }
            // als der Standort: seine Artikel, Grundprodukte und Preise (Sichtfilter und Brille aus Sicht des Standorts)
            $r = \Platform\FoodAlchemist\Support\FaTeam::imTeam($t, fn () => $standorte->alsEigen($t, fn () => $this->optimieren($t, $excludeSupplierIds, $von, $bis)));
            $r['top'] = array_map(fn (array $z) => $z + ['team_id' => (int) $t->id, 'standort' => (string) $t->name], $r['top']);
            $teile[] = ['team' => $t, 'r' => $r];
        }
        if (count($teile) === 1) {
            return $teile[0]['r'] + ['je_standort' => []];
        }

        $summe = fn (string $k) => round(array_sum(array_map(fn ($t) => (float) $t['r'][$k], $teile)), 2);
        $ist = $summe('ist_total');
        $top = array_merge(...array_map(fn ($t) => $t['r']['top'], $teile ?: [['r' => ['top' => []]]]));
        usort($top, fn ($a, $b) => $b['saving_rebate'] <=> $a['saving_rebate']);

        return [
            'ist_total' => $ist,
            'optimal_list_total' => $summe('optimal_list_total'),
            'optimal_rebate_total' => $summe('optimal_rebate_total'),
            'saving_list' => $summe('saving_list'),
            'saving_rebate' => $summe('saving_rebate'),
            'saving_list_pct' => $ist > 0 ? round($summe('saving_list') / $ist * 100, 1) : null,
            'saving_rebate_pct' => $ist > 0 ? round($summe('saving_rebate') / $ist * 100, 1) : null,
            'top' => array_slice($top, 0, self::TOP_N),
            'n_articles' => (int) array_sum(array_map(fn ($t) => $t['r']['n_articles'], $teile)),
            'n_skipped' => (int) array_sum(array_map(fn ($t) => $t['r']['n_skipped'], $teile)),
            'je_standort' => array_map(fn ($t) => [
                'team_id' => (int) $t['team']->id, 'standort' => (string) $t['team']->name,
                'ist_total' => $t['r']['ist_total'], 'saving_rebate' => $t['r']['saving_rebate'], 'saving_list' => $t['r']['saving_list'],
            ], $teile),
        ];
    }
}
