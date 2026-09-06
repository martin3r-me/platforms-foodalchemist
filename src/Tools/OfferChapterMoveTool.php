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
 * Spec 50 · C-7 — ein Angebot-Kapitel unter ein anderes hängen (oder auf die oberste Ebene).
 * Der Zyklus-Schutz sitzt im {@see OfferCompositionService::moveKapitel()}, nicht hier.
 */
class OfferChapterMoveTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.offer_chapter.MOVE';
    }

    public function getDescription(): string
    {
        return 'Verschiebt ein Kapitel eines team-eigenen Angebots unter ein anderes Kapitel '
            . '(parent_id) oder auf die oberste Ebene (parent_id weglassen/null). '
            . 'Ein Kapitel kann nicht unter einen eigenen Nachfahren.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'chapter_id' => ['type' => 'integer', 'description' => 'Zu verschiebendes Kapitel.'],
                'parent_id' => ['type' => ['integer', 'null'], 'description' => 'Neues Eltern-Kapitel; null/weglassen = oberste Ebene.'],
            ],
            'required' => ['chapter_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $chapterId = (int) ($arguments['chapter_id'] ?? 0);
        if (($guard = $this->guardOwned($team, FoodAlchemistOfferChapter::class, $chapterId, 'Kapitel')) !== null) {
            return $guard;
        }
        $parentId = isset($arguments['parent_id']) && $arguments['parent_id'] !== null
            ? (int) $arguments['parent_id']
            : null;

        try {
            app(OfferCompositionService::class)->moveKapitel($team, $chapterId, $parentId);
        } catch (ModelNotFoundException) {
            return ToolResult::error('Kapitel nicht sichtbar/vorhanden.', 'NOT_FOUND');
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['chapter_id' => $chapterId, 'parent_id' => $parentId]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'angebot', 'composer', 'kapitel', 'move', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.offer_chapter.REORDER', 'foodalchemist.offer_chapter.PUT'],
            'examples' => ['Hänge Kapitel 22 unter Kapitel 19.', 'Hole Kapitel 22 auf die oberste Ebene.'],
        ];
    }
}
