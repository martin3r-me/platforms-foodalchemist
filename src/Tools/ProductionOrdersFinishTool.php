<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Enums\ProductionOrderStatus;
use Platform\FoodAlchemist\Services\ProductionOrderService;

class ProductionOrdersFinishTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.production_orders.FINISH';
    }

    public function getDescription(): string
    {
        return 'Meldet einen Produktionsauftrag fertig. Bei offenen oder blockierten Zeilen ist finish_note Pflicht.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'order_id' => ['type' => 'integer'],
                'finish_note' => ['type' => ['string', 'null']],
                'expected_updated_at' => ['type' => ['string', 'null']],
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
            $order = app(ProductionOrderService::class)->setStatus($team, (int) $arguments['order_id'], ProductionOrderStatus::Done, [
                'expected_updated_at' => $arguments['expected_updated_at'] ?? null,
                'finish_note' => $arguments['finish_note'] ?? null,
            ]);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'NOT_ALLOWED');
        } catch (\Throwable $e) {
            return ToolResult::error('Produktionsauftrag nicht im Zugriff.', 'NOT_FOUND');
        }

        return ToolResult::success(['order_id' => (int) $order->id, 'status' => $order->status->value, 'finished_at' => $order->finished_at?->toIso8601String()]);
    }

    public function getMetadata(): array
    {
        return ['category' => 'command', 'tags' => ['foodalchemist', 'produktion', 'finish'], 'read_only' => false, 'idempotent' => false, 'risk_level' => 'medium'];
    }
}
