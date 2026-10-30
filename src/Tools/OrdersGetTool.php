<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Enums\OrderStatus;
use Platform\FoodAlchemist\Services\BestellListeService;
use Platform\FoodAlchemist\Services\OrderService;
use Platform\FoodAlchemist\Services\StandortService;
use Platform\FoodAlchemist\Support\Zeitraum;

/**
 * Spec 17/S2 (read): Bestellschienen/Bestellungen. Ohne order_id → Liste (optional
 * nach Status gefiltert); mit order_id → Detail inkl. Gebinde-Zeilen + MOQ-Ampel.
 */
class OrdersGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.orders.GET';
    }

    public function getDescription(): string
    {
        return 'Bestellungen/Bestellschienen des Teams. Ohne order_id: Liste wie der Bestell-Browser, gefiltert nach status, '
            . 'supplier_id, production_order_id (aus dieser Produktion befüllt), q (Suche in Kopf, Herkunft und Positionen), '
            . 'Zeitfenster von/bis oder zeitraum auf der datumsbasis (liefertag = Liefertag aufsteigend, bestelldatum = angelegt, '
            . 'neueste zuerst), nur_mit_positionen, nur_mit_klaerung; seitenweise (page, per_page, total). Je Bestellung auch '
            . 'Standort, Bestelldatum, supplier_id und Herkunft (Produktionen). Mit order_id: Detail mit '
            . 'Gebinde-Zeilen (Artikel-Nr, Anzahl Gebinde, Preis, Zeilensumme), Freigabe, Wareneingang, '
            . 'Rechnung, Lagerbestand je Zeile, total_net, MOQ-Ampel, Warnungen und Sende-Blockern. Read-only.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'order_id' => ['type' => 'integer'],
                'status' => ['type' => 'string', 'enum' => ['draft', 'sent', 'confirmed', 'delivered', 'cancelled', '']],
                'supplier_id' => ['type' => 'integer', 'description' => 'Nur Bestellungen dieses Lieferanten.'],
                'production_order_id' => ['type' => 'integer', 'description' => 'Nur Bestellungen, die aus diesem Produktionsauftrag befüllt wurden.'],
                'q' => ['type' => 'string', 'description' => 'Suche in Nummer (ord-12), Lieferant, Referenz, Notizen, Herkunft und Positionen.'],
                'datumsbasis' => ['type' => 'string', 'enum' => ['liefertag', 'bestelldatum', ''], 'description' => 'Achse für von/bis/zeitraum (Default liefertag).'],
                'von' => ['type' => 'string', 'description' => 'YYYY-MM-DD, einschließlich.'],
                'bis' => ['type' => 'string', 'description' => 'YYYY-MM-DD, einschließlich.'],
                'zeitraum' => ['type' => 'string', 'enum' => ['heute', 'woche', 'naechste', ''], 'description' => 'Preset statt von/bis (woche = Mo–So).'],
                'nur_mit_positionen' => ['type' => 'boolean', 'description' => 'Leere Entwürfe ausblenden.'],
                'nur_mit_klaerung' => ['type' => 'boolean', 'description' => 'Nur Bestellungen mit Warnungen.'],
                'page' => ['type' => 'integer', 'description' => 'Seite (ab 1).'],
                'per_page' => ['type' => 'integer', 'description' => 'Zeilen je Seite (Default 100, max. 500).'],
            ],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $svc = app(OrderService::class);

        if (! empty($arguments['order_id'])) {
            try {
                return ToolResult::success($svc->detail($team, (int) $arguments['order_id']));
            } catch (\Throwable $e) {
                return ToolResult::error('Bestellung nicht sichtbar/vorhanden.', 'NOT_FOUND');
            }
        }

        if ($fehler = $this->enumFehler($arguments)) {
            return $fehler;
        }
        [$von, $bis] = ($arguments['zeitraum'] ?? '') !== '' ? Zeitraum::grenzen((string) $arguments['zeitraum']) : [$arguments['von'] ?? null, $arguments['bis'] ?? null];
        ['herkunft' => $herkunft, 'liste' => $treffer] = app(BestellListeService::class)->filtern($team, [
            'status' => (string) ($arguments['status'] ?? ''),
            'datumsbasis' => ($arguments['datumsbasis'] ?? '') ?: 'liefertag',
            'von' => $von,
            'bis' => $bis,
            'supplier_id' => $arguments['supplier_id'] ?? null,
            'production_order_id' => $arguments['production_order_id'] ?? null,
            'nur_mit_positionen' => filter_var($arguments['nur_mit_positionen'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'nur_mit_klaerung' => filter_var($arguments['nur_mit_klaerung'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'suche' => (string) ($arguments['q'] ?? ''),
        ]);
        $perPage = max(1, min(500, (int) ($arguments['per_page'] ?? 100)));
        $page = max(1, (int) ($arguments['page'] ?? 1));
        $standorte = app(StandortService::class);
        $namen = $standorte->teamNamen([(int) $team->id, ...$standorte->unterTeamIds($team)]);
        $liste = $treffer->forPage($page, $perPage)->map(function ($o) use ($svc, $herkunft, $namen) {
            $invoiceDueDate = $o->invoice_date !== null && $o->supplier?->payment_term_days !== null
                ? $o->invoice_date->copy()->addDays(max(0, (int) $o->supplier->payment_term_days))->toDateString()
                : null;

            return [
                'id' => (int) $o->id,
                'supplier' => $o->supplier?->name,
                'supplier_id' => $o->supplier_id !== null ? (int) $o->supplier_id : null,
                'team_id' => (int) $o->team_id,
                'standort' => $namen[(int) $o->team_id] ?? null,
                'bestelldatum' => $o->created_at?->toDateString(),
                'status' => $o->status instanceof OrderStatus ? $o->status->value : (string) $o->status,
                'total_net' => (float) $o->total_net,
                'reference' => $o->reference,
                'desired_delivery_date' => $o->desired_delivery_date?->toDateString(),
                'supplier_order_number' => $o->supplier_order_number,
                'confirmed_delivery_date' => $o->confirmed_delivery_date?->toDateString(),
                'invoice_number' => $o->invoice_number,
                'invoice_date' => $o->invoice_date?->toDateString(),
                'invoice_due_date' => $invoiceDueDate,
                'payment_term_days' => $o->supplier?->payment_term_days,
                'payment' => $svc->paymentSummary($o),
                'approval' => $svc->approvalSummary($o),
                'quota' => $svc->quotaSummary($o),
                'positions_count' => (int) $o->lines->count(),
                'warnings' => $svc->orderWarnings($o),
                'send_blockers' => $svc->sendBlockers($o),
                'sent_at' => $o->sent_at?->toDateTimeString(),
                'herkunft' => $herkunft[(int) $o->id] ?? [],
            ];
        })->values()->all();

        return $this->erfolgMitWarnungen(['orders' => $liste, 'count' => count($liste), 'total' => $treffer->count(), 'page' => $page, 'per_page' => $perPage], $arguments);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'bestellung', 'order', 'bestellschiene', 'einkauf'],
            'read_only' => true,
            'idempotent' => true,
            'risk_level' => 'safe',
        ];
    }
}
