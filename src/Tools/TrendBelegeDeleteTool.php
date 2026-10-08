<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\TrendService;

/** Spec 79 · Beleg samt Datei entfernen; die Konfidenz des Trends wird neu berechnet. */
class TrendBelegeDeleteTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.trend_belege.DELETE';
    }

    public function getDescription(): string
    {
        return 'Entfernt einen Beleg oder ein Fundstück der Pinnwand (samt Datei) im eigenen Team. Fundstücke nur, wer im hochladenden Team kuratiert; eigene Trend-Belege darf jeder zurücknehmen. Erfordert confirm=true.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => ['beleg_id' => ['type' => 'integer'], 'confirm' => ['type' => 'boolean']], 'required' => ['beleg_id', 'confirm']];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        if (($arguments['confirm'] ?? false) !== true) {
            return ToolResult::error('Entfernen braucht confirm=true.', 'CONFIRM_REQUIRED');
        }
        try {
            app(TrendService::class)->belegEntfernen($team, (int) $arguments['beleg_id'], $context->user?->id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return ToolResult::error('Beleg nicht gefunden oder gehört einem anderen Team.', 'NOT_FOUND');
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['entfernt' => (int) $arguments['beleg_id']]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action', 'tags' => ['foodalchemist', 'trendradar', 'beleg', 'delete'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'destructive', 'confirmation_required' => true,
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db', 'side_effects' => ['deletes'],
            'related_tools' => ['foodalchemist.trend_belege.POST'],
            'examples' => ['Entfern den falschen Beleg 31.'],
        ];
    }
}
