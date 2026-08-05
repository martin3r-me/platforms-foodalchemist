<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Enums\ProductionLineStatus;
use Platform\FoodAlchemist\Services\ProductionOrderService;

/**
 * Spec 30 E6 (write): Produktionszeile abhaken.
 *
 * Nur im LAUFENDEN Auftrag. Im `planned` verboten, weil dort ein Recompute die Zeile unter
 * der Hand ersetzt — ein „erledigt", das das überlebt, hinge an inzwischen geänderten
 * Ansätzen und wäre ein Protokoll, das lügt.
 *
 * `skipped` ≠ `is_struck`: `is_struck` ist ein Planungs-Entscheid im `planned` („produzieren
 * wir nicht", fliegt aus den Summen), `skipped` ein Ausführungs-Ergebnis im `in_progress`
 * („hätten wir sollen, haben wir nicht", bleibt als Soll drin).
 *
 * Der AUFTRAGS-Status wird hierdurch nie weitergeschaltet — „fertig melden" bleibt SET_STATUS.
 */
class ProductionOrdersLineStatusTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.production_orders.LINE_STATUS';
    }

    public function getDescription(): string
    {
        return 'Setzt den Ausführungs-Status einer Produktionszeile: open|in_progress|done|skipped. '
            . 'Nur im laufenden Auftrag, nur eigene Belege. Keine Ist-Mengen — nur abhaken. '
            . 'Der Auftragsstatus bleibt davon unberührt.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'line_id' => ['type' => 'integer'],
                'status' => ['type' => 'string', 'enum' => ['open', 'in_progress', 'done', 'skipped']],
            ],
            'required' => ['line_id', 'status'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }

        $ziel = ProductionLineStatus::tryFrom((string) $arguments['status']);
        if ($ziel === null) {
            return ToolResult::error('Unbekannter Zeilen-Status.', 'INVALID_STATUS');
        }

        try {
            $line = app(ProductionOrderService::class)->setLineStatus($team, (int) $arguments['line_id'], $ziel);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'NOT_ALLOWED');
        } catch (\Throwable $e) {
            return ToolResult::error('Produktionszeile nicht im Zugriff.', 'NOT_FOUND');
        }

        return ToolResult::success([
            'line_id' => (int) $line->id,
            'line_status' => $line->line_status->value,
            'line_status_label' => $line->line_status->label(),
            'done_at' => $line->done_at?->toIso8601String(),
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'command',
            'tags' => ['foodalchemist', 'produktion', 'production_order', 'zeile', 'abhaken', 'ausfuehrung'],
            'read_only' => false,
            'idempotent' => true,
            'risk_level' => 'low',
        ];
    }
}
