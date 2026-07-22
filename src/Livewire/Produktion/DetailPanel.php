<?php

namespace Platform\FoodAlchemist\Livewire\Produktion;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;
use Platform\FoodAlchemist\Enums\ProductionOrderStatus;
use Platform\FoodAlchemist\Services\OrderService;
use Platform\FoodAlchemist\Services\ProductionOrderService;

/**
 * Spec 18 — Cockpit-DetailPanel eines Produktionsauftrags (v3-Design wie
 * Recipes/Verkauf/Concepter). Status-Aktionen + „An Bestellung übergeben"
 * (Einbahn-Handover: Produktion ist der Planungs-Einstieg, die Bestellschiene
 * der nachgelagerte Beleg — kein Auto-Sync, kein Rückkanal).
 */
class DetailPanel extends Component
{
    public ?int $orderId = null;

    public ?string $hinweis = null;

    public ?string $fehler = null;

    public function mount(?int $orderId = null): void
    {
        $this->orderId = $orderId;
    }

    #[On('production-order-selected')]
    public function waehle(int $id): void
    {
        $this->orderId = $id;
        $this->hinweis = null;
        $this->fehler = null;
    }

    public function setStatus(string $status, ProductionOrderService $svc): void
    {
        $ziel = ProductionOrderStatus::tryFrom($status);
        if ($ziel === null || $this->orderId === null) {
            return;
        }
        $this->fuehreAus(fn ($team) => $svc->setStatus($team, $this->orderId, $ziel), 'Status gesetzt.');
        $this->dispatch('produktion-status-geaendert');
    }

    public function updateLineNote(int $lineId, string $note, ProductionOrderService $svc): void
    {
        $this->fuehreAus(fn ($team) => $svc->updateLine($team, $lineId, ['note' => $note]), 'Notiz gespeichert.');
    }

    /** Einbahn-Übergabe: Bedarf aller Ziele dieses Auftrags an die Bestellschienen. */
    public function anBestellungUebergeben(ProductionOrderService $prod, OrderService $orders): void
    {
        $this->fuehreAus(function ($team) use ($prod, $orders) {
            $order = $prod->detail($team, $this->orderId);
            $touched = 0;
            foreach ($order['targets'] as $ziel) {
                $sourceRef = 'produktion:' . $this->orderId . ':' . ($ziel['source_ref'] ?? '');
                $res = $orders->addNeedFromTarget($team, Arr::except($ziel, ['source_ref', 'label']), $sourceRef);
                $touched += count($res['orders']);
            }
            $this->hinweis = $touched > 0 ? "{$touched} Bestellschiene(n) aktualisiert." : 'Kein bestellbarer Bedarf.';
        }, null);
    }

    private function fuehreAus(callable $fn, ?string $ok): void
    {
        $this->hinweis = null;
        $this->fehler = null;
        try {
            $team = Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
            $fn($team);
            $this->hinweis ??= $ok;
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function render(ProductionOrderService $svc)
    {
        $team = Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');

        $detail = null;
        $erlaubteStatus = [];
        if ($this->orderId !== null) {
            try {
                $detail = $svc->detail($team, $this->orderId);
                $aktuell = ProductionOrderStatus::from($detail['status']);
                foreach ([ProductionOrderStatus::InProgress, ProductionOrderStatus::Done, ProductionOrderStatus::Cancelled] as $z) {
                    if ($aktuell->darfWechselnZu($z)) {
                        $erlaubteStatus[] = $z;
                    }
                }
            } catch (\Throwable) {
                $this->orderId = null;
            }
        }

        return view('foodalchemist::livewire.produktion.detail-panel', [
            'detail' => $detail,
            'erlaubteStatus' => $erlaubteStatus,
        ]);
    }
}
