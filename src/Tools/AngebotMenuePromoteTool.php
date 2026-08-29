<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Services\AngebotService;

/** MCP-Steuerbarkeit · D10: ein angebots-lokales Menü in den Concept-Katalog überführen (promoten). */
class AngebotMenuePromoteTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.angebot_menue.PROMOTE';
    }

    public function getDescription(): string
    {
        return 'Überführt ein angebots-lokales Menü (Concept) in den wiederverwendbaren Concept-Katalog (standardisiert).';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => ['concept_id' => ['type' => 'integer', 'description' => 'Menü-Concept-Id.']], 'required' => ['concept_id']];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $conceptId = (int) ($arguments['concept_id'] ?? 0);
        if (($guard = $this->guardOwned($team, FoodAlchemistConcept::class, $conceptId, 'Menü-Concept')) !== null) {
            return $guard;
        }

        try {
            $concept = app(AngebotService::class)->promoteConcept($team, $conceptId);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['concept_id' => (int) $concept->id, 'promoted' => true]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'angebot', 'menue', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.angebot_menue.POST'],
            'examples' => ['Promote das Menü-Concept 12 in den Katalog.'],
        ];
    }
}
