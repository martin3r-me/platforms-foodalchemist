<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\InventurService;

/** Spec 66 §6 · Inventuren lesen: Liste oder eine Inventur mit Zählliste. */
class InventoryCountsGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.inventory_counts.GET';
    }

    public function getDescription(): string
    {
        return 'Ohne count_id: Liste der Inventuren des Teams (Stichtag, Lagerort, Status offen|gebucht, Wert). '
            . 'Mit count_id: die Inventur mit allen Zeilen (line_id, Grundprodukt, Einheit kg/l/Stk, Soll, gezählt, Preis je Einheit) '
            . 'und Summen, sortiert nach Laufweg (Stellplatz). Je Zeile auch Stellplatz und Gebinde (1 Karton = n Einheiten, '
            . '1 Einheit = x kg/l/Stk), wenn bekannt. Filter: stellplatz (bin_id | "ohne"), status (offen | gezaehlt | differenz). '
            . 'Mengen zum Zählen über foodalchemist.inventory_counts.PUT, Buchen über .BOOK.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'count_id' => ['type' => 'integer', 'description' => 'Inventur-Id (für die Detailansicht).'],
                'stellplatz' => ['type' => 'string', 'description' => 'Nur Zeilen dieses Stellplatzes (bin_id) oder "ohne".'],
                'status' => ['type' => 'string', 'enum' => ['offen', 'gezaehlt', 'differenz'], 'description' => 'differenz = über 10 % vom Soll.'],
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
        if (empty($arguments['count_id'])) {
            return ToolResult::success(['inventuren' => $svc->liste($team)->map(fn ($c) => self::kopf($c))->values()->all()]);
        }
        try {
            $c = $svc->detail($team, (int) $arguments['count_id']);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return ToolResult::error('Inventur nicht gefunden.', 'NOT_FOUND');
        }

        return ToolResult::success(self::kopf($c) + [
            'summen' => $svc->summen($c),
            'zeilen' => $svc->filterZeilen($c->lines, array_intersect_key($arguments, array_flip(['stellplatz', 'status'])))
                ->sortBy(fn ($l) => [$l->bin?->sort_order ?? PHP_INT_MAX, $l->position])->values()->map(fn ($l) => [
                'line_id' => $l->id,
                'gp_id' => $l->gp_id,
                'name' => $l->gp?->name ?? $l->supplierItem?->designation,
                'einheit' => $svc->anzeigeEinheit((string) $l->base_unit),
                'soll' => $svc->anzeigeMenge((float) $l->qty_expected, (string) $l->base_unit),
                'gezaehlt' => $svc->anzeigeMenge($l->qty_counted !== null ? (float) $l->qty_counted : null, (string) $l->base_unit),
                'preis_je_einheit' => $l->price_per_base !== null
                    ? round((float) $l->price_per_base * (in_array($l->base_unit, ['g', 'ml'], true) ? 1000 : 1), 4) : null,
                'wert' => $l->wert(),
                'stellplatz' => $l->bin?->name,
                'gebinde' => $l->hatGebinde() ? [
                    'karton' => $l->pack_label, 'einheiten_je_karton' => $l->pack_units !== null ? (float) $l->pack_units : null,
                    'einheit' => $l->unit_label, 'inhalt_je_einheit' => $svc->anzeigeMenge((float) $l->unit_base, (string) $l->base_unit),
                ] : null,
                'gezaehlt_als' => $l->counted_packs !== null || $l->counted_units !== null
                    ? ['kartons' => $l->counted_packs !== null ? (float) $l->counted_packs : null, 'einheiten' => $l->counted_units !== null ? (float) $l->counted_units : null, 'lose' => $l->counted_loose !== null ? (float) $l->counted_loose : null]
                    : null,
            ])->values()->all(),
        ]);
    }

    /** @return array<string, mixed> */
    public static function kopf($c): array
    {
        return [
            'count_id' => $c->id,
            'stichtag' => $c->count_date?->toDateString(),
            'lagerort' => $c->location?->name,
            'location_id' => $c->inventory_location_id,
            'status' => $c->status,
            'wert' => $c->value_total !== null ? (float) $c->value_total : null,
            'gebucht_am' => $c->booked_at?->toDateTimeString(),
            'notiz' => $c->note,
            'nicht_gezaehlt_als_null' => (bool) $c->uncounted_zeroed,
        ];
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'lager', 'inventur'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => [],
            'related_tools' => ['foodalchemist.inventory_counts.POST', 'foodalchemist.inventory_counts.PUT', 'foodalchemist.inventory_counts.BOOK'],
            'examples' => ['Zeig mir die offene Inventur.', 'Welche Positionen sind in Inventur 3 noch nicht gezählt?'],
        ];
    }
}
