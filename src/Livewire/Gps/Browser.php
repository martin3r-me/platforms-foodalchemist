<?php

namespace Platform\FoodAlchemist\Livewire\Gps;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Platform\FoodAlchemist\Enums\GpStatus;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Services\GpService;
use Platform\FoodAlchemist\Services\KpiService;
use Platform\FoodAlchemist\Services\VocabularyService;
use Platform\FoodAlchemist\Support\Curate;

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

    /** Deeplink „in den Artikel" (z.B. aus dem Convenience-Screen): öffnet den Editor beim Ankommen. */
    #[Url(as: 'edit')]
    public bool $editOeffnen = false;

    #[Url(as: 'wg')]
    public string $commodity_group = '';

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
        $this->commodity_group = $this->commodity_group === $code ? '' : $code;
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

    /**
     * Inline-Status-Pflege direkt aus der Tabelle (Kuratierungs-Beschleuniger).
     * canCurate-Gate (D1) — der Select wird ohnehin nur für Kuratoren gerendert;
     * hier zusätzlich serverseitig abgesichert.
     */
    public function statusSetzen(int $id, string $status, GpService $gps): void
    {
        $team = Auth::user()?->currentTeamRelation;
        $gp = $team !== null ? FoodAlchemistGp::visibleToTeam($team)->find($id) : null;
        if ($gp === null || ! Curate::canCurate(Auth::user(), $gp)) {
            return;
        }
        $fall = GpStatus::tryFrom($status);
        if ($fall === null) {
            return;
        }
        try {
            $gps->setStatus($gp, $fall);
        } catch (\RuntimeException) {
            // Merged o. Ä. — im Inline-Select nicht anwählbar, still ignorieren.
        }
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

    #[\Livewire\Attributes\On('gp-geloescht')]
    public function gpGeloescht(): void
    {
        $this->gpId = null; // Auswahl aufheben — die Zeile existiert nicht mehr
    }

    public function mount(): void
    {
        if ($this->gpId !== null) {
            // Kontext-Erhalt: Auswahl aus der URL übersteht den Reload — Panel direkt befüllen
            $this->dispatch('gp-selected', id: $this->gpId);
            // Deeplink „in den Artikel": Editor gleich aufziehen, dann edit=1 aus der URL putzen.
            if ($this->editOeffnen) {
                $this->dispatch('gp-modal.oeffnen', id: $this->gpId);
                $this->editOeffnen = false;
            }
        }
    }

    public function render(GpService $gps, KpiService $kpis, VocabularyService $vocab)
    {
        $team = Auth::user()?->currentTeamRelation;
        $filters = [
            'search' => $this->search,
            'commodity_group' => $this->commodity_group,
            'sub_category' => $this->subKategorie,
            'status' => $this->status,
        ];

        return view('foodalchemist::livewire.gps.browser', [
            'gps' => $gps->paginateBrowser($filters, $team, in_array($this->perPage, [25, 50, 100, 250, 500], true) ? $this->perPage : 100),
            'warengruppen' => $team !== null ? $vocab->listWarengruppen($team) : collect(),
            'wgCounts' => $gps->wgCounts($team, $filters),
            'subCounts' => $this->commodity_group !== '' ? $gps->subKategorieCounts($team, $this->commodity_group) : [],
            // Merged = System-Tombstone, komplett unsichtbar (2026-07-02) — weder Filter noch Zeilen
            'statusFaelle' => array_values(array_filter(GpStatus::cases(), fn (GpStatus $f) => $f !== GpStatus::Merged)),
            'statusCounts' => $gps->statusCounts($team),
            'kpis' => $kpis->forTeam($team),
        ])->layout('platform::layouts.app');
    }
}
