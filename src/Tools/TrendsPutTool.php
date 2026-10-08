<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\TrendService;
use Platform\FoodAlchemist\Support\TrendVokabular as V;

/** Spec 79 · Trend einordnen, bearbeiten, Status setzen. Nur Felder, die übergeben werden, ändern sich. */
class TrendsPutTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.trends.PUT';
    }

    public function getDescription(): string
    {
        return 'Ändert einen Trend des eigenen Teams: Einordnung (typ, ebene, kategorie, food_cluster, sicht), Texte, '
            .'Suchbegriffe, Hashtags, konfidenz_manuell (leer = Regel aus den Belegen) und status. status auf_radar/in_umsetzung '
            .'wird abgelehnt, solange Einordnung oder eine bestätigende Quelle fehlt — die Antwort nennt, was fehlt. '
            .'Leerer String löscht ein Feld.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer'],
                'name' => ['type' => 'string'],
                'definition' => ['type' => 'string'],
                'typ' => ['type' => 'string', 'enum' => array_merge(array_keys(V::TYPEN), [''])],
                'ebene' => ['type' => 'string', 'enum' => array_merge(array_keys(V::EBENEN), [''])],
                'kategorie' => ['type' => 'string', 'enum' => array_merge(array_keys(V::KATEGORIEN), [''])],
                'food_cluster' => ['type' => 'string', 'enum' => array_merge(array_keys(V::FOOD_CLUSTER), [''])],
                'sicht' => ['type' => 'string', 'enum' => array_merge(array_keys(V::SICHTEN), [''])],
                'status' => ['type' => 'string', 'enum' => array_keys(V::STATUS)],
                'konfidenz_manuell' => ['type' => 'string', 'enum' => array_merge(array_keys(V::KONFIDENZ), [''])],
                'suchbegriffe' => ['type' => 'array', 'items' => ['type' => 'string']],
                'hashtags' => ['type' => 'array', 'items' => ['type' => 'string']],
                'historische_einordnung' => ['type' => 'string'],
                'gartner_phase' => ['type' => 'string', 'enum' => array_merge(array_keys(V::GARTNER_PHASEN), [''])],
                'einordnung_quelle' => ['type' => 'string', 'enum' => ['manuell', 'ki']],
                'einordnung_begruendung' => ['type' => 'string'],
            ],
            'required' => ['id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $svc = app(TrendService::class);
        $id = (int) $arguments['id'];
        unset($arguments['id']);
        try {
            $trend = $svc->aendern($team, $id, $arguments, $context->user?->id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return ToolResult::error('Trend nicht gefunden oder gehört einem anderen Team.', 'NOT_FOUND');
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success($svc->alsArray($svc->detail($team, $trend->id), true));
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action', 'tags' => ['foodalchemist', 'trendradar', 'trends', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db', 'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.trends.GET', 'foodalchemist.trend_belege.POST'],
            'examples' => ['Ordne Trend 7 als Megatrend Food ein.', 'Setz Trend 12 aufs Radar.', 'Verwirf Trend 3.'],
        ];
    }
}
