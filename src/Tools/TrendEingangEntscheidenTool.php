<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\TrendEingangService;

/** Spec 93 A · Eintrag im Eingang entscheiden: verwerfen, wiederherstellen, Thema ändern oder als Inspiration auf die Pinnwand. */
class TrendEingangEntscheidenTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.trend_eingang.ENTSCHEIDEN';
    }

    public function getDescription(): string
    {
        return 'Entscheidet Einträge im Trend-Eingang (Kuratieren, nur eigenes Team). aktion: verwerfen (wert = Begründung, optional) | '
            .'wiederherstellen | thema (wert = neues Thema) | pinnwand (als Inspiration auf die Pinnwand legen — die einzige Brücke vom Eingang '
            .'zur Pinnwand). ids = ein oder mehrere Einträge.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
            'aktion' => ['type' => 'string', 'enum' => TrendEingangService::AKTIONEN],
            'wert' => ['type' => 'string'],
        ], 'required' => ['ids', 'aktion']];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $ids = array_values(array_unique(array_map('intval', (array) ($arguments['ids'] ?? []))));
        if ($ids === []) {
            return ToolResult::error('ids fehlt.', 'VALIDATION_ERROR');
        }
        $svc = app(TrendEingangService::class);
        $out = [];
        foreach ($ids as $id) {
            try {
                $out[] = $svc->alsArray($svc->entscheiden($team, $id, (string) ($arguments['aktion'] ?? ''), $arguments['wert'] ?? null, $context->user?->id), $team);
            } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
                return ToolResult::error('Eintrag '.$id.' nicht gefunden oder gehört einem anderen Team.', 'NOT_FOUND');
            } catch (\RuntimeException $e) {
                return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
            }
        }

        return ToolResult::success(['anzahl' => count($out), 'eintraege' => $out]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action', 'tags' => ['foodalchemist', 'trendradar', 'eingang', 'write'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db', 'side_effects' => ['updates', 'creates'],
            'related_tools' => ['foodalchemist.trend_eingang.GET', 'foodalchemist.fundstuecke.GET'],
            'examples' => ['Verwirf die Einträge 12 und 13, das sind Personalien.', 'Leg Eintrag 20 als Inspiration auf die Pinnwand.'],
        ];
    }
}
