<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\ProductionOrderService;

/**
 * Spec 20 · P0 (write): entfernt ein Ziel (per source_ref) aus einem geplanten
 * Produktionsauftrag und rechnet die verbleibenden Ziele vollständig neu (nicht
 * additiv). Nur eigene Belege, nur solange `planned`. Idempotent — unbekannter
 * source_ref lässt den Auftrag unverändert.
 */
class ProductionOrdersRemoveTargetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.production_orders.REMOVE_TARGET';
    }

    public function getDescription(): string
    {
        return 'Entfernt ein Ziel (source_ref) aus einem geplanten Produktionsauftrag und rechnet die '
            . 'verbleibenden Ziele gemeinsam neu (nicht additiv). Nur eigene Belege, nur solange nicht '
            . 'gestartet. Idempotent: unbekannter source_ref ⇒ keine Änderung.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'order_id' => ['type' => 'integer'],
                'source_ref' => ['type' => 'string', 'description' => 'Quell-Kennung des zu entfernenden Ziels'],
            ],
            'required' => ['order_id', 'source_ref'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }

        try {
            $order = app(ProductionOrderService::class)->removeTarget($team, (int) $arguments['order_id'], (string) $arguments['source_ref']);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'NOT_ALLOWED');
        } catch (\Throwable $e) {
            return ToolResult::error('Produktionsauftrag nicht im Zugriff.', 'NOT_FOUND');
        }

        return ToolResult::success([
            'order_id' => (int) $order->id,
            'targets' => $order->targets,
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'command',
            'tags' => ['foodalchemist', 'produktion', 'production_order', 'ziel'],
            'read_only' => false,
            'idempotent' => true,
            'risk_level' => 'low',
        ];
    }
}
