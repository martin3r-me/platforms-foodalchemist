<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Services\ConceptService;

/** MCP-Steuerbarkeit · D5: Preis-/Aggregat-Cache eines team-eigenen Konzepts neu berechnen. */
class ConceptsRecomputeTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.concepts.RECOMPUTE';
    }

    public function getDescription(): string
    {
        return 'Berechnet den Preis-/Aggregat-Cache eines team-eigenen Konzepts neu. Idempotent.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['id' => ['type' => 'integer', 'description' => 'Konzept-Id (team-eigen).']],
            'required' => ['id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $id = (int) ($arguments['id'] ?? 0);
        $c = FoodAlchemistConcept::visibleToTeam($team)->whereKey($id)->first();
        if ($c === null) {
            return ToolResult::error('Konzept nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }
        if (! $c->isOwnedBy($team)) {
            return ToolResult::error('Nur fürs Besitzer-Team.', 'ACCESS_DENIED');
        }

        $c = app(ConceptService::class)->recomputeCache($c);

        return ToolResult::success(['id' => (int) $c->id, 'recomputed' => true]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'concept', 'recompute', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.concepts.GET'],
            'examples' => ['Berechne Konzept 7 neu.'],
        ];
    }
}
