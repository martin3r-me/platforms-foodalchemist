<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\ConceptVariantService;

/** MCP-Steuerbarkeit · D5: konzept-lokale Varianten eines Slots zurücksetzen (auf das Basis-Gericht). */
class ConceptSlotVarianteResetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.concept_slot_variante.RESET';
    }

    public function getDescription(): string
    {
        return 'Setzt die konzept-lokalen Zutat-Varianten eines Slots auf das Basis-Gericht zurück.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => ['slot_id' => ['type' => 'integer', 'description' => 'Slot-Id.']], 'required' => ['slot_id']];
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
            app(ConceptVariantService::class)->zuruecksetzen($team, $slotId);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['slot_id' => $slotId, 'reset' => true]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'concept', 'slot', 'variante', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.concept_slot_variante.SWAP'],
            'examples' => ['Setze die Varianten von Slot 12 zurück.'],
        ];
    }
}
