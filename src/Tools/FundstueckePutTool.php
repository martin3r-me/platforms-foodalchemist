<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\TrendService;

/** Spec 79 · Inspirationen kuratieren: einem Trend zuordnen, einen Trend daraus machen, lösen oder zusammenführen. */
class FundstueckePutTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.fundstuecke.PUT';
    }

    public function getDescription(): string
    {
        return 'Kuratiert Inspirationen der Pinnwand (je ein Thema mit seinen Quellen). inspiration_ids = eine oder mehrere '
            .'Inspirationen (fundstueck_ids = einzelne Quellen, nehmen ihre Inspiration mit). Genau eine Aktion: trend_id (dem '
            .'Trend zuordnen, alle Quellen werden Belege), trend_anlegen {name, definition, typ, ebene, kategorie, …} (neuen Trend '
            .'daraus machen, Status gesichtet), loesen=true (zurück in die Pinnwand) oder zusammenfuehren_in = Ziel-Inspiration '
            .'(alle Quellen wandern dorthin, die anderen Karten verschwinden).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'inspiration_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
                'fundstueck_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Einzelne Quellen; zählt als deren Inspiration.'],
                'trend_id' => ['type' => 'integer'],
                'trend_anlegen' => ['type' => 'object', 'description' => 'Felder wie foodalchemist.trends.POST (name Pflicht).'],
                'loesen' => ['type' => 'boolean'],
                'zusammenfuehren_in' => ['type' => 'integer', 'description' => 'Ziel-Inspiration für das Zusammenführen.'],
            ],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $svc = app(TrendService::class);
        $ids = $svc->inspirationIdsAus($team, $arguments);
        $aktionen = (int) ! empty($arguments['trend_id']) + (int) ! empty($arguments['trend_anlegen'])
            + (int) ! empty($arguments['loesen']) + (int) ! empty($arguments['zusammenfuehren_in']);
        if ($ids === [] || $aktionen !== 1) {
            return ToolResult::error('inspiration_ids (oder fundstueck_ids) und genau eine Aktion (trend_id, trend_anlegen, loesen oder zusammenfuehren_in) angeben.', 'VALIDATION_ERROR');
        }
        $uid = $context->user?->id;
        try {
            if (! empty($arguments['trend_anlegen'])) {
                $trend = $svc->anlegen($team, (array) $arguments['trend_anlegen'] + ['inspiration_ids' => $ids], $uid);

                return ToolResult::success($svc->alsArray($svc->detail($team, $trend->id), true));
            }
            if (! empty($arguments['zusammenfuehren_in'])) {
                $ziel = $svc->inspirationenZusammenfuehren($team, (int) $arguments['zusammenfuehren_in'], $ids, $uid);

                return ToolResult::success(['inspiration_id' => $ziel->id, 'titel' => $ziel->titel,
                    'quellen' => $ziel->quellen()->count(), 'schlagworte' => $ziel->schlagworte ?? []]);
            }
            foreach ($ids as $id) {
                ! empty($arguments['loesen'])
                    ? $svc->inspirationLoesen($team, $id, $uid)
                    : $svc->inspirationZuordnen($team, $id, (int) $arguments['trend_id'], $uid);
            }
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return ToolResult::error('Inspiration oder Trend nicht gefunden (oder gehört einem anderen Team).', 'NOT_FOUND');
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }
        if (! empty($arguments['loesen'])) {
            return ToolResult::success(['geloest' => $ids]);
        }

        return ToolResult::success($svc->alsArray($svc->detail($team, (int) $arguments['trend_id']), true));
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action', 'tags' => ['foodalchemist', 'trendradar', 'inspiration', 'fundstueck', 'write'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db', 'side_effects' => ['updates', 'creates', 'deletes'],
            'related_tools' => ['foodalchemist.fundstuecke.GET', 'foodalchemist.trends.GET'],
            'examples' => ['Führ die drei Kristallbrot-Inspirationen zusammen.', 'Mach aus Inspiration 4 einen Hype „Kristallbrot“.'],
        ];
    }
}
