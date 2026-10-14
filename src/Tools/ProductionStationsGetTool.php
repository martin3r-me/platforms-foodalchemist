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
        return 'Listet sichtbare aktive Küchenposten (mit ist_sammelposten: Pass ohne eigenes Personal) und die Team-Vorgabe "Posten für Gerichte" (gerichte_station_id). '
            .'Die id kann über recipes.PUT als default_station_id am Rezept gesetzt werden; Sammelposten-Flag über production_stations.PUT, die Vorgabe über team_settings.PUT.';
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
        return ToolResult::success([
            'posten' => FoodAlchemistProductionStation::visibleToTeam($team)
                ->where('is_inactive', false)->orderBy('sort_order')->orderBy('name')
                ->get(['id', 'name', 'slug', 'group_name', 'ist_sammelposten'])
                ->map(fn ($p) => ['id' => (int) $p->id, 'name' => $p->name, 'slug' => $p->slug, 'group_name' => $p->group_name, 'ist_sammelposten' => (bool) $p->ist_sammelposten])->all(),
            'gerichte_station_id' => app(\Platform\FoodAlchemist\Services\TeamSettingsService::class)->gerichtePostenId($team),   // Spec 83 B2: Team-Vorgabe (Pass)
        ]);
    }

    public function getMetadata(): array
    {
        return ['category' => 'query', 'read_only' => true, 'idempotent' => true,
            'risk_level' => 'safe', 'requires_auth' => true, 'requires_team' => true,
            'cost_class' => 'local_db', 'tags' => ['foodalchemist', 'posten', 'produktion'],
            'related_tools' => ['foodalchemist.recipes.PUT']];
    }
}
