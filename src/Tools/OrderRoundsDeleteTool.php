<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\OrderService;

/** Spec 73 · Bestellrunde löschen (nur ohne versendete Bestellung). */
class OrderRoundsDeleteTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.order_rounds.DELETE';
    }

    public function getDescription(): string
    {
        return 'Löscht eine Bestellrunde (round_id). Nur solange keine ihrer Bestellungen versendet ist. Ihre Beiträge verschwinden '
            . 'aus den Entwürfen; Entwürfe, die nur zu dieser Runde gehören, werden ganz gelöscht. Ihre Lager-Reservierung entfällt.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => ['round_id' => ['type' => 'integer']], 'required' => ['round_id']];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        try {
            $r = app(OrderService::class)->deleteRound($team, (int) ($arguments['round_id'] ?? 0));
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return ToolResult::error('Bestellrunde nicht gefunden.', 'NOT_FOUND');
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['geloeschte_bestellungen' => $r['geloescht'], 'aktualisierte_bestellungen' => $r['aktualisiert']]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action', 'tags' => ['foodalchemist', 'einkauf', 'bestellrunde', 'write'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'destructive',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db', 'side_effects' => ['deletes', 'updates'],
            'related_tools' => ['foodalchemist.orders.GET'],
            'examples' => ['Lösch die Bestellrunde von gestern, die ist doppelt.'],
        ];
    }
}
