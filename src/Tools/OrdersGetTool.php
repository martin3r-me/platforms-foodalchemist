<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Enums\OrderStatus;
use Platform\FoodAlchemist\Services\OrderService;

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
        return 'Bestellungen/Bestellschienen des Teams. Ohne order_id: Liste (offene Entwürfe zuerst; '
            . 'optional status=draft|sent|confirmed|delivered|cancelled). Mit order_id: Detail mit '
            . 'Gebinde-Zeilen (Artikel-Nr, Anzahl Gebinde, Preis, Zeilensumme), total_net und MOQ-Ampel '
            . '(Mindestbestellwert/Frei-Haus). Read-only.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'order_id' => ['type' => 'integer'],
                'status' => ['type' => 'string', 'enum' => ['draft', 'sent', 'confirmed', 'delivered', 'cancelled']],
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

        $status = $arguments['status'] ?? null;
        $liste = $svc->listForTeam($team, $status)->map(fn ($o) => [
            'id' => (int) $o->id,
            'supplier' => $o->supplier?->name,
            'status' => $o->status instanceof OrderStatus ? $o->status->value : (string) $o->status,
            'total_net' => (float) $o->total_net,
            'reference' => $o->reference,
            'sent_at' => $o->sent_at?->toDateTimeString(),
        ])->all();

        return ToolResult::success(['orders' => $liste, 'count' => count($liste)]);
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
