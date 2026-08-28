<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\SalesRecipeService;

/**
 * MCP-Steuerbarkeit · D3: Verkaufsrezept (Gericht) löschen. Destruktiv → confirm=true Pflicht.
 * Nur team-eigene Gerichte. Löscht die VK-Ebene (das Gericht), nicht die referenzierten Basisrezepte.
 */
class VerkaufsrezepteDeleteTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.verkaufsrezepte.DELETE';
    }

    public function getDescription(): string
    {
        return 'Löscht ein team-eigenes Gericht (confirm=true Pflicht). Entfernt die VK-Ebene, nicht die '
            . 'referenzierten Basisrezepte.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'Gericht-Id (team-eigen).'],
                'confirm' => ['type' => 'boolean', 'description' => 'Muss true sein (destruktive Aktion).'],
            ],
            'required' => ['id', 'confirm'],
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

        $id = (int) ($arguments['id'] ?? 0);
        $r = FoodAlchemistRecipe::visibleToTeam($team)->where('is_sales_recipe', true)->whereKey($id)->first();
        if ($r === null) {
            return ToolResult::error('Gericht nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }
        if (! $r->isOwnedBy($team)) {
            return ToolResult::error('Geerbtes Gericht — Löschen nur durchs Besitzer-Team.', 'ACCESS_DENIED');
        }

        try {
            app(SalesRecipeService::class)->deleteDish($team, $id);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['id' => $id, 'deleted' => true]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'verkaufsrezept', 'gericht', 'loeschen', 'destructive', 'write'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'destructive',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'confirmation_required' => true,
            'side_effects' => ['deletes'],
            'related_tools' => ['foodalchemist.verkaufsrezepte.STATUS'],
            'examples' => ['Lösche Gericht 501 (confirm=true).'],
        ];
    }
}
