<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\Trends\TrendAutomatikService;

/** Spec 93 C · Trend-Automatik jetzt laufen lassen (wie der tägliche Lauf; im Schatten-Modus bzw. bei Stufe „aus“ nur die Liste). */
class TrendAutomatikLaufTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.trend_automatik.LAUF';
    }

    public function getDescription(): string
    {
        return 'Führt die Trend-Automatik jetzt aus (Kuratieren): ordnet die automatischen Themen nach Regeln ein und legt je Stufe Trends an bzw. hängt '
            .'Artikel an. Im Schatten-Modus oder bei Stufe „aus“ wird nur gerechnet (geschrieben=false). Ruft keine KI.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => []];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        try {
            return ToolResult::success(app(TrendAutomatikService::class)->lauf($team, null, $context->user?->id));
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action', 'tags' => ['foodalchemist', 'trendradar', 'automatik', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db', 'side_effects' => ['creates', 'updates'],
            'related_tools' => ['foodalchemist.trend_automatik.GET', 'foodalchemist.trends.GET'],
            'examples' => ['Lass die Trend-Automatik jetzt laufen.'],
        ];
    }
}
