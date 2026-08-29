<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\SpeisekarteService;

/** MCP-Steuerbarkeit · D8: sichtbare Speisekarten auflisten (paginiert). Read-only. */
class SpeisekartenListTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.speisekarten.LIST';
    }

    public function getDescription(): string
    {
        return 'Listet sichtbare Speisekarten (paginiert). Optional status-Filter. Für Freitext: speisekarten.SEARCH.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'status' => ['type' => 'string', 'description' => 'Optionaler Status-Filter.'],
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
        $page = app(SpeisekarteService::class)->paginateBrowser($filters, $team, $perPage > 0 ? $perPage : 100);

        return ToolResult::success([
            'total' => $page->total(),
            'speisekarten' => collect($page->items())->map(fn ($k) => [
                'id' => (int) $k->id, 'name' => $k->name,
                'status' => $k->status instanceof \BackedEnum ? $k->status->value : $k->status,
                'karten_typ' => $k->karten_typ,
                'crm_company_id' => $k->crm_company_id !== null ? (int) $k->crm_company_id : null,
            ])->all(),
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'read',
            'tags' => ['foodalchemist', 'speisekarte', 'read'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => [],
            'related_tools' => ['foodalchemist.speisekarten.SEARCH', 'foodalchemist.speisekarten.GET'],
            'examples' => ['Liste alle aktiven Speisekarten.'],
        ];
    }
}
