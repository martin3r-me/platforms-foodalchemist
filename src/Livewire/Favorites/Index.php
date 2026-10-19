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
    use \Platform\FoodAlchemist\Livewire\Concerns\InteractsWithSavedToast;

    public string $q = '';

    public bool $nurGepinnt = false;

    public int $limit = 100;

    public function pin(int $gpId, ?int $rank = null): void
    {
        $team = Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
        $gp = FoodAlchemistGp::visibleToTeam($team)->find($gpId);
        if ($gp === null) {   // Spec 85 FAV-1: eigene Liste je Team — jeder sichtbare GP darf Favorit sein
            $this->errorToast('GP nicht gefunden.');

            return;
        }
        try {
            app(FavoriteGpService::class)->pin($team, $gp, $rank);
            $this->savedToast('Favorit gepinnt');
        } catch (\RuntimeException $e) {
            $this->errorToast($e->getMessage());
        }
    }

    public function exclude(int $gpId): void
    {
        $team = Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
        $gp = FoodAlchemistGp::visibleToTeam($team)->find($gpId);
        if ($gp === null) {
            $this->errorToast('GP nicht gefunden.');

            return;
        }
        try {
            app(FavoriteGpService::class)->exclude($team, $gp);
            $this->savedToast('Favorit ausgeschlossen');
        } catch (\RuntimeException $e) {
            $this->errorToast($e->getMessage());
        }
    }

    public function setRank(int $gpId, ?int $rank): void
    {
        $team = Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
        $gp = FoodAlchemistGp::visibleToTeam($team)->find($gpId);
        if ($gp !== null) {
            try {
                app(FavoriteGpService::class)->reorder($team, [$gpId => (int) $rank]);
            } catch (\RuntimeException $e) {
                $this->errorToast($e->getMessage());
            }
        }
    }

    public function render()
    {
        $team = Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
        // Suche + „nur gepinnt" server-seitig — sonst fänden wir GPs außerhalb
        // des Score-Caps nicht (Pool ist jetzt der ganze approved-Bestand).
        $items = app(FavoriteGpService::class)->suggest($team, $this->limit, $this->q, $this->nurGepinnt);

        // Nur Anzeige: gepinnte Favoriten oben in ihrer Reihenfolge (Rang, dann Score),
        // darunter die Vorschläge nach Score — gleiche Zeilen wie $items, nur gruppiert.
        $favoriten = $items->where('is_favorite', true)
            ->sortBy([
                fn ($a, $b) => ($a['favorite_rank'] ?? PHP_INT_MAX) <=> ($b['favorite_rank'] ?? PHP_INT_MAX),
                fn ($a, $b) => $b['score'] <=> $a['score'],
            ])->values();
        $vorschlaege = $items->where('is_favorite', false)->values();

        return view('foodalchemist::livewire.favorites.index', [
            'items' => $items,
            'favoriten' => $favoriten,
            'vorschlaege' => $vorschlaege,
            'anzahlGepinnt' => $items->where('is_favorite', true)->count(),
        ])->layout(\Platform\FoodAlchemist\Support\FaShell::layout());
    }
}
