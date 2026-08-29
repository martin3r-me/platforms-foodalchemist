<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Jobs\TrendRefreshJob;

/**
 * MCP-Steuerbarkeit · D12 (Trendradar): einen Trend-Refresh anstoßen (async Job, externe Quellen/Kosten).
 * Confirm=true Pflicht.
 */
class TrendradarImportTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.trendradar.IMPORT';
    }

    public function getDescription(): string
    {
        return 'Stößt einen Trendradar-Refresh an (async Job zieht externe Trend-Quellen). Kostet externe Calls — erfordert confirm=true.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => ['confirm' => ['type' => 'boolean', 'description' => 'Muss true sein (externe Quellen/Kosten).']], 'required' => ['confirm']];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        if (($arguments['confirm'] ?? false) !== true) {
            return ToolResult::error('Trend-Refresh erfordert confirm=true (externe Quellen/Kosten).', 'CONFIRM_REQUIRED');
        }

        TrendRefreshJob::dispatch();

        return ToolResult::success(['dispatched' => true]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'trendradar', 'import', 'async'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'confirmation_required' => true,
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'external',
            'side_effects' => ['dispatches_job'],
            'related_tools' => ['foodalchemist.signal_trend.GET'],
            'examples' => ['Stoße einen Trendradar-Refresh an (confirm=true).'],
        ];
    }
}
