<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistFormat;
use Platform\FoodAlchemist\Services\FormatService;

/** MCP-Steuerbarkeit · D6: Struktur-Block (header/text/spacer) in ein Format einfügen. */
class FormatBlocksPostTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.format_blocks.POST';
    }

    public function getDescription(): string
    {
        return 'Fügt einen Struktur-Block (type header/text/spacer) in ein team-eigenes Format ein. '
            . 'felder: title/text_content (header/text), height (spacer). Optional after_slot_id.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'format_id' => ['type' => 'integer', 'description' => 'Format-Id.'],
                'type' => ['type' => 'string', 'enum' => ['header', 'text', 'spacer'], 'description' => 'Block-Typ.'],
                'felder' => ['type' => 'object', 'description' => 'Block-Felder (title, text_content, height).'],
                'after_slot_id' => ['type' => 'integer', 'description' => 'Optional dahinter einsortieren.'],
            ],
            'required' => ['format_id', 'type'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $type = trim((string) ($arguments['type'] ?? ''));
        if ($type === '') {
            return ToolResult::error('type ist Pflicht.', 'VALIDATION_ERROR');
        }
        $formatId = (int) ($arguments['format_id'] ?? 0);
        if (($guard = $this->guardOwned($team, FoodAlchemistFormat::class, $formatId, 'Format')) !== null) {
            return $guard;
        }

        try {
            $slot = app(FormatService::class)->slotBlockEinfuegen(
                $team,
                $formatId,
                $type,
                is_array($arguments['felder'] ?? null) ? $arguments['felder'] : [],
                isset($arguments['after_slot_id']) ? (int) $arguments['after_slot_id'] : null
            );
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['format_id' => $formatId, 'slot_id' => (int) $slot->id, 'type' => $type]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'format', 'block', 'write'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['creates'],
            'related_tools' => ['foodalchemist.format_blocks.PUT'],
            'examples' => ['Füge Format 3 einen Header-Block „Vorspeisen" hinzu.'],
        ];
    }
}
