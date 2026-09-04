<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\RegenerationCascadeService;

/**
 * Spec 51 · MCP: die Regenerations-KASKADE eines Rezepts lesen — mit Herkunft je Zeile.
 *
 * Das ist der Weg, die Vererbung zu prüfen, ohne den Editor zu öffnen: welche Zeile ist ein
 * Override an DIESEM Gericht, welche kommt aus der Komponente, welche aus einer Regel, und wo
 * fehlt schlicht etwas. Ohne diese Sicht sähe ein MCP-Aufruf nur die gespeicherten Zeilen und
 * hielte die geerbten für nicht vorhanden.
 */
class RecipeRegenerationGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.recipe_regeneration.GET';
    }

    public function getDescription(): string
    {
        return 'Liest die Regeneration eines Rezepts als Kaskade: Gesamt-Zeile(n) plus eine Zeile je '
            . 'Direkt-Komponente mit Herkunft (override|geerbt|regel|fehlt), dazu die Zahl der Lücken '
            . 'und verwaiste Overrides. Am Basisrezept steht dieselbe Form für »das bin ich«. '
            . 'Gerät leer heisst kalt servieren; KEINE Zeile heisst fehlt.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'recipe_id' => ['type' => 'integer', 'description' => 'Rezept-Id (sichtbar fürs Team).'],
            ],
            'required' => ['recipe_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }

        $recipe = FoodAlchemistRecipe::visibleToTeam($team)->find((int) ($arguments['recipe_id'] ?? 0));
        if ($recipe === null) {
            return ToolResult::error('Rezept nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }

        $kaskade = app(RegenerationCascadeService::class)->fuerRezept($recipe);

        return ToolResult::success([
            'recipe_id' => (int) $recipe->id,
            'name' => $recipe->name,
            'ist_gericht' => (bool) $recipe->is_sales_recipe,
            'gesamt' => $kaskade['gesamt'],
            'komponenten' => $kaskade['komponenten'],
            'luecken' => $kaskade['luecken'],
            'verwaist' => $kaskade['verwaist'],
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'rezept', 'regeneration', 'read'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'read',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => [],
            'related_tools' => ['foodalchemist.recipe_regeneration.PUT', 'foodalchemist.behaelter_bedarf.GET'],
            'examples' => ['Woher kommt die Regeneration der Komponenten von Gericht 501?'],
        ];
    }
}
