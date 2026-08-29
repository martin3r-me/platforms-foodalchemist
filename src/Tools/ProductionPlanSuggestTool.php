<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\ProductionPlanService;

/** MCP-Steuerbarkeit · D11: Produktionsplan-Vorschlag für einen Zeitraum berechnen (read/preview). */
class ProductionPlanSuggestTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.production_plan.SUGGEST';
    }

    public function getDescription(): string
    {
        return 'Berechnet einen Produktionsplan-Vorschlag (Vorlauf/Bündelung) für einen Zeitraum (von/bis, YYYY-MM-DD). '
            . 'Read-only Preview — Übernahme via production_plan.APPLY.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'von' => ['type' => 'string', 'description' => 'Startdatum YYYY-MM-DD.'],
                'bis' => ['type' => 'string', 'description' => 'Enddatum YYYY-MM-DD.'],
            ],
            'required' => ['von', 'bis'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $von = trim((string) ($arguments['von'] ?? ''));
        $bis = trim((string) ($arguments['bis'] ?? ''));
        if ($von === '' || $bis === '') {
            return ToolResult::error('von und bis sind Pflicht (YYYY-MM-DD).', 'VALIDATION_ERROR');
        }

        try {
            $vorschlag = app(ProductionPlanService::class)->schlage($team, $von, $bis);
        } catch (\RuntimeException | \Carbon\Exceptions\InvalidFormatException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['von' => $von, 'bis' => $bis, 'vorschlag' => $vorschlag]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'read',
            'tags' => ['foodalchemist', 'production', 'plan', 'read'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => [],
            'related_tools' => ['foodalchemist.production_plan.APPLY'],
            'examples' => ['Schlag einen Produktionsplan für 2027-01-04 bis 2027-01-10 vor.'],
        ];
    }
}
