<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\PairingService;

/**
 * MCP-Steuerbarkeit · D2: Kern-Aroma-Anker eines Rezepts verknüpfen/lösen (team-scoped Link auf ein
 * sichtbares Rezept; Cap pro Rezept im Service). Gilt für Basis- und VK-Rezepte.
 */
class RecipeAnchorsPutTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.recipe_anchors.PUT';
    }

    public function getDescription(): string
    {
        return 'Verknüpft/löst einen Kern-Aroma-Anker (GP) mit einem sichtbaren Rezept (team-scoped). '
            . 'action=set|remove. Die Anzahl Kern-Anker pro Rezept ist im Service gedeckelt.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'recipe_id' => ['type' => 'integer', 'description' => 'Rezept-Id (Basis oder VK, sichtbar).'],
                'anker_id' => ['type' => 'integer', 'description' => 'Anker-GP-Id.'],
                'action' => ['type' => 'string', 'enum' => ['set', 'remove'], 'description' => 'Setzen oder entfernen.'],
            ],
            'required' => ['recipe_id', 'anker_id', 'action'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $action = (string) ($arguments['action'] ?? '');
        if (! in_array($action, ['set', 'remove'], true)) {
            return ToolResult::error('action muss set|remove sein.', 'VALIDATION_ERROR');
        }

        $recipeId = (int) ($arguments['recipe_id'] ?? 0);
        if (! FoodAlchemistRecipe::visibleToTeam($team)->whereKey($recipeId)->exists()) {
            return ToolResult::error('Rezept nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }

        $ankerId = (int) ($arguments['anker_id'] ?? 0);
        if (! $this->pairingAnkerSichtbar($team, $ankerId)) {
            return ToolResult::error('anker_id nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }
        $svc = app(PairingService::class);
        try {
            $action === 'set'
                ? $svc->setRecipeAnker($team, $recipeId, $ankerId)
                : $svc->removeRecipeAnker($team, $recipeId, $ankerId);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['recipe_id' => $recipeId, 'anker_id' => $ankerId, 'action' => $action]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'recipe', 'anker', 'pairing', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.recipe_pairings.PUT', 'foodalchemist.pairings.GET'],
            'examples' => ['Verknüpfe Anker-GP 88 mit Rezept 12.'],
        ];
    }
}
