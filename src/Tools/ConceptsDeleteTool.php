<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Services\ConceptService;

/**
 * Phase C: Konzept löschen (Soft-Delete, wiederherstellbar). Schritt 2 des Cleanup-
 * Zwei-Schritts. Referenz-Schutz (GT-FB-4/V-06): ein Konzept, das noch in Foodbooks
 * steckt, wird NICHT gelöscht — der Fehler listet die betroffenen Foodbooks; dort
 * zuerst den Block via foodalchemist.foodbook_blocks.DELETE entfernen.
 */
class ConceptsDeleteTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.concepts.DELETE';
    }

    public function getDescription(): string
    {
        return 'Löscht ein Konzept (Soft-Delete, im Concepter wiederherstellbar). Geht nur, wenn das Konzept '
            . 'in keinem Foodbook mehr referenziert ist — sonst kommt ein Fehler mit der Liste der Foodbooks, '
            . 'aus denen der Block zuerst via foodalchemist.foodbook_blocks.DELETE zu entfernen ist. '
            . 'concept_id liefert foodalchemist.concepts.SEARCH/LIST.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'concept_id' => ['type' => 'integer', 'description' => 'ID des Konzepts (aus foodalchemist.concepts.SEARCH)'],
            ],
            'required' => ['concept_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }

        $conceptId = (int) $arguments['concept_id'];
        $concept = FoodAlchemistConcept::visibleToTeam($team)->whereKey($conceptId)->first();
        if ($concept === null) {
            return ToolResult::error('Konzept nicht sichtbar/vorhanden — via foodalchemist.concepts.SEARCH ermitteln.', 'NOT_FOUND');
        }
        $name = $concept->name;

        $svc = app(ConceptService::class);

        // Referenz-Schutz vorab prüfen, um eine handlungsleitende Fehlermeldung zu liefern
        // (welche Foodbooks blockieren). Der Service prüft es beim Löschen erneut.
        $rest = $svc->verwendetInFoodbooks($team, $conceptId);
        if ($rest->isNotEmpty()) {
            return ToolResult::error(
                'Konzept "' . $name . '" wird noch in ' . $rest->count() . ' Foodbook(s) verwendet — '
                . 'dort zuerst den Block via foodalchemist.foodbook_blocks.DELETE entfernen.',
                'HAS_REFERENCES',
                ['blocking_foodbooks' => $rest->map(fn ($fb) => [
                    'id' => $fb->id, 'label' => $fb->label, 'jahr' => $fb->jahr,
                ])->values()->all()]
            );
        }

        try {
            $svc->delete($team, $conceptId);
        } catch (\RuntimeException $e) {
            // Owner-Guard (geerbtes Konzept) bzw. Race auf den Referenz-Schutz.
            $code = str_contains($e->getMessage(), 'verwendet') ? 'HAS_REFERENCES' : 'ACCESS_DENIED';

            return ToolResult::error($e->getMessage(), $code);
        }

        return ToolResult::success([
            'deleted_concept' => ['id' => $conceptId, 'name' => $name],
            'hinweis' => 'Konzept soft-gelöscht — im Concepter wiederherstellbar.',
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'concept', 'konzept', 'loeschen', 'delete', 'cleanup'],
            'read_only' => false,
            'idempotent' => false,
            'risk_level' => 'destructive',
            'requires_auth' => true,
            'requires_team' => true,
            'side_effects' => ['deletes'],
            'confirmation_required' => true,
            'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.concepts.SEARCH', 'foodalchemist.foodbook_blocks.DELETE', 'foodalchemist.concepts.POST'],
            'examples' => ['Lösche Konzept 42', 'Entferne das Konzept, nachdem es aus dem Foodbook raus ist'],
        ];
    }
}
