<?php

namespace Platform\FoodAlchemist\Livewire;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Component;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Services\KpiService;

/**
 * R6 (Dominique: «Dashboard komplett ausbauen nach deinem Gusto»):
 * Bestands-KPIs (KpiService, 60-s-Cache) + Workflow-Zähler (Review-Pipeline,
 * Allergen-Lücken, ungemappte Zutaten) + KI-Nutzung — alles klickbar in den
 * jeweiligen Browser mit vorgesetztem Filter (URL-Parameter, #[Url]-Vertrag).
 */
class Dashboard extends Component
{
    public function rendered()
    {
        $this->dispatch('comms', [
            'model' => null,
            'modelId' => null,
            'subject' => 'Food Alchemist Dashboard',
            'description' => 'Bestands- und Workflow-Übersicht',
            'url' => route('foodalchemist.dashboard'),
            'source' => 'foodalchemist.dashboard',
            'recipients' => [],
            'meta' => ['view_type' => 'dashboard'],
        ]);
    }

    public function render(KpiService $kpis)
    {
        $team = Auth::user()?->currentTeamRelation;
        $kette = $team !== null ? FoodAlchemistGp::teamAncestryIds($team) : [];

        $rezept = fn () => DB::table('foodalchemist_recipes')->whereIn('team_id', $kette)->whereNull('deleted_at');

        $workflow = $team === null ? [] : [
            'basis' => (clone $rezept())->where('ist_verkaufsrezept', false)->count(),
            'vk' => (clone $rezept())->where('ist_verkaufsrezept', true)->count(),
            'templates' => (clone $rezept())->where('is_template', true)->count(),
            'review' => (clone $rezept())->where('status', 'review')->count(),
            'draft' => (clone $rezept())->where('status', 'draft')->count(),
            'approved' => (clone $rezept())->where('status', 'approved')->count(),
            'allergen_low' => (clone $rezept())->whereIn('allergene_konfidenz', ['low', 'unknown'])->count(),
            'ungemappt' => (clone $rezept())->where('n_zutaten_ungemappt', '>', 0)->count(),
            'vk_ohne_klasse' => (clone $rezept())->where('ist_verkaufsrezept', true)->whereNull('speisen_klasse_id')->count(),
        ];

        $ki = ['calls' => 0, 'accepted' => 0];
        if ($team !== null && Schema::hasTable('foodalchemist_ai_call_log')) {
            $ki = [
                'calls' => DB::table('foodalchemist_ai_call_log')->where('team_id', $team->id)->count(),
                'accepted' => DB::table('foodalchemist_ai_call_log')->where('team_id', $team->id)->whereNotNull('accepted_at')->count(),
            ];
        }

        return view('foodalchemist::livewire.dashboard', [
            'kpis' => $kpis->forTeam($team),
            'workflow' => $workflow,
            'ki' => $ki,
        ])->layout('platform::layouts.app');
    }
}
