<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\ConceptVariantService;

/** MCP-Steuerbarkeit · D5: Zutat eines Konzept-Slots konzept-lokal tauschen (Variante). */
class ConceptSlotVarianteSwapTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.concept_slot_variante.SWAP';
    }

    public function getDescription(): string
    {
        return 'Tauscht eine Zutat eines Konzept-Slots konzept-lokal (ohne das Basis-Gericht zu ändern).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'slot_id' => ['type' => 'integer', 'description' => 'Slot-Id.'],
                'ingredient_id' => ['type' => 'integer', 'description' => 'Zutat-Zeilen-Id im Slot-Gericht.'],
            ],
            'required' => ['slot_id', 'ingredient_id'],
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
            app(ConceptVariantService::class)->tauscheZutatKonzeptLokal($team, $slotId, (int) ($arguments['ingredient_id'] ?? 0));
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['slot_id' => $slotId, 'ingredient_id' => (int) ($arguments['ingredient_id'] ?? 0)]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'concept', 'slot', 'variante', 'write'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.concept_slot_variante.RESET'],
            'examples' => ['Tausche bei Slot 12 die Zutat 5 (konzept-lokal).'],
        ];
    }
}
