<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Services\FavoriteGpService;

/**
 * 06·H2 (MCP-Lockstep, write) — Favorit (Lieblings-GP) pinnen/excludieren.
 * Jeder approved GP ist pinbar (kein Convenience-Zwang mehr, H4b). Schreibrecht:
 * GP muss dem aktiven Team gehören (global/Master = read-only für Kind-Teams).
 */
class FavoritesPutTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.favorites.PUT';
    }

    public function getDescription(): string
    {
        return 'Pinnt (action=pin, optional rank) oder entfernt (action=exclude) ein GP in/aus der '
            . 'kuratierten Favoriten-Liste (Lieblings-GPs). Jeder approved GP ist pinbar.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'gp_id' => ['type' => 'integer', 'description' => 'ID des Grundprodukts'],
                'action' => ['type' => 'string', 'enum' => ['pin', 'exclude'], 'description' => 'pin = aufnehmen, exclude = entfernen'],
                'rank' => ['type' => 'integer', 'description' => 'Anzeige-Rang beim Pinnen (optional)'],
            ],
            'required' => ['gp_id', 'action'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }

        $gp = FoodAlchemistGp::visibleToTeam($team)->whereKey((int) $arguments['gp_id'])->first();
        if ($gp === null) {
            return ToolResult::error('GP nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }
        if (! $gp->isOwnedBy($team)) {
            return ToolResult::error('GP gehört nicht dem aktiven Team (global/Master ist read-only).', 'ACCESS_DENIED');
        }

        $svc = app(FavoriteGpService::class);
        $action = (string) $arguments['action'];

        try {
            if ($action === 'pin') {
                $svc->pin($gp, isset($arguments['rank']) ? (int) $arguments['rank'] : null);
            } else {
                $svc->exclude($gp);
            }
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success([
            'gp_id' => $gp->id,
            'action' => $action,
            'is_favorite' => (bool) $gp->refresh()->is_favorite,
            'favorite_rank' => $gp->favorite_rank,
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'curation',
            'tags' => ['foodalchemist', 'favoriten', 'lieblings-gp', 'kuratierung', 'pin'],
            'read_only' => false,
            'idempotent' => true,
            'risk_level' => 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.favorites.GET'],
            'examples' => ['Pinne GP 1234 als Favorit', 'Nimm GP 987 aus den Favoriten'],
        ];
    }
}
