<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistFormat;
use Platform\FoodAlchemist\Services\FormatService;

/** MCP-Steuerbarkeit · D6: Aufbau-Positionen (Slots) eines Formats neu ordnen. */
class FormatSlotsReorderTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.format_slots.REORDER';
    }

    public function getDescription(): string
    {
        return 'Ordnet die Aufbau-Positionen (Concept-Referenzen + Struktur-Blöcke) eines team-eigenen Formats neu (slot-ids in Zielreihenfolge).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'format_id' => ['type' => 'integer', 'description' => 'Format-Id.'],
                'ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Slot-Ids in Zielreihenfolge.'],
            ],
            'required' => ['format_id', 'ids'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $ids = $arguments['ids'] ?? null;
        if (! is_array($ids) || $ids === []) {
            return ToolResult::error('ids muss ein nicht-leeres Array sein.', 'VALIDATION_ERROR');
        }
        $formatId = (int) ($arguments['format_id'] ?? 0);
        if (($guard = $this->guardOwned($team, FoodAlchemistFormat::class, $formatId, 'Format')) !== null) {
            return $guard;
        }

        try {
            app(FormatService::class)->slotsNeuOrdnen($team, $formatId, array_map('intval', $ids));
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['format_id' => $formatId, 'ids' => array_map('intval', $ids)]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'format', 'slot', 'reorder', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.format_slots.MOVE', 'foodalchemist.format_editions.POST'],
            'examples' => ['Ordne die Slots von Format 3 als [5,2,8].'],
        ];
    }
}
