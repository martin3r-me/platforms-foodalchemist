<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\PaketService;

/** MCP-Steuerbarkeit · D5d: sichtbare Pakete auflisten (paginiert). Read-only. */
class PaketeListTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.pakete.LIST';
    }

    public function getDescription(): string
    {
        return 'Listet sichtbare Pakete (paginiert). Optional role-Filter. Für Freitextsuche: pakete.SEARCH.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'role' => ['type' => 'string', 'description' => 'Optionaler Rollen-Filter.'],
                'per_page' => ['type' => 'integer', 'description' => 'Seitengröße (Default 100).'],
                'outlet_id' => ['type' => 'integer', 'description' => 'Optional: Betrieb — VK/Person betriebsscharf (price_per_person_outlet). Fehlt = aktive Brille bzw. Team-Baseline. IDs via outlets.GET.'],
            ],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $outlet = null;
        if (! empty($arguments['outlet_id'])) {
            $outlet = \Platform\FoodAlchemist\Models\FoodAlchemistOutlet::where('team_id', $team->id)->find((int) $arguments['outlet_id']);
            if ($outlet === null) {
                return ToolResult::error('Betrieb nicht gefunden im Team.', 'NOT_FOUND');
            }
        } else {
            $outlet = app(\Platform\FoodAlchemist\Services\ActiveOutletContext::class)
                ->current($team, $context->user?->id !== null ? (int) $context->user->id : null);
        }
        $filters = [];
        if (($role = trim((string) ($arguments['role'] ?? ''))) !== '') {
            $filters['role'] = $role;
        }
        $perPage = (int) ($arguments['per_page'] ?? 100);
        $page = app(PaketService::class)->paginateBrowser($filters, $team, $perPage > 0 ? $perPage : 100);

        return ToolResult::success([
            'total' => $page->total(),
            'active_outlet_id' => $outlet?->id,
            'pakete' => collect($page->items())->map(fn ($p) => $this->paketPayload($p, false, $outlet))->all(),
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'read',
            'tags' => ['foodalchemist', 'paket', 'read'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => [],
            'related_tools' => ['foodalchemist.pakete.SEARCH', 'foodalchemist.pakete.GET'],
            'examples' => ['Liste alle Pakete der Rolle „Buffet".'],
        ];
    }
}
