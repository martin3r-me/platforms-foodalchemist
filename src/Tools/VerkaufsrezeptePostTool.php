<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\SalesRecipeService;

/**
 * MCP-Steuerbarkeit · D3: Verkaufsrezept (Gericht) anlegen — leer oder aus einem sichtbaren Basisrezept
 * (das dann als erste Komponente eingehängt wird). Team-eigen.
 */
class VerkaufsrezeptePostTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.verkaufsrezepte.POST';
    }

    public function getDescription(): string
    {
        return 'Legt ein Verkaufsrezept (Gericht) an. Mit basis_recipe_id wird das (sichtbare) Basisrezept '
            . 'als erste Komponente eingehängt; ohne wird ein leeres Gericht erzeugt.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'description' => 'Name des Gerichts.'],
                'basis_recipe_id' => ['type' => 'integer', 'description' => 'Optionales Basisrezept als erste Komponente (sichtbar).'],
            ],
            'required' => ['name'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $name = trim((string) ($arguments['name'] ?? ''));
        if ($name === '') {
            return ToolResult::error('name ist Pflicht.', 'VALIDATION_ERROR');
        }

        $svc = app(SalesRecipeService::class);
        $basisId = isset($arguments['basis_recipe_id']) ? (int) $arguments['basis_recipe_id'] : 0;
        try {
            if ($basisId > 0) {
                if (! FoodAlchemistRecipe::visibleToTeam($team)->where('is_sales_recipe', false)->whereKey($basisId)->exists()) {
                    return ToolResult::error('basis_recipe_id nicht sichtbar/vorhanden.', 'NOT_FOUND');
                }
                $vk = $svc->createFromBasis($team, $basisId, $name);
            } else {
                $vk = $svc->createLeer($team, $name);
            }
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['id' => (int) $vk->id, 'name' => $vk->name, 'status' => $this->statusWert($vk)]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'verkaufsrezept', 'gericht', 'anlegen', 'write'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['creates'],
            'related_tools' => ['foodalchemist.verkaufsrezepte.PUT', 'foodalchemist.verkaufsrezepte.GET'],
            'examples' => ['Lege ein Gericht „Wolfsbarsch, Fenchel" aus Basisrezept 12 an.'],
        ];
    }
}
