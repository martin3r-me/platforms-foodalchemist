<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\EigenproduktionService;
use Platform\FoodAlchemist\Services\EtikettService;

/** Spec 69 · Eigenproduktion einlagern (neue Charge) — liefert Charge + Etikett-Link. */
class EigenproduktionPostTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.eigenproduktion.POST';
    }

    public function getDescription(): string
    {
        return 'Lagert selbst Hergestelltes als Charge ein (z. B. eingefrorene Suppe). recipe_id, menge (Basisrezept in kg bzw. Stück, '
            . 'Gericht in Portionen), optional location_id (sonst Standardlager), lagerart (gekuehlt|tiefgekuehlt|trocken, sonst die '
            . 'übliche des Rezepts), produziert_am, eingefroren_am, verbrauchen_bis (sonst aus der Haltbarkeit am Rezept), notiz, '
            . 'production_order_line_id. Bewertet zum Rezept-EK. Gibt Charge und Etikett-Druck-Link zurück.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'recipe_id' => ['type' => 'integer'], 'menge' => ['type' => ['number', 'string']], 'location_id' => ['type' => 'integer'],
            'lagerart' => ['type' => 'string', 'enum' => ['gekuehlt', 'tiefgekuehlt', 'trocken']], 'produziert_am' => ['type' => 'string'],
            'eingefroren_am' => ['type' => 'string'], 'verbrauchen_bis' => ['type' => 'string'], 'notiz' => ['type' => 'string'],
            'production_order_line_id' => ['type' => 'integer'],
        ], 'required' => ['recipe_id', 'menge']];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        try {
            $b = app(EigenproduktionService::class)->einlagern($team, $arguments, $context->user?->id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return ToolResult::error('Rezept oder Lagerort nicht gefunden.', 'NOT_FOUND');
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success([
            'batch_id' => $b->id, 'charge' => $b->charge, 'verbrauchen_bis' => $b->best_before?->toDateString(), 'lagerart' => $b->storage_type,
            'etikett_url' => app(EtikettService::class)->druckUrl('charge', (int) $b->id),
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action', 'tags' => ['foodalchemist', 'lager', 'eigenproduktion', 'write'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db', 'side_effects' => ['creates', 'updates'],
            'related_tools' => ['foodalchemist.inventory_batches.GET', 'foodalchemist.eigenproduktion.ENTNAHME'],
            'examples' => ['Lager 8 l Kürbissuppe eingefroren ein.'],
        ];
    }
}
