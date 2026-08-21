<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\SpeisekarteService;

/**
 * Werkstrang M Phase C (Spec 40 §6): Positionen INNERHALB einer Rubrik neu ordnen (vollständige ID-Reihenfolge).
 * Team-scoped über {@see SpeisekarteService::reorderPositionen}.
 */
class SpeisekartePositionenReorderTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.speisekarte_positionen.REORDER';
    }

    public function getDescription(): string
    {
        return 'Ordnet die Positionen einer Rubrik neu. `ids` ist die vollständige Ziel-Reihenfolge der '
            . 'Positions-IDs dieser Rubrik (Index = neue position).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'rubrik_id' => ['type' => 'integer'],
                'ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Positions-IDs in Ziel-Reihenfolge.'],
            ],
            'required' => ['rubrik_id', 'ids'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $ids = array_values(array_map('intval', (array) ($arguments['ids'] ?? [])));
        try {
            app(SpeisekarteService::class)->reorderPositionen($team, (int) $arguments['rubrik_id'], $ids);
        } catch (\Throwable $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['reordered' => true, 'rubrik_id' => (int) $arguments['rubrik_id'], 'n' => count($ids)]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'speisekarte', 'position', 'reorder'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true,
            'side_effects' => ['updates'], 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.speisekarte_positionen.MOVE', 'foodalchemist.speisekarte_rubrik.REORDER'],
            'examples' => ['Ordne die Positionen der Rubrik 12 in dieser Reihenfolge: [7,3,9]'],
        ];
    }
}
