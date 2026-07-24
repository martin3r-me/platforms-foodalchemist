<?php

namespace Platform\FoodAlchemist\Services;

use Platform\FoodAlchemist\Models\FoodAlchemistConceptSlot;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookBlock;
use Platform\FoodAlchemist\Models\FoodAlchemistPaketGericht;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;

/**
 * Löst auf, WELCHE Darreichung eines Gerichts in einem Kontext gilt
 * (Umbau-Spec Darreichungen §2). Auflösungsreihenfolge:
 *
 *   1. explizit am Slot/Paket-Gericht gesetzte Darreichung
 *   2. (Phase 4, vorbereitet) Darreichung passend zur Servierform des Konzepts
 *   3. Standard-Darreichung des Gerichts
 *
 * Stateless. Preis-Wahrheit liegt an der Darreichung; recipes.sales_net ist
 * nur noch Anzeige-Spiegel der Standard-Form (Import füllt ihn fill-only).
 */
class DarreichungResolver
{
    public function fuerSlot(FoodAlchemistConceptSlot $slot): ?FoodAlchemistRecipeDarreichung
    {
        if ($slot->presentation_id !== null && $slot->presentation !== null) {
            return $slot->presentation;
        }

        if ($slot->dish === null) {
            return null;
        }

        // Phase 4: Servierform des Konzepts → passende Darreichung des Gerichts
        $konzeptServierformId = $slot->concept?->serving_form_id;
        if ($konzeptServierformId !== null) {
            $passend = $slot->dish->presentations()
                ->where('serving_form_id', $konzeptServierformId)
                ->first();
            if ($passend !== null) {
                return $passend;
            }
        }

        return $this->standardFuer($slot->dish);
    }

    public function fuerPaketGericht(FoodAlchemistPaketGericht $pg): ?FoodAlchemistRecipeDarreichung
    {
        if ($pg->presentation_id !== null && $pg->presentation !== null) {
            return $pg->presentation;
        }

        return $pg->dish !== null ? $this->standardFuer($pg->dish) : null;
    }

    /**
     * Darreichung eines `recipe_ref`-Foodbook-Blocks (Spec 19, M5/E7.1 — Einzel-Gericht-Pfad).
     * Auflösung analog {@see fuerSlot()}:
     *   1. block.presentation_id (expliziter Override) →
     *   2. Gericht-Darreichung zur übergebenen Servierform (Kapitel-/Foodbook-Servierform,
     *      vom Aufrufer aus der Dimensions-Kaskade gereicht) →
     *   3. standardFuer().
     *
     * `$servingFormId === null` UND keine block-Darreichung ⇒ standardFuer(), dessen sales_net
     * per Invariante der Anzeige-Spiegel `recipes.sales_net` ist → bit-identisch zu heute
     * (keine sichtbaren Preisänderungen im Bestand). dish === null ⇒ null.
     */
    public function fuerBlock(FoodAlchemistFoodbookBlock $block, ?int $servingFormId = null): ?FoodAlchemistRecipeDarreichung
    {
        if ($block->presentation_id !== null && $block->presentation !== null) {
            return $block->presentation;
        }

        if ($block->dish === null) {
            return null;
        }

        if ($servingFormId !== null) {
            $passend = $block->dish->presentations()
                ->where('serving_form_id', $servingFormId)
                ->first();
            if ($passend !== null) {
                return $passend;
            }
        }

        return $this->standardFuer($block->dish);
    }

    public function standardFuer(FoodAlchemistRecipe $recipe): ?FoodAlchemistRecipeDarreichung
    {
        return $recipe->standardPresentation ?? $recipe->presentations()->orderBy('id')->first();
    }

    /** VK netto im Kontext (Darreichung zuerst, Legacy-Spalte als Fallback). */
    public function vkNettoFuerSlot(FoodAlchemistConceptSlot $slot): ?float
    {
        $darreichung = $this->fuerSlot($slot);

        if ($darreichung?->sales_net !== null) {
            return (float) $darreichung->sales_net;
        }

        return $slot->dish?->sales_net !== null ? (float) $slot->dish->sales_net : null;
    }
}
