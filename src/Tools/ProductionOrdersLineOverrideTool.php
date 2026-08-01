<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\ProductionOrderService;

/**
 * Spec 30 E1 (write): Ansätze einer Produktionszeile überschreiben oder streichen.
 *
 * Der Override ist eine KÜCHEN-KORREKTUR, kein Bedarfs-Eingriff: er ändert weder den
 * GP-Bedarf noch die Einkaufs-Übergabe (die liest `targets`, nicht Zeilen). Wer wirklich
 * mehr braucht, legt ein Ziel an.
 *
 * `ansaetze: null` nimmt den Override zurück — die Zeile fällt auf den berechneten Wert
 * zurück. Der berechnete Wert bleibt immer erhalten (`ansaetze_berechnet`).
 */
class ProductionOrdersLineOverrideTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.production_orders.LINE_OVERRIDE';
    }

    public function getDescription(): string
    {
        return 'Überschreibt die Ansätze einer Produktionszeile (null = Override zurücknehmen) oder streicht '
            . 'die Zeile (is_struck). Gestrichene Zeilen fallen aus allen Summen und aus dem Druck, bleiben aber '
            . 'sichtbar. Nur eigene Aufträge, nur solange geplant.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'line_id' => ['type' => 'integer'],
                'ansaetze' => ['type' => ['number', 'null'], 'description' => 'Manuelle Ansätze; null = Override zurücknehmen'],
                'is_struck' => ['type' => 'boolean', 'description' => 'true = streichen, false = wiederherstellen'],
                'struck_reason' => ['type' => 'string', 'description' => 'Grund fürs Streichen'],
            ],
            'required' => ['line_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }

        $lineId = (int) $arguments['line_id'];
        $svc = app(ProductionOrderService::class);

        try {
            $line = null;
            if (array_key_exists('ansaetze', $arguments)) {
                $line = $svc->setLineAnsaetze($team, $lineId, $arguments['ansaetze'] !== null ? (float) $arguments['ansaetze'] : null);
            }
            if (array_key_exists('is_struck', $arguments)) {
                $line = $svc->setLineStruck($team, $lineId, (bool) $arguments['is_struck'], $arguments['struck_reason'] ?? null);
            }
            if ($line === null) {
                return ToolResult::error('Nichts zu ändern — ansaetze oder is_struck angeben.', 'NO_CHANGE');
            }
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'NOT_ALLOWED');
        } catch (\Throwable $e) {
            return ToolResult::error('Produktionszeile nicht im Zugriff.', 'NOT_FOUND');
        }

        return ToolResult::success([
            'line_id' => (int) $line->id,
            'ansaetze_effektiv' => (float) $line->ansaetze_effektiv,
            'ansaetze_berechnet' => (float) $line->ansaetze,
            'ist_manuelle_ansaetze' => (bool) $line->is_manual_ansaetze,
            'is_struck' => (bool) $line->is_struck,
            'struck_reason' => $line->struck_reason,
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'command',
            'tags' => ['foodalchemist', 'produktion', 'production_order', 'zeile', 'ansaetze', 'override'],
            'read_only' => false,
            'idempotent' => true,
            'risk_level' => 'low',
        ];
    }
}
