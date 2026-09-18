<?php

namespace Platform\FoodAlchemist\Tools;

use Illuminate\Pagination\Paginator;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\GpService;
use Platform\FoodAlchemist\Services\PairingService;

/**
 * Spec 53 Paket J: GP↔Aroma-Anker-Zuordnungen lesen. Drei Modi: `gp_id` (Anker dieses EINEN GP,
 * kern+neben), `anchor_slug` (Reverse-Lookup — welche GPs tragen diesen Anker als Kern, s.
 * {@see \Platform\FoodAlchemist\Services\PairingService::gpsForAnkerIds}), oder ohne beides der
 * vollständige, seiten-basierte Export aller Zuordnungen des Teams (page/per_page, wie gps.LIST).
 */
class GpAnchorsGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.gp_anchors.GET';
    }

    public function getDescription(): string
    {
        return 'Liest GP↔Aroma-Anker-Zuordnungen. gp_id → Anker dieses GP (kern+neben). anchor_slug → '
            . 'Reverse-Lookup (welche GPs tragen diesen Anker als Kern). Ohne beides → vollständiger, '
            . 'seiten-basierter Export aller Zuordnungen des Teams (page/per_page).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'gp_id' => ['type' => 'integer', 'description' => 'Anker dieses GP (sichtbar).'],
                'anchor_slug' => ['type' => 'string', 'description' => 'Reverse-Lookup: welche GPs tragen diesen Anker als Kern.'],
                'page' => ['type' => 'integer', 'minimum' => 1, 'default' => 1, 'description' => 'Nur im Export-Modus (ohne gp_id/anchor_slug).'],
                'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 100, 'description' => 'Seitengröße Export-Modus (max. 200).'],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }

        $gpId = isset($arguments['gp_id']) && $arguments['gp_id'] !== '' ? (int) $arguments['gp_id'] : null;
        $slug = trim((string) ($arguments['anchor_slug'] ?? ''));
        $svc = app(PairingService::class);

        if ($gpId !== null) {
            $gp = app(GpService::class)->find($gpId, $team);
            if ($gp === null) {
                return ToolResult::error('GP nicht sichtbar/vorhanden.', 'NOT_FOUND');
            }

            return ToolResult::success([
                'gp_id' => (int) $gp->id,
                'anker' => $svc->gpAnkerAlle($gp->id)->map(fn ($a) => [
                    'anchor_id' => (int) $a->id, 'anchor_slug' => $a->slug, 'display_de' => $a->display_de,
                    'role' => $a->role, 'source' => $a->source, 'ai_confidence' => $a->ai_confidence !== null ? (float) $a->ai_confidence : null,
                ])->all(),
            ]);
        }

        if ($slug !== '') {
            $ankerId = $this->pairingAnkerIdFuerSlug($team, $slug);
            if ($ankerId === null) {
                return ToolResult::error("Anker-Slug „{$slug}“ gibt es nicht (oder ist nicht sichtbar).", 'NOT_FOUND');
            }

            return ToolResult::success([
                'anchor_slug' => $slug,
                'anchor_id' => $ankerId,
                'gps' => $svc->gpsForAnkerIds($team, [$ankerId])->map(fn ($g) => [
                    'gp_id' => (int) $g->id, 'name' => $g->name, 'status' => $g->status,
                ])->all(),
            ]);
        }

        // Export-Modus: vollständige, seiten-basierte Auflistung.
        $page = max(1, (int) ($arguments['page'] ?? 1));
        $perPage = min(200, max(1, (int) ($arguments['per_page'] ?? 100)));
        Paginator::currentPageResolver(fn () => $page);
        $gpIds = \Platform\FoodAlchemist\Models\FoodAlchemistGp::visibleToTeam($team)->pluck('id');
        $treffer = \Illuminate\Support\Facades\DB::table('foodalchemist_gp_anchor_mappings AS m')
            ->join('foodalchemist_vocab_pairing_anchors AS a', 'a.id', '=', 'm.anchor_id')
            ->whereIn('m.gp_id', $gpIds)->whereNull('m.deleted_at')
            ->orderBy('m.gp_id')->orderBy('a.slug')
            ->paginate($perPage, ['m.gp_id', 'a.slug AS anchor_slug', 'a.display_de', 'm.role', 'm.source', 'm.ai_confidence'], 'page', $page);

        return ToolResult::success([
            'total' => $treffer->total(),
            'page' => $treffer->currentPage(),
            'last_page' => $treffer->lastPage(),
            'per_page' => $treffer->perPage(),
            'zuordnungen' => collect($treffer->items())->map(fn ($z) => [
                'gp_id' => (int) $z->gp_id, 'anchor_slug' => $z->anchor_slug, 'display_de' => $z->display_de,
                'role' => $z->role, 'source' => $z->source, 'ai_confidence' => $z->ai_confidence !== null ? (float) $z->ai_confidence : null,
            ])->all(),
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'tags' => ['foodalchemist', 'gp', 'grundprodukt', 'anker', 'pairing', 'aroma', 'list', 'paging'],
            'related_tools' => ['foodalchemist.gp_anchors.PUT', 'foodalchemist.gp_anchors.IMPORT', 'foodalchemist.gps.GET'],
            'examples' => ['Welche Aroma-Anker trägt GP 123?', 'Welche GPs tragen den Anker "vanille"?', 'Exportiere alle GP-Anker-Zuordnungen seitenweise.'],
        ];
    }
}
