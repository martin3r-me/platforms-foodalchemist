<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\VocabularyService;

/**
 * MCP-Steuerbarkeit · D13: team-eigene Einheit (in)aktiv schalten — der reversible Ersatz fürs Löschen
 * (Safe-Variante). Geerbte/globale Einheiten bleiben read-only.
 */
class VocabEinheitenToggleTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.vocab_einheiten.TOGGLE';
    }

    public function getDescription(): string
    {
        return 'Schaltet eine team-eigene Einheit aktiv/inaktiv (inactive=true blendet sie aus; reversibel, kein Delete).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'Einheit-Id.'],
                'inactive' => ['type' => 'boolean', 'description' => 'true = inaktiv, false = aktiv.'],
            ],
            'required' => ['id', 'inactive'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }

        try {
            app(VocabularyService::class)->setEinheitInactive($team, (int) ($arguments['id'] ?? 0), (bool) ($arguments['inactive'] ?? false));
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return ToolResult::error('Einheit nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }

        return ToolResult::success(['id' => (int) ($arguments['id'] ?? 0), 'inactive' => (bool) ($arguments['inactive'] ?? false)]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'vocab', 'einheit', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.vocab_einheiten.PUT'],
            'examples' => ['Deaktiviere Einheit 5.'],
        ];
    }
}
