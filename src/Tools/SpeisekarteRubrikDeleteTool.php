<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\SpeisekarteService;

/**
 * MCP-Steuerbarkeit · D8: Rubrik (Baustein) aus einer Speisekarte entfernen. Die ganze Karte zu löschen
 * bleibt human-only (kein speisekarten.DELETE via MCP). Confirm=true Pflicht.
 */
class SpeisekarteRubrikDeleteTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.speisekarte_rubrik.DELETE';
    }

    public function getDescription(): string
    {
        return 'Entfernt eine Rubrik (samt Unterrubriken/Positionen) aus einer team-eigenen Speisekarte. Erfordert confirm=true.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'rubrik_id' => ['type' => 'integer', 'description' => 'Rubrik-Id.'],
                'confirm' => ['type' => 'boolean', 'description' => 'Muss true sein (destruktive Aktion).'],
            ],
            'required' => ['rubrik_id', 'confirm'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        if (($arguments['confirm'] ?? false) !== true) {
            return ToolResult::error('Löschen erfordert confirm=true (destruktive Aktion).', 'CONFIRM_REQUIRED');
        }
        $rubrikId = (int) ($arguments['rubrik_id'] ?? 0);
        if (($guard = $this->guardSpeisekarteRubrikOwned($team, $rubrikId)) !== null) {
            return $guard;
        }

        try {
            app(SpeisekarteService::class)->deleteRubrik($team, $rubrikId);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['rubrik_id' => $rubrikId, 'deleted' => true]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'speisekarte', 'rubrik', 'delete'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'destructive',
            'confirmation_required' => true,
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['deletes'],
            'related_tools' => ['foodalchemist.speisekarte_rubrik.MOVE'],
            'examples' => ['Entferne Rubrik 8 (confirm=true).'],
        ];
    }
}
