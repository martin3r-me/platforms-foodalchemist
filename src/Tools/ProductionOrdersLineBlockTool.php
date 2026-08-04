<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\ProductionOrderService;

class ProductionOrdersLineBlockTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.production_orders.LINE_BLOCK';
    }

    public function getDescription(): string
    {
        return 'Erfasst einen Blocker auf einer laufenden Produktionszeile. Grund ist Pflicht; schreibt ein Produktionsereignis.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'line_id' => ['type' => 'integer'],
                'reason' => ['type' => 'string'],
                'note' => ['type' => ['string', 'null']],
                'expected_updated_at' => ['type' => ['string', 'null']],
            ],
            'required' => ['line_id', 'reason'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        try {
            $line = app(ProductionOrderService::class)->setLineBlocked($team, (int) $arguments['line_id'], (string) $arguments['reason'], $arguments['note'] ?? null, [
                'expected_updated_at' => $arguments['expected_updated_at'] ?? null,
            ]);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'NOT_ALLOWED');
        } catch (\Throwable $e) {
            return ToolResult::error('Produktionszeile nicht im Zugriff.', 'NOT_FOUND');
        }

        return ToolResult::success([
            'line_id' => (int) $line->id,
            'blocked_reason' => $line->blocked_reason,
            'blocked_note' => $line->blocked_note,
            'updated_at' => $line->updated_at?->toIso8601String(),
        ]);
    }

    public function getMetadata(): array
    {
        return ['category' => 'command', 'tags' => ['foodalchemist', 'produktion', 'blocker'], 'read_only' => false, 'idempotent' => true, 'risk_level' => 'low'];
    }
}
