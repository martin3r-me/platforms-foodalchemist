<?php

namespace Platform\FoodAlchemist\Livewire\Orders;

use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Platform\FoodAlchemist\Enums\OrderStatus;
use Platform\FoodAlchemist\Services\OrderService;

/**
 * Bestellungen-Browser: bestell-zentrierte Liste nach Liefertag/Bestelldatum + Filter +
 * „Neue Bestellung". Die einzelne Bestellung ist je (Lieferant, Liefertag) getrennt; der
 * Lieferant ist Attribut/Sekundärfilter, nicht die Navigations-Achse. Das Bearbeiten
 * (Positionen · Hinzufügen · Kopf/Status/Versand) liegt im Fullscreen-Editor (Orders\Editor),
 * geöffnet per `orders-editor.bearbeiten` {id}. Der Editor meldet Änderungen per
 * `orders-geaendert` zurück (Liste aktualisiert sich).
 */
class Index extends Component
{
    /** Deep-Link ?o=ID (Herkunfts-Links aus Produktion) → beim Mount den Editor öffnen. */
    #[Url(as: 'o')]
    public ?int $openId = null;

    #[Url(as: 's')]
    public string $statusFilter = '';

    /** Sekundärfilter (nicht Einstieg): auf einen Lieferanten eingrenzen. */
    #[Url(as: 'lief')]
    public ?int $supplierFilter = null;

    /** Kontextfilter: alle Lieferanten-Schienen, die aus dieser Produktion befüllt wurden. */
    #[Url(as: 'p')]
    public ?int $productionFilter = null;

    #[Url(as: 'q')]
    public string $suche = '';

    /** Datumsachse der Liste: nach Liefertag (Default) oder Bestelldatum (angelegt). */
    #[Url(as: 'basis')]
    public string $datumsbasis = 'liefertag';

    #[Url(as: 'von')]
    public ?string $von = null;

    #[Url(as: 'bis')]
    public ?string $bis = null;

    /** Aktiver Zeitraum-Preset ('' = alle, heute, woche, naechste). */
    #[Url(as: 'zeitraum')]
    public string $zeitraum = '';

    /** „Neue Bestellung": neutraler Start; Lieferant ergibt sich erst aus Artikel/Bedarf. */
    public ?string $neuerLiefertag = null;

    public ?string $hinweis = null;

    public ?string $fehler = null;

    public function mount(): void
    {
        if ($this->openId !== null) {
            $this->dispatch('orders-editor.bearbeiten', id: $this->openId);
        }
    }

    /** Zeilen-Klick → Editor für diese Bestellung öffnen. */
    public function oeffnen(int $id): void
    {
        $this->dispatch('orders-editor.bearbeiten', id: $id);
    }

    /** Zeitraum-Preset togglen und das von/bis-Fenster daraus setzen. */
    public function waehleZeitraum(string $key): void
    {
        $this->zeitraum = $this->zeitraum === $key ? '' : $key;
        [$this->von, $this->bis] = $this->zeitraumGrenzen($this->zeitraum);
    }

    /** @return array{0:?string, 1:?string} */
    private function zeitraumGrenzen(string $key): array
    {
        $heute = Carbon::today();

        return match ($key) {
            'heute' => [$heute->toDateString(), $heute->toDateString()],
            'woche' => [
                $heute->copy()->startOfWeek(Carbon::MONDAY)->toDateString(),
                $heute->copy()->endOfWeek(Carbon::SUNDAY)->toDateString(),
            ],
            'naechste' => [
                $heute->copy()->addWeek()->startOfWeek(Carbon::MONDAY)->toDateString(),
                $heute->copy()->addWeek()->endOfWeek(Carbon::SUNDAY)->toDateString(),
            ],
            default => [null, null],
        };
    }

    /** Manuelles Datum → Preset auf „frei" zurücksetzen. */
    public function updatedVon(): void
    {
        $this->zeitraum = '';
    }

