<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\VocabularyService;

/** MCP-Steuerbarkeit · D13: Rezept-Hauptgruppe bearbeiten (team-eigen). */
class VocabRecipeMaingroupsPutTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.vocab_recipe_maingroups.PUT';
    }

    public function getDescription(): string
    {
        return 'Bearbeitet eine team-eigene Rezept-Hauptgruppe (felder: label, bereich, sort_order).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'Hauptgruppen-Id.'],
                'felder' => ['type' => 'object', 'description' => 'label, bereich, sort_order.'],
            ],
            'required' => ['id', 'felder'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $felder = $arguments['felder'] ?? null;
        if (! is_array($felder) || $felder === []) {
            return ToolResult::error('felder muss ein nicht-leeres Objekt sein.', 'VALIDATION_ERROR');
        }
        $in = array_intersect_key($felder, array_flip(['label', 'bereich', 'sort_order']));
        if ($in === []) {
            return ToolResult::error('Keine bekannten Felder in felder.', 'VALIDATION_ERROR');
        }

        try {
            app(VocabularyService::class)->updateMainGroup($team, (int) ($arguments['id'] ?? 0), $in);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return ToolResult::error('Hauptgruppe nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }

        return ToolResult::success(['id' => (int) ($arguments['id'] ?? 0), 'updated' => array_keys($in)]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'vocab', 'taxonomie', 'recipe', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.vocab_recipe_maingroups.REORDER'],
            'examples' => ['Benenne Rezept-Hauptgruppe 5 um.'],
        ];
    }
}
