<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\VocabularyService;

/** MCP-Steuerbarkeit · D13: Warengruppen neu ordnen (Sortierung, team-eigen). */
class VocabWarengruppenReorderTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.vocab_warengruppen.REORDER';
    }

    public function getDescription(): string
    {
        return 'Ordnet die Warengruppen neu (ids in Zielreihenfolge).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Warengruppen-Ids in Zielreihenfolge.']],
            'required' => ['ids'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $ids = $arguments['ids'] ?? null;
        if (! is_array($ids) || $ids === []) {
            return ToolResult::error('ids muss ein nicht-leeres Array sein.', 'VALIDATION_ERROR');
        }

        try {
            app(VocabularyService::class)->reorderWarengruppen($team, array_map('intval', $ids));
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['ids' => array_map('intval', $ids)]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'vocab', 'warengruppe', 'reorder', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.vocab_warengruppen.PUT'],
            'examples' => ['Ordne die Warengruppen als [3,1,2].'],
        ];
    }
}
