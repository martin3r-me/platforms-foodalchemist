<?php

namespace Platform\FoodAlchemist\Tools;

use Illuminate\Pagination\Paginator;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\SpeiseplanService;

/** MCP-Steuerbarkeit · D9: sichtbare Speisepläne auflisten (paginiert). Read-only. */
class SpeiseplaeneListTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.speiseplaene.LIST';
    }

    public function getDescription(): string
    {
        return 'Listet sichtbare Speisepläne seitenweise (page, per_page, last_page) mit Anzahl Einträge. Optional status-Filter oder Freitext-search.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'search' => ['type' => 'string', 'description' => 'Freitext (Name).'],
                'status' => ['type' => 'string', 'enum' => array_column(\Platform\FoodAlchemist\Enums\AusgabeStatus::cases(), 'value'), 'description' => 'Optionaler Status-Filter (leer = alle).'],
                'per_page' => ['type' => 'integer', 'description' => 'Seitengröße (Default 100).'],
                'page' => ['type' => 'integer', 'description' => 'Seite (ab 1, last_page im Ergebnis).'],
            ],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        if ($fehler = $this->enumFehler($arguments)) {
            return $fehler;
        }
        $filters = [];
        if (($search = trim((string) ($arguments['search'] ?? ''))) !== '') {
            $filters['search'] = $search;
        }
        if (($status = trim((string) ($arguments['status'] ?? ''))) !== '') {
            $filters['status'] = $status;
        }
        $seite = max(1, (int) ($arguments['page'] ?? 1));
        Paginator::currentPageResolver(fn () => $seite);
        $perPage = (int) ($arguments['per_page'] ?? 100);
        $page = app(SpeiseplanService::class)->paginateBrowser($filters, $team, $perPage > 0 ? $perPage : 100);

        return $this->erfolgMitWarnungen([
            'total' => $page->total(),
            'page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'speiseplaene' => collect($page->items())->map(fn ($p) => [
                'id' => (int) $p->id, 'name' => $p->name,
                'status' => $p->status instanceof \BackedEnum ? $p->status->value : $p->status,
                'cycle_weeks' => $p->cycle_weeks,
                'crm_company_id' => $p->crm_company_id !== null ? (int) $p->crm_company_id : null,
                'eintraege' => (int) $p->entries_count,
            ])->all(),
        ], $arguments);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'read',
            'tags' => ['foodalchemist', 'speiseplan', 'read'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => [],
            'related_tools' => ['foodalchemist.speiseplaene.GET'],
            'examples' => ['Liste alle aktiven Speisepläne.'],
        ];
    }
}
