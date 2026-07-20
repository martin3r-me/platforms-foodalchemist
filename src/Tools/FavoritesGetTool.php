<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\FavoriteGpService;

/**
 * 06·H2 (MCP-Lockstep, read) — Favoriten (Lieblings-GPs): aktuelle gepinnte Liste
 * + Auto-Score-Rangliste aller approved GPs (Nutzung × Lead-LA × Priorität).
 */
class FavoritesGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.favorites.GET';
    }

    public function getDescription(): string
    {
        return 'Liefert die kuratierte Favoriten-Liste (Lieblings-GPs, Haus-Standard) sowie die '
            . 'Auto-Score-Rangliste aller approved GPs (Nutzungshäufigkeit × Lead-LA-Vollständigkeit '
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

        $svc = app(FavoriteGpService::class);
        $limit = min(300, max(1, (int) ($arguments['limit'] ?? 50)));

        if (($arguments['mode'] ?? 'current') === 'suggest') {
            return ToolResult::success(['mode' => 'suggest', 'items' => $svc->suggest($team, $limit)->all()]);
        }

        return ToolResult::success([
            'mode' => 'current',
            'items' => $svc->current($team)->map(fn ($g) => [
                'gp_id' => $g->id, 'name' => $g->name, 'favorite_rank' => $g->favorite_rank,
            ])->all(),
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'favoriten', 'lieblings-gp', 'kuratierung', 'gp'],
            'read_only' => true,
            'idempotent' => true,
            'risk_level' => 'safe',
            'requires_auth' => true,
            'requires_team' => true,
            'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.favorites.PUT', 'foodalchemist.gps.SEARCH'],
            'examples' => ['Zeig mir die Favoriten (Lieblings-GPs)', 'Welche GPs sollten wir als Favorit pinnen?'],
        ];
    }
}
