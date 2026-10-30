<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\TrendQuellenService;

/** Spec 93 A · Quelle entfernen (weich gelöscht; Einträge im Eingang bleiben). */
class TrendQuellenDeleteTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.trend_quellen.DELETE';
    }

    public function getDescription(): string
    {
        return 'Entfernt eine Quelle des Trendradars (Kuratieren, nur eigenes Team, braucht confirm=true). Bereits eingegangene Artikel bleiben im Eingang. '
            .'Zum Pausieren lieber foodalchemist.trend_quellen.PUT mit aktiv=false oder rhythmus=aus.';
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
            app(TrendQuellenService::class)->loeschen($team, (int) $arguments['id'], $context->user?->id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return ToolResult::error('Quelle nicht gefunden oder gehört einem anderen Team.', 'NOT_FOUND');
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['geloescht' => (int) $arguments['id']]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action', 'tags' => ['foodalchemist', 'trendradar', 'quellen', 'delete'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'destructive', 'confirmation_required' => true,
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db', 'side_effects' => ['deletes'],
            'related_tools' => ['foodalchemist.trend_quellen.PUT'],
            'examples' => ['Lösch die doppelt angelegte Quelle 7.'],
        ];
    }
}
