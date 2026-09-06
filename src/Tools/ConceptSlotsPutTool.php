<?php

namespace Platform\FoodAlchemist\Tools;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\ConceptService;

/**
 * MCP-Steuerbarkeit · D5: Konzept-Slot bearbeiten (Rolle/Titel/Pflicht/Füllung + wording + quantity).
 */
class ConceptSlotsPutTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.concept_slots.PUT';
    }

    public function getDescription(): string
    {
        return 'Bearbeitet einen Konzept-Slot (felder: role, title, is_pflicht, note, sales_recipe_id, package_id). '
            . 'felder.sales_recipe_id FÜLLT eine leere Position mit einem Gericht (XOR package_id; type=basisrezept optional) — '
            . 'so werden die Positionen eines Gerüsts aus concepts.POST geruest=… belegt. '
            . 'felder.wording setzt den Slot-Text, felder.quantity/unit_vocab_id die Menge.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'slot_id' => ['type' => 'integer', 'description' => 'Slot-Id.'],
                'felder' => ['type' => 'object', 'description' => 'Slot-Felder (+ optional wording, quantity, unit_vocab_id).'],
            ],
            'required' => ['slot_id', 'felder'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $felder = $arguments['felder'] ?? null;
        if (! is_array($felder) || $felder === []) {
            return ToolResult::error('felder muss ein nicht-leeres Objekt sein.', 'VALIDATION_ERROR');
        }
        $slotId = (int) ($arguments['slot_id'] ?? 0);
        if (($guard = $this->guardConceptSlotOwned($team, $slotId)) !== null) {
            return $guard;
        }

        // Spec 50 C-1: Befüllen über PUT — vorher wurden sales_recipe_id/package_id hier STILL verworfen
        // (updateSlot kennt nur role/title/is_pflicht/note); die Beschreibung versprach es aber.
        if (isset($felder['sales_recipe_id'], $felder['package_id'])) {
            return ToolResult::error('sales_recipe_id und package_id sind XOR — nur eines angeben.', 'VALIDATION_ERROR');
        }
        if (! empty($felder['sales_recipe_id'])
            && ! \Platform\FoodAlchemist\Models\FoodAlchemistRecipe::visibleToTeam($team)->whereKey((int) $felder['sales_recipe_id'])->exists()) {
            return ToolResult::error('sales_recipe_id nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }
        if (! empty($felder['package_id'])
            && ! \Platform\FoodAlchemist\Models\FoodAlchemistPaket::visibleToTeam($team)->whereKey((int) $felder['package_id'])->exists()) {
            return ToolResult::error('package_id nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }

        $svc = app(ConceptService::class);
        try {
            $svc->updateSlot($team, $slotId, $felder);
            if (! empty($felder['sales_recipe_id']) || ! empty($felder['package_id'])) {
                $svc->fillSlot($team, $slotId, array_intersect_key($felder, array_flip(['sales_recipe_id', 'package_id', 'type', 'quantity', 'unit_vocab_id'])));
            }
            if (array_key_exists('wording', $felder)) {
                $svc->setSlotWording($team, $slotId, ($felder['wording'] ?? '') !== '' ? (string) $felder['wording'] : null);
            }
            if (array_key_exists('quantity', $felder)) {
                $svc->setSlotMengeEinheit($team, $slotId, $felder['quantity'] !== null ? (float) $felder['quantity'] : null, isset($felder['unit_vocab_id']) ? (int) $felder['unit_vocab_id'] : null);
            }
        } catch (ModelNotFoundException $e) {
            return ToolResult::error('Slot/Referenz nicht gefunden.', 'NOT_FOUND');
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['slot_id' => $slotId, 'updated' => true]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'concept', 'slot', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.concept_slots.DELETE', 'foodalchemist.concept_slots.REORDER'],
            'examples' => ['Setze bei Slot 12 die Rolle „Vorspeise" und ein Gericht.'],
        ];
    }
}
