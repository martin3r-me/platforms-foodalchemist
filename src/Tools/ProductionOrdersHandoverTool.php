<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\OrderService;
use Platform\FoodAlchemist\Services\ProductionOrderService;

/**
 * Spec 20 · P4 (write): Bedarf aller Ziele eines Produktionsauftrags an die
 * Bestellschienen übergeben (Einbahn, kein Rückkanal). Legt/aktualisiert je
 * Lieferant eine Draft-Schiene und setzt den Stale-Marker (last_handover_at +
 * Ziel-Hash) — damit spätere Ziel-Änderungen als „veraltet" erkannt werden.
 * Idempotent pro Ziel über den `source_ref`-Präfix (Re-Import ersetzt Beiträge).
 * Nur eigene Team-Belege.
 */
class ProductionOrdersHandoverTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.production_orders.HANDOVER';
    }

    public function getDescription(): string
    {
        return 'Übergibt den Bedarf ALLER Ziele eines Produktionsauftrags an die Bestellschienen '
            . '(pro Lieferant eine Draft-Bestellung, angelegt wenn nötig). Idempotent je Ziel '
            . '(erneutes Übergeben ersetzt die Beiträge, dupliziert nicht) und setzt den Stale-Marker, '
            . 'sodass nachträgliche Ziel-Änderungen als „Bestellung veraltet" sichtbar werden. '
            . 'Kein Auto-Sync, kein Rückkanal. Nur eigene Team-Belege.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'order_id' => ['type' => 'integer', 'description' => 'ID des Produktionsauftrags, dessen Ziele übergeben werden.'],
            ],
            'required' => ['order_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }

        try {
            $res = app(ProductionOrderService::class)->anBestellungUebergeben(
                $team,
                (int) $arguments['order_id'],
                app(OrderService::class),
                $context->user?->id,
            );
        } catch (\Throwable $e) {
            return ToolResult::error('Produktionsauftrag nicht im Zugriff.', 'NOT_FOUND');
        }

        return ToolResult::success([
            'order_id' => (int) $arguments['order_id'],
            'orders' => $res['orders'],
            'orders_count' => count($res['orders']),
            'skipped_ohne_la' => $res['skipped_ohne_la'],
            'warnungen' => $res['warnungen'],
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'command',
            'tags' => ['foodalchemist', 'produktion', 'production_order', 'einkauf', 'handover'],
            'read_only' => false,
            'idempotent' => true,
            'risk_level' => 'medium',
        ];
    }
}
