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
        return 'Verknüpft/löst einen Kern-Aroma-Anker mit einem sichtbaren Rezept (team-scoped). '
            . 'anker_id ist die Id aus dem AROMA-ANKER-VOKABULAR (foodalchemist.composer.ANKER_SUCHE), '
            . 'NICHT die Grundprodukt-Id — ein Anker steht für eine Aroma-Familie, ein GP für einen Artikel. '
            . 'action=set|remove. Die Anzahl Kern-Anker pro Rezept ist im Service gedeckelt.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'recipe_id' => ['type' => 'integer', 'description' => 'Rezept-Id (Basis oder VK, sichtbar).'],
                'anker_id' => ['type' => 'integer', 'description' => 'Id aus dem Aroma-Anker-Vokabular — '
                    . 'zu holen über foodalchemist.composer.ANKER_SUCHE. NICHT die gp_id: eine GP-Id wird hier '
                    . 'als NOT_FOUND abgewiesen.'],
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
            // ★ Der haeufigste Griff daneben ist die gp_id — sie ist die naheliegende Zahl, und bis
            // 2026-09-12 stand im Schema sogar "Anker-GP-Id". Wer ihr folgte, bekam ein nacktes
            // "nicht sichtbar/vorhanden" und keinen Hinweis, welche Id gemeint war. Eine
            // Fehlermeldung, die den Weg zur richtigen Id nennt, spart genau diese Sackgasse.
            return ToolResult::error(
                "Anker {$ankerId} gibt es im Aroma-Anker-Vokabular nicht (oder er ist für dieses Team "
                . 'nicht sichtbar). Achtung: hier gehört die ANKER-Id hin, nicht die gp_id — passende '
                . 'Anker findest du mit foodalchemist.composer.ANKER_SUCHE.',
                'NOT_FOUND'
            );
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
            'related_tools' => ['foodalchemist.composer.ANKER_SUCHE', 'foodalchemist.recipe_pairings.PUT',
                'foodalchemist.pairings.GET'],
            'examples' => ['Anker per composer.ANKER_SUCHE finden, dann dessen id mit Rezept 12 verknüpfen.'],
        ];
    }
}
