<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\ProductionOrderService;

/**
 * Spec 18 (write): Küchen-Notiz an einer Produktions-Zeile im offenen
 * (geplanten) Auftrag setzen.
 *
 * ⚠️ Korrektur (Spec 30): Der frühere Satz „Ansätze sind NICHT manuell überschreibbar"
 * stimmt nicht mehr. Sie sind es — aber über LINE_OVERRIDE, nicht hier. Das Tool bleibt
 * bewusst auf die Notiz beschränkt: explizite Verben, explizite Guards.
 * Zuteilung → LINE_ASSIGN. Abhaken → LINE_STATUS.
 */
class ProductionOrdersUpdateLineTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.production_orders.UPDATE_LINE';
    }

    public function getDescription(): string
    {
        return 'Setzt eine Küchen-Notiz an einer Produktions-Zeile im geplanten Auftrag. Nur eigene Belege, '
            . 'nur solange der Auftrag noch nicht gestartet ist. Ansätze überschreiben: LINE_OVERRIDE. '
            . 'Posten/Verantwortlich/Vorlauf: LINE_ASSIGN. Abhaken: LINE_STATUS.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'line_id' => ['type' => 'integer'],
                'note' => ['type' => 'string'],
            ],
            'required' => ['line_id', 'note'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }

        try {
            $line = app(ProductionOrderService::class)->updateLine($team, (int) $arguments['line_id'], ['note' => $arguments['note']]);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'NOT_ALLOWED');
        } catch (\Throwable $e) {
            return ToolResult::error('Produktionszeile nicht im Zugriff.', 'NOT_FOUND');
        }

        return ToolResult::success([
            'line_id' => (int) $line->id,
            'note' => $line->note,
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'command',
            'tags' => ['foodalchemist', 'produktion', 'production_order', 'zeile', 'notiz'],
            'read_only' => false,
            'idempotent' => true,
            'risk_level' => 'low',
        ];
    }
}
