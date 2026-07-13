<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistConceptSlot;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use RuntimeException;

/**
 * R4.4 — Konzept-lokale Slot-Variante: „Tauschen" im Concepter mutiert NIE das
 * global geteilte VK-Gericht (es hängt in N Konzepten/Foodbooks), sondern
 * dupliziert es voll (replicate: alle VK-/Allergen-/EK-Felder + Zutaten +
 * Darreichungen) und hängt die Kopie an GENAU DIESEN Slot.
 *
 * - Marker: slot.variant_source_recipe_id = Original (→ „variiert"-Badge + Rücksetzen)
 * - Katalog-Hygiene: recipes.variant_source_recipe_id ≠ NULL wird in Browser/Pickern
 *   ausgefiltert — die Variante lebt nur im Konzept.
 * - EK/Marge rechnen automatisch gegen die Variante (der Slot referenziert sie).
 */
class ConceptVariantService
{
    public function __construct(
        private ComponentEquivalentService $equiv,
        private RecipeRecomputeService $recompute,
    ) {}

    private function slotMitGuard(Team $team, int $slotId): FoodAlchemistConceptSlot
    {
        $slot = FoodAlchemistConceptSlot::with('concept')->findOrFail($slotId);
        if ($slot->concept === null || ! $slot->concept->isOwnedBy($team)) {
            throw new RuntimeException('Geerbtes/fremdes Konzept — Slot-Varianten setzt nur das Besitzer-Team (D1).');
        }

        return $slot;
    }

    /**
     * Variante für den Slot sicherstellen (idempotent): existiert schon eine,
     * kommt der Slot unverändert zurück; sonst wird das Gericht voll dupliziert
     * und der Slot umgehängt. Das Quell-Gericht bleibt unangetastet.
     */
    public function varianteFuerSlot(Team $team, int $slotId): FoodAlchemistConceptSlot
    {
        $slot = $this->slotMitGuard($team, $slotId);
        if ($slot->variant_source_recipe_id !== null) {
            return $slot; // schon variiert
        }
        if ($slot->sales_recipe_id === null) {
            throw new RuntimeException('Slot trägt kein fest gesetztes Gericht — nur Gericht-Slots können variiert werden.');
        }

        $original = FoodAlchemistRecipe::visibleToTeam($team)
            ->with(['ingredients', 'darreichungen'])
            ->findOrFail((int) $slot->sales_recipe_id);

        return DB::transaction(function () use ($team, $slot, $original) {
            /** @var FoodAlchemistRecipe $kopie */
            $kopie = $original->replicate(['uuid', 'legacy_id']);
            $kopie->team_id = $team->id;
            $kopie->uuid = null; // HasUuidV7 vergibt neu
            $kopie->recipe_key = $original->recipe_key . '-var-slot' . $slot->id;
            $kopie->name = $original->name . ' · Variante (' . ($slot->concept->name ?? 'Konzept') . ')';
            $kopie->status = 'draft';
            $kopie->variant_source_recipe_id = $original->id;
            $kopie->save();

            foreach ($original->ingredients as $z) {
                $zk = $z->replicate(['uuid']);
                $zk->uuid = null;
                $zk->recipe_id = $kopie->id;
                $zk->team_id = $team->id;
                $zk->save();
            }
            foreach ($original->darreichungen as $d) {
                $dk = $d->replicate(['uuid']);
                $dk->uuid = null;
                $dk->recipe_id = $kopie->id;
                $dk->team_id = $team->id;
                $dk->save();
            }

            $this->recompute->recomputeAndPropagate($kopie->id);

            $slot->update([
                'sales_recipe_id' => $kopie->id,
                'variant_source_recipe_id' => $original->id,
            ]);

            return $slot->refresh();
        });
    }

    /**
     * Konzept-lokaler Äquivalenz-Tausch: Variante sicherstellen, die Zutat auf der
     * VARIANTE tauschen (Position-Mapping, wenn die übergebene Zutat noch zum
     * Original gehört — read-first-UI zeigt vor der ersten Variierung Original-Zeilen).
     */
    public function tauscheZutatKonzeptLokal(Team $team, int $slotId, int $ingredientId): FoodAlchemistConceptSlot
    {
        $slot = $this->varianteFuerSlot($team, $slotId);
        $variante = FoodAlchemistRecipe::with('ingredients')->findOrFail((int) $slot->sales_recipe_id);

        $zutat = $variante->ingredients->firstWhere('id', $ingredientId);
        if ($zutat === null) {
            // Zutat-ID stammt vom Original → über die Position auf die Varianten-Zeile mappen
            $originalZutat = \Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient::findOrFail($ingredientId);
            if ((int) $originalZutat->recipe_id !== (int) $slot->variant_source_recipe_id) {
                throw new RuntimeException('Zutat gehört weder zur Variante noch zum Quell-Gericht dieses Slots.');
            }
            $zutat = $variante->ingredients->firstWhere('position', $originalZutat->position);
            if ($zutat === null) {
                throw new RuntimeException('Varianten-Zutat zur Position nicht gefunden — Variante zurücksetzen und neu variieren.');
            }
        }
        if ((bool) $zutat->swap_locked) {
            throw new RuntimeException('Zutat ist swap-gesperrt (bewusst gewählte Realisierung) — erst entsperren.');
        }

        $this->equiv->tauscheZutat($team, (int) $zutat->id);

        return $slot->refresh();
    }

    /** Rücksetzen: Original wieder in den Slot, Variante löschen (konzept-lokal, hängt nirgends sonst). */
    public function zuruecksetzen(Team $team, int $slotId): FoodAlchemistConceptSlot
    {
        $slot = $this->slotMitGuard($team, $slotId);
        if ($slot->variant_source_recipe_id === null) {
            return $slot;
        }

        return DB::transaction(function () use ($team, $slot) {
            $varianteId = (int) $slot->sales_recipe_id;
            $slot->update([
                'sales_recipe_id' => $slot->variant_source_recipe_id,
                'variant_source_recipe_id' => null,
            ]);

            // Variante nur löschen, wenn kein anderer Slot sie noch referenziert (Duplikat-Konzepte).
            $nochGenutzt = FoodAlchemistConceptSlot::where('sales_recipe_id', $varianteId)->exists();
            if (! $nochGenutzt) {
                $variante = FoodAlchemistRecipe::whereKey($varianteId)->whereNotNull('variant_source_recipe_id')->first();
                if ($variante !== null && $variante->isOwnedBy($team)) {
                    $variante->ingredients()->delete();
                    $variante->darreichungen()->delete();
                    $variante->delete();
                }
            }

            return $slot->refresh();
        });
    }
}
