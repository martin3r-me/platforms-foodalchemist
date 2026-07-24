<?php

namespace Platform\FoodAlchemist\Livewire\Orders;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;
use Platform\FoodAlchemist\Enums\OrderStatus;
use Platform\FoodAlchemist\Services\OrderService;

/**
 * Spec 17/S2 + Spec 20/E1 — „Bestellungen" als 3-Panel-Cockpit (Muster: Produktion):
 *   Panel 1 Schienen-Browser (Status-/Lieferant-Filter, Suche, Entwürfe zuerst)
 *   Panel 2 Positionen (Artikel · Gebinde · Anzahl Auto/Manuell · Bedarf · Preis · Summe · Herkunft · Notiz · ✕)
 *   Panel 3 Detail/Aktionen (Kopf-Edit, Status-Buttons, MOQ-Ampel, Herkunft, Export)
 * Nur der `draft` ist editierbar; Schreiben geht durch den D1-gescopten OrderService
 * (isOwnedBy + Status-Guard).
 */
class Index extends Component
{
    #[Url(as: 'o')]
    public ?int $selectedId = null;

    #[Url(as: 's')]
    public string $statusFilter = '';

    #[Url(as: 'lief')]
    public ?int $supplierFilter = null;

    #[Url(as: 'q')]
    public string $suche = '';

    // Kopf-Edit-Form (Panel 3) — beim Wählen aus dem Detail befüllt.
    public string $formReference = '';

    public string $formDeliveryDate = '';

    public string $formNote = '';

    public ?string $hinweis = null;

    public ?string $fehler = null;

    public function select(int $id): void
    {
        $this->selectedId = $id;
        $this->hinweis = null;
        $this->fehler = null;
        $this->ladeKopf();
    }

    /** Kopf-Felder des aktiven Belegs in die Form spiegeln (für Panel 3). */
    private function ladeKopf(): void
    {
        $this->formReference = '';
        $this->formDeliveryDate = '';
        $this->formNote = '';
        if ($this->selectedId === null) {
            return;
        }
        try {
            $team = Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
            $d = app(OrderService::class)->detail($team, $this->selectedId);
            $this->formReference = (string) ($d['reference'] ?? '');
            $this->formDeliveryDate = (string) ($d['desired_delivery_date'] ?? '');
            $this->formNote = (string) ($d['note'] ?? '');
        } catch (\Throwable $e) {
            // Detail wird in render() ohnehin defensiv behandelt.
        }
    }

    public function saveHeader(OrderService $orders): void
    {
        if ($this->selectedId === null) {
            return;
        }
        $this->fuehreAus(fn ($team) => $orders->updateHeader($team, $this->selectedId, [
            'reference' => $this->formReference,
            'desired_delivery_date' => $this->formDeliveryDate,
            'note' => $this->formNote,
        ]), 'Kopf gespeichert.');
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

    public function updateLineNote(int $lineId, $note, OrderService $orders): void
    {
        $this->fuehreAus(fn ($team) => $orders->updateLine($team, $lineId, ['note' => $note]), 'Notiz gespeichert.');
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

        $roh = $orders->listForTeam($team, $this->statusFilter !== '' ? $this->statusFilter : null);

        // Lieferanten-Optionen aus dem (nur status-gefilterten) Bestand — für Panel-1-Filter.
        $lieferanten = $roh
            ->filter(fn ($o) => $o->supplier !== null)
            ->map(fn ($o) => ['id' => (int) $o->supplier->id, 'name' => (string) $o->supplier->name])
            ->unique('id')->sortBy('name')->values();

        $suche = trim(mb_strtolower($this->suche));
        $liste = $roh
            ->when($this->supplierFilter !== null, fn ($c) => $c->filter(fn ($o) => (int) $o->supplier_id === $this->supplierFilter))
            ->when($suche !== '', fn ($c) => $c->filter(function ($o) use ($suche) {
                $hay = mb_strtolower(($o->supplier?->name ?? '') . ' ' . ($o->reference ?? ''));

                return str_contains($hay, $suche);
            }))
            ->map(fn ($o) => [
                'id' => (int) $o->id,
                'supplier' => $o->supplier?->name ?? '—',
                'status' => $o->status instanceof OrderStatus ? $o->status : OrderStatus::from((string) $o->status),
                'total_net' => (float) $o->total_net,
                'reference' => $o->reference,
            ])->values();

        $detail = null;
        $erlaubteStatus = [];
        $mailto = null;
        if ($this->selectedId !== null) {
            try {
                $detail = $orders->detail($team, $this->selectedId);
                $aktuell = OrderStatus::from($detail['status']);
                foreach ([OrderStatus::Sent, OrderStatus::Confirmed, OrderStatus::Delivered, OrderStatus::Cancelled] as $z) {
                    if ($aktuell->darfWechselnZu($z)) {
                        $erlaubteStatus[] = $z;
                    }
                }
                // S3: vorbefüllte E-Mail an den Lieferanten (Bestellweg email_order).
                $m = $orders->mailtoData($team, $this->selectedId);
                if (($m['to'] ?? '') !== '') {
                    $mailto = 'mailto:' . $m['to'] . '?subject=' . rawurlencode($m['subject']) . '&body=' . rawurlencode($m['body']);
                }
            } catch (\Throwable $e) {
                $this->selectedId = null;
            }
        }

        return view('foodalchemist::livewire.orders.index', [
            'liste' => $liste,
            'lieferanten' => $lieferanten,
            'detail' => $detail,
            'erlaubteStatus' => $erlaubteStatus,
            'mailto' => $mailto,
        ])->layout('platform::layouts.app');
    }
}
