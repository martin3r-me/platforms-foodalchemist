<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\VocabularyService;

/** MCP-Steuerbarkeit · D13: Gericht-Hauptgruppe (VK-Taxonomie) anlegen. */
class VocabDishMaingroupsPostTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.vocab_dish_maingroups.POST';
    }

    public function getDescription(): string
    {
        return 'Legt eine team-eigene Gericht-Hauptgruppe (VK-Taxonomie) an (code, label; optional sort_order).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'code' => ['type' => 'string', 'description' => 'Code.'],
                'label' => ['type' => 'string', 'description' => 'Anzeigename.'],
                'sort_order' => ['type' => 'integer', 'description' => 'Sortierung.'],
            ],
            'required' => ['code', 'label'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $in = array_intersect_key($arguments, array_flip(['code', 'label', 'sort_order']));
        if (trim((string) ($in['code'] ?? '')) === '' || trim((string) ($in['label'] ?? '')) === '') {
            return ToolResult::error('code und label sind Pflicht.', 'VALIDATION_ERROR');
        }

        try {
            $mg = app(VocabularyService::class)->createDishMainGroup($team, $in);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['id' => (int) $mg->id, 'code' => $mg->code, 'label' => $mg->label]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'vocab', 'taxonomie', 'dish', 'write'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['creates'],
            'related_tools' => ['foodalchemist.vocab_dish_maingroups.PUT', 'foodalchemist.vocab_dish_maingroups.REORDER'],
            'examples' => ['Lege die Gericht-Hauptgruppe „Bowls“ an.'],
        ];
    }
}
