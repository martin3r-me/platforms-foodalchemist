<?php

namespace Platform\FoodAlchemist\Livewire\Verkauf;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;
use Platform\FoodAlchemist\Services\SalesRecipeService;

/**
 * M6-03 / 13_REFERENZ N2: VK-DetailPanel — Titel + Komponentenliste, Status/
 * HG/Diät, VERKAUFT-ALS-Box (Orange), KPI-Karten (EK GESAMT · VK NETTO mit
 * Quelle · VK BRUTTO Highlight · WARENEINSATZ % · Reihe 2 pro Einheit),
 * Formel-Klartext aus der Klasse, Beschreibung/Marketing, Zutaten-Kurzliste.
 * Alle Zahlen aus SalesRecipeService::cockpit (MargeService Single-Source).
 * ✨ Klassifizieren + Kohärenz-Check folgen mit M6-05.
 */
class DetailPanel extends Component
{
    public ?int $recipeId = null;

    public function mount(?int $recipeId = null): void
    {
        $this->recipeId = $recipeId;
    }

    #[On('vk-recipe-selected')]
    public function zeige(int $id): void
    {
        $this->recipeId = $id;
    }

    #[On('recipe-gespeichert')]
    public function aktualisiere(): void
    {
        // Editor-Save → Cockpit neu rendern (Kontext bleibt)
    }

    public function render(SalesRecipeService $verkauf)
    {
        $team = Auth::user()?->currentTeamRelation;
        $rezept = $team !== null && $this->recipeId !== null ? $verkauf->detail($team, $this->recipeId) : null;

        return view('foodalchemist::livewire.verkauf.detail-panel', [
            'rezept' => $rezept,
            'cockpit' => $rezept !== null ? $verkauf->cockpit($rezept) : null,
        ]);
    }
}
