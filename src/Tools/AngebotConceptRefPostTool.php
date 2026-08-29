<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistAngebot;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Services\AngebotService;

/** MCP-Steuerbarkeit · D10: ein Katalog-Concept in einem Angebot referenzieren (ohne Kopie). */
class AngebotConceptRefPostTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.angebot_concept_ref.POST';
    }

    public function getDescription(): string
    {
        return 'Referenziert ein sichtbares Katalog-Concept in einem team-eigenen Angebot (reine Referenz, keine Kopie).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'angebot_id' => ['type' => 'integer', 'description' => 'Angebot-Id.'],
                'concept_id' => ['type' => 'integer', 'description' => 'Zu referenzierendes Concept.'],
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
        $conceptId = (int) ($arguments['concept_id'] ?? 0);
        if (! FoodAlchemistConcept::visibleToTeam($team)->whereKey($conceptId)->exists()) {
            return ToolResult::error('concept_id nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }

        try {
            app(AngebotService::class)->referenziereConcept($team, $angebotId, $conceptId);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['angebot_id' => $angebotId, 'concept_id' => $conceptId, 'referenced' => true]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'angebot', 'concept', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['creates'],
            'related_tools' => ['foodalchemist.angebot_concept_ref.DELETE'],
            'examples' => ['Referenziere Concept 7 in Angebot 5.'],
        ];
    }
}
