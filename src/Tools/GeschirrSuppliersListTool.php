<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\GeschirrService;

/** MCP-Steuerbarkeit · D4: Geschirr-Lieferanten listen (team-sichtbar, mit Artikel-Zähler). */
class GeschirrSuppliersListTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.geschirr_suppliers.LIST';
    }

    public function getDescription(): string
    {
        return 'Listet die (team-sichtbaren) Geschirr-Lieferanten mit Artikel-Zähler. Optional include_inactive/search.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'include_inactive' => ['type' => 'boolean', 'description' => 'Inaktive mitlisten.'],
                'search' => ['type' => 'string', 'description' => 'Namensfilter.'],
            ],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }

        $rows = app(GeschirrService::class)->listSuppliersWithCounts(
            $team, (bool) ($arguments['include_inactive'] ?? false), (string) ($arguments['search'] ?? '')
        );

        return ToolResult::success([
            'suppliers' => $rows->map(fn ($s) => [
                'id' => (int) $s->id,
                'name' => $s->name ?? null,
                'is_inactive' => (bool) ($s->is_inactive ?? false),
                'items_count' => (int) ($s->items_count ?? $s->artikel_count ?? 0),
            ])->values()->all(),
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'tags' => ['foodalchemist', 'geschirr', 'lieferant', 'liste'],
            'related_tools' => ['foodalchemist.geschirr_items.LIST', 'foodalchemist.geschirr_suppliers.POST'],
            'examples' => ['Zeig die Geschirr-Lieferanten.'],
        ];
    }
}
