<?php

namespace Platform\FoodAlchemist\Livewire\Gps;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\Attributes\Url;
use Livewire\WithPagination;
use Platform\FoodAlchemist\Services\GpService;
use Platform\FoodAlchemist\Services\KpiService;

/**
 * GP-Browser (Vertical Slice, D-3-Teil): Suche + Warengruppen-/Status-Filter + Pagination.
 * Jede Entität hat eine eigene Route (V-17 — kein Tab-State-Verlust mehr).
 */
class Index extends Component
{
    use WithPagination;

    #[Url(as: 'zeilen')]
    public int $perPage = 100;

    public string $search = '';
    public string $warengruppe = '';
    public string $status = '';

    protected $queryString = [
        'search' => ['except' => ''],
        'warengruppe' => ['except' => ''],
        'status' => ['except' => ''],
        'page' => ['except' => 1],
    ];

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedWarengruppe(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function render(GpService $gps, KpiService $kpis)
    {
        $team = Auth::user()?->currentTeamRelation;

        return view('foodalchemist::livewire.gps.index', [
            'gps' => $gps->paginate([
                'search' => $this->search,
                'warengruppe' => $this->warengruppe,
                'status' => $this->status,
            ], $team, in_array($this->perPage, [25, 50, 100, 250, 500], true) ? $this->perPage : 100),
            'warengruppen' => $gps->warengruppenOptions($team),
            'statusCounts' => $gps->statusCounts($team),
            'kpis' => $kpis->forTeam($team),
        ])->layout('platform::layouts.app');
    }
}
