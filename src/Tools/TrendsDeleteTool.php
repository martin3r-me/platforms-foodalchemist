<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\TrendService;

/** Spec 79 · Trend samt Belegen löschen. Zum Aussortieren lieber status=verworfen per PUT. */
class TrendsDeleteTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.trends.DELETE';
    }

    public function getDescription(): string
    {
        return 'Löscht einen Trend des eigenen Teams mit allen Belegen und Dateien. Erfordert confirm=true. '
            .'Wer nur aussortieren will: foodalchemist.trends.PUT mit status=verworfen (bleibt nachvollziehbar).';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'confirm' => ['type' => 'boolean']], 'required' => ['id', 'confirm']];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        if (($arguments['confirm'] ?? false) !== true) {
            return ToolResult::error('Löschen braucht confirm=true.', 'CONFIRM_REQUIRED');
        }
        try {
            app(TrendService::class)->loeschen($team, (int) $arguments['id'], $context->user?->id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return ToolResult::error('Trend nicht gefunden oder gehört einem anderen Team.', 'NOT_FOUND');
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['geloescht' => (int) $arguments['id']]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action', 'tags' => ['foodalchemist', 'trendradar', 'trends', 'delete'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'destructive', 'confirmation_required' => true,
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db', 'side_effects' => ['deletes'],
            'related_tools' => ['foodalchemist.trends.PUT'],
            'examples' => ['Lösch den doppelt angelegten Trend 14.'],
        ];
    }
}
