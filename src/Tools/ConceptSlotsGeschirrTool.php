<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\ConceptService;

/** MCP-Steuerbarkeit · D5: Geschirr-Zuordnung (Rolle → Geschirr-Artikel) an einem Konzept-Slot setzen/lösen. */
class ConceptSlotsGeschirrTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.concept_slots.GESCHIRR';
    }

    public function getDescription(): string
    {
        return 'Setzt/löst die Geschirr-Zuordnung (role → item_id) an einem Konzept-Slot. item_id weglassen = lösen.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'slot_id' => ['type' => 'integer', 'description' => 'Slot-Id.'],
                'role' => ['type' => 'string', 'description' => 'Geschirr-Rolle.'],
                'item_id' => ['type' => 'integer', 'description' => 'Geschirr-Artikel-Id (weglassen = lösen).'],
            ],
            'required' => ['slot_id', 'role'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $role = trim((string) ($arguments['role'] ?? ''));
        if ($role === '') {
            return ToolResult::error('role ist Pflicht.', 'VALIDATION_ERROR');
        }
        $slotId = (int) ($arguments['slot_id'] ?? 0);
        if (($guard = $this->guardConceptSlotOwned($team, $slotId)) !== null) {
            return $guard;
        }

        try {
            app(ConceptService::class)->setSlotGeschirr($team, $slotId, $role, isset($arguments['item_id']) ? (int) $arguments['item_id'] : null);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['slot_id' => $slotId, 'role' => $role, 'item_id' => isset($arguments['item_id']) ? (int) $arguments['item_id'] : null]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'concept', 'slot', 'geschirr', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.concept_slots.DARREICHUNG'],
            'examples' => ['Weise Slot 12 der Rolle „Teller" den Geschirr-Artikel 5 zu.'],
        ];
    }
}
