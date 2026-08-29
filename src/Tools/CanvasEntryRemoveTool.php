<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistCanvasEntry;
use Platform\FoodAlchemist\Services\CanvasService;

/** MCP-Steuerbarkeit · D12: einen Listen-Eintrag eines Canvas-Felds entfernen (team-eigen). */
class CanvasEntryRemoveTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.canvas.ENTRY_REMOVE';
    }

    public function getDescription(): string
    {
        return 'Entfernt einen Listen-Eintrag eines Canvas-Felds (per entry_id). Nur an team-eigenen Canvasses.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => ['entry_id' => ['type' => 'integer', 'description' => 'Canvas-Eintrag-Id.']], 'required' => ['entry_id']];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $entryId = (int) ($arguments['entry_id'] ?? 0);
        $entry = FoodAlchemistCanvasEntry::with('canvas')->whereKey($entryId)->first();
        if ($entry === null || $entry->canvas === null) {
            return ToolResult::error('Canvas-Eintrag nicht vorhanden.', 'NOT_FOUND');
        }
        if ((int) $entry->canvas->team_id !== (int) $team->id) {
            return ToolResult::error('Nur an team-eigenen Canvasses.', 'ACCESS_DENIED');
        }

        app(CanvasService::class)->removeEntry($entryId);

        return ToolResult::success(['entry_id' => $entryId, 'removed' => true]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'canvas', 'dna', 'entry', 'delete'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['deletes'],
            'related_tools' => ['foodalchemist.canvas.ENTRY_ADD'],
            'examples' => ['Entferne den Canvas-Eintrag 12.'],
        ];
    }
}
