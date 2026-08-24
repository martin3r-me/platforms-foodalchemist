<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\FormatService;

/** Format-Modul: bestehendes Konzept als Edition einem Format zuordnen. */
class FormatEditionsPostTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.format_editions.POST';
    }

    public function getDescription(): string
    {
        return 'Fügt ein bestehendes Konzept (Zusammenstellung) als Aufbau-Position (Referenz) in ein Format ein. '
            . 'F2-Referenz-Modell: ein Konzept kann in mehreren Formaten stehen (kein format_id-Besitz mehr). '
            . 'Optional direkt hinter einer Ziel-Position (after_slot_id). Guardet das Format (team-eigen). Kein Recompute.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'format_id' => ['type' => 'integer'],
                'concept_id' => ['type' => 'integer'],
                'after_slot_id' => ['type' => 'integer', 'description' => 'optional: neue Position direkt hinter diesem Slot einsortieren (sonst ans Ende)'],
            ],
            'required' => ['format_id', 'concept_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }

        try {
            $slot = app(FormatService::class)->slotConceptEinfuegen(
                $team,
                (int) $arguments['format_id'],
                (int) $arguments['concept_id'],
                isset($arguments['after_slot_id']) ? (int) $arguments['after_slot_id'] : null,
            );
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return ToolResult::error('Format oder Konzept nicht sichtbar/vorhanden.', 'NOT_FOUND');
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success([
            'edition' => [
                'slot_id' => $slot->id, 'concept_id' => (int) $slot->concept_id,
                'format_id' => (int) $slot->format_id, 'position' => (int) $slot->position,
            ],
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'format', 'edition', 'zuordnen'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true,
            'side_effects' => ['updates'], 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.format_editions.DELETE', 'foodalchemist.formats.GET'],
            'examples' => ['Ordne Konzept 12 dem Format 3 zu'],
        ];
    }
}
