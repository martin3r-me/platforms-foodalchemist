<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\FormatService;

/** MCP-Steuerbarkeit · D6: Bildunterschrift eines Format-Bilds setzen/leeren. */
class FormatImagesCaptionTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.format_images.CAPTION';
    }

    public function getDescription(): string
    {
        return 'Setzt/leert die Bildunterschrift eines Format-Bilds (caption weglassen = leeren).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'image_id' => ['type' => 'integer', 'description' => 'Bild-Id.'],
                'caption' => ['type' => 'string', 'description' => 'Bildunterschrift (weglassen = leeren).'],
            ],
            'required' => ['image_id'],
        ];
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
            app(FormatService::class)->setImageCaption($team, $imageId, isset($arguments['caption']) ? (string) $arguments['caption'] : null);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['image_id' => $imageId, 'caption' => isset($arguments['caption']) ? (string) $arguments['caption'] : null]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'format', 'image', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.format_images.HERO'],
            'examples' => ['Setze bei Bild 9 die Unterschrift „Sommerbuffet".'],
        ];
    }
}
