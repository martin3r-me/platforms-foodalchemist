<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistAngebot;
use Platform\FoodAlchemist\Services\AngebotService;

/** MCP-Steuerbarkeit · D10: ein angebots-lokales Menü (Concept) im Angebot anlegen. */
class AngebotMenuePostTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.angebot_menue.POST';
    }

    public function getDescription(): string
    {
        return 'Legt ein angebots-lokales Menü (Concept) in einem team-eigenen Angebot an (optional name).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'angebot_id' => ['type' => 'integer', 'description' => 'Angebot-Id.'],
                'name' => ['type' => 'string', 'description' => 'Optionaler Menü-Name.'],
            ],
            'required' => ['angebot_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $angebotId = (int) ($arguments['angebot_id'] ?? 0);
        if (($guard = $this->guardOwned($team, FoodAlchemistAngebot::class, $angebotId, 'Angebot')) !== null) {
            return $guard;
        }

        try {
            $concept = app(AngebotService::class)->neuesConcept($team, $angebotId, ($n = trim((string) ($arguments['name'] ?? ''))) !== '' ? $n : null);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['angebot_id' => $angebotId, 'concept_id' => (int) $concept->id, 'name' => $concept->name]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'angebot', 'menue', 'write'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['creates'],
            'related_tools' => ['foodalchemist.angebot_menue.PROMOTE', 'foodalchemist.angebot_menue.DELETE'],
            'examples' => ['Lege in Angebot 5 ein Menü „Galadinner" an.'],
        ];
    }
}
