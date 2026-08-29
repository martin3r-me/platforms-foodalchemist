<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\FoodbookService;

/** MCP-Steuerbarkeit · D7: Blöcke eines Kapitels in einem Entwurf-Foodbook neu ordnen. */
class FoodbookBlocksReorderTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.foodbook_blocks.REORDER';
    }

    public function getDescription(): string
    {
        return 'Ordnet die Blöcke eines Kapitels in einem team-eigenen Entwurf-Foodbook neu (block-ids in Zielreihenfolge).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'kapitel_id' => ['type' => 'integer', 'description' => 'Kapitel-Id.'],
                'ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Block-Ids in Zielreihenfolge.'],
            ],
            'required' => ['kapitel_id', 'ids'],
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
        $kapitelId = (int) ($arguments['kapitel_id'] ?? 0);
        if (($guard = $this->guardFoodbookEditable($team, $this->foodbookVonKapitel($team, $kapitelId))) !== null) {
            return $guard;
        }

        try {
            app(FoodbookService::class)->reorderBlocks($team, $kapitelId, array_map('intval', $ids));
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['kapitel_id' => $kapitelId, 'ids' => array_map('intval', $ids)]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'foodbook', 'block', 'reorder', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.foodbook_blocks.PUT'],
            'examples' => ['Ordne die Blöcke von Kapitel 12 als [30,28,31].'],
        ];
    }
}
