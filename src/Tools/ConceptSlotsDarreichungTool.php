<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\ConceptService;

/** MCP-Steuerbarkeit · D5: Darreichung eines Konzept-Slots setzen/lösen. */
class ConceptSlotsDarreichungTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.concept_slots.DARREICHUNG';
    }

    public function getDescription(): string
    {
        return 'Setzt/löst die Darreichung (des Gerichts) an einem Konzept-Slot. darreichung_id weglassen = lösen.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'slot_id' => ['type' => 'integer', 'description' => 'Slot-Id.'],
                'darreichung_id' => ['type' => 'integer', 'description' => 'Darreichungs-Id (weglassen = lösen).'],
            ],
            'required' => ['slot_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $slotId = (int) ($arguments['slot_id'] ?? 0);
        if (($guard = $this->guardConceptSlotOwned($team, $slotId)) !== null) {
            return $guard;
        }

        try {
            app(ConceptService::class)->setSlotDarreichung($team, $slotId, isset($arguments['darreichung_id']) ? (int) $arguments['darreichung_id'] : null);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['slot_id' => $slotId, 'darreichung_id' => isset($arguments['darreichung_id']) ? (int) $arguments['darreichung_id'] : null]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'concept', 'slot', 'darreichung', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.concept_slots.GESCHIRR'],
            'examples' => ['Setze bei Slot 12 die Darreichung 88.'],
        ];
    }
}
