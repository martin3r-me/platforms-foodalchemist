<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\InventurService;

/** Spec 66 §6 · Inventur buchen: Bestand := gezählt, Differenzen als Bewegungen, Wert eingefroren. */
class InventoryCountsBookTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.inventory_counts.BOOK';
    }

    public function getDescription(): string
    {
        return 'Bucht eine offene Inventur: für jede GEZÄHLTE Zeile wird der Lagerbestand auf die gezählte Menge gesetzt, '
            . 'die Differenz als Lagerbewegung (Quelle inventur) gebucht und der Bestandswert festgeschrieben. '
            . 'Nicht gezählte Zeilen bleiben unberührt — außer mit nicht_gezaehlt_null=true, dann zählen sie als 0. '
            . 'Danach ist die Inventur gesperrt — nicht umkehrbar.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'count_id' => ['type' => 'integer', 'description' => 'Inventur-Id.'],
                'nicht_gezaehlt_null' => ['type' => 'boolean', 'description' => 'Nicht gezählte Positionen als 0 buchen.'],
            ],
            'required' => ['count_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        try {
            $c = app(InventurService::class)->buchen($team, (int) ($arguments['count_id'] ?? 0), $context->user?->id, ! empty($arguments['nicht_gezaehlt_null']));
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return ToolResult::error('Inventur nicht gefunden.', 'NOT_FOUND');
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }
        $c->load('location');

        return ToolResult::success(InventoryCountsGetTool::kopf($c));
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'lager', 'inventur', 'write'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.inventory_counts.GET', 'foodalchemist.inventory.GET'],
            'examples' => ['Buche die Inventur 3.'],
        ];
    }
}
