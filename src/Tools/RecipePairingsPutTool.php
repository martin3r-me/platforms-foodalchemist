<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\PairingService;

/**
 * MCP-Steuerbarkeit · D2: Pairing-Kante (Rezept↔Anker) mit Typ verknüpfen/lösen (team-scoped, auf
 * sichtbares Rezept). typ ∈ klassisch|modern|aroma|kontrast. Gilt für Basis- und VK-Rezepte.
 */
class RecipePairingsPutTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    private const TYPEN = ['klassisch', 'modern', 'aroma', 'kontrast'];

    public function getName(): string
    {
        return 'foodalchemist.recipe_pairings.PUT';
    }

    public function getDescription(): string
    {
        return 'Verknüpft/löst eine Pairing-Kante zwischen Rezept und Anker-GP mit Typ '
            . '(klassisch|modern|aroma|kontrast). action=set|remove. Bei remove ohne typ werden alle Typen gelöst.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'recipe_id' => ['type' => 'integer', 'description' => 'Rezept-Id (Basis oder VK, sichtbar).'],
                'anker_id' => ['type' => 'integer', 'description' => 'Anker-GP-Id.'],
                'typ' => ['type' => 'string', 'enum' => self::TYPEN, 'description' => 'Pairing-Typ (Default aroma).'],
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
        $typ = $arguments['typ'] ?? null;
        if ($typ !== null && ! in_array((string) $typ, self::TYPEN, true)) {
            return ToolResult::error('typ muss einer von: ' . implode(', ', self::TYPEN) . ' sein.', 'VALIDATION_ERROR');
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
            if ($action === 'set') {
                $svc->setRecipePairing($team, $recipeId, $ankerId, (string) ($typ ?? 'aroma'));
            } else {
                $svc->removeRecipePairing($team, $recipeId, $ankerId, $typ !== null ? (string) $typ : null);
            }
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success([
            'recipe_id' => $recipeId, 'anker_id' => $ankerId,
            'typ' => $typ !== null ? (string) $typ : ($action === 'set' ? 'aroma' : null),
            'action' => $action,
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'recipe', 'pairing', 'aroma', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.recipe_anchors.PUT', 'foodalchemist.pairings.GET'],
            'examples' => ['Verknüpfe Rezept 12 mit Anker 88 als klassisches Pairing.'],
        ];
    }
}
