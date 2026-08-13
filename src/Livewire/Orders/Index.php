<?php

namespace Platform\FoodAlchemist\Livewire\Orders;

use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Platform\FoodAlchemist\Enums\LeadLaStrategie;
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

    /** Optional leere 0-Euro-Entwürfe ausblenden. */
    #[Url(as: 'pos')]
    public bool $nurMitPositionen = false;

    #[Url(as: 'sicht')]
    public string $sicht = 'bestellungen';

    #[Url(as: 'klaer')]
    public bool $nurMitKlaerung = false;

    /** „Neue Bestellung": neutraler Start; Lieferant ergibt sich erst aus Artikel/Bedarf. */
    public ?string $neuerLiefertag = null;

    /** Einkaufsstrategie für den neutralen Cockpit-Start. */
    public string $neueStrategie = '';

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
        $this->dispatch('orders-editor.neu', deliveryDate: $this->neuerLiefertag ?: null, strategy: $this->neueStrategie ?: null);
        $this->neuerLiefertag = null;
    }

    public function leereEntwuerfeLoeschen(OrderService $orders): void
    {
        $team = Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
        $count = $orders->deleteEmptyDrafts($team);
        $this->hinweis = $count === 1 ? '1 leerer Entwurf gelöscht.' : $count . ' leere Entwürfe gelöscht.';
        $this->fehler = null;
    }

    private function strategieLabel(?string $value): string
    {
        if ($value === null || $value === '') {
            return 'Team-Standard';
        }

        return LeadLaStrategie::tryFrom($value)?->label() ?? $value;
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
            ->when($this->nurMitPositionen, fn ($c) => $c->filter(fn ($o) => $o->lines->count() > 0))
            ->when($this->nurMitKlaerung, fn ($c) => $c->filter(fn ($o) => $orders->orderWarnings($o) !== []))
            ->when($suche !== '', fn ($c) => $c->filter(function ($o) use ($suche, $herkunftByOrder) {
                $herkunft = collect($herkunftByOrder[(int) $o->id] ?? [])->pluck('label')->implode(' ');
                $positionen = $o->lines
                    ->map(fn ($l) => implode(' ', [
                        $l->designation ?? '',
                        $l->article_number ?? '',
                        $l->packaging_unit ?? '',
                        $l->supplierItem?->designation ?? '',
                        $l->supplierItem?->article_number ?? '',
                        $l->gp?->name ?? '',
                        $l->note ?? '',
                        $l->received_note ?? '',
                        $l->invoice_note ?? '',
                        $l->claim_note ?? '',
                    ]))
                    ->implode(' ');
                $hay = mb_strtolower(implode(' ', [
                    'ord-' . (int) $o->id,
                    '#' . (int) $o->id,
                    $o->supplier?->name ?? '',
                    $o->reference ?? '',
                    $o->supplier_order_number ?? '',
                    $o->invoice_number ?? '',
                    $o->supplier_confirmation_note ?? '',
                    $o->invoice_note ?? '',
                    $o->payment_note ?? '',
                    $o->approval_note ?? '',
                    $o->note ?? '',
                    $herkunft,
                    $positionen,
                ]));

                return str_contains($hay, $suche);
            }))
            ->map(function ($o) use ($herkunftByOrder, $orders) {
                $lineCount = $o->lines->count();
                $totalNet = (float) $o->total_net;
                $invoiceDueDate = $o->invoice_date !== null && $o->supplier?->payment_term_days !== null
                    ? $o->invoice_date->copy()->addDays(max(0, (int) $o->supplier->payment_term_days))->toDateString()
                    : null;
                $payment = $orders->paymentSummary($o);
                $approval = $orders->approvalSummary($o);

                return [
                    'id' => (int) $o->id,
                    'order_label' => 'ord-' . (int) $o->id,
                    'supplier_order_number' => $o->supplier_order_number,
                    'invoice_number' => $o->invoice_number,
                    'invoice_date' => $o->invoice_date?->toDateString(),
                    'invoice_due_date' => $invoiceDueDate,
                    'payment_term_days' => $o->supplier?->payment_term_days,
                    'payment' => $payment,
                    'approval' => $approval,
                    'supplier' => $o->supplier?->name ?? '—',
                    'status' => $o->status instanceof OrderStatus ? $o->status : OrderStatus::from((string) $o->status),
                    'total_net' => $totalNet,
                    'line_count' => $lineCount,
                    'reference' => $o->reference,
                    'liefertag' => $o->desired_delivery_date?->toDateString(),
                    'strategy' => (string) ($o->sourcing_strategy ?? ''),
                    'strategy_label' => $this->strategieLabel($o->sourcing_strategy),
                    'herkunft' => $herkunftByOrder[(int) $o->id] ?? [],
                    'warnings' => $orders->orderWarnings($o),
                ];
            })->values();

        $kpis = [
            'orders' => $liste->count(),
            'drafts' => $liste->filter(fn ($o) => $o['status'] === OrderStatus::Draft)->count(),
            'ready' => $liste->filter(fn ($o) => $o['status'] === OrderStatus::Draft && $o['line_count'] > 0 && (float) $o['total_net'] > 0.0 && empty($o['warnings']))->count(),
            'positions' => (int) $liste->sum('line_count'),
            'total_net' => round((float) $liste->sum('total_net'), 2),
            'clarifications' => $liste->filter(fn ($o) => ! empty($o['warnings']))->count(),
            'suppliers' => $liste->pluck('supplier')->filter(fn ($s) => $s !== '—')->unique()->count(),
        ];

        // Bei Liefertag-Basis nach Tag gruppieren (Reihenfolge kommt sortiert aus dem Service,
        // undatierte landen als '' am Ende). Bei Bestelldatum: eine flache Gruppe.
        $gruppiert = $this->datumsbasis === 'liefertag';
        $gruppen = $gruppiert
            ? $liste->groupBy(fn ($o) => $o['liefertag'] ?? '')
            : collect(['' => $liste]);

        $liefertagGruppen = $liste->groupBy(fn ($o) => $o['liefertag'] ?? '')
            ->map(fn ($items, $tag) => [
                'key' => (string) $tag,
                'label' => $tag === '' ? 'Ohne Liefertag' : Carbon::parse($tag)->locale('de')->isoFormat('dddd, DD.MM.YYYY'),
                'orders' => $items->values(),
                'suppliers' => $items->pluck('supplier')->unique()->count(),
                'total_net' => round((float) $items->sum('total_net'), 2),
                'line_count' => (int) $items->sum('line_count'),
                'warnings' => $items->flatMap(fn ($o) => $o['warnings'])->unique()->values()->all(),
            ])->values();

        $lieferantGruppen = $liste->groupBy('supplier')
            ->map(fn ($items, $supplier) => [
                'supplier' => (string) $supplier,
                'orders' => $items->sortBy(fn ($o) => $o['liefertag'] ?? '9999-12-31')->values(),
                'dates' => $items->pluck('liefertag')->filter()->unique()->count(),
                'total_net' => round((float) $items->sum('total_net'), 2),
                'line_count' => (int) $items->sum('line_count'),
                'warnings' => $items->flatMap(fn ($o) => $o['warnings'])->unique()->values()->all(),
            ])->sortBy('supplier')->values();

        return view('foodalchemist::livewire.orders.index', [
            'liste' => $liste,
            'gruppen' => $gruppen,
            'liefertagGruppen' => $liefertagGruppen,
            'lieferantGruppen' => $lieferantGruppen,
            'gruppiert' => $gruppiert,
            'lieferanten' => $lieferanten,
            'produktionen' => $produktionen,
            'statusFaelle' => OrderStatus::cases(),
            'strategieOptionen' => LeadLaStrategie::cases(),
            'kpis' => $kpis,
        ])->layout('platform::layouts.app');
    }
}
