<?php

namespace Platform\FoodAlchemist\Livewire\Controlling\Panels;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Platform\FoodAlchemist\Services\WareneinsatzAbweichungService;

/**
 * Spec 32 · C4 — Soll/Ist-Wareneinsatz über einen Zeitraum.
 *
 * Die Fläche, die beide Ist-Seiten zusammenbringt: Einkaufsjournal (Kosten) gegen
 * Verkaufsjournal (Erlös). Erst hier steht die ECHTE Wareneinsatzquote — überall sonst im
 * Modul steht die kalkulierte.
 *
 * Vorbelegt ist der volle Vormonat, weil der Wert ohne Inventur eine Perioden-Rechnung ist:
 * ein angebrochener Monat oder ein gleitendes Fenster liest sich wie Schwund, wo nur das
 * Lager gefüllt wurde.
 */
class Abweichung extends Component
{
    public string $von = '';

    public string $bis = '';

    public function mount(): void
    {
        $von = now()->subMonthNoOverflow()->startOfMonth();
        $this->von = $von->toDateString();
        $this->bis = $von->copy()->endOfMonth()->toDateString();
    }

    /** Zeitraum auf den vollen Vormonat zurücksetzen. */
    public function vormonat(): void
    {
        $this->mount();
    }

    public function render(WareneinsatzAbweichungService $svc)
    {
        $team = Auth::user()?->currentTeamRelation;
        $von = $this->von ?: null;
        $bis = $this->bis ?: null;

        return view('foodalchemist::livewire.controlling.panels.abweichung', [
            'a' => $team !== null ? $svc->analyse($team, $von, $bis) : null,
            // Spec 77c: Team-Brille „alle" → Summe oben, je Standort nebeneinander (eigene Preise + Einstellungen)
            'jeStandort' => $team !== null
                ? app(\Platform\FoodAlchemist\Services\StandortService::class)->jeStandort($team, fn ($t) => $svc->analyse($t, $von, $bis))
                : [],
        ]);
    }
}
