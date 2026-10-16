<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\SupplierGroupService;

/** Lieferanten-Gruppen (Taxonomie) mit Anzahl Lieferanten je Gruppe. Nur lesend. */
class SupplierGroupsListTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.supplier_groups.LIST';
    }

    public function getDescription(): string
    {
        return 'Listet die Lieferanten-Gruppen (Lebensmittel-Großhandel, Frische & Fachhandel, Getränke, Non-Food & Verbrauch, Verleih & Eventausstattung, Sonstiges) '
            .'mit Code, Name und Anzahl der Lieferanten. Ein Lieferant hat genau eine Hauptgruppe; setzen mit foodalchemist.suppliers.PUT (input.group = Code oder Id), '
            .'ohne_gruppe zählt Lieferanten ohne Zuordnung.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $svc = app(SupplierGroupService::class);
        $zaehler = $svc->zaehler($team);

        return ToolResult::success([
            'gruppen' => $svc->liste($team)->map(fn ($g) => ['id' => (int) $g->id, 'code' => $g->code, 'name' => $g->name, 'lieferanten' => $zaehler[$g->id] ?? 0])->values()->all(),
            'ohne_gruppe' => $zaehler[''] ?? ($zaehler[0] ?? 0),
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query', 'tags' => ['foodalchemist', 'lieferant', 'gruppe', 'taxonomie'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe', 'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => [], 'related_tools' => ['foodalchemist.suppliers.PUT', 'foodalchemist.suppliers.SEARCH'],
            'examples' => ['Welche Lieferanten-Gruppen gibt es und wie viele Lieferanten hat jede?'],
        ];
    }
}
