<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistAngebot;
use Platform\FoodAlchemist\Services\AngebotService;

/** MCP-Steuerbarkeit · D10: eine Concept-Referenz aus einem Angebot lösen (das Concept bleibt bestehen). */
class AngebotConceptRefDeleteTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.angebot_concept_ref.DELETE';
    }

    public function getDescription(): string
    {
        return 'Löst eine Concept-Referenz aus einem team-eigenen Angebot (das referenzierte Concept selbst bleibt unangetastet).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'angebot_id' => ['type' => 'integer', 'description' => 'Angebot-Id.'],
                'concept_id' => ['type' => 'integer', 'description' => 'Referenziertes Concept.'],
            ],
            'required' => ['angebot_id', 'concept_id'],
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
            app(AngebotService::class)->entferneReferenz($team, $angebotId, (int) ($arguments['concept_id'] ?? 0));
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['angebot_id' => $angebotId, 'concept_id' => (int) ($arguments['concept_id'] ?? 0), 'unlinked' => true]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'angebot', 'concept', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['deletes'],
            'related_tools' => ['foodalchemist.angebot_concept_ref.POST'],
            'examples' => ['Löse Concept 7 aus Angebot 5.'],
        ];
    }
}
