<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\PresentationDesignService;

/**
 * Spec 43 (write): Legt ein wiederverwendbares Präsentations-Design an (blockbasiertes
 * layout_json + Style-tokens_json). team-eigen.
 */
class PresentationDesignsPostTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.presentation_designs.POST';
    }

    public function getDescription(): string
    {
        return 'Legt ein Präsentations-Design an: geordnete Blockliste (layout_json) + Style-Tokens '
            . '(tokens_json). base_slug editorial|menu|kiosk als Ausgangspunkt. Datengebundene Blöcke '
            . '(cover, chapter_loop, dish_list, price_summary, legend, grid, text, heading, image, spacer, cta).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
                'base_slug' => ['type' => 'string', 'enum' => ['editorial', 'menu', 'kiosk']],
                'layout_json' => ['type' => 'array', 'items' => ['type' => 'object']],
                'tokens_json' => ['type' => 'object'],
                'custom_css' => ['type' => 'string', 'description' => 'Sandboxed CSS-only Layer (kein <, @import, expression) — überschreibt die Basis-Optik. Content bleibt datengebunden.'],
            ],
            'required' => ['name'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        try {
            $d = app(PresentationDesignService::class)->create($team, [
                'name' => (string) ($arguments['name'] ?? ''),
                'base_slug' => $arguments['base_slug'] ?? 'editorial',
                'layout_json' => $arguments['layout_json'] ?? null,
                'tokens_json' => $arguments['tokens_json'] ?? null,
                'custom_css' => $arguments['custom_css'] ?? null,
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['id' => (int) $d->id, 'name' => $d->name, 'base_slug' => $d->base_slug]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'command',
            'tags' => ['foodalchemist', 'praesentation', 'design', 'anlegen'],
            'read_only' => false,
            'idempotent' => false,
            'risk_level' => 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.presentation_designs.PUT', 'foodalchemist.presentation_designs.SEARCH'],
            'examples' => ['Lege ein Design „Sommer" auf Basis editorial an.'],
        ];
    }
}
