<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\PaketService;

/** MCP-Steuerbarkeit · D5d: Pakete per Freitext suchen. Read-only. */
class PaketeSearchTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.pakete.SEARCH';
    }

    public function getDescription(): string
    {
        return 'Sucht sichtbare Pakete per Freitext (Name), optional mit role-Filter.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => 'Suchbegriff (Paketname).'],
                'role' => ['type' => 'string', 'description' => 'Optionaler Rollen-Filter.'],
                'per_page' => ['type' => 'integer', 'description' => 'Seitengröße (Default 100).'],
            ],
            'required' => ['query'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $filters = ['search' => trim((string) ($arguments['query'] ?? ''))];
        if (($role = trim((string) ($arguments['role'] ?? ''))) !== '') {
            $filters['role'] = $role;
        }
        $perPage = (int) ($arguments['per_page'] ?? 100);
        $page = app(PaketService::class)->paginateBrowser($filters, $team, $perPage > 0 ? $perPage : 100);

        return ToolResult::success([
            'total' => $page->total(),
            'pakete' => collect($page->items())->map(fn ($p) => $this->paketPayload($p))->all(),
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'read',
            'tags' => ['foodalchemist', 'paket', 'search', 'read'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => [],
            'related_tools' => ['foodalchemist.pakete.LIST', 'foodalchemist.pakete.GET'],
            'examples' => ['Suche Pakete mit „Grill".'],
        ];
    }
}
