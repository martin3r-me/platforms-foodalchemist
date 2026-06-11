<?php

/**
 * Dashboard Livewire Component
 * 
 * Hauptübersicht des Moduls.
 * 
 * WICHTIG FÜR LLMs:
 * - Jedes Modul sollte ein Dashboard haben
 * - Dashboard zeigt Übersicht/Statistiken
 * - Verwendet platform::layouts.app Layout
 * - Kann comms-Event dispatch'en (für Kommunikation)
 * 
 * ANPASSUNGEN:
 * - Füge Datenqueries hinzu
 * - Passe View an deine Bedürfnisse an
 * - Füge Statistiken hinzu
 */

namespace Platform\FoodAlchemist\Livewire;

use Livewire\Component;
use Illuminate\Support\Facades\Auth;

class Dashboard extends Component
{
    /**
     * Dispatch comms-Event (optional)
     * 
     * Wird nach dem Rendern aufgerufen.
     * Kann für Kommunikation/Notifications verwendet werden.
     */
    public function rendered()
    {
        $this->dispatch('comms', [
            'model' => null,
            'modelId' => null,
            'subject' => 'Food Alchemist Dashboard',
            'description' => 'Übersicht des Template-Moduls',
            'url' => route('foodalchemist.dashboard'),
            'source' => 'foodalchemist.dashboard',
            'recipients' => [],
            'meta' => [
                'view_type' => 'dashboard',
            ],
        ]);
    }

    /**
     * Render-Methode
     * 
     * Lädt Daten und gibt die View zurück.
     * 
     * PATTERN:
     * 1. User/Team holen
     * 2. Daten laden (Models, Statistiken, etc.)
     * 3. View mit Daten zurückgeben
     */
    public function render()
    {
        $user = Auth::user();
        $team = $user->currentTeam;

        /**
         * BEISPIEL: Daten laden
         * 
         * $entities = YourModel::where('team_id', $team->id)
         *     ->orderBy('name')
         *     ->get();
         * 
         * $stats = [
         *     'total' => $entities->count(),
         *     'active' => $entities->where('is_active', true)->count(),
         * ];
         */

        return view('foodalchemist::livewire.dashboard', [
            'currentDate' => now()->format('d.m.Y'),
            'currentDay' => now()->format('l'),
            // Füge hier deine Daten hinzu
        ])->layout('platform::layouts.app');
    }
}
