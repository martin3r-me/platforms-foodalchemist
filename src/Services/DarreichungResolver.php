<?php

namespace Platform\FoodAlchemist\Services;

use Platform\FoodAlchemist\Models\FoodAlchemistConceptSlot;
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
 * Stateless. Preis-Wahrheit liegt an der Darreichung; recipes.vk_netto ist
 * nur noch Anzeige-Spiegel der Standard-Form (Import füllt ihn fill-only).
 */
class DarreichungResolver
{
    public function fuerSlot(FoodAlchemistConceptSlot $slot): ?FoodAlchemistRecipeDarreichung
    {
        if ($slot->darreichung_id !== null && $slot->darreichung !== null) {
            return $slot->darreichung;
        }

        // Phase 4: hier Konzept-Servierform → passende Darreichung auflösen,
        // sobald concepts eine servierform_id trägt.

        return $slot->gericht !== null ? $this->standardFuer($slot->gericht) : null;
    }

    public function fuerPaketGericht(FoodAlchemistPaketGericht $pg): ?FoodAlchemistRecipeDarreichung
    {
        if ($pg->darreichung_id !== null && $pg->darreichung !== null) {
            return $pg->darreichung;
        }

        return $pg->gericht !== null ? $this->standardFuer($pg->gericht) : null;
    }

    public function standardFuer(FoodAlchemistRecipe $recipe): ?FoodAlchemistRecipeDarreichung
    {
        return $recipe->standardDarreichung ?? $recipe->darreichungen()->orderBy('id')->first();
    }

    /** VK netto im Kontext (Darreichung zuerst, Legacy-Spalte als Fallback). */
    public function vkNettoFuerSlot(FoodAlchemistConceptSlot $slot): ?float
    {
        $darreichung = $this->fuerSlot($slot);

        if ($darreichung?->vk_netto !== null) {
            return (float) $darreichung->vk_netto;
        }

        return $slot->gericht?->vk_netto !== null ? (float) $slot->gericht->vk_netto : null;
    }
}
