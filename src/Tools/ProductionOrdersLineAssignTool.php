<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\ProductionOrderService;

/**
 * Spec 30 E3 (write): Zeile einem Posten zuteilen, verantwortlich benennen, Vorlauf setzen.
 *
 * Anders als der Ansätze-Override ist Zuteilung auch im LAUFENDEN Auftrag erlaubt: die
 * Realität besetzt mitten im Service um.
 *
 * ⚠️ `assignee` ist ein freier Name und bleibt es — kein FK, kein `user_id`, und es gibt
 * KEINE Aggregation darüber. Auslastung wird ausschließlich je Posten gerechnet. Das ist die
 * Wand gegen Autocomplete → user_id → Verfügbarkeiten → Schichtplanung, also gegen genau die
 * Personalplanung, die Nicht-Ziel des Moduls ist.
 *
 * `vorlauf_tage` ist ein OFFSET auf den Liefertag, kein Datum: verschiebt sich das Event,
 * wandert der ganze Vorproduktions-Schwanz automatisch mit.
 */
class ProductionOrdersLineAssignTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.production_orders.LINE_ASSIGN';
    }

    public function getDescription(): string
    {
        return 'Teilt eine Produktionszeile einem Posten zu, setzt den Verantwortlichen (freier Name) und den '
            . 'Vorlauf in Tagen vor dem Liefertag (0 = am Tag selbst). Auch im laufenden Auftrag erlaubt. '
            . 'Nur eigene Aufträge.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'line_id' => ['type' => 'integer'],
                'station_id' => ['type' => ['integer', 'null'], 'description' => 'Posten; null = unverplant'],
                'assignee' => ['type' => ['string', 'null'], 'description' => 'Verantwortlich (freier Name); "" oder null löscht'],
                'vorlauf_tage' => ['type' => 'integer', 'description' => '0–14 Tage vor dem Liefertag'],
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

        $input = array_intersect_key($arguments, array_flip(['station_id', 'assignee', 'vorlauf_tage']));
        if ($input === []) {
            return ToolResult::error('Nichts zu ändern — station_id, assignee oder vorlauf_tage angeben.', 'NO_CHANGE');
        }

        try {
            $line = app(ProductionOrderService::class)->assignLine($team, (int) $arguments['line_id'], $input);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'NOT_ALLOWED');
        } catch (\Throwable $e) {
            return ToolResult::error('Produktionszeile nicht im Zugriff.', 'NOT_FOUND');
        }

        return ToolResult::success([
            'line_id' => (int) $line->id,
            'station_id' => $line->station_id !== null ? (int) $line->station_id : null,
            'station' => $line->station?->name,
            'assignee' => $line->assignee,
            'vorlauf_tage' => (int) $line->vorlauf_tage,
            'plan_date' => $line->plan_date?->toDateString(),
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'command',
            'tags' => ['foodalchemist', 'produktion', 'production_order', 'zeile', 'posten', 'zuteilung'],
            'read_only' => false,
            'idempotent' => true,
            'risk_level' => 'low',
        ];
    }
}
