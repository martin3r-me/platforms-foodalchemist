<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\SpeisekarteService;

/** Position aus einer Speisekarten-Rubrik entfernen. */
class SpeisekartePositionenDeleteTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.speisekarte_positionen.DELETE';
    }

    public function getDescription(): string
    {
        return 'Entfernt eine Position aus einer Speisekarte (Soft-Delete). Nur durchs Besitzer-Team.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'position_id' => ['type' => 'integer'],
            ],
            'required' => ['position_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }

        try {
            app(SpeisekarteService::class)->deletePosition($team, (int) $arguments['position_id']);
        } catch (\Throwable $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['deleted' => true, 'position_id' => (int) $arguments['position_id']]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'speisekarte', 'position', 'loeschen'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true,
            'side_effects' => ['deletes'], 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.speisekarte_positionen.POST'],
            'examples' => ['Entferne Position 88'],
        ];
    }
}
