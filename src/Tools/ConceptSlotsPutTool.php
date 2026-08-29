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
        return 'Bearbeitet einen Konzept-Slot (felder: role, title, is_pflicht, sales_recipe_id, package_id, …). '
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

        $svc = app(ConceptService::class);
        try {
            $svc->updateSlot($team, $slotId, $felder);
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
