<?php

namespace Platform\FoodAlchemist\Livewire\Favorites;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Services\FavoriteGpService;

/**
 * 06·H2 — Kuratierungs-Screen für Favoriten-GPs (v1).
 * Auto-Score-Rangliste (Nutzung × Lead-LA × Priorität) mit Pin/Exclude je GP
 * + flachem Anzeige-Rang. Jeder approved GP ist pinbar (Convenience-Zwang §4
 * fallengelassen 2026-07-20). Schreibrecht nur am eigenen Team (global = read-only).
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
            app(FavoriteGpService::class)->pin($gp, $rank);
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
        app(FavoriteGpService::class)->exclude($gp);
    }

    public function setRank(int $gpId, ?int $rank): void
    {
        $team = Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
        $gp = FoodAlchemistGp::visibleToTeam($team)->find($gpId);
        if ($gp !== null && $gp->isOwnedBy($team) && $gp->is_favorite) {
            app(FavoriteGpService::class)->reorder([$gpId => (int) $rank]);
        }
    }

    public function render()
    {
        $team = Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
        // Suche + „nur gepinnt" server-seitig — sonst fänden wir GPs außerhalb
        // des Score-Caps nicht (Pool ist jetzt der ganze approved-Bestand).
        $items = app(FavoriteGpService::class)->suggest($team, $this->limit, $this->q, $this->nurGepinnt);

        return view('foodalchemist::livewire.favorites.index', [
            'items' => $items,
            'anzahlGepinnt' => $items->where('is_favorite', true)->count(),
        ])->layout('platform::layouts.app');
    }
}
