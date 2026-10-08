<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\TrendService;

/** Spec 79 · Fundstück einem Trend zuordnen, aus Fundstücken einen Trend machen oder die Zuordnung lösen. */
class FundstueckePutTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.fundstuecke.PUT';
    }

    public function getDescription(): string
    {
        return 'Kuratiert Fundstücke der Inspirations-Pinnwand. Genau eine Aktion: trend_id (dem Trend zuordnen, wird Beleg), '
            .'trend_anlegen {name, definition, typ, ebene, kategorie, …} (neuen Trend aus diesen Fundstücken machen, Status '
            .'gesichtet) oder loesen=true (zurück in die Pinnwand). fundstueck_ids = ein oder mehrere Fundstücke.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'fundstueck_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
                'trend_id' => ['type' => 'integer'],
                'trend_anlegen' => ['type' => 'object', 'description' => 'Felder wie foodalchemist.trends.POST (name Pflicht).'],
                'loesen' => ['type' => 'boolean'],
            ],
            'required' => ['fundstueck_ids'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $ids = array_values(array_map('intval', (array) ($arguments['fundstueck_ids'] ?? [])));
        $aktionen = (int) ! empty($arguments['trend_id']) + (int) ! empty($arguments['trend_anlegen']) + (int) ! empty($arguments['loesen']);
        if ($ids === [] || $aktionen !== 1) {
            return ToolResult::error('fundstueck_ids und genau eine Aktion (trend_id, trend_anlegen oder loesen) angeben.', 'VALIDATION_ERROR');
        }
        $svc = app(TrendService::class);
        $uid = $context->user?->id;
        try {
            if (! empty($arguments['trend_anlegen'])) {
                $trend = $svc->anlegen($team, (array) $arguments['trend_anlegen'] + ['fundstueck_ids' => $ids], $uid);

                return ToolResult::success($svc->alsArray($svc->detail($team, $trend->id), true));
            }
            foreach ($ids as $id) {
                ! empty($arguments['loesen'])
                    ? $svc->fundstueckLoesen($team, $id, $uid)
                    : $svc->fundstueckZuordnen($team, $id, (int) $arguments['trend_id'], $uid);
            }
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return ToolResult::error('Fundstück oder Trend nicht gefunden (oder gehört einem anderen Team).', 'NOT_FOUND');
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
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db', 'side_effects' => ['updates', 'creates'],
            'related_tools' => ['foodalchemist.fundstuecke.GET', 'foodalchemist.trends.GET'],
            'examples' => ['Häng Fundstück 12 und 15 an den Trend Dubai-Schokolade.', 'Mach aus den drei Loaded-Potato-Fundstücken einen Hype.'],
        ];
    }
}
