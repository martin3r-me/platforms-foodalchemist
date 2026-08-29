<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\VocabularyService;

/** MCP-Steuerbarkeit · D13: team-eigene Warengruppe umbenennen (geerbte/kanonische read-only). */
class VocabWarengruppenPutTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.vocab_warengruppen.PUT';
    }

    public function getDescription(): string
    {
        return 'Benennt eine team-eigene Warengruppe um (id, name).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'Warengruppen-Id.'],
                'name' => ['type' => 'string', 'description' => 'Neuer Name.'],
            ],
            'required' => ['id', 'name'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $name = trim((string) ($arguments['name'] ?? ''));
        if ($name === '') {
            return ToolResult::error('name ist Pflicht.', 'VALIDATION_ERROR');
        }

        try {
            app(VocabularyService::class)->updateWarengruppeName($team, (int) ($arguments['id'] ?? 0), $name);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return ToolResult::error('Warengruppe nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }

        return ToolResult::success(['id' => (int) ($arguments['id'] ?? 0), 'name' => $name]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'vocab', 'warengruppe', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.vocab_warengruppen.REORDER'],
            'examples' => ['Benenne Warengruppe 12 in „Fermente & Pickles" um.'],
        ];
    }
}
