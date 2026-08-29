<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\FoodbookService;

/** MCP-Steuerbarkeit · D7: sichtbare Foodbooks auflisten (paginiert). Read-only. */
class FoodbooksListTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.foodbooks.LIST';
    }

    public function getDescription(): string
    {
        return 'Listet sichtbare Foodbooks (paginiert). Optional status-Filter. Für Freitext: foodbooks.SEARCH.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'status' => ['type' => 'string', 'description' => 'Optionaler Status-Filter (entwurf/aktiv/inaktiv/archiviert).'],
                'per_page' => ['type' => 'integer', 'description' => 'Seitengröße (Default 100).'],
            ],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $filters = [];
        if (($status = trim((string) ($arguments['status'] ?? ''))) !== '') {
            $filters['status'] = $status;
        }
        $perPage = (int) ($arguments['per_page'] ?? 100);
        $page = app(FoodbookService::class)->paginateBrowser($filters, $team, $perPage > 0 ? $perPage : 100);

        return ToolResult::success([
            'total' => $page->total(),
            'foodbooks' => collect($page->items())->map(fn ($f) => [
                'id' => (int) $f->id, 'label' => $f->label, 'status' => $f->status,
                'jahr' => $f->jahr, 'personen' => $f->personen,
                'crm_company_id' => $f->crm_company_id !== null ? (int) $f->crm_company_id : null,
            ])->all(),
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'read',
            'tags' => ['foodalchemist', 'foodbook', 'read'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => [],
            'related_tools' => ['foodalchemist.foodbooks.SEARCH', 'foodalchemist.foodbooks.GET'],
            'examples' => ['Liste alle aktiven Foodbooks.'],
        ];
    }
}
