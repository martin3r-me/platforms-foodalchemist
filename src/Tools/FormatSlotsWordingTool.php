<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\FormatService;

/**
 * MCP-Steuerbarkeit · D6: format-lokales Per-Gericht-Wording eines Concept-Slots im Format setzen/leeren.
 * Das referenzierte Concept bleibt unangetastet (Override liegt am Format-Slot).
 */
class FormatSlotsWordingTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.format_slots.WORDING';
    }

    public function getDescription(): string
    {
        return 'Setzt/leert das format-lokale Wording eines Concept-Slots (per format_slot_id + concept_slot_id). '
            . 'text weglassen/leer = zurück auf die Wording-Kette (Konzept → Standard → Name).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'format_slot_id' => ['type' => 'integer', 'description' => 'Format-Slot (Concept-Referenz).'],
                'concept_slot_id' => ['type' => 'integer', 'description' => 'Betroffene Concept-Slot-Id (Gericht-Position im Concept).'],
                'text' => ['type' => 'string', 'description' => 'Override-Text (leer/weglassen = löschen).'],
            ],
            'required' => ['format_slot_id', 'concept_slot_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $formatSlotId = (int) ($arguments['format_slot_id'] ?? 0);
        if (($guard = $this->guardFormatSlotOwned($team, $formatSlotId)) !== null) {
            return $guard;
        }

        try {
            app(FormatService::class)->setSlotWording(
                $team,
                $formatSlotId,
                (int) ($arguments['concept_slot_id'] ?? 0),
                isset($arguments['text']) ? (string) $arguments['text'] : null
            );
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['format_slot_id' => $formatSlotId, 'concept_slot_id' => (int) ($arguments['concept_slot_id'] ?? 0)]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'format', 'slot', 'wording', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.format_editions.POST'],
            'examples' => ['Überschreibe im Format-Slot 5 das Wording der Concept-Position 12.'],
        ];
    }
}
