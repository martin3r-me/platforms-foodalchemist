<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\ProductionOrderService;

class ProductionOrdersReleaseDemandTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.production_orders.RELEASE_DEMAND';
    }

    public function getDescription(): string
    {
        return 'Gibt den Materialbedarf eines Produktionsauftrags als versionierten Snapshot für das Bestellwesen frei. Erstellt keine Bestellungen.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'order_id' => ['type' => 'integer', 'description' => 'ID des Produktionsauftrags.'],
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
            $order = app(ProductionOrderService::class)->materialbedarfFreigeben(
                $team,
                (int) $arguments['order_id'],
                $context->user?->id,
            );
        } catch (\Throwable $e) {
            return ToolResult::error($e->getMessage(), 'RELEASE_FAILED');
        }

        return ToolResult::success([
            'order_id' => (int) $order->id,
            'released_at' => $order->procurement_released_at?->toIso8601String(),
            'targets_hash' => $order->procurement_targets_hash,
            'targets_count' => count($order->procurement_targets_snapshot ?? []),
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'command',
            'tags' => ['foodalchemist', 'produktion', 'materialbedarf', 'release'],
            'read_only' => false,
            'idempotent' => true,
            'risk_level' => 'low',
        ];
    }
}
