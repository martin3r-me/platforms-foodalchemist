<?php

namespace Platform\FoodAlchemist\Tools;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\OfferCompositionService;

/**
 * Spec 50 · C-7 — Angebot auf Foodbook-Niveau: Kapitel eines Angebots neu ordnen.
 *
 * Das Gegenstück `foodalchemist.foodbook_kapitel.REORDER` gibt es seit Spec 19; am Angebot
 * fehlte es, obwohl {@see OfferCompositionService::reorderKapitel()} da war — die Reihenfolge
 * eines Kundendokuments war über MCP nur per UI änderbar.
 *
 * Anders als beim Foodbook OHNE `parent_id`: `reorderKapitel` am Angebot sortiert
 * offer-weit über `position`, nicht je Elternebene.
 */
class OfferChapterReorderTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.offer_chapter.REORDER';
    }

    public function getDescription(): string
    {
        return 'Ordnet die Kapitel eines team-eigenen Angebots neu (chapter-ids in Zielreihenfolge). '
            . 'Sortiert offer-weit über position — anders als beim Foodbook gibt es hier keine parent_id.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'offer_id' => ['type' => 'integer', 'description' => 'Angebot-Id.'],
                'ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Kapitel-Ids in Zielreihenfolge.'],
            ],
            'required' => ['offer_id', 'ids'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $ids = $arguments['ids'] ?? null;
        if (! is_array($ids) || $ids === []) {
            return ToolResult::error('ids muss ein nicht-leeres Array sein.', 'VALIDATION_ERROR');
        }
        $offerId = (int) ($arguments['offer_id'] ?? 0);

        try {
            app(OfferCompositionService::class)->reorderKapitel($team, $offerId, array_map('intval', $ids));
        } catch (ModelNotFoundException) {
            return ToolResult::error('Angebot nicht sichtbar/vorhanden.', 'NOT_FOUND');
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['offer_id' => $offerId, 'ids' => array_map('intval', $ids)]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'angebot', 'composer', 'kapitel', 'reorder', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.offer_chapter.MOVE', 'foodalchemist.offer_chapter.PUT'],
            'examples' => ['Ordne die Kapitel von Angebot 8 als [21,19,22].'],
        ];
    }
}
