<?php

namespace Platform\FoodAlchemist\Livewire\Orders;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;
use Platform\FoodAlchemist\Enums\OrderStatus;
use Platform\FoodAlchemist\Services\OrderService;

/**
 * Spec 17/S2 — „Bestellungen": Bestellschienen je Lieferant verwalten. Liste
 * (Entwürfe zuerst) + Detail mit Gebinde-Zeilen, MOQ-Ampel, Status-Buttons und
 * manueller Gebinde-Korrektur. Nur der `draft` ist editierbar; Schreiben geht
 * durch den D1-gescopten OrderService (isOwnedBy + Status-Guard).
 */
class Index extends Component
{
    #[Url(as: 'o')]
    public ?int $selectedId = null;

    #[Url(as: 's')]
    public string $statusFilter = '';

    public ?string $hinweis = null;

    public ?string $fehler = null;

    public function select(int $id): void
    {
        $this->selectedId = $id;
        $this->hinweis = null;
        $this->fehler = null;
    }

    public function setStatus(string $status, OrderService $orders): void
    {
        $ziel = OrderStatus::tryFrom($status);
        if ($ziel === null || $this->selectedId === null) {
            return;
        }
        $this->fuehreAus(fn ($team) => $orders->setStatus($team, $this->selectedId, $ziel), 'Status gesetzt.');
    }

    public function updateLineQty(int $lineId, $qty, OrderService $orders): void
    {
        $this->fuehreAus(fn ($team) => $orders->updateLine($team, $lineId, ['qty_packs' => $qty]), 'Menge angepasst.');
    }

    public function resetLineQty(int $lineId, OrderService $orders): void
    {
        $this->fuehreAus(fn ($team) => $orders->updateLine($team, $lineId, ['reset_qty' => true]), 'Auto-Menge wiederhergestellt.');
    }

    public function removeLine(int $lineId, OrderService $orders): void
    {
        $this->fuehreAus(fn ($team) => $orders->removeLine($team, $lineId), 'Position entfernt.');
    }

    private function fuehreAus(callable $fn, string $ok): void
    {
        $this->hinweis = null;
        $this->fehler = null;
        try {
            $team = Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
            $fn($team);
            $this->hinweis = $ok;
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function render(OrderService $orders)
    {
        $team = Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');

        $liste = $orders->listForTeam($team, $this->statusFilter !== '' ? $this->statusFilter : null)
            ->map(fn ($o) => [
                'id' => (int) $o->id,
                'supplier' => $o->supplier?->name ?? '—',
                'status' => $o->status instanceof OrderStatus ? $o->status : OrderStatus::from((string) $o->status),
                'total_net' => (float) $o->total_net,
                'reference' => $o->reference,
            ]);

        $detail = null;
        $erlaubteStatus = [];
        if ($this->selectedId !== null) {
            try {
                $detail = $orders->detail($team, $this->selectedId);
                $aktuell = OrderStatus::from($detail['status']);
                foreach ([OrderStatus::Sent, OrderStatus::Confirmed, OrderStatus::Delivered, OrderStatus::Cancelled] as $z) {
                    if ($aktuell->darfWechselnZu($z)) {
                        $erlaubteStatus[] = $z;
                    }
                }
            } catch (\Throwable $e) {
                $this->selectedId = null;
            }
        }

        return view('foodalchemist::livewire.orders.index', [
            'liste' => $liste,
            'detail' => $detail,
            'erlaubteStatus' => $erlaubteStatus,
        ])->layout('platform::layouts.app');
    }
}
