<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\SpeisekarteService;

/**
 * Werkstrang M Phase C (Spec 40 §6): Rubriken einer Speisekarte-Ebene neu ordnen (gleicher parent).
 * Team-scoped über {@see SpeisekarteService::reorderRubriken}.
 */
class SpeisekarteRubrikReorderTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.speisekarte_rubrik.REORDER';
    }

    public function getDescription(): string
    {
        return 'Ordnet die Rubriken einer Ebene neu. `ids` ist die vollständige Ziel-Reihenfolge der Rubrik-IDs '
            . 'mit demselben parent_id (Index = neue position). parent_id weglassen = Wurzel-Ebene.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'speisekarte_id' => ['type' => 'integer'],
                'parent_id' => ['type' => 'integer', 'description' => 'Eltern-Rubrik; weglassen = Wurzel-Ebene.'],
                'ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Rubrik-IDs in Ziel-Reihenfolge.'],
            ],
            'required' => ['speisekarte_id', 'ids'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $ids = array_values(array_map('intval', (array) ($arguments['ids'] ?? [])));
        $parentId = isset($arguments['parent_id']) ? (int) $arguments['parent_id'] : null;
        try {
            app(SpeisekarteService::class)->reorderRubriken($team, (int) $arguments['speisekarte_id'], $parentId, $ids);
        } catch (\Throwable $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['reordered' => true, 'speisekarte_id' => (int) $arguments['speisekarte_id'], 'n' => count($ids)]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'speisekarte', 'rubrik', 'reorder'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true,
            'side_effects' => ['updates'], 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.speisekarte_rubrik.POST', 'foodalchemist.speisekarte_positionen.REORDER'],
            'examples' => ['Ordne die Rubriken der Karte 5 in dieser Reihenfolge: [3,1,2]'],
        ];
    }
}
