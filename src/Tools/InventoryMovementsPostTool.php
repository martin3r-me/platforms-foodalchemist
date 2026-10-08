<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\LagerBewegungService;

/** Spec 67 · Lagerbewegung von Hand buchen: Zugang, Abgang mit Grund, Umlagerung. */
class InventoryMovementsPostTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.inventory_movements.POST';
    }

    public function getDescription(): string
    {
        return 'Bucht eine Lagerbewegung von Hand und schreibt den Bestand sofort fort. art: zugang (grund: ohne_bestellung | '
            . 'ruecknahme | marktkauf | korrektur; optional preis in € je kg/l/Stk), abgang (grund: verderb | bruch | schwund | '
            . 'personal | probe | korrektur) oder umlagerung (location_id → ziel_location_id). Menge: menge in kg/l/Stk ODER '
            . 'kartons/einheiten/lose, wenn der Lead-Artikel ein Gebinde hat. Bewertung wird eingefroren (value_eur).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'art' => ['type' => 'string', 'enum' => ['zugang', 'abgang', 'umlagerung']],
                'gp_id' => ['type' => 'integer'],
                'location_id' => ['type' => 'integer', 'description' => 'Lagerort (bei Umlagerung: Quelle).'],
                'ziel_location_id' => ['type' => 'integer', 'description' => 'Nur Umlagerung: Ziel-Lagerort.'],
                'menge' => ['type' => ['number', 'string'], 'description' => 'kg / l / Stk'],
                'kartons' => ['type' => ['number', 'string']],
                'einheiten' => ['type' => ['number', 'string']],
                'lose' => ['type' => ['number', 'string'], 'description' => 'kg / l / Stk zusätzlich zu Gebinden'],
                'grund' => ['type' => 'string'],
                'preis' => ['type' => ['number', 'string'], 'description' => 'Nur Zugang: € je kg/l/Stk; leer = aktueller EK.'],
                'datum' => ['type' => 'string', 'description' => 'YYYY-MM-DD, Default heute.'],
                'notiz' => ['type' => 'string'],
            ],
            'required' => ['art', 'gp_id', 'location_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        try {
            $ms = app(LagerBewegungService::class)->buchen($team, $arguments, $context->user?->id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return ToolResult::error('Grundprodukt oder Lagerort nicht gefunden.', 'NOT_FOUND');
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['bewegungen' => array_map(fn ($m) => [
            'movement_id' => $m->id, 'location_id' => $m->inventory_location_id, 'richtung' => $m->direction, 'quelle' => $m->source,
            'grund' => $m->reason, 'menge_basis' => (float) $m->qty_base, 'einheit_basis' => $m->base_unit,
            'wert_eur' => $m->value_eur !== null ? (float) $m->value_eur : null,
        ], $ms)]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'lager', 'bewegung', 'write'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['creates', 'updates'],
            'related_tools' => ['foodalchemist.inventory_movements.STORNO', 'foodalchemist.inventory.GET'],
            'examples' => ['Buch 2 kg Lachs als Verderb aus dem Kühlhaus aus.', 'Lager 3 Kartons Sahne vom Hauptlager in die Küche um.'],
        ];
    }
}
