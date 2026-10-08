<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\EigenproduktionService;

/** Spec 69 · Eigenproduktion entnehmen — FIFO nach Haltbarkeit oder aus einer bestimmten Charge. */
class EigenproduktionEntnahmeTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.eigenproduktion.ENTNAHME';
    }

    public function getDescription(): string
    {
        return 'Entnimmt Eigenproduktion aus dem Lager: entweder batch_id (genau diese Charge) oder recipe_id (FIFO — älteste '
            . 'Haltbarkeit zuerst, optional location_id). menge in kg/Stück/Portionen. grund: verbrauch (Produktion), verderb, '
            . 'bruch, schwund, personal, probe, korrektur — alles außer verbrauch zählt im Controlling als Abgang mit Grund.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'batch_id' => ['type' => 'integer'], 'recipe_id' => ['type' => 'integer'], 'location_id' => ['type' => 'integer'],
            'menge' => ['type' => ['number', 'string']], 'grund' => ['type' => 'string'], 'notiz' => ['type' => 'string'],
        ], 'required' => ['menge']];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        try {
            $r = app(EigenproduktionService::class)->entnehmen($team, $arguments, $context->user?->id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return ToolResult::error('Rezept oder Charge nicht gefunden.', 'NOT_FOUND');
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['entnommen' => $r]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action', 'tags' => ['foodalchemist', 'lager', 'eigenproduktion', 'write'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db', 'side_effects' => ['updates', 'creates'],
            'related_tools' => ['foodalchemist.inventory_batches.GET'],
            'examples' => ['Nimm 2 l Kalbsfond aus dem TK für die Produktion.', 'Buch die abgelaufene Charge C261001-01 als Verderb aus.'],
        ];
    }
}
