<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistFormatSlot;
use Platform\FoodAlchemist\Services\FormatService;

/** Format-Modul: eine Aufbau-Position (Concept-Referenz) aus einem Format entfernen. */
class FormatEditionsDeleteTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.format_editions.DELETE';
    }

    public function getDescription(): string
    {
        return 'Entfernt eine Aufbau-Position (Concept-Referenz) aus einem Format. F2-Referenz-Modell: '
            . 'entfernt nur den Slot, das Konzept selbst bleibt bestehen (kann in anderen Formaten weiterlaufen). '
            . 'Adressierung per slot_id (bevorzugt) oder concept_id (+ format_id zur Eindeutigkeit). Nur das Besitzer-Team.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'slot_id' => ['type' => 'integer', 'description' => 'bevorzugt: die Slot-ID der Aufbau-Position'],
                'concept_id' => ['type' => 'integer', 'description' => 'alternativ: das referenzierte Konzept'],
                'format_id' => ['type' => 'integer', 'description' => 'zur Eindeutigkeit, wenn per concept_id adressiert'],
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

        // Slot-ID bestimmen: direkt oder über concept_id (+ optional format_id zur Eindeutigkeit).
        $slotId = isset($arguments['slot_id']) ? (int) $arguments['slot_id'] : null;
        if ($slotId === null) {
            if (! isset($arguments['concept_id'])) {
                return ToolResult::error('slot_id oder concept_id erforderlich.', 'VALIDATION_ERROR');
            }
            $treffer = FoodAlchemistFormatSlot::where('type', 'concept')
                ->where('concept_id', (int) $arguments['concept_id'])
                ->when(isset($arguments['format_id']), fn ($q) => $q->where('format_id', (int) $arguments['format_id']))
                ->pluck('id');
            if ($treffer->isEmpty()) {
                return ToolResult::error('Keine passende Aufbau-Position gefunden.', 'NOT_FOUND');
            }
            if ($treffer->count() > 1) {
                return ToolResult::error('Konzept steht in mehreren Formaten — format_id angeben.', 'VALIDATION_ERROR');
            }
            $slotId = (int) $treffer->first();
        }

        try {
            $slot = FoodAlchemistFormatSlot::findOrFail($slotId);
            $formatId = (int) $slot->format_id;
            $conceptId = $slot->concept_id !== null ? (int) $slot->concept_id : null;
            app(FormatService::class)->slotEntfernen($team, $slotId);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return ToolResult::error('Aufbau-Position nicht sichtbar/vorhanden.', 'NOT_FOUND');
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['slot_id' => $slotId, 'concept_id' => $conceptId, 'format_id' => $formatId]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'format', 'edition', 'loesen'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true,
            'side_effects' => ['updates'], 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.format_editions.POST'],
            'examples' => ['Löse Konzept 12 aus seinem Format'],
        ];
    }
}
