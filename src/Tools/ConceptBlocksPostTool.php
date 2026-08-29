<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Services\ConceptService;

/** MCP-Steuerbarkeit · D5: Layout-Block (Header/Text/Spacer) an einem team-eigenen Konzept anlegen. */
class ConceptBlocksPostTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.concept_blocks.POST';
    }

    public function getDescription(): string
    {
        return 'Legt einen Layout-Block (type z.B. header/text/spacer) an einem team-eigenen Konzept an (felder optional).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'concept_id' => ['type' => 'integer', 'description' => 'Konzept-Id (team-eigen).'],
                'type' => ['type' => 'string', 'description' => 'Block-Typ.'],
                'felder' => ['type' => 'object', 'description' => 'Block-Felder (label, text, …).'],
            ],
            'required' => ['concept_id', 'type'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $type = trim((string) ($arguments['type'] ?? ''));
        if ($type === '') {
            return ToolResult::error('type ist Pflicht.', 'VALIDATION_ERROR');
        }
        $conceptId = (int) ($arguments['concept_id'] ?? 0);
        if (($guard = $this->guardOwned($team, FoodAlchemistConcept::class, $conceptId, 'Konzept')) !== null) {
            return $guard;
        }

        try {
            $slot = app(ConceptService::class)->addBlock($team, $conceptId, $type, is_array($arguments['felder'] ?? null) ? $arguments['felder'] : []);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['concept_id' => $conceptId, 'slot_id' => (int) $slot->id, 'type' => $type]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'concept', 'block', 'write'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['creates'],
            'related_tools' => ['foodalchemist.concept_blocks.PUT'],
            'examples' => ['Füge Konzept 7 einen Header-Block hinzu.'],
        ];
    }
}
