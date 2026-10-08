<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\InventurService;

/** Spec 66 §6 · Lager lesen: Bestand je GP und Lagerort (bewertet), optional Bewegungen und Bestandswert zum Stichtag. */
class InventoryGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.inventory.GET';
    }

    public function getDescription(): string
    {
        return 'Liest das Lager des Teams (periodisch: Bestand entsteht aus Wareneingang + Inventur). '
            . 'Liefert den Bestand je Grundprodukt und Lagerort mit Menge (kg/l/Stk) und Wert zum aktuellen EK. '
            . 'Optional: bewegungen=true (letzte Zu-/Abgänge, Filter quelle=wareneingang|inventur|…) und '
            . 'stichtag=YYYY-MM-DD (Bestandswert aus der jüngsten gebuchten Inventur je Lagerort). Filter: stellplatz (bin_id | "ohne"), '
            . 'zustand (frisch|TK|trocken|konserviert), warengruppe (Code 01–15), lieferant (supplier_id), ohne_preis, '
            . 'ladenhueter (seit 90 Tagen keine Bewegung).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'location_id' => ['type' => 'integer', 'description' => 'Nur dieser Lagerort.'],
                'suche' => ['type' => 'string', 'description' => 'Namensfilter (Teilstring).'],
                'gp_id' => ['type' => 'integer', 'description' => 'Nur dieses Grundprodukt.'],
                'stellplatz' => ['type' => 'string'],
                'zustand' => ['type' => 'string'],
                'warengruppe' => ['type' => 'string'],
                'lieferant' => ['type' => 'integer'],
                'ohne_preis' => ['type' => 'boolean'],
                'ladenhueter' => ['type' => 'boolean'],
                'bewegungen' => ['type' => 'boolean', 'description' => 'Letzte Lagerbewegungen mitliefern.'],
                'quelle' => ['type' => 'string', 'description' => 'Filter für Bewegungen nach Quelle.'],
                'stichtag' => ['type' => 'string', 'description' => 'Bestandswert zu diesem Datum (YYYY-MM-DD).'],
                'limit' => ['type' => 'integer', 'description' => 'Max. Bestandszeilen (Default 200).'],
            ],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $svc = app(InventurService::class);
        $rows = array_values(array_filter($svc->filterBestand(
            $svc->bestand($team, isset($arguments['location_id']) ? (int) $arguments['location_id'] : null),
            array_intersect_key($arguments, array_flip(['suche', 'stellplatz', 'zustand', 'warengruppe', 'lieferant', 'ohne_preis', 'ladenhueter'])),
        ), fn ($r) => empty($arguments['gp_id']) || $r['gp_id'] === (int) $arguments['gp_id']));
        $limit = max(1, min(1000, (int) ($arguments['limit'] ?? 200)));
        $out = [
            'bestand' => array_slice($rows, 0, $limit),
            'zeilen_gesamt' => count($rows),
            'wert_gesamt' => round(array_sum(array_map(fn ($r) => (float) ($r['wert'] ?? 0), $rows)), 2),
            'ohne_preis' => count(array_filter($rows, fn ($r) => $r['wert'] === null)),
        ];
        if (! empty($arguments['bewegungen'])) {
            $out['bewegungen'] = $svc->bewegungen($team, $arguments['quelle'] ?? null, 100)->map(fn ($m) => [
                'id' => $m->id, 'gp_id' => $m->gp_id, 'name' => $m->gp?->name, 'richtung' => $m->direction,
                'menge' => $svc->anzeigeMenge((float) $m->qty_base, (string) $m->base_unit),
                'einheit' => $svc->anzeigeEinheit((string) $m->base_unit),
                'quelle' => $m->source, 'datum' => $m->moved_at?->toDateTimeString(),
                'grund' => $m->reason, 'wert_eur' => $m->value_eur !== null ? (float) $m->value_eur : null,
                'lagerort' => $m->location?->name, 'storno_von' => $m->storno_of_id,
            ])->values()->all();
        }
        if (! empty($arguments['stichtag'])) {
            $out['bestandswert'] = $svc->bestandswert($team, (string) $arguments['stichtag']);
        }

        return ToolResult::success($out);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'lager', 'inventory', 'bestand'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => [],
            'related_tools' => ['foodalchemist.inventory_counts.GET', 'foodalchemist.gp_einkauf.GET'],
            'examples' => ['Wie viel Mehl liegt am Lager?', 'Wie hoch war der Lagerwert am 30.09.?'],
        ];
    }
}
