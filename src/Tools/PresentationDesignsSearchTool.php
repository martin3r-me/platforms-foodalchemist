<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\PresentationDesignService;

/**
 * Spec 43 (read): Sichtbare Präsentations-Designs (eigene + geerbte/globale), optional gefiltert.
 */
class PresentationDesignsSearchTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.presentation_designs.SEARCH';
    }

    public function getDescription(): string
    {
        return 'Listet sichtbare Präsentations-Designs (eigene + geerbte/globale). Optional Namensfilter q.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['q' => ['type' => 'string']],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $q = trim((string) ($arguments['q'] ?? ''));
        $designs = app(PresentationDesignService::class)->list($team)
            ->when($q !== '', fn ($c) => $c->filter(fn ($d) => stripos((string) $d->name, $q) !== false))
            ->map(fn ($d) => [
                'id' => (int) $d->id,
                'name' => $d->name,
                'base_slug' => $d->base_slug,
                'owned' => $d->isOwnedBy($team),
            ])->values()->all();

        return ToolResult::success(['designs' => $designs, 'count' => count($designs)]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'praesentation', 'design', 'liste'],
            'read_only' => true,
            'idempotent' => true,
            'risk_level' => 'read',
            'requires_auth' => true,
            'requires_team' => true,
            'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.presentation_designs.GET'],
            'examples' => ['Welche Präsentations-Designs haben wir?'],
        ];
    }
}
