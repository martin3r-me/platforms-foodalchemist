<?php

namespace Platform\FoodAlchemist\Livewire\Produktion;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Platform\FoodAlchemist\Enums\ProductionOrderStatus;
use Platform\FoodAlchemist\Services\ProductionOrderService;

/**
 * Spec 18 — Produktion: Browser-Liste der Produktionsaufträge (Datum, Anlass,
 * Status), rechts das Cockpit-DetailPanel. „+ Neuer Produktionsauftrag" öffnet
 * den Editor. Absorbiert die bisherigen Planungsblätter als Vorschau-Kartei
 * IM Editor — /blaetter existiert nur noch als Redirect hierher.
 */
class Browser extends Component
{
    #[Url(as: 'auftrag')]
    public ?int $orderId = null;

    #[Url(as: 's')]
    public string $statusFilter = '';

    #[Url(as: 'von')]
    public ?string $von = null;

    #[Url(as: 'bis')]
    public ?string $bis = null;

    #[Url(as: 'q')]
    public string $suche = '';

    public function waehle(int $id): void
    {
        $this->orderId = $id;
        $this->dispatch('production-order-selected', id: $id);
    }

    public function neuerAuftrag(): void
    {
        $this->dispatch('produktion-editor.oeffnen');
    }

    #[On('produktion-gespeichert')]
    public function aktualisiere(int $id): void
    {
        $this->orderId = $id;
        $this->dispatch('production-order-selected', id: $id);
    }

    #[On('produktion-status-geaendert')]
    public function aktualisiereListe(): void
    {
        // Re-render holt den neuen Status der Liste (Cross-Component-Refresh, kein State nötig).
    }

    public function mount(): void
    {
        if ($this->orderId !== null) {
            $this->dispatch('production-order-selected', id: $this->orderId); // Kontext-Erhalt nach Reload
        }
    }

    public function render(ProductionOrderService $svc)
    {
        $team = Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');

        $auftraege = $svc->listForTeam($team, $this->statusFilter !== '' ? $this->statusFilter : null)
            ->when($this->von, fn ($c) => $c->filter(fn ($o) => $o->production_date->toDateString() >= $this->von))
            ->when($this->bis, fn ($c) => $c->filter(fn ($o) => $o->production_date->toDateString() <= $this->bis))
            ->when(trim($this->suche) !== '', fn ($c) => $c->filter(
                fn ($o) => str_contains(mb_strtolower((string) $o->name . ' ' . (string) $o->reference), mb_strtolower(trim($this->suche)))
            ))
            ->values();

        return view('foodalchemist::livewire.produktion.browser', [
            'auftraege' => $auftraege,
            'statusFaelle' => ProductionOrderStatus::cases(),
        ])->layout('platform::layouts.app');
    }
}
