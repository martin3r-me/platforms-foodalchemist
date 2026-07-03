<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\PairingService;

/**
 * Phase K: Teller-Kohäsion + Komplettierungs-Vorschläge für ein Rezept —
 * »hängt der Teller zusammen, was fehlt?«. Sichtbarkeits-Guard wie ui.OPEN.
 */
class PairingsSuggestTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.pairings.SUGGEST';
    }

    public function getDescription(): string
    {
        return 'Analysiert ein Rezept über den Pairing-Anker-Graph: cohesion (hängt der Teller '
            . 'aromatisch zusammen) + suggestions (Klassiker/Signature-Komponenten, die das Gericht '
            . 'komplettieren). recipe_id vorher per foodalchemist.recipes.SEARCH ermitteln.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'recipe_id' => ['type' => 'integer'],
                'top' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20, 'default' => 8, 'description' => 'Anzahl Vorschläge'],
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
        $recipe = FoodAlchemistRecipe::visibleToTeam($team)->whereKey((int) $arguments['recipe_id'])->first();
        if ($recipe === null) {
            return ToolResult::error('Rezept nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }
        $svc = app(PairingService::class);

        return ToolResult::success([
            'recipe' => ['id' => $recipe->id, 'name' => $recipe->name],
            'cohesion' => $svc->recipeCohesion($recipe),
            'suggestions' => $svc->componentSuggestions($recipe, min(20, max(1, (int) ($arguments['top'] ?? 8)))),
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'pairing', 'kohäsion', 'rezept', 'komplettierung', 'vorschlag'],
            'read_only' => true,
            'idempotent' => true,
            'risk_level' => 'safe',
            'requires_auth' => true,
            'requires_team' => true,
            'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.pairings.GET', 'foodalchemist.recipes.SEARCH'],
            'examples' => ['Hängt Rezept 456 aromatisch zusammen?', 'Was würde Gericht 456 komplettieren?'],
        ];
    }
}
