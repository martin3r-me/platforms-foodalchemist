<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\ProductionPlanService;

/**
 * MCP-Steuerbarkeit · D11: Produktionsplan-Vorschlag eines Zeitraums übernehmen (erzeugt Produktionsaufträge).
 * Re-berechnet den Vorschlag intern (von/bis) und übernimmt ihn. Confirm=true Pflicht.
 */
class ProductionPlanApplyTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.production_plan.APPLY';
    }

    public function getDescription(): string
    {
        return 'Übernimmt den Produktionsplan-Vorschlag eines Zeitraums (von/bis YYYY-MM-DD) — erzeugt/aktualisiert '
            . 'Produktionsaufträge. Optional nur_linien (Teilmenge der Vorschlags-Linien-Keys). Erfordert confirm=true.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'von' => ['type' => 'string', 'description' => 'Startdatum YYYY-MM-DD.'],
                'bis' => ['type' => 'string', 'description' => 'Enddatum YYYY-MM-DD.'],
                'nur_linien' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Optionale Teilmenge der Linien-Keys aus SUGGEST.'],
                'confirm' => ['type' => 'boolean', 'description' => 'Muss true sein (erzeugt Produktionsaufträge).'],
            ],
            'required' => ['von', 'bis', 'confirm'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        if (($arguments['confirm'] ?? false) !== true) {
            return ToolResult::error('Übernahme erfordert confirm=true (erzeugt Produktionsaufträge).', 'CONFIRM_REQUIRED');
        }
        $von = trim((string) ($arguments['von'] ?? ''));
        $bis = trim((string) ($arguments['bis'] ?? ''));
        if ($von === '' || $bis === '') {
            return ToolResult::error('von und bis sind Pflicht (YYYY-MM-DD).', 'VALIDATION_ERROR');
        }
        $nurLinien = isset($arguments['nur_linien']) && is_array($arguments['nur_linien'])
            ? array_map('strval', $arguments['nur_linien']) : null;

        try {
            $svc = app(ProductionPlanService::class);
            $vorschlag = $svc->schlage($team, $von, $bis);
            $count = $svc->uebernehmen($team, $vorschlag, $nurLinien);
        } catch (\RuntimeException | \Carbon\Exceptions\InvalidFormatException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['von' => $von, 'bis' => $bis, 'applied_lines' => (int) $count]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'production', 'plan', 'write'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'destructive',
            'confirmation_required' => true,
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['creates', 'updates'],
            'related_tools' => ['foodalchemist.production_plan.SUGGEST'],
            'examples' => ['Übernimm den Produktionsplan für 2027-01-04 bis 2027-01-10 (confirm=true).'],
        ];
    }
}
