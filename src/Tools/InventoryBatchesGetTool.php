<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\EigenproduktionService;

/** Spec 69 · Offene Chargen der Eigenproduktion lesen (älteste Haltbarkeit zuerst), optional nur bald ablaufende. */
class InventoryBatchesGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.inventory_batches.GET';
    }

    public function getDescription(): string
    {
        return 'Offene Chargen der Eigenproduktion (selbst Hergestelltes im Lager: Basisrezepte in kg/Stück, Gerichte in '
            . 'Portionen): Charge, Rezept, Lagerort, Lagerart, Rest, hergestellt, eingefroren, verbrauchen bis, Tage bis '
            . 'Ablauf, Wert. Filter: recipe_id, location_id, ablaufend_tage (z. B. 3 = alles, was in ≤ 3 Tagen abläuft).';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'recipe_id' => ['type' => 'integer'], 'location_id' => ['type' => 'integer'], 'ablaufend_tage' => ['type' => 'integer'],
        ]];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $svc = app(EigenproduktionService::class);
        $chargen = $svc->offeneChargen($team, isset($arguments['recipe_id']) ? (int) $arguments['recipe_id'] : null, isset($arguments['location_id']) ? (int) $arguments['location_id'] : null);
        if (isset($arguments['ablaufend_tage'])) {
            $t = (int) $arguments['ablaufend_tage'];
            $chargen = $chargen->filter(fn ($b) => $b->best_before !== null && $b->tageBisAblauf() <= $t)->values();
        }

        return ToolResult::success(['chargen' => $chargen->map(fn ($b) => [
            'batch_id' => $b->id, 'charge' => $b->charge, 'recipe_id' => $b->recipe_id, 'rezept' => $b->recipe?->name, 'lagerort' => $b->location?->name,
            'lagerart' => $b->storage_type, 'rest' => $svc->anzeigeMenge((float) $b->qty_rest, (string) $b->base_unit), 'einheit' => $svc->anzeigeEinheit((string) $b->base_unit),
            'hergestellt' => $b->produced_at?->toDateString(), 'eingefroren' => $b->frozen_at?->toDateString(), 'verbrauchen_bis' => $b->best_before?->toDateString(),
            'tage_bis_ablauf' => $b->tageBisAblauf(), 'wert_eur' => $b->price_per_base !== null ? round((float) $b->qty_rest * (float) $b->price_per_base, 2) : null,
        ])->values()->all()]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query', 'tags' => ['foodalchemist', 'lager', 'eigenproduktion', 'charge'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db', 'side_effects' => [],
            'related_tools' => ['foodalchemist.eigenproduktion.POST', 'foodalchemist.eigenproduktion.ENTNAHME', 'foodalchemist.labels.POST'],
            'examples' => ['Was liegt an Eigenproduktion im TK?', 'Welche Chargen laufen in den nächsten 3 Tagen ab?'],
        ];
    }
}
