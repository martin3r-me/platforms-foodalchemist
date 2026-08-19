<?php

namespace Platform\FoodAlchemist\Livewire\Produktion;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;
use Platform\FoodAlchemist\Enums\ProductionOrderStatus;
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
        // 0 = „nichts mehr ausgewählt" (z. B. nach dem Löschen) — sonst suchte das Panel
        // beharrlich nach Auftrag #0 und zeigte einen Fehler statt des Leer-Zustands.
        $this->orderId = $id > 0 ? $id : null;
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

    public function materialbedarfFreigeben(ProductionOrderService $prod): void
    {
        $this->fuehreAus(fn ($team) => $prod->materialbedarfFreigeben($team, $this->orderId, Auth::id()), 'Materialbedarf für den Einkauf freigegeben.');
        $this->dispatch('produktion-status-geaendert');
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
        $verknuepfteOrders = collect();
        $zielUebergaben = [];
        if ($this->orderId !== null) {
            try {
                $detail = $svc->detail($team, $this->orderId);
                $aktuell = ProductionOrderStatus::from($detail['status']);
                foreach ([ProductionOrderStatus::InProgress, ProductionOrderStatus::Done, ProductionOrderStatus::Cancelled] as $z) {
                    if ($aktuell->darfWechselnZu($z)) {
                        $erlaubteStatus[] = $z;
                    }
                }
                $verknuepfteOrders = $svc->verknuepfteOrders($team, $this->orderId);
                $zielUebergaben = $svc->zielUebergaben($team, $this->orderId);
            } catch (\Throwable) {
                $this->orderId = null;
            }
        }

        // Spec 30 E5: Posten-Auslastung + passive Überlast-Meldung für DIESEN Auftrag
        $postenSummen = $this->orderId !== null && $detail !== null ? $svc->postenSummen($team, $this->orderId) : [];
        $kapazitaetsWarnungen = $this->orderId !== null && $detail !== null
            ? app(\Platform\FoodAlchemist\Services\ProductionCapacityService::class)->warnungenFuer($team, $this->orderId)
            : [];

        return view('foodalchemist::livewire.produktion.detail-panel', [
            'postenSummen' => $postenSummen,
            'kapazitaetsWarnungen' => $kapazitaetsWarnungen,
            'detail' => $detail,
            'verknuepfteOrders' => $verknuepfteOrders,
            'zielUebergaben' => $zielUebergaben,
            'erlaubteStatus' => $erlaubteStatus,
        ]);
    }
}
