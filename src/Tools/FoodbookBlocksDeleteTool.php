<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookBlock;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Services\FoodbookService;

/**
 * Phase B: Block aus einem Foodbook-Kapitel entfernen (Soft-Delete, wiederherstellbar).
 * Schritt 1 des Cleanup-Zwei-Schritts: erst den concept_ref-Block aus dem Foodbook
 * nehmen, dann lässt sich das Konzept selbst löschen (foodalchemist.concepts.DELETE).
 * War der Block eine Konzept-Referenz, meldet das Ergebnis, ob das Konzept jetzt frei ist.
 */
class FoodbookBlocksDeleteTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.foodbook_blocks.DELETE';
    }

    public function getDescription(): string
    {
        return 'Entfernt einen Block aus einem Foodbook-Kapitel (Soft-Delete, im Editor wiederherstellbar). '
            . 'Für den Cleanup: erst den Konzept-Block (type=concept_ref) hier entfernen, danach das Konzept '
            . 'via foodalchemist.concepts.DELETE löschen. Block-IDs liefert foodalchemist.foodbook.GET. '
            . 'Meldet nach dem Entfernen, ob das referenzierte Konzept jetzt in keinem Foodbook mehr steckt.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'block_id' => ['type' => 'integer', 'description' => 'ID des Blocks (aus foodalchemist.foodbook.GET)'],
            ],
            'required' => ['block_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }

        $block = FoodAlchemistFoodbookBlock::visibleToTeam($team)
            ->whereKey((int) $arguments['block_id'])->first();
        if ($block === null) {
            return ToolResult::error('Block nicht sichtbar/vorhanden — via foodalchemist.foodbook.GET ermitteln.', 'NOT_FOUND');
        }

        // Vor dem Löschen merken (für Ergebnis + Konzept-Referenz-Nachcheck).
        $conceptId = $block->concept_id !== null ? (int) $block->concept_id : null;
        $entfernt = [
            'id' => $block->id,
            'type' => $block->type,
            'label' => $block->label,
            'chapter_id' => $block->chapter_id,
            'concept_id' => $conceptId,
        ];

        $svc = app(FoodbookService::class);
        try {
            $svc->deleteBlock($team, $block->id);
        } catch (\RuntimeException $e) {
            // Owner-Guard (geerbtes Foodbook) → typisierter Zugriffs-Fehler.
            return ToolResult::error($e->getMessage(), 'ACCESS_DENIED');
        }

        // War es eine Konzept-Referenz: prüfen, ob das Konzept jetzt frei löschbar ist.
        $conceptFreiZumLoeschen = null;
        $nochInFoodbooks = null;
        if ($conceptId !== null) {
            $rest = app(ConceptService::class)->verwendetInFoodbooks($team, $conceptId);
            $nochInFoodbooks = $rest->map(fn ($fb) => [
                'id' => $fb->id, 'label' => $fb->label, 'jahr' => $fb->jahr,
            ])->values()->all();
            $conceptFreiZumLoeschen = $rest->isEmpty();
        }

        return ToolResult::success([
            'removed_block' => $entfernt,
            'concept_now_deletable' => $conceptFreiZumLoeschen,
            'concept_still_in_foodbooks' => $nochInFoodbooks,
            'hinweis' => $conceptFreiZumLoeschen === true
                ? 'Konzept in keinem Foodbook mehr referenziert — foodalchemist.concepts.DELETE ist jetzt möglich.'
                : ($conceptFreiZumLoeschen === false
                    ? 'Konzept steckt noch in weiteren Foodbooks — dort ebenfalls entfernen, bevor es gelöscht werden kann.'
                    : 'Kein Konzept-Block (nichts weiter zu tun).'),
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'foodbook', 'block', 'concept', 'entfernen', 'loeschen', 'cleanup'],
            'read_only' => false,
            'idempotent' => false,
            'risk_level' => 'destructive',
            'requires_auth' => true,
            'requires_team' => true,
            'side_effects' => ['deletes'],
            'confirmation_required' => true,
            'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.foodbook.GET', 'foodalchemist.concepts.DELETE', 'foodalchemist.foodbook_blocks.POST'],
            'examples' => ['Entferne Block 8123 aus dem Foodbook', 'Nimm das Konzept aus Kapitel X, damit ich es löschen kann'],
        ];
    }
}
