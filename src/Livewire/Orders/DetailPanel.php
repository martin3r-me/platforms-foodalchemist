<?php

namespace Platform\FoodAlchemist\Livewire\Orders;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;
use Platform\FoodAlchemist\Services\OrderService;

class DetailPanel extends Component
{
    use \Platform\FoodAlchemist\Livewire\Concerns\HandyLesebereich;   // #1003 PR5: am Handy nur lesen

    /** #1003: am Handy nur Auswahl; „Bestellung bearbeiten“ bleibt am Computer. */
    public function handyLeseMethoden(): array
    {
        return ['waehle'];
    }

    public ?int $orderId = null;

    public function mount(?int $orderId = null): void
    {
        $this->orderId = $orderId;
    }

    #[On('order-selected')]
    public function waehle(int $id): void
    {
        $this->orderId = $id > 0 ? $id : null;
    }

    public function bearbeiten(): void
    {
        if ($this->orderId !== null) {
            $this->dispatch('orders-editor.bearbeiten', id: $this->orderId)->to(Editor::class);
        }
    }

    public function render(OrderService $orders)
    {
        $detail = null;
        if ($this->orderId !== null) {
            try {
                $team = Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
                $detail = $orders->detail($team, $this->orderId);
            } catch (\Throwable) {
                $this->orderId = null;
            }
        }

        return view('foodalchemist::livewire.orders.detail-panel', ['detail' => $detail]);
    }
}
