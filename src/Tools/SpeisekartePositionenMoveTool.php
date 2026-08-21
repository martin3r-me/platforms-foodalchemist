<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\SpeisekarteService;

/**
 * Werkstrang M Phase C (Spec 40 §6): eine Speisekarten-Position in eine ANDERE Rubrik derselben Karte
 * schieben (section_id ist bewusst nicht in der Positions-Update-Whitelist → eigene Methode). Team-scoped.
 */
class SpeisekartePositionenMoveTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.speisekarte_positionen.MOVE';
    }

    public function getDescription(): string
    {
        return 'Verschiebt eine Position in eine andere Rubrik DERSELBEN Speisekarte (ans Ende der Ziel-Rubrik). '
            . 'Beide Rubriken müssen zur selben Karte gehören.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'position_id' => ['type' => 'integer'],
                'section_id' => ['type' => 'integer', 'description' => 'Ziel-Rubrik (gleiche Karte).'],
            ],
            'required' => ['position_id', 'section_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        try {
            app(SpeisekarteService::class)->movePosition($team, (int) $arguments['position_id'], (int) $arguments['section_id']);
        } catch (\Throwable $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['moved' => true, 'position_id' => (int) $arguments['position_id'], 'section_id' => (int) $arguments['section_id']]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'speisekarte', 'position', 'move'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true,
            'side_effects' => ['updates'], 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.speisekarte_positionen.REORDER', 'foodalchemist.speisekarte_positionen.POST'],
            'examples' => ['Verschiebe Position 44 in Rubrik 12'],
        ];
    }
}
