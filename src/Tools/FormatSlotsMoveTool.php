<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\FormatService;

/** MCP-Steuerbarkeit · D6: eine Format-Position hinter eine andere ziehen (Drag/Einfüge-Ziel). */
class FormatSlotsMoveTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.format_slots.MOVE';
    }

    public function getDescription(): string
    {
        return 'Verschiebt eine Format-Position hinter eine andere. after_slot_id weglassen = an den Anfang.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'slot_id' => ['type' => 'integer', 'description' => 'Zu verschiebender Slot.'],
                'after_slot_id' => ['type' => 'integer', 'description' => 'Ziel: dahinter einsortieren (weglassen = an den Anfang).'],
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
        if (($guard = $this->guardFormatSlotOwned($team, $slotId)) !== null) {
            return $guard;
        }

        try {
            app(FormatService::class)->slotVerschieben($team, $slotId, isset($arguments['after_slot_id']) ? (int) $arguments['after_slot_id'] : null);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['slot_id' => $slotId, 'after_slot_id' => isset($arguments['after_slot_id']) ? (int) $arguments['after_slot_id'] : null]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'format', 'slot', 'write'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.format_slots.REORDER'],
            'examples' => ['Schiebe Slot 8 hinter Slot 2.'],
        ];
    }
}
