<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\LagerBewegungService;

/** Spec 67 · Hand-Buchung stornieren (Gegenbuchung, Original bleibt als Beleg). */
class InventoryMovementsStornoTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.inventory_movements.STORNO';
    }

    public function getDescription(): string
    {
        return 'Storniert eine Hand-Buchung (Zugang, Abgang, Umlagerung — bei Umlagerung beide Hälften) per Gegenbuchung. '
            . 'Wareneingang und Inventur werden an ihrer Quelle korrigiert, nicht hier.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => ['movement_id' => ['type' => 'integer']], 'required' => ['movement_id']];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        try {
            $ms = app(LagerBewegungService::class)->stornieren($team, (int) ($arguments['movement_id'] ?? 0), $context->user?->id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return ToolResult::error('Bewegung nicht gefunden.', 'NOT_FOUND');
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['storniert' => (int) $arguments['movement_id'], 'gegenbuchungen' => array_map(fn ($m) => $m->id, $ms)]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'lager', 'bewegung', 'write'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['creates', 'updates'],
            'related_tools' => ['foodalchemist.inventory_movements.POST'],
            'examples' => ['Storniere die Verderb-Buchung von gestern.'],
        ];
    }
}
