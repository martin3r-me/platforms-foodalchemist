<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;

/**
 * Spec 43 (read): Präsentations-Status eines Foodbooks — aktiv?, live?, Design, Freigabe-/
 * Ablaufdatum, öffentlicher Link. Zeigt bewusst KEINE Snapshot-Interna.
 */
class FoodbookPresentationGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.foodbook_presentation.GET';
    }

    public function getDescription(): string
    {
        return 'Liefert den Präsentations-Status eines Foodbooks: aktiv/live, gewähltes Design, '
            . 'Freigabe- und Ablaufdatum sowie den öffentlichen Kundenlink (falls aktiv).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['foodbook_id' => ['type' => 'integer']],
            'required' => ['foodbook_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $fb = FoodAlchemistFoodbook::visibleToTeam($team)->find((int) $arguments['foodbook_id']);
        if ($fb === null) {
            return ToolResult::error('Foodbook nicht gefunden oder nicht sichtbar.', 'NOT_FOUND');
        }

        return ToolResult::success([
            'foodbook_id' => (int) $fb->id,
            'enabled' => (bool) $fb->presentation_enabled,
            'live' => $fb->isPresentationLive(),
            'design' => $fb->presentation_design,
            'published_at' => $fb->presentation_published_at?->toIso8601String(),
            'expires_at' => $fb->presentation_expires_at?->toIso8601String(),
            'url' => ($fb->presentation_enabled && $fb->presentation_token) ? url('/p/foodbook/' . $fb->presentation_token) : null,
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'foodbook', 'praesentation', 'status'],
            'read_only' => true,
            'idempotent' => true,
            'risk_level' => 'read',
            'requires_auth' => true,
            'requires_team' => true,
            'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.foodbook_presentation.PUBLISH'],
            'examples' => ['Ist Foodbook 12 als Kundenbuch veröffentlicht?'],
        ];
    }
}
