<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\OrderService;

/** MCP-Steuerbarkeit · D11: den Lieferantenartikel einer Bestellzeile tauschen (Alternative). */
class OrdersSwitchArticleTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.orders.SWITCH_ARTICLE';
    }

    public function getDescription(): string
    {
        return 'Tauscht den Lieferantenartikel einer Bestellzeile gegen eine Alternative (new_la_id). '
            . 'Kandidaten liefert die Zeilen-Alternativen-Ansicht.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'line_id' => ['type' => 'integer', 'description' => 'Bestellzeilen-Id.'],
                'new_la_id' => ['type' => 'integer', 'description' => 'Neuer Lieferantenartikel (LA).'],
            ],
            'required' => ['line_id', 'new_la_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $lineId = (int) ($arguments['line_id'] ?? 0);
        if (($guard = $this->guardOrderLineOwned($team, $lineId)) !== null) {
            return $guard;
        }

        try {
            $res = app(OrderService::class)->switchLineArticle($team, $lineId, (int) ($arguments['new_la_id'] ?? 0), $context->user->id ?? null);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['line_id' => $lineId, 'new_la_id' => (int) ($arguments['new_la_id'] ?? 0), 'result' => $res]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'order', 'line', 'write'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.orders.REMOVE_LINE'],
            'examples' => ['Tausche bei Zeile 30 den Artikel auf LA 77.'],
        ];
    }
}
