<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeRolle;
use Platform\FoodAlchemist\Support\TellerRolle;

/**
 * Rollen eines Basisrezepts (Dominique 11.10.): eine Liste möglicher Teller-Rollen mit Quelle je Eintrag.
 * Ein Mensch (manuell) wird nie von KI, Kaskade oder Vorschlag überschrieben.
 */
class RecipeRollenService
{
    /** @return list<string> Rollen in Reihenfolge (Hauptrolle zuerst) */
    public function rollen(int $recipeId): array
    {
        return FoodAlchemistRecipeRolle::where('recipe_id', $recipeId)->orderBy('position')->orderBy('id')->pluck('rolle')->all();
    }

    /**
     * Rollen setzen. Ungültige fallen weg, Dubletten auch. `$haupt` (Kaskade: Rolle, für die das Kind im Gericht geplant
     * wurde) steht vorn und trägt eigene Quelle. Gibt es schon manuelle Rollen, bleibt alles, wie es ist.
     *
     * @param  list<mixed>  $rollen
     * @return list<string> gespeicherte Rollen
     */
    public function setze(int $recipeId, array $rollen, string $source, ?string $haupt = null, ?string $hauptSource = null): array
    {
        if (FoodAlchemistRecipeRolle::where('recipe_id', $recipeId)->where('source', 'manuell')->exists() && $source !== 'manuell') {
            return $this->rollen($recipeId);
        }
        $liste = [];
        if (($h = TellerRolle::kanonisch($haupt)) !== null) {
            $liste[$h] = $hauptSource ?? $source;
        }
        foreach ($rollen as $r) {
            if (($k = TellerRolle::kanonisch($r)) !== null && ! isset($liste[$k])) {
                $liste[$k] = $source;
            }
        }
        $teamId = (int) \Platform\FoodAlchemist\Models\FoodAlchemistRecipe::query()->whereKey($recipeId)->value('team_id');
        DB::transaction(function () use ($recipeId, $liste, $teamId) {
            // Hart löschen: die Liste wird ersetzt; ein weich gelöschter Eintrag würde den Unique-Index (recipe_id, rolle) sperren.
            FoodAlchemistRecipeRolle::withTrashed()->where('recipe_id', $recipeId)->forceDelete();
            $pos = 0;
            foreach ($liste as $rolle => $src) {
                FoodAlchemistRecipeRolle::create(['team_id' => $teamId, 'recipe_id' => $recipeId, 'rolle' => $rolle, 'position' => $pos++, 'source' => $src]);
            }
        });

        return array_keys($liste);
    }
}
