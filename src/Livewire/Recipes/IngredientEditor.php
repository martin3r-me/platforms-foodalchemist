<?php

namespace Platform\FoodAlchemist\Livewire\Recipes;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\RecipeRecomputeService;
use Platform\FoodAlchemist\Services\RecipeService;

/**
 * M4-07/08 / P-8: Zutaten-Editor — Alpine-first: Tippen/Reorder/Add laufen
 * komplett im Client (rows-Array), Zeilen-EK + Summen live (ek_pro_g vom
 * Server vorgerechnet, T3-Quelle); Server-Sync erst bei „Speichern"
 * (RecipeService::syncIngredients = EINE Transaktion + EIN Recompute).
 *
 * Ehrliche Grenze (P-8-Abweichungstabelle): Client-EK ist eine Live-Näherung
 * über default_in_g/ml — count-Einheiten + Brücken rechnet erst der Save-
 * Recompute (Zeile zeigt dann den Server-Wert).
 */
class IngredientEditor extends Component
{
    public ?int $recipeId = null;

    public ?string $fehler = null;

    #[On('zutaten-editor.oeffnen')]
    public function oeffnen(int $id): void
    {
        $this->fehler = null;
        $this->recipeId = $id;
        $this->dispatch('modal.open', name: 'zutaten-editor');
    }

    /** @param array<int, array> $zeilen kompletter Client-Stand (Reihenfolge = Position) */
    public function speichern(array $zeilen): void
    {
        $this->fehler = null;
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || $this->recipeId === null) {
            return;
        }

        try {
            app(RecipeService::class)->syncIngredients($team, $this->recipeId, $zeilen);
            $this->dispatch('modal.close', name: 'zutaten-editor');
            $this->dispatch('recipe-gespeichert');
            $this->dispatch('recipe-selected', id: $this->recipeId);
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    /**
     * M4-11: Garverlust-Vorschläge via Gateway (GL-07: nichts persistiert —
     * Alpine merged in die rows, geschrieben wird beim Save mit quelle=ki).
     *
     * @param array<int, string> $zutaten [index => raw_text]
     * @return array{verluste: array<int, float>, confidence: float}
     */
    public function garverlustVorschlag(array $zutaten): array
    {
        $vorschlag = app(\Platform\FoodAlchemist\Services\Ai\AiGatewayService::class)
            ->propose('recipe.garverlust', ['zutaten' => $zutaten, 'verluste' => new \stdClass]);
        $verluste = [];
        foreach (($vorschlag->werte['verluste'] ?? []) as $idx => $pct) {
            if (is_numeric($pct)) {
                $verluste[(int) $idx] = max(0.0, min(60.0, (float) $pct));  // Clamp lt. Prompt-Spez
            }
        }

        return ['verluste' => $verluste, 'confidence' => max(0.0, min(1.0, $vorschlag->confidence))];
    }

    /** GP-/Sub-Picker (M4-08): liefert Auto-Fill-Daten inkl. ek_pro_g. */
    public function sucheZiel(string $suche): array
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || $this->recipeId === null) {
            return [];
        }

        return app(RecipeService::class)->sucheZutatenZiel($team, $suche, $this->recipeId);
    }

    public function render(RecipeRecomputeService $recompute)
    {
        $team = Auth::user()?->currentTeamRelation;
        // M6-04 / D-6 §6: sicht-neutral laden — EIN Editor für Basis- UND VK-Sicht
        $rezept = $team !== null && $this->recipeId !== null
            ? app(RecipeService::class)->detailAnySicht($team, $this->recipeId)
            : null;

        $zeilen = [];
        if ($rezept !== null) {
            foreach ($rezept->ingredients as $z) {
                $ekProG = null;
                if ($z->gp !== null) {
                    $ekProG = $recompute->preisProGrammPublic($z->gp);
                } elseif ($z->referencedRecipe?->ek_per_kg_eur !== null) {
                    $ekProG = ((float) $z->referencedRecipe->ek_per_kg_eur) / 1000;
                }
                $zeilen[] = [
                    'id' => $z->id,
                    'gp_id' => $z->gp_id,
                    'referenced_recipe_id' => $z->referenced_recipe_id,
                    'ziel_name' => $z->gp?->name ?? ($z->referencedRecipe !== null ? '↳ ' . $z->referencedRecipe->name : null),
                    'raw_text' => $z->raw_text,
                    'display_name' => $z->display_name,
                    'menge' => (float) $z->menge,
                    'menge_max' => $z->menge_max !== null ? (float) $z->menge_max : null,
                    'einheit_vocab_id' => $z->einheit_vocab_id,
                    'garverlust_pct' => $z->garverlust_pct !== null ? (float) $z->garverlust_pct : null,
                    'putzverlust_pct' => $z->putzverlust_pct !== null ? (float) $z->putzverlust_pct : null,
                    'is_optional' => (bool) $z->is_optional,
                    'note' => $z->note,
                    'rolle' => $z->rolle,
                    'ist_wertgebend' => (bool) $z->ist_wertgebend,
                    'lineage' => $z->match_method?->value,
                    'ek_pro_g' => $ekProG,
                ];
            }
        }

        $einheiten = $team !== null
            ? FoodAlchemistVocabEinheit::visibleToTeam($team)->where('is_inactive', false)
                ->orderBy('sort_order')->get(['id', 'slug', 'display_de', 'dimension', 'default_in_g', 'default_in_ml'])
            : collect();

        return view('foodalchemist::livewire.recipes.ingredient-editor', [
            'rezept' => $rezept,
            'zeilenJson' => $zeilen,
            'einheiten' => $einheiten,
        ]);
    }
}
