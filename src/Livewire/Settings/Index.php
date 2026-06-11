<?php

namespace Platform\FoodAlchemist\Livewire\Settings;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * M1-01 / D-1 §4: Settings-Gerüst — vertikale Sektions-Navigation, jede Sektion
 * eine eigene URL (V-17: kein Tab-State-Verlust). Die Sektionen selbst sind
 * eigenständige Livewire-Komponenten (Isolation, lazy pro Route).
 *
 * Edit-Gating macht jede Sektion zeilen-genau über Curate::canCurate (M1-08);
 * das Gerüst zeigt Kind-Teams nur den Read-only-Hinweis (D1: geerbter Katalog).
 */
class Index extends Component
{
    public string $sektion = 'einheiten';

    /** @var array<string, array{label: string, hint: string}> */
    public const SEKTIONEN = [
        'einheiten' => ['label' => 'Einheiten', 'hint' => 'Gramm-/ml-Defaults, Stück-Gewichte (GL-02/GL-11)'],
        'warengruppen' => ['label' => 'Warengruppen & Sub-Kategorien', 'hint' => '§3-Codes fix · Sub-Kategorien-Housekeeping'],
        'taxonomie' => ['label' => 'Rezept-Taxonomie', 'hint' => 'Hauptgruppen + Kategorien (M4-Browser-Bäume)'],
        'einkauf' => ['label' => 'Einkauf & Lead-LA', 'hint' => 'Lead-Strategie (V-27) · Stamm-Lieferanten-Matrix'],
        'kalkulation' => ['label' => 'Kalkulation', 'hint' => 'Garverlust-, MwSt-Defaults, Rundung (GL-02)'],
    ];

    public function mount(string $sektion = 'einheiten'): void
    {
        abort_unless(array_key_exists($sektion, self::SEKTIONEN), 404);
        $this->sektion = $sektion;
    }

    public function render()
    {
        $team = Auth::user()?->currentTeamRelation;

        return view('foodalchemist::livewire.settings.index', [
            'sektionen' => self::SEKTIONEN,
            'istKindTeam' => $team !== null && $team->parent_team_id !== null,
        ])->layout('platform::layouts.app');
    }
}
