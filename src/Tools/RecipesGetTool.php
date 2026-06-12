<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\RecipeService;

/** M8-01: Basisrezept-Detail inkl. Zutaten + GL-02-Aggregate. */
class RecipesGetTool extends FoodAlchemistTool implements ToolContract
{
    public function getName(): string
    {
        return 'foodalchemist.recipes.GET';
    }

    public function getDescription(): string
    {
        return 'Liefert ein Basisrezept im Detail: Kopf, Zutaten (mit GP-/Sub-Rezept-Verknüpfung), '
            . 'Yield/EK-Aggregate (GL-02) und Allergen-Konfidenz.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['id' => ['type' => 'integer']],
            'required' => ['id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $r = app(RecipeService::class)->detail($team, (int) $arguments['id']);
        if ($r === null) {
            return ToolResult::error('Rezept nicht sichtbar/vorhanden (Basis-Sicht).', 'NOT_FOUND');
        }

        return ToolResult::success([
            'id' => $r->id, 'name' => $r->name, 'status' => $r->status->value,
            'beschreibung' => $r->beschreibung, 'yield_kg' => $r->yield_kg,
            'ek_total_eur' => $r->ek_total_eur, 'ek_per_kg_eur' => $r->ek_per_kg_eur,
            'allergene_konfidenz' => $r->allergene_konfidenz,
            'zutaten' => $r->ingredients->map(fn ($z) => [
                'menge' => $z->menge, 'einheit' => $z->einheit?->slug,
                'name' => $z->referencedRecipe?->name ?? $z->gp?->name ?? $z->display_name,
                'gp_id' => $z->gp_id, 'sub_recipe_id' => $z->referenced_recipe_id,
            ])->all(),
        ]);
    }
}
