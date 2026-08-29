<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\FoodbookService;

/**
 * MCP-Steuerbarkeit · D7: mehrere Blöcke zu einer Varianten-Gruppe zusammenfassen (Wahl-Alternativen)
 * bzw. die Gruppe lösen (group_id weglassen = neue Gruppe, group_id=0/null = auflösen).
 */
class FoodbookBlocksVariantGroupTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.foodbook_blocks.VARIANT_GROUP';
    }

    public function getDescription(): string
    {
        return 'Fasst mehrere Blöcke eines Kapitels zu einer Varianten-Gruppe zusammen (Wahl-Alternativen). '
            . 'group_id angeben = dieser Gruppe zuordnen; group_id=0 = Gruppe lösen; weglassen = neue Gruppe bilden.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'block_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Betroffene Block-Ids.'],
                'group_id' => ['type' => 'integer', 'description' => 'Ziel-Gruppe (0 = lösen; weglassen = neue Gruppe).'],
            ],
            'required' => ['block_ids'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $blockIds = array_values(array_filter(array_map('intval', (array) ($arguments['block_ids'] ?? []))));
        if ($blockIds === []) {
            return ToolResult::error('block_ids muss mindestens eine Block-Id enthalten.', 'VALIDATION_ERROR');
        }
        // Draft-Gate + Ownership über das Foodbook des ersten Blocks; setVariantGroup guardt jeden Block einzeln.
        $fb = $this->foodbookVonBlock($team, $blockIds[0]);
        if (($guard = $this->guardFoodbookEditable($team, $fb)) !== null) {
            return $guard;
        }

        try {
            $svc = app(FoodbookService::class);
            // Neue Gruppe: nächste freie Group-Id am Kapitel des ersten Blocks.
            $kapitelId = (int) \Platform\FoodAlchemist\Models\FoodAlchemistFoodbookBlock::whereKey($blockIds[0])->value('chapter_id');
            $groupId = array_key_exists('group_id', $arguments)
                ? ((int) $arguments['group_id'] ?: null)
                : $svc->nextVariantGroupId($team, $kapitelId);
            $svc->setVariantGroup($team, $blockIds, $groupId);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['block_ids' => $blockIds, 'group_id' => $groupId]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'foodbook', 'block', 'variant', 'write'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.foodbook_blocks.PUT'],
            'examples' => ['Fasse die Blöcke 30,31 zu einer Wahl-Gruppe zusammen.'],
        ];
    }
}
