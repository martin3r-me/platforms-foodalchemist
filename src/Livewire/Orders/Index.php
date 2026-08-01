<?php

namespace Platform\FoodAlchemist\Livewire\Orders;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Platform\FoodAlchemist\Enums\OrderStatus;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Services\OrderService;

/**
 * Bestellungen-Browser: Schienen-Liste + Filter + „Neue Bestellung". Das Bearbeiten
 * (Positionen · Hinzufügen · Kopf/Status/Versand) liegt im Fullscreen-Editor
 * (Orders\Editor), geöffnet per `orders-editor.bearbeiten` {id}. Der Editor meldet
 * Änderungen per `orders-geaendert` zurück (Liste aktualisiert sich).
 */
class Index extends Component
{
    /** Deep-Link ?o=ID (Herkunfts-Links aus Produktion) → beim Mount den Editor öffnen. */
    #[Url(as: 'o')]
    public ?int $openId = null;

    #[Url(as: 's')]
    public string $statusFilter = '';

    #[Url(as: 'lief')]
    public ?int $supplierFilter = null;

    #[Url(as: 'q')]
    public string $suche = '';

    /** „Neue Bestellung": leere Draft-Schiene je Lieferant. */
    public ?int $neuerLieferant = null;

    public ?string $hinweis = null;

    public ?string $fehler = null;

    public function mount(): void
    {
        if ($this->openId !== null) {
            $this->dispatch('orders-editor.bearbeiten', id: $this->openId);
        }
    }

    /** Zeilen-Klick → Editor für diese Schiene öffnen. */
    public function oeffnen(int $id): void
    {
        $this->dispatch('orders-editor.bearbeiten', id: $id);
    }

    /** „Neue Bestellung": Draft je Lieferant anlegen und direkt im Editor öffnen. */
    public function neueBestellung(OrderService $orders): void
    {
        if ($this->neuerLieferant === null) {
            $this->fehler = 'Erst einen Lieferanten wählen.';

            return;
        }
        $this->hinweis = null;
        $this->fehler = null;
        try {
            $team = Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
            $draft = $orders->createDraft($team, (int) $this->neuerLieferant, [], Auth::id());
            $this->neuerLieferant = null;
            $this->dispatch('orders-editor.bearbeiten', id: (int) $draft->id);
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();
        }
    }

    /** Editor meldet eine Änderung — die Liste (Summen/Status) neu rendern. */
    #[On('orders-geaendert')]
    public function aktualisiere(): void
    {
        // Re-render zieht die neuen Werte; kein State nötig.
    }

    public function render(OrderService $orders)
    {
        $team = Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');

        $roh = $orders->listForTeam($team, $this->statusFilter !== '' ? $this->statusFilter : null);

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

        $alleLieferanten = FoodAlchemistSupplier::visibleToTeam($team)
            ->orderBy('name')->get(['id', 'name'])
            ->map(fn ($s) => ['id' => (int) $s->id, 'name' => (string) $s->name])->values();

        return view('foodalchemist::livewire.orders.index', [
            'liste' => $liste,
            'lieferanten' => $lieferanten,
            'alleLieferanten' => $alleLieferanten,
            'statusFaelle' => OrderStatus::cases(),
        ])->layout('platform::layouts.app');
    }
}