    public function updatedBis(): void
    {
        $this->zeitraum = '';
    }

    /** „Neue Bestellung": Editor neutral öffnen; die Schienen entstehen beim Hinzufügen. */
    public function neueBestellung(): void
    {
        $this->hinweis = null;
        $this->fehler = null;
        $this->dispatch('orders-editor.neu', deliveryDate: $this->neuerLiefertag ?: null);
        $this->neuerLiefertag = null;
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

        $roh = $orders->listForTeam(
            $team,
            $this->statusFilter !== '' ? $this->statusFilter : null,
            [
                'datumsbasis' => $this->datumsbasis,
                'von' => $this->von ?: null,
                'bis' => $this->bis ?: null,
            ],
        );

        $lieferanten = $roh
            ->filter(fn ($o) => $o->supplier !== null)
            ->map(fn ($o) => ['id' => (int) $o->supplier->id, 'name' => (string) $o->supplier->name])
            ->unique('id')->sortBy('name')->values();

        $herkunftByOrder = $roh->mapWithKeys(function ($o) use ($orders) {
            $refs = $o->lines
                ->flatMap(fn ($l) => array_keys((array) ($l->source_contributions ?? [])))
                ->values()->all();

            return [(int) $o->id => $orders->herkunftAggregat($refs)];
        });

        $herkunftByOrder = $herkunftByOrder->map(fn ($items) => $orders->herkunftMitProduktionsnamen($team, $items));

        $produktionen = $herkunftByOrder
            ->flatten(1)
            ->filter(fn ($h) => ($h['production_order_id'] ?? null) !== null)
            ->map(fn ($h) => ['id' => (int) $h['production_order_id'], 'label' => (string) $h['label']])
            ->unique('id')
            ->sortBy('label')
            ->values();

        $suche = trim(mb_strtolower($this->suche));
        $liste = $roh
            ->when($this->supplierFilter !== null, fn ($c) => $c->filter(fn ($o) => (int) $o->supplier_id === $this->supplierFilter))
            ->when($this->productionFilter !== null, fn ($c) => $c->filter(fn ($o) => collect($herkunftByOrder[(int) $o->id] ?? [])
                ->contains(fn ($h) => (int) ($h['production_order_id'] ?? 0) === $this->productionFilter)))
            ->when($suche !== '', fn ($c) => $c->filter(function ($o) use ($suche, $herkunftByOrder) {
                $herkunft = collect($herkunftByOrder[(int) $o->id] ?? [])->pluck('label')->implode(' ');
                $hay = mb_strtolower(($o->supplier?->name ?? '') . ' ' . ($o->reference ?? '') . ' ' . $herkunft);

                return str_contains($hay, $suche);
            }))
            ->map(fn ($o) => [
                'id' => (int) $o->id,
                'supplier' => $o->supplier?->name ?? '—',
                'status' => $o->status instanceof OrderStatus ? $o->status : OrderStatus::from((string) $o->status),
                'total_net' => (float) $o->total_net,
                'reference' => $o->reference,
                'liefertag' => $o->desired_delivery_date?->toDateString(),
                'herkunft' => $herkunftByOrder[(int) $o->id] ?? [],
            ])->values();

        // Bei Liefertag-Basis nach Tag gruppieren (Reihenfolge kommt sortiert aus dem Service,
        // undatierte landen als '' am Ende). Bei Bestelldatum: eine flache Gruppe.
        $gruppiert = $this->datumsbasis === 'liefertag';
        $gruppen = $gruppiert
            ? $liste->groupBy(fn ($o) => $o['liefertag'] ?? '')
            : collect(['' => $liste]);

        return view('foodalchemist::livewire.orders.index', [
            'liste' => $liste,
            'gruppen' => $gruppen,
            'gruppiert' => $gruppiert,
            'lieferanten' => $lieferanten,
            'produktionen' => $produktionen,
            'statusFaelle' => OrderStatus::cases(),
        ])->layout('platform::layouts.app');
    }
}
