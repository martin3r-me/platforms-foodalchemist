<?php

namespace Platform\FoodAlchemist\Livewire\Gps;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Platform\FoodAlchemist\Enums\GpStatus;
use Platform\FoodAlchemist\Services\GpService;
use Platform\FoodAlchemist\Services\KpiService;
use Platform\FoodAlchemist\Services\VocabularyService;

/**
 * M3-01/02 / P-1 + Screen 1: GP-Browser-Neubau.
 * WG-Baum in linker Page-Sidebar (Platzierungs-Entscheid 2026-06-11), Auswahl/Filter
 * in der URL (V-17 + Kontext-Erhalt-Gebot), Zeilen-Klick = Event ohne Seitenwechsel,
 * DetailPanel (M3-03) hört auf `gp-selected` in der rechten Page-Sidebar.
 */
class Browser extends Component
{
    use WithPagination;

    #[Url(as: 'gp')]
    public ?int $gpId = null;

    #[Url(as: 'wg')]
    public string $warengruppe = '';

    #[Url(as: 'sub')]
    public string $subKategorie = '';

    #[Url]
    public string $status = '';

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'zeilen')]
    public int $perPage = 100;

    public function waehleWg(string $code): void
    {
        $this->warengruppe = $this->warengruppe === $code ? '' : $code;
        $this->subKategorie = '';
        $this->resetPage();
    }

    public function waehleSub(string $sub): void
    {
        $this->subKategorie = $this->subKategorie === $sub ? '' : $sub;
        $this->resetPage();
    }

    /** R6: Direkt-Öffnen — Namens-Klick öffnet den GP-Editor. */
    public function bearbeite(int $id): void
    {
        $this->waehleGp($id);
        $this->dispatch('gp-modal.oeffnen', id: $id);
    }

    public function waehleGp(int $id): void
    {
        $this->gpId = $id;
        $this->dispatch('gp-selected', id: $id); // M3-03: Panel hört zu — KEIN Seitenwechsel
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        $this->perPage = in_array((int) $this->perPage, [25, 50, 100, 250, 500], true) ? (int) $this->perPage : 100;
        $this->resetPage();
    }

    #[\Livewire\Attributes\On('gp-gespeichert')]
    public function aktualisiere(): void
    {
        // M3-09: Neuanlage/Edit → Tabelle + Baum-Counts neu rendern (Kontext bleibt)
    }

    public function mount(): void
    {
        if ($this->gpId !== null) {
            // Kontext-Erhalt: Auswahl aus der URL übersteht den Reload — Panel direkt befüllen
            $this->dispatch('gp-selected', id: $this->gpId);
        }
    }

    public function render(GpService $gps, KpiService $kpis, VocabularyService $vocab)
    {
        $team = Auth::user()?->currentTeamRelation;
        $filters = [
            'search' => $this->search,
            'warengruppe' => $this->warengruppe,
            'sub_kategorie' => $this->subKategorie,
            'status' => $this->status,
        ];

        return view('foodalchemist::livewire.gps.browser', [
            'gps' => $gps->paginateBrowser($filters, $team, in_array($this->perPage, [25, 50, 100, 250, 500], true) ? $this->perPage : 100),
            'warengruppen' => $team !== null ? $vocab->listWarengruppen($team) : collect(),
            'wgCounts' => $gps->wgCounts($team, $filters),
            'subCounts' => $this->warengruppe !== '' ? $gps->subKategorieCounts($team, $this->warengruppe) : [],
            'statusFaelle' => GpStatus::cases(),
            'statusCounts' => $gps->statusCounts($team),
            'kpis' => $kpis->forTeam($team),
        ])->layout('platform::layouts.app');
    }
}
