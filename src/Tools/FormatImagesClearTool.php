<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\FormatService;

/**
 * MCP-Steuerbarkeit · D6: ein Marketing-Bild eines Formats entfernen (löscht die Datei + Zeile).
 * War es der Hero, rückt das älteste verbliebene Bild nach. Destruktiv → confirm=true Pflicht.
 */
class FormatImagesClearTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.format_images.CLEAR';
    }

    public function getDescription(): string
    {
        return 'Entfernt ein Marketing-Bild eines team-eigenen Formats (Datei + Zeile). Erfordert confirm=true.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'image_id' => ['type' => 'integer', 'description' => 'Bild-Id.'],
                'confirm' => ['type' => 'boolean', 'description' => 'Muss true sein (destruktive Aktion).'],
            ],
            'required' => ['image_id', 'confirm'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        if (($arguments['confirm'] ?? false) !== true) {
            return ToolResult::error('Löschen erfordert confirm=true (destruktive Aktion).', 'CONFIRM_REQUIRED');
        }
        $imageId = (int) ($arguments['image_id'] ?? 0);
        if (($guard = $this->guardFormatImageOwned($team, $imageId)) !== null) {
            return $guard;
        }

        try {
            app(FormatService::class)->clearImage($team, $imageId);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['image_id' => $imageId, 'deleted' => true]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'format', 'image', 'delete'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'destructive',
            'confirmation_required' => true,
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['deletes'],
            'related_tools' => ['foodalchemist.format_images.HERO'],
            'examples' => ['Entferne Bild 9 (confirm=true).'],
        ];
    }
}
