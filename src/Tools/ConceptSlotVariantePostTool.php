<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\ConceptVariantService;

/**
 * R4.4: Konzept-lokale Slot-Variante — Tausch im Concepter mutiert NIE das global
 * geteilte VK-Gericht, sondern erzeugt eine an den Slot gebundene Voll-Kopie
 * (Katalog-unsichtbar, `variant_source_recipe_id`-Lineage).
 */
class ConceptSlotVariantePostTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.concept_slot_variante.POST';
    }

    public function getDescription(): string
    {
        return 'Erzeugt/bearbeitet die konzept-lokale Variante eines Gericht-Slots: ohne weitere Args wird das '
            . 'Slot-Gericht als Variante dupliziert (idempotent); mit ingredient_id wird diese Zutat konzept-lokal '
            . 'per Äquivalenz-Katalog getauscht (Variante entsteht bei Bedarf, swap_locked wird respektiert); '
            . 'mit zuruecksetzen=true kommt das Original zurück und die Variante wird verworfen. '
            . 'Das Quell-Gericht bleibt IMMER unangetastet — wer es global ändern will, nutzt den Verkauf-Editor.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'slot_id' => ['type' => 'integer', 'description' => 'Concept-Slot mit fest gesetztem Gericht'],
                'ingredient_id' => ['type' => 'integer', 'description' => 'Zutat (Original- oder Varianten-Zeile) für den Äquivalenz-Tausch'],
                'zuruecksetzen' => ['type' => 'boolean', 'description' => 'true = Variante verwerfen, Original wiederherstellen'],
            ],
            'required' => ['slot_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $svc = app(ConceptVariantService::class);
        $slotId = (int) $arguments['slot_id'];

        try {
            if (($arguments['zuruecksetzen'] ?? false) === true) {
                $slot = $svc->zuruecksetzen($team, $slotId);
            } elseif (isset($arguments['ingredient_id'])) {
                $slot = $svc->tauscheZutatKonzeptLokal($team, $slotId, (int) $arguments['ingredient_id']);
            } else {
                $slot = $svc->varianteFuerSlot($team, $slotId);
            }
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return ToolResult::error('Slot/Zutat nicht gefunden oder nicht team-sichtbar.', 'NOT_FOUND');
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success([
            'slot_id' => $slot->id,
            'sales_recipe_id' => $slot->sales_recipe_id,
            'variiert' => $slot->variant_source_recipe_id !== null,
            'variant_source_recipe_id' => $slot->variant_source_recipe_id,
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'concepter', 'variante', 'tausch', 'substitution', 'slot'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true,
            'side_effects' => ['creates', 'updates'], 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.concepts.GET', 'foodalchemist.concept_slots.POST', 'foodalchemist.coverage.GET'],
            'examples' => ['Tausche in Konzept-Slot 12 die Zutat 88 konzept-lokal', 'Setze Slot 12 auf das Original-Gericht zurück'],
        ];
    }
}
