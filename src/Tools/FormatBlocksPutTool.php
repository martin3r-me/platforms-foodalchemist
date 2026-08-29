<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\FormatService;

/** MCP-Steuerbarkeit · D6: Struktur-Block eines Formats bearbeiten (title/text_content/height). */
class FormatBlocksPutTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.format_blocks.PUT';
    }

    public function getDescription(): string
    {
        return 'Bearbeitet einen Struktur-Block eines team-eigenen Formats (per slot_id; felder title/text_content/height).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'slot_id' => ['type' => 'integer', 'description' => 'Block-/Slot-Id.'],
                'felder' => ['type' => 'object', 'description' => 'Block-Felder (title, text_content, height).'],
            ],
            'required' => ['slot_id', 'felder'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $felder = $arguments['felder'] ?? null;
        if (! is_array($felder) || $felder === []) {
            return ToolResult::error('felder muss ein nicht-leeres Objekt sein.', 'VALIDATION_ERROR');
        }
        $slotId = (int) ($arguments['slot_id'] ?? 0);
        if (($guard = $this->guardFormatSlotOwned($team, $slotId)) !== null) {
            return $guard;
        }

        try {
            app(FormatService::class)->slotBlockSpeichern($team, $slotId, $felder);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['slot_id' => $slotId, 'updated' => true]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'format', 'block', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.format_blocks.POST'],
            'examples' => ['Ändere den Text von Format-Block 20.'],
        ];
    }
}
