<?php

namespace Platform\FoodAlchemist\Tools;

use Illuminate\Pagination\Paginator;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\PaketService;

/**
 * Abo-/Alt-Pakete (foodalchemist_packages) auflisten — Gegenstück zum Alt-Paket-Browser (/pakete).
 * Seit 2026-11 führen foodalchemist.pakete.* die Concepter-Pakete; siehe AboPaketeGetTool.
 */
class AboPaketeListTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.abo_pakete.LIST';
    }

    public function getDescription(): string
    {
        return 'Listet sichtbare Abo-/Alt-Pakete (Paket-Modell der Abos, Speisepläne und Bestellungen — NICHT die Concepter-Pakete, '
            . 'dafür foodalchemist.pakete.LIST) seitenweise (page, per_page, last_page) mit Anzahl Gerichte. Filter: q (Name/Rolle), role. '
            . 'Abo-Fassungen tragen den Team-Preis des Mandanten (unvollständig = null + price_reason). Read-only.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'role' => ['type' => 'string', 'description' => 'Optionaler Rollen-Filter.'],
                'q' => ['type' => 'string', 'description' => 'Suche in Name und Rolle.'],
                'per_page' => ['type' => 'integer', 'description' => 'Seitengröße (Default 100).'],
                'page' => ['type' => 'integer', 'description' => 'Seite (ab 1, last_page im Ergebnis).'],
                'outlet_id' => ['type' => 'integer', 'description' => 'Optional: Kalkulationsprofil — VK/Person je Kalkulationsprofil (price_per_person_outlet). Fehlt = aktive Brille bzw. Team-Baseline. IDs via outlets.GET.'],
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
                return ToolResult::error('Kalkulationsprofil nicht gefunden im Team.', 'NOT_FOUND');
            }
        } else {
            $outlet = app(\Platform\FoodAlchemist\Services\ActiveOutletContext::class)
                ->current($team, $context->user?->id !== null ? (int) $context->user->id : null);
        }
        $filters = [];
        if (($role = trim((string) ($arguments['role'] ?? ''))) !== '') {
            $filters['role'] = $role;
        }
        if (($q = trim((string) ($arguments['q'] ?? ''))) !== '') {
            $filters['search'] = $q;
        }
        $seite = max(1, (int) ($arguments['page'] ?? 1));
        Paginator::currentPageResolver(fn () => $seite);
        $perPage = (int) ($arguments['per_page'] ?? 100);
        $page = app(PaketService::class)->paginateBrowser($filters, $team, $perPage > 0 ? $perPage : 100);

        return $this->erfolgMitWarnungen([
            'total' => $page->total(),
            'page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'active_outlet_id' => $outlet?->id,
            'pakete' => collect($page->items())->map(fn ($p) => $this->paketPayload($p, false, $outlet, $team) + ['gerichte' => (int) $p->dishes_count])->all(),
        ], $arguments);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'read',
            'tags' => ['foodalchemist', 'paket', 'abo', 'read'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => [],
            'related_tools' => ['foodalchemist.abo_pakete.GET', 'foodalchemist.abos.GET', 'foodalchemist.pakete.LIST'],
            'examples' => ['Liste alle Pakete der Rolle „Buffet".'],
        ];
    }
}
