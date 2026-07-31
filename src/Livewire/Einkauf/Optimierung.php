<?php

namespace Platform\FoodAlchemist\Livewire\Einkauf;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Services\EinkaufOptimizerService;

/**
 * Einkauf E4 — Wareneinsatz-Optimierung: der Ist-Wareneinsatz aus dem Einkaufsjournal
 * gegenüber dem optimalen Bezug (günstigster Lieferant) — als Listenpreis UND inkl.
 * Rückvergütung — plus die größten Einsparpotenziale. „Lieferant ausklammern" spielt
 * Was-wäre-wenn-Szenarien durch. Braucht Journal-Daten (FA-Bestellungen geliefert bzw.
 * Necta-Import); ohne Journal ein Leerzustand. Read-only, team-scoped.
 */
class Optimierung extends Component
{
    /** @var list<int> ausgeklammerte Lieferanten (Session-State, Was-wäre-wenn) */
    public array $excludeSupplierIds = [];

    public function render(EinkaufOptimizerService $optimizer)
    {
        $team = Auth::user()?->currentTeamRelation;
        $r = $team !== null
            ? $optimizer->optimieren($team, array_map('intval', $this->excludeSupplierIds))
            : ['ist_total' => 0.0, 'optimal_list_total' => 0.0, 'optimal_rebate_total' => 0.0,
                'saving_list' => 0.0, 'saving_rebate' => 0.0, 'saving_list_pct' => null,
                'saving_rebate_pct' => null, 'top' => [], 'n_articles' => 0, 'n_skipped' => 0];

        return view('foodalchemist::livewire.einkauf.optimierung', [
            'r' => $r,
            'lieferanten' => $team !== null
                ? FoodAlchemistSupplier::visibleToTeam($team)->where('is_inactive', false)
                    ->orderBy('name')->get(['id', 'name'])
                : collect(),
        ])->layout('platform::layouts.app');
    }
}
