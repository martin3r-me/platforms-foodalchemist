<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\PresentationDesignService;

/**
 * Spec 43 (read): Ein Präsentations-Design mit vollem layout_json + tokens_json.
 */
class PresentationDesignsGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.presentation_designs.GET';
    }

    public function getDescription(): string
    {
        return 'Liefert ein Präsentations-Design vollständig (name, base_slug, output_types, layout_json=Blöcke, '
            . 'tokens_json=Einstellungen, custom_css). Round-trip-fähig mit PUT. ' . PresentationDesignService::tokensVocabDoc();
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['id' => ['type' => 'integer']],
            'required' => ['id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $d = app(PresentationDesignService::class)->find($team, (int) $arguments['id']);
        if ($d === null) {
            return ToolResult::error('Design nicht gefunden oder nicht sichtbar.', 'NOT_FOUND');
        }

        return ToolResult::success([
            'id' => (int) $d->id,
            'name' => $d->name,
            'base_slug' => $d->base_slug,
            'output_types' => $d->output_types,
            'owned' => $d->isOwnedBy($team),
            'layout_json' => $d->layout_json,
            'tokens_json' => $d->tokens_json,
            'custom_css' => $d->custom_css,
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'praesentation', 'design'],
            'read_only' => true,
            'idempotent' => true,
            'risk_level' => 'read',
            'requires_auth' => true,
            'requires_team' => true,
            'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.presentation_designs.SEARCH'],
            'examples' => ['Zeig mir das Design 5.'],
        ];
    }
}
