<?php

namespace Platform\FoodAlchemist\Livewire\Handy;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Services\GpAggregateService;
use Platform\FoodAlchemist\Services\GpAromaService;
use Platform\FoodAlchemist\Services\LeadLaService;
use Platform\FoodAlchemist\Services\PairingService;
use Platform\FoodAlchemist\Services\SensorikService;
use Platform\FoodAlchemist\Support\FaShell;

/**
 * #1003 Grundprodukt am Handy (nur lesen), Ziel der GP-Links im Rezept-/Gericht-Handbuch.
 * Daten über dieselben Wege wie das Desktop-Detail (Gps\DetailPanel): Sicht visibleToTeam, Artikel-Rangliste und
 * effektiver Lead (LeadLaService), Allergene (GpAggregateService, 4-Wert-Modell), Kern-Anker (PairingService::gpAnkerAlle),
 * Geschmacksprofil aus foodalchemist_gp_taste_vectors (nur wenn GpAromaService::profilFehlt nein sagt).
 * Ein fremdes oder unsichtbares Grundprodukt zeigt „nicht verfügbar“, nie Daten.
 */
class GpAnsicht extends Component
{
    /** So viele Artikel zeigt die Liste; der Rest steht am Computer. */
    public const ARTIKEL_MAX = 8;

    public int $gpId;

    public function mount(int $gp): void
    {
        $this->gpId = $gp;
    }

    public function render(GpAggregateService $aggregate, LeadLaService $leads)
    {
        $team = Auth::user()?->currentTeamRelation;
        $gp = $team !== null
            ? FoodAlchemistGp::visibleToTeam($team)->with('commodity_group')->find($this->gpId)
            : null;

        $daten = ['gp' => $gp];
        if ($gp !== null) {
            $kette = $leads->rangliste($gp, $team);
            $leadId = $leads->effektiverLead($gp, $team)?->id;
            $profilFehlt = app(GpAromaService::class)->profilFehlt($gp->id);
            $vektor = $profilFehlt ? null : DB::table('foodalchemist_gp_taste_vectors')->where('gp_id', $gp->id)->first();

            $daten += [
                'kette' => $kette,
                'lead' => $leadId !== null ? $kette->firstWhere('id', $leadId) : null,
                'allergene' => $aggregate->allergene($gp),
                'kernAnker' => app(PairingService::class)->gpAnkerAlle($gp->id)->where('role', 'kern')->values(),
                'geschmack' => $vektor !== null
                    ? collect(SensorikService::DIMS)->mapWithKeys(fn ($d) => [$d => max(0.0, min(1.0, (float) $vektor->{$d}))])->all()
                    : null,
            ];
        }

        return view('foodalchemist::livewire.handy.gp-ansicht', $daten)->layout(FaShell::layout());
    }
}
