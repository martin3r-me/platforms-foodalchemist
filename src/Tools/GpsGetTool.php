<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\GpAggregateService;
use Platform\FoodAlchemist\Services\GpService;

/** M8-01: GP-Detail inkl. GL-01-Allergen-Aggregat — Tool → Services. */
class GpsGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.gps.GET';
    }

    public function getDescription(): string
    {
        return 'Liefert ein Grundprodukt im Detail: Stammdaten, Status, Lead-LA-Referenz '
            . 'und das ALL-MAXIMAL-Allergen-Aggregat (GL-01).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['id' => ['type' => 'integer', 'description' => 'GP-Id']],
            'required' => ['id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $gp = app(GpService::class)->find((int) $arguments['id'], $team);
        if ($gp === null) {
            return ToolResult::error('GP nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }

        return ToolResult::success([
            'id' => $gp->id, 'name' => $gp->name, 'status' => $gp->status,
            'main_ingredient_slug' => $gp->main_ingredient_slug, 'condition' => $gp->condition, 'bio' => $gp->bio,
            'lead_la_supplier_item_id' => $gp->lead_la_supplier_item_id,
            'allergene' => app(GpAggregateService::class)->allergene($gp),
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'read_only' => true,
            'idempotent' => true,
            'risk_level' => 'safe',
            'requires_auth' => true,
            'requires_team' => true,
            'cost_class' => 'local_db',
            'tags' => ['foodalchemist', 'gp', 'grundprodukt', 'detail', 'allergene', 'price'],
            'examples' => ['Zeig mir Details zu GP 123', 'Welche Allergene hat das Zanderfilet-GP?'],
        ];
    }
}
