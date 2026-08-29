<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Services\ConceptService;

/** MCP-Steuerbarkeit · D5: aus mehreren Konzept-Slots ein Paket bilden. */
class ConceptPaketBuildTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.concept_paket.BUILD';
    }

    public function getDescription(): string
    {
        return 'Bündelt mehrere Positionen (Slots) eines team-eigenen Konzepts zu einem Paket (name, optional role).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'concept_id' => ['type' => 'integer', 'description' => 'Konzept-Id (team-eigen).'],
                'slot_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Zu bündelnde Slot-Ids.'],
                'name' => ['type' => 'string', 'description' => 'Paket-Name.'],
                'role' => ['type' => 'string', 'description' => 'Optionale Rolle/Gang des Pakets.'],
            ],
            'required' => ['concept_id', 'slot_ids', 'name'],
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
        $slotIds = array_values(array_filter(array_map('intval', (array) ($arguments['slot_ids'] ?? []))));
        if ($slotIds === []) {
            return ToolResult::error('slot_ids muss mindestens eine Slot-Id enthalten.', 'VALIDATION_ERROR');
        }
        $conceptId = (int) ($arguments['concept_id'] ?? 0);
        if (($guard = $this->guardOwned($team, FoodAlchemistConcept::class, $conceptId, 'Konzept')) !== null) {
            return $guard;
        }

        try {
            $slot = app(ConceptService::class)->bildePaketAusPositionen(
                $team,
                $conceptId,
                $slotIds,
                $name,
                ($r = trim((string) ($arguments['role'] ?? ''))) !== '' ? $r : null
            );
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['concept_id' => $conceptId, 'paket_slot_id' => (int) $slot->id, 'name' => $name]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'concept', 'paket', 'write'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['creates', 'updates'],
            'related_tools' => ['foodalchemist.concept_slots.REORDER'],
            'examples' => ['Bündle die Slots 3,4,5 von Konzept 7 zum Paket „Menü A".'],
        ];
    }
}
