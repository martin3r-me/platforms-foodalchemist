<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\VocabularyService;

/** MCP-Steuerbarkeit · D13: team-eigene Warengruppe anlegen. Safe-additiv. */
class VocabWarengruppenPostTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.vocab_warengruppen.POST';
    }

    public function getDescription(): string
    {
        return 'Legt eine team-eigene Warengruppe an (name; optional code). Kanonische §3-Warengruppen bleiben read-only.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'description' => 'Warengruppen-Name.'],
                'code' => ['type' => 'string', 'description' => 'Optionaler Code.'],
            ],
            'required' => ['name'],
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
            $wg = app(VocabularyService::class)->createWarengruppe($team, $name, ($c = trim((string) ($arguments['code'] ?? ''))) !== '' ? $c : null);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['id' => (int) $wg->id, 'name' => $wg->name, 'code' => $wg->code ?? null]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'vocab', 'warengruppe', 'write'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['creates'],
            'related_tools' => ['foodalchemist.vocab_warengruppen.PUT', 'foodalchemist.vocab_subkategorien.POST'],
            'examples' => ['Lege die Warengruppe „Fermente" an.'],
        ];
    }
}
