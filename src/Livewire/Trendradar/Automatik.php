<?php

namespace Platform\FoodAlchemist\Livewire\Trendradar;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Platform\FoodAlchemist\Enums\FaRolle;
use Platform\FoodAlchemist\Services\FaRechte;
use Platform\FoodAlchemist\Services\Trends\TrendAutomatikService;
use Platform\FoodAlchemist\Support\TrendAutomatikRegeln;

/**
 * Spec 93 C · Reiter „Automatik“: „Was die Automatik tun würde“ — je automatischem Thema Kennzahlen, Entscheidung, geplante
 * Aktion und Begründung (reine Rechnung). „Lauf jetzt“ schreibt nur bei Stufe ≠ aus und ausgeschaltetem Schatten-Modus.
 * Stufe, Schatten und Schwellen stehen in den Trendradar-Einstellungen.
 */
class Automatik extends Component
{
    public ?string $meldung = null;

    public ?string $fehler = null;

    public function laufen(): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null) {
            return;
        }
        $this->meldung = $this->fehler = null;
        try {
            $r = app(TrendAutomatikService::class)->lauf($team, null, Auth::id());
            $this->meldung = $r['geschrieben']
                ? 'Lauf fertig: '.$r['neue_trends'].' neue Trends'.($r['gestaut'] > 0 ? ', '.$r['gestaut'].' warten auf den nächsten Lauf' : '').'.'
                : 'Schatten-Modus bzw. Stufe „aus“: nur gerechnet, nichts geschrieben.';
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function render()
    {
        $user = Auth::user();
        $team = $user?->currentTeamRelation;
        $svc = app(TrendAutomatikService::class);

        return view('foodalchemist::livewire.trendradar.automatik', [
            'einstellungen' => $team ? $svc->einstellungen($team) : null,
            'plan' => $team ? $svc->lauf($team, false) : null,
            'stufen' => TrendAutomatikRegeln::STUFEN,
            'darfKuratieren' => $team ? app(FaRechte::class)->darf($user, $team, FaRolle::Kuratieren) : false,
        ]);
    }
}
