<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\SpeisekarteService;

/** MCP-Steuerbarkeit · D8: Rubrik unter eine andere Eltern-Rubrik hängen (Baum umhängen). */
class SpeisekarteRubrikMoveTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.speisekarte_rubrik.MOVE';
    }

    public function getDescription(): string
    {
        return 'Hängt eine Rubrik unter eine andere Eltern-Rubrik (new_parent_id null/weglassen = Wurzel-Ebene).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'rubrik_id' => ['type' => 'integer', 'description' => 'Zu verschiebende Rubrik.'],
                'new_parent_id' => ['type' => 'integer', 'description' => 'Neue Eltern-Rubrik (weglassen = Wurzel).'],
            ],
            'required' => ['rubrik_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $rubrikId = (int) ($arguments['rubrik_id'] ?? 0);
        if (($guard = $this->guardSpeisekarteRubrikOwned($team, $rubrikId)) !== null) {
            return $guard;
        }

        try {
            app(SpeisekarteService::class)->moveRubrik($team, $rubrikId, isset($arguments['new_parent_id']) ? (int) $arguments['new_parent_id'] : null);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['rubrik_id' => $rubrikId, 'new_parent_id' => isset($arguments['new_parent_id']) ? (int) $arguments['new_parent_id'] : null]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'speisekarte', 'rubrik', 'write'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.speisekarte_rubrik.REORDER'],
            'examples' => ['Hänge Rubrik 8 unter Rubrik 3.'],
        ];
    }
}
