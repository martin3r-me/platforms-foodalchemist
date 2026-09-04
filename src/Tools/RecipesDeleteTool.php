<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\RecipeService;

/**
 * MCP-Steuerbarkeit · D2: Basisrezept löschen. Destruktiv → confirm=true Pflicht.
 *
 * Nur team-eigene Basisrezepte (is_sales_recipe=false). Der Service blockt bei JEDER harten
 * Referenz (Spec 49): Komponenten-Zeilen, Ersatz-Verknüpfungen, direkt gepinnte Ausgabe-Positionen
 * und Zeilen in offenen Produktionsaufträgen — Komponenten-Zeilen löst `recipes.REPLACE` auf.
 * VK-Gerichte laufen über verkaufsrezepte.DELETE.
 */
class RecipesDeleteTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.recipes.DELETE';
    }

    public function getDescription(): string
    {
        return 'Löscht ein team-eigenes Basisrezept (confirm=true Pflicht). Blockiert, solange etwas darauf '
            . 'zeigt: Komponenten-Zeilen, Ersatz-Verknüpfungen, Ausgabe-Positionen, offene Produktionsaufträge. '
            . 'Komponenten-Zeilen vorher per recipes.REPLACE umhängen. VK-Gerichte → verkaufsrezepte.DELETE.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'Basisrezept-Id (team-eigen).'],
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
        $recipe = FoodAlchemistRecipe::visibleToTeam($team)->where('is_sales_recipe', false)->whereKey($id)->first();
        if ($recipe === null) {
            return ToolResult::error('Basisrezept nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }
        if (! $recipe->isOwnedBy($team)) {
            return ToolResult::error('Geerbtes Rezept — Löschen nur durchs Besitzer-Team.', 'ACCESS_DENIED');
        }

        try {
            app(RecipeService::class)->delete($team, $id);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['id' => $id, 'deleted' => true]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'recipe', 'basisrezept', 'loeschen', 'destructive', 'write'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'destructive',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'confirmation_required' => true,
            'side_effects' => ['deletes'],
            'related_tools' => ['foodalchemist.recipes.REPLACE', 'foodalchemist.recipes.STATUS', 'foodalchemist.verkaufsrezepte.DELETE'],
            'examples' => ['Lösche Basisrezept 123 (confirm=true).'],
        ];
    }
}
