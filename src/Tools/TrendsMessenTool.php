<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\TrendSignalService;

/** Spec 79 · Google Trends für einen Trend abfragen (DataForSEO, kostenpflichtig, mit Monatsbudget). */
class TrendsMessenTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.trends.MESSEN';
    }

    public function getDescription(): string
    {
        return 'Fragt Google Trends (Deutschland, 12 Monate) für die Suchbegriffe eines Trends über DataForSEO ab und speichert '
            .'Kurve, Richtung (steigend/stabil/fallend) und Hype-Indiz als Messung plus Beleg „Google Trends". Kostet je '
            .'Suchbegriff ca. 0,009 $ und läuft gegen das Monatsbudget des Teams. Erfordert confirm=true.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => ['trend_id' => ['type' => 'integer'], 'confirm' => ['type' => 'boolean']], 'required' => ['trend_id', 'confirm']];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        if (($arguments['confirm'] ?? false) !== true) {
            return ToolResult::error('Die Abfrage kostet Geld — confirm=true setzen.', 'CONFIRM_REQUIRED');
        }
        $svc = app(TrendSignalService::class);
        try {
            $signale = $svc->messen($team, (int) $arguments['trend_id'], $context->user);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return ToolResult::error('Trend nicht gefunden oder gehört einem anderen Team.', 'NOT_FOUND');
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success([
            'messungen' => $signale->map(fn ($s) => ['suchbegriff' => $s->suchbegriff, 'richtung' => $s->richtung,
                'veraenderung' => $s->veraenderung, 'durchschnitt' => $s->durchschnitt, 'spitze' => $s->spitze,
                'spitze_ohne_sockel' => (bool) $s->spitze_ohne_sockel])->all(),
            'kosten_usd' => round($signale->sum('kosten_usd'), 4),
            'verbraucht_diesen_monat_usd' => round($svc->verbrauchDiesenMonat($team), 4),
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action', 'tags' => ['foodalchemist', 'trendradar', 'google-trends', 'dataforseo'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write', 'confirmation_required' => true,
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'external_api_paid', 'side_effects' => ['creates', 'external_cost'],
            'related_tools' => ['foodalchemist.trends.GET', 'foodalchemist.team_settings.PUT'],
            'examples' => ['Miss Google Trends für Trend 4.'],
        ];
    }
}
