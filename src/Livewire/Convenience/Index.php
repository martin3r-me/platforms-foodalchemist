<?php

namespace Platform\FoodAlchemist\Livewire\Convenience;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Services\ConvenienceHighlightService;

/**
 * 06·H2 — Kuratierungs-Screen für Convenience-Highlights (v1).
 * Auto-Score-Rangliste (Nutzung × Lead-LA × Priorität) mit Pin/Exclude je GP
 * + flachem Anzeige-Rang. Schreibrecht nur am eigenen Team (global = read-only).
 */
class Index extends Component
{
    public string $q = '';

    public bool $nurGepinnt = false;

    public int $limit = 100;

    public function pin(int $gpId, ?int $rank = null): void
    {
        $team = Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
        $gp = FoodAlchemistGp::visibleToTeam($team)->find($gpId);
        if ($gp === null || ! $gp->isOwnedBy($team)) {
            $this->dispatch('notify', type: 'error', message: 'GP nicht editierbar (global/Master ist read-only).');

            return;
        }
        try {
            app(ConvenienceHighlightService::class)->pin($gp, $rank);
        } catch (\RuntimeException $e) {
            $this->dispatch('notify', type: 'error', message: $e->getMessage());
        }
    }

    public function exclude(int $gpId): void
    {
        $team = Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
        $gp = FoodAlchemistGp::visibleToTeam($team)->find($gpId);
        if ($gp === null || ! $gp->isOwnedBy($team)) {
            $this->dispatch('notify', type: 'error', message: 'GP nicht editierbar.');

            return;
        }
        app(ConvenienceHighlightService::class)->exclude($gp);
    }

    public function setRank(int $gpId, ?int $rank): void
    {
        $team = Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
        $gp = FoodAlchemistGp::visibleToTeam($team)->find($gpId);
        if ($gp !== null && $gp->isOwnedBy($team) && $gp->is_convenience_highlight) {
            app(ConvenienceHighlightService::class)->reorder([$gpId => (int) $rank]);
        }
    }

    public function render()
    {
        $team = Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
        $items = app(ConvenienceHighlightService::class)->suggest($team, $this->limit);

        $suche = trim(mb_strtolower($this->q));
        if ($suche !== '') {
            $items = $items->filter(fn ($r) => str_contains(mb_strtolower($r['name']), $suche))->values();
        }
        if ($this->nurGepinnt) {
            $items = $items->filter(fn ($r) => $r['is_highlight'])->values();
        }

        return view('foodalchemist::livewire.convenience.index', [
            'items' => $items,
            'anzahlGepinnt' => $items->where('is_highlight', true)->count(),
        ])->layout('platform::layouts.app');
    }
}
