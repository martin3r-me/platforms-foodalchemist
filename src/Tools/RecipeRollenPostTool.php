<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\SpeisenKlassenService;

/**
 * MCP-Steuerbarkeit · D3: Komponenten-Rollen eines Gerichts per KI verteilen (Held/Begleiter/…).
 * accept=false liefert nur den Vorschlag (GL-07); accept=true schreibt die Rollen an die Zutaten.
 * Nur team-eigene Rezepte.
 */
class RecipeRollenPostTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.recipe_rollen.POST';
    }

    public function getDescription(): string
    {
        return 'Verteilt die Komponenten-Rollen eines team-eigenen Rezepts per KI. accept=false = nur Vorschlag; '
            . 'accept=true übernimmt die Rollen an die Zutaten-Zeilen.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'recipe_id' => ['type' => 'integer', 'description' => 'Rezept-Id (team-eigen).'],
                'accept' => ['type' => 'boolean', 'description' => 'true übernimmt die vorgeschlagenen Rollen.'],
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

        $recipeId = (int) ($arguments['recipe_id'] ?? 0);
        $r = FoodAlchemistRecipe::visibleToTeam($team)->whereKey($recipeId)->first();
        if ($r === null) {
            return ToolResult::error('Rezept nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }
        if (! $r->isOwnedBy($team)) {
            return ToolResult::error('Nur fürs Besitzer-Team.', 'ACCESS_DENIED');
        }

        $svc = app(SpeisenKlassenService::class);
        try {
            $vorschlag = $svc->verteileRollen($team, $recipeId);
            $accepted = ($arguments['accept'] ?? false) === true;
            $n = $accepted ? $svc->acceptRollen($team, $recipeId, (array) ($vorschlag['rollen'] ?? [])) : 0;
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success([
            'recipe_id' => $recipeId,
            'accepted' => $accepted,
            'rollen' => $vorschlag['rollen'] ?? [],
            'confidence' => $vorschlag['confidence'] ?? null,
            'uebernommen' => $n,
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'gericht', 'rollen', 'ki', 'write'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'llm',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.recipe_klasse.POST', 'foodalchemist.recipe_coherence.POST'],
            'examples' => ['Verteile die Rollen von Gericht 501 (nur Vorschlag).'],
        ];
    }
}
