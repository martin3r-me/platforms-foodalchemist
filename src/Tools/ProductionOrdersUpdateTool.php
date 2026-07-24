<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\ProductionOrderService;

/**
 * Spec 20 · P0 (write): Kopf-Felder eines geplanten Produktionsauftrags ändern
 * (Name/Anlass/Notiz/Datum). Nur eigene Belege, nur solange `planned`. Ziele/Zeilen
 * werden hier NICHT angefasst (dafür ADD_TARGET/REMOVE_TARGET).
 */
class ProductionOrdersUpdateTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.production_orders.UPDATE';
    }

    public function getDescription(): string
    {
        return 'Ändert die Kopf-Felder eines geplanten Produktionsauftrags: name, reference (Anlass), '
            . 'note, production_date. Nur eigene Belege, nur solange nicht gestartet. Ziele bleiben unberührt.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'order_id' => ['type' => 'integer'],
                'name' => ['type' => 'string', 'description' => 'Auftrags-Name (leer/fehlend ⇒ unverändert)'],
                'reference' => ['type' => 'string', 'description' => 'Anlass; "" löscht'],
                'note' => ['type' => 'string', 'description' => 'Notiz; "" löscht'],
                'production_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
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

        $input = array_intersect_key($arguments, array_flip(['name', 'reference', 'note', 'production_date']));

        try {
            $order = app(ProductionOrderService::class)->updateHeader($team, (int) $arguments['order_id'], $input);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'NOT_ALLOWED');
        } catch (\Throwable $e) {
            return ToolResult::error('Produktionsauftrag nicht im Zugriff.', 'NOT_FOUND');
        }

        return ToolResult::success([
            'order_id' => (int) $order->id,
            'name' => $order->name,
            'reference' => $order->reference,
            'note' => $order->note,
            'production_date' => $order->production_date?->toDateString(),
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'command',
            'tags' => ['foodalchemist', 'produktion', 'production_order', 'kopf'],
            'read_only' => false,
            'idempotent' => true,
            'risk_level' => 'low',
        ];
    }
}
