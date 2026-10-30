<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Enums\ProductionOrderStatus;
use Platform\FoodAlchemist\Services\ProductionOrderService;
use Platform\FoodAlchemist\Services\ProduktionListeService;
use Platform\FoodAlchemist\Services\StandortService;
use Platform\FoodAlchemist\Support\Zeitraum;

/**
 * Spec 18 (read): Produktionsaufträge. Ohne order_id → Liste (optional nach
 * Status gefiltert); mit order_id → Detail inkl. Rezept-/Ansätze-Zeilen,
 * Ziele, Kennzahlen (Ansätze/Portionen/Arbeitszeit gesamt) und Warnungen.
 */
class ProductionOrdersGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.production_orders.GET';
    }

    public function getDescription(): string
    {
        return 'Produktionsaufträge des Teams. Ohne order_id: Liste wie der Produktions-Browser (geplante zuerst, dann nach '
            . 'Datum), gefiltert nach status, Produktionsdatum von/bis oder zeitraum, q (Name/Referenz); seitenweise (page, '
            . 'per_page 10–250, total). Je Auftrag Standort, Ziele, Ansätze, Portionen, Arbeitszeit, Posten und Einkaufs-Bedarf '
            . '(entwurf = noch nicht freigegeben, geaendert = Freigabe veraltet, freigegeben). Mit order_id: Detail mit Rezept-/Ansätze-Zeilen '
            . '(Ansätze, Portionen, Arbeitszeit, Zubereitung, Darreichung), Zielen und Kennzahlen sowie den '
            . 'Freigabestatus des Materialbedarfs (procurement_released_at/procurement_stale) und bereits '
            . 'im Bestellwesen verknüpften Bestellschienen. Read-only.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'order_id' => ['type' => 'integer'],
                'status' => ['type' => 'string', 'enum' => ['planned', 'in_progress', 'done', 'cancelled', '']],
                'q' => ['type' => 'string', 'description' => 'Suche in Name und Referenz.'],
                'von' => ['type' => 'string', 'description' => 'Produktionsdatum ab, YYYY-MM-DD.'],
                'bis' => ['type' => 'string', 'description' => 'Produktionsdatum bis, YYYY-MM-DD.'],
                'zeitraum' => ['type' => 'string', 'enum' => ['heute', 'woche', 'naechste', 'vergangen', ''], 'description' => 'Preset statt von/bis (vergangen = bis gestern).'],
                'page' => ['type' => 'integer', 'description' => 'Seite (ab 1).'],
                'per_page' => ['type' => 'integer', 'description' => 'Zeilen je Seite (Default 50, 10–250).'],
            ],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $svc = app(ProductionOrderService::class);

        if (! empty($arguments['order_id'])) {
            try {
                return ToolResult::success($svc->detail($team, (int) $arguments['order_id']));
            } catch (\Throwable $e) {
                return ToolResult::error('Produktionsauftrag nicht sichtbar/vorhanden.', 'NOT_FOUND');
            }
        }

        if ($fehler = $this->enumFehler($arguments)) {
            return $fehler;
        }
        [$von, $bis] = ($arguments['zeitraum'] ?? '') !== '' ? Zeitraum::grenzen((string) $arguments['zeitraum']) : [$arguments['von'] ?? null, $arguments['bis'] ?? null];
        $seite = $svc->paginateBrowser($team, [
            'status' => (string) ($arguments['status'] ?? ''),
            'von' => $von,
            'bis' => $bis,
            'suche' => (string) ($arguments['q'] ?? ''),
        ], (int) ($arguments['per_page'] ?? 50), max(1, (int) ($arguments['page'] ?? 1)));
        $kennzahlen = app(ProduktionListeService::class);
        $kennzahlen->zeilenLaden($seite->getCollection());
        $bedarf = $kennzahlen->indikatoren($seite->getCollection());
        $standorte = app(StandortService::class);
        $namen = $standorte->teamNamen([(int) $team->id, ...$standorte->unterTeamIds($team)]);
        $liste = $seite->getCollection()->map(fn ($o) => [
            'id' => (int) $o->id,
            'name' => $o->name,
            'production_date' => $o->production_date?->toDateString(),
            'status' => $o->status instanceof ProductionOrderStatus ? $o->status->value : (string) $o->status,
            'reference' => $o->reference,
            'team_id' => (int) $o->team_id,
            'standort' => $namen[(int) $o->team_id] ?? null,
            'bedarf' => $bedarf[(int) $o->id],
        ] + $kennzahlen->kennzahlen($o))->values()->all();

        return $this->erfolgMitWarnungen(['production_orders' => $liste, 'count' => count($liste), 'total' => $seite->total(),
            'page' => $seite->currentPage(), 'per_page' => $seite->perPage()], $arguments);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'produktion', 'production_order'],
            'read_only' => true,
            'idempotent' => true,
            'risk_level' => 'safe',
        ];
    }
}
