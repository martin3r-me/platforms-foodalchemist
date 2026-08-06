<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Enums\ProductionOrderStatus;
use Platform\FoodAlchemist\Services\ProductionOrderService;

class ProductionOrdersStartTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.production_orders.START';
    }

    public function getDescription(): string
    {
        return 'Startet einen Produktionsauftrag. Bei bekannten Warnungen muss override_reason gesetzt werden.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'order_id' => ['type' => 'integer'],
                'expected_updated_at' => ['type' => ['string', 'null']],
                'override_reason' => ['type' => ['string', 'null']],
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
            $order = app(ProductionOrderService::class)->setStatus($team, (int) $arguments['order_id'], ProductionOrderStatus::InProgress, [
                'expected_updated_at' => $arguments['expected_updated_at'] ?? null,
                'override_reason' => $arguments['override_reason'] ?? null,
            ]);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'NOT_ALLOWED');
        } catch (\Throwable $e) {
            return ToolResult::error('Produktionsauftrag nicht im Zugriff.', 'NOT_FOUND');
        }

        return ToolResult::success(['order_id' => (int) $order->id, 'status' => $order->status->value, 'updated_at' => $order->updated_at?->toIso8601String()]);
    }

    public function getMetadata(): array
    {
        return ['category' => 'command', 'tags' => ['foodalchemist', 'produktion', 'start'], 'read_only' => false, 'idempotent' => false, 'risk_level' => 'medium'];
    }
}
