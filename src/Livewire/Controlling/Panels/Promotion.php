<?php

namespace Platform\FoodAlchemist\Livewire\Controlling\Panels;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Platform\FoodAlchemist\Services\PromotionService;

/**
 * Spec 33 · P6 — was bringt eine laufende Ausgabe?
 *
 * Die Auswertungs-Hälfte der Portfolio-Steuerung: Umsatz je laufender Karte, je laufendem Plan,
 * je laufendem Foodbook — im jeweiligen Gültigkeitsfenster.
 *
 * Die Fläche zeigt bewusst **zwei Vorbehalte gleichrangig neben den Zahlen**: den exklusiven
 * Anteil (ein Gericht kann in mehreren laufenden Ausgaben stehen, dann zählt sein Umsatz bei
 * beiden) und die Zuordnungs-Abdeckung des Verkaufsjournals. Ohne beide läse sich die Liste
 * genauer, als sie ist.
 */
class Promotion extends Component
{
    /** Leer = heute. */
    public string $stichtag = '';

    public function heute(): void
    {
        $this->stichtag = '';
    }

    public function render(PromotionService $promotion)
    {
        $team = Auth::user()?->currentTeamRelation;

        return view('foodalchemist::livewire.controlling.panels.promotion', [
            'p' => $team === null ? null : $promotion->uebersicht($team, $this->stichtag ?: null),
        ]);
    }
}
