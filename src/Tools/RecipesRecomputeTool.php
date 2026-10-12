<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\RecipeRecomputeService;

/**
 * MCP-Steuerbarkeit · D2: Aggregation eines team-eigenen Basisrezepts neu berechnen
 * (Yield/Allergene/Zusatzstoffe/EK) + Propagation an nutzende Eltern-Rezepte. Idempotent.
 */
class RecipesRecomputeTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.recipes.RECOMPUTE';
    }

    public function getDescription(): string
    {
        return 'Berechnet die Aggregation eines team-eigenen Rezepts neu — Basisrezept ODER Gericht — '
            . '(Yield/Allergene/EK, Darreichungen, Aromenprofil/Aroma-Anker aus den Zutaten) und propagiert an '
            . 'Eltern-Rezepte. Idempotent. Nach Rezeptänderungen der Weg, das Aromenprofil eines Gerichts zu erneuern.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['id' => ['type' => 'integer', 'description' => 'Rezept-Id (team-eigen; Basisrezept oder Gericht).']],
            'required' => ['id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }

        $id = (int) ($arguments['id'] ?? 0);
        // Weg-B-Befund 7 (2026-10-12): Gerichte waren ausgeschlossen — das Aromenprofil eines Gerichts ließ sich
        // nach einer Rezeptänderung per MCP nicht erneuern (nur als Elternteil eines Basisrezepts, nebenbei).
        $recipe = FoodAlchemistRecipe::visibleToTeam($team)->whereKey($id)->first();
        if ($recipe === null) {
            return ToolResult::error('Rezept nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }
        if (! $recipe->isOwnedBy($team)) {
            return ToolResult::error('Geerbtes Rezept — Neuberechnung nur durchs Besitzer-Team.', 'ACCESS_DENIED');
        }

        // Rückgabe = Liste betroffener recipe_ids (Start-Menge + transitive Eltern).
        $betroffen = app(RecipeRecomputeService::class)->recomputeAndPropagate($id);

        return ToolResult::success([
            'id' => $id,
            'recomputed' => true,
            'betroffene_rezepte' => count($betroffen),
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'recipe', 'basisrezept', 'recompute', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.recipes.GET'],
            'examples' => ['Berechne Basisrezept 12 neu.'],
        ];
    }
}
