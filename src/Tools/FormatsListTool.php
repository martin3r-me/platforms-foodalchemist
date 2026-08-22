<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\FormatService;

/** Format-Modul: Formate (Marken-/Themen-Container über den Concepts) auflisten. */
class FormatsListTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.formats.LIST';
    }

    public function getDescription(): string
    {
        return 'Listet Formate (Marken-/Themen-Container EINE Ebene über den Konzepten, z. B. „CHEFS.CORNER") '
            . 'mit Editionen-Anzahl. Optional nach status (draft|active|archiviert) / origin (eigen|gruppe|kunde) filtern.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'status' => ['type' => 'string'],
                'origin' => ['type' => 'string'],
            ],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }

        $page = app(FormatService::class)->paginateBrowser([
            'status' => $arguments['status'] ?? '',
            'origin' => $arguments['origin'] ?? '',
        ], $team, 200);

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
            'tags' => ['foodalchemist', 'format', 'foodkonzept', 'liste'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.formats.GET', 'foodalchemist.formats.SEARCH'],
            'examples' => ['Zeig mir alle Formate', 'Welche aktiven Formate gibt es?'],
        ];
    }
}
