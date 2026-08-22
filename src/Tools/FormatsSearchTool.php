<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\FormatService;

/** Format-Modul: Formate nach Name/Consumer-Name/Claim suchen. */
class FormatsSearchTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.formats.SEARCH';
    }

    public function getDescription(): string
    {
        return 'Sucht Formate (Marken-/Themen-Container) über Name, Consumer-Name und Claim.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['query' => ['type' => 'string']],
            'required' => ['query'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }

        $page = app(FormatService::class)->paginateBrowser(['search' => (string) ($arguments['query'] ?? '')], $team, 50);

        return ToolResult::success([
            'formats' => collect($page->items())->map(fn ($f) => [
                'id' => $f->id, 'name' => $f->name, 'consumer_name' => $f->consumer_name,
                'status' => $f->status, 'origin' => $f->origin, 'editions_count' => (int) $f->editions_count,
            ])->all(),
            'total' => $page->total(),
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'format', 'foodkonzept', 'suche'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.formats.GET', 'foodalchemist.formats.LIST'],
            'examples' => ['Suche das Format CHEFS.CORNER'],
        ];
    }
}
