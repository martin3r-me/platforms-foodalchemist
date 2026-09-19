<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistProductionStation;

class ProductionStationsGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.production_stations.GET';
    }

    public function getDescription(): string
    {
        return 'Listet sichtbare aktive Küchenposten. Die id kann über recipes.PUT als default_station_id am Basisrezept gesetzt werden.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        return ToolResult::success(['posten' => FoodAlchemistProductionStation::visibleToTeam($team)
            ->where('is_inactive', false)->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'name', 'slug', 'group_name'])->toArray()]);
    }

    public function getMetadata(): array
    {
        return ['category' => 'query', 'read_only' => true, 'idempotent' => true,
            'risk_level' => 'safe', 'requires_auth' => true, 'requires_team' => true,
            'cost_class' => 'local_db', 'tags' => ['foodalchemist', 'posten', 'produktion'],
            'related_tools' => ['foodalchemist.recipes.PUT']];
    }
}
