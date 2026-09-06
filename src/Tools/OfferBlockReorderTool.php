<?php

namespace Platform\FoodAlchemist\Tools;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistOfferChapter;
use Platform\FoodAlchemist\Services\OfferCompositionService;

/**
 * Spec 50 · C-7 — Blöcke eines Angebot-Kapitels neu ordnen. Gegenstück zu
 * `foodalchemist.foodbook_blocks.REORDER`.
 */
class OfferBlockReorderTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.offer_block.REORDER';
    }

    public function getDescription(): string
    {
        return 'Ordnet die Blöcke eines Kapitels in einem team-eigenen Angebot neu (block-ids in Zielreihenfolge).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'chapter_id' => ['type' => 'integer', 'description' => 'Kapitel-Id.'],
                'ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Block-Ids in Zielreihenfolge.'],
            ],
            'required' => ['chapter_id', 'ids'],
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
        $chapterId = (int) ($arguments['chapter_id'] ?? 0);
        if (($guard = $this->guardOwned($team, FoodAlchemistOfferChapter::class, $chapterId, 'Kapitel')) !== null) {
            return $guard;
        }

        try {
            app(OfferCompositionService::class)->reorderBlocks($team, $chapterId, array_map('intval', $ids));
        } catch (ModelNotFoundException) {
            return ToolResult::error('Kapitel nicht sichtbar/vorhanden.', 'NOT_FOUND');
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['chapter_id' => $chapterId, 'ids' => array_map('intval', $ids)]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'angebot', 'composer', 'block', 'reorder', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.offer_block.PUT', 'foodalchemist.offer_block.POST'],
            'examples' => ['Ordne die Blöcke von Kapitel 12 als [30,28,31].'],
        ];
    }
}
