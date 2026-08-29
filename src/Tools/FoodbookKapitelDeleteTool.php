<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\FoodbookService;

/**
 * MCP-Steuerbarkeit · D7: Kapitel (Baustein) aus einem Entwurf-Foodbook entfernen. Das ganze Buch
 * zu löschen bleibt human-only (kein foodbook.DELETE via MCP). Confirm=true Pflicht.
 */
class FoodbookKapitelDeleteTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.foodbook_kapitel.DELETE';
    }

    public function getDescription(): string
    {
        return 'Entfernt ein Kapitel (samt Unterkapitel/Blöcke) aus einem team-eigenen Entwurf-Foodbook. Erfordert confirm=true.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'kapitel_id' => ['type' => 'integer', 'description' => 'Kapitel-Id.'],
                'confirm' => ['type' => 'boolean', 'description' => 'Muss true sein (destruktive Aktion).'],
            ],
            'required' => ['kapitel_id', 'confirm'],
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
        $kapitelId = (int) ($arguments['kapitel_id'] ?? 0);
        if (($guard = $this->guardFoodbookEditable($team, $this->foodbookVonKapitel($team, $kapitelId))) !== null) {
            return $guard;
        }

        try {
            app(FoodbookService::class)->deleteKapitel($team, $kapitelId);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['kapitel_id' => $kapitelId, 'deleted' => true]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'foodbook', 'kapitel', 'delete'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'destructive',
            'confirmation_required' => true,
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['deletes'],
            'related_tools' => ['foodalchemist.foodbook_kapitel.REORDER'],
            'examples' => ['Entferne Kapitel 12 (confirm=true).'],
        ];
    }
}
