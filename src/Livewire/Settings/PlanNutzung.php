<?php

namespace Platform\FoodAlchemist\Livewire\Settings;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Services\PlanNutzungService;

/**
 * Spec 82 §13 D/C1 — „Plan und Nutzung“ in den Einstellungen des Oberteams: was der Mandant im Monat zahlt.
 * Lesend, nur Gebühren und Mengen (nie Inhalte). Sichtbar für Inhaber/Admin des Mandanten, Plattform-Admin und Admin im Master-Team
 * (Settings\Index blendet die Sektion sonst aus; hier wird zusätzlich im Dienst geprüft). Unter-Teams zahlen nicht selbst.
 */
class PlanNutzung extends Component
{
    public string $monat = '';

    public function mount(): void
    {
        $this->monat = now()->format('Y-m');
        $team = Auth::user()?->currentTeamRelation;
        abort_unless($team !== null && app(PlanNutzungService::class)->darfLesen(Auth::user(), $team), 404);
    }

    public function vorher(): void
    {
        $this->monat = \Carbon\Carbon::parse($this->monat.'-01')->subMonth()->format('Y-m');
    }

    public function nachher(): void
    {
        $naechster = \Carbon\Carbon::parse($this->monat.'-01')->addMonth();
        $this->monat = $naechster->gt(now()->startOfMonth()) ? now()->format('Y-m') : $naechster->format('Y-m');
    }

    public function render()
    {
        $team = Auth::user()?->currentTeamRelation;
        $daten = null;
        $fehler = null;
        try {
            $daten = $team !== null ? app(PlanNutzungService::class)->monat($team, Auth::user(), $this->monat) : null;
        } catch (FaRechtFehltException|\RuntimeException $e) {
            $fehler = $e->getMessage();
        }

        return view('foodalchemist::livewire.settings.plan-nutzung', ['daten' => $daten, 'fehler' => $fehler, 'istUnterTeam' => $team?->parent_team_id !== null]);
    }
}
