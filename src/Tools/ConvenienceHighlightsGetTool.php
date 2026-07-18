<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\ConvenienceHighlightService;

/**
 * 06·H2 (MCP-Lockstep, read) — Convenience-Highlights: aktuelle gepinnte Liste
 * + Auto-Score-Rangliste der Convenience-GPs (Nutzung × Lead-LA × Priorität).
 */
class ConvenienceHighlightsGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.convenience_highlights.GET';
    }

    public function getDescription(): string
    {
        return 'Liefert die kuratierte Convenience-Highlight-Liste (Haus-Standard) sowie die '
            . 'Auto-Score-Rangliste aller Convenience-GPs (Nutzungshäufigkeit × Lead-LA-Vollständigkeit '
            . '× Lieferanten-Priorität). Basis für die Kuratierung (pin/exclude via .PUT).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'mode' => ['type' => 'string', 'enum' => ['current', 'suggest'], 'default' => 'current', 'description' => 'current = gepinnte Liste, suggest = Score-Rangliste'],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 300, 'default' => 50],
            ],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }

        $svc = app(ConvenienceHighlightService::class);
        $limit = min(300, max(1, (int) ($arguments['limit'] ?? 50)));

        if (($arguments['mode'] ?? 'current') === 'suggest') {
            return ToolResult::success(['mode' => 'suggest', 'items' => $svc->suggest($team, $limit)->all()]);
        }

        return ToolResult::success([
            'mode' => 'current',
            'items' => $svc->current($team)->map(fn ($g) => [
                'gp_id' => $g->id, 'name' => $g->name, 'highlight_rank' => $g->highlight_rank,
            ])->all(),
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'convenience', 'highlights', 'kuratierung', 'gp'],
            'read_only' => true,
            'idempotent' => true,
            'risk_level' => 'safe',
            'requires_auth' => true,
            'requires_team' => true,
            'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.convenience_highlights.PUT', 'foodalchemist.gps.SEARCH'],
            'examples' => ['Zeig mir die Convenience-Highlights', 'Welche Convenience-GPs sollten wir pinnen?'],
        ];
    }
}
