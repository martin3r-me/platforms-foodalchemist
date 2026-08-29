<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\FormatService;

/** MCP-Steuerbarkeit · D6: ein Format-Bild als Hero markieren (genau 0/1 Hero je Format). */
class FormatImagesHeroTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.format_images.HERO';
    }

    public function getDescription(): string
    {
        return 'Markiert ein Format-Bild als Hero (setzt alle anderen des Formats zurück).';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => ['image_id' => ['type' => 'integer', 'description' => 'Bild-Id.']], 'required' => ['image_id']];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $imageId = (int) ($arguments['image_id'] ?? 0);
        if (($guard = $this->guardFormatImageOwned($team, $imageId)) !== null) {
            return $guard;
        }

        try {
            app(FormatService::class)->setHero($team, $imageId);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['image_id' => $imageId, 'is_hero' => true]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'format', 'image', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.format_images.CAPTION', 'foodalchemist.format_images.REORDER'],
            'examples' => ['Mach Bild 9 zum Hero.'],
        ];
    }
}
