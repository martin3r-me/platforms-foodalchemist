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

    /**
     * VK netto EINES GERICHTS ohne Kontext — die Preis-Leiter selbst: Standard-Darreichung
     * (M2-Wahrheit) → `recipes.sales_net` (Legacy-Spiegel) → keine Zahl. Gibt die Quelle mit
     * aus, damit ein Aufrufer sie benennen kann statt sie zu erraten.
     *
     * **Warum hier und nicht beim Aufrufer (V-046):** dieselbe Leiter lag verstreut in
     * `KalkulationService::recipeHk`, im Wirtschafts-Block des Kandidaten-Pools und (in halber
     * Form) direkt als Rezept-Spalte im Pool-Filter. Solange der harte Preisfilter die eine
     * Zahl liest und die Zielfunktion die andere, ist ein Optimum nicht erklärbar.
     *
     * **Batch-fähig ohne zweiten Weg:** ist `standardPresentation` bereits eager geladen, wird
     * die geladene Relation genommen (keine Query je Gericht); sonst läuft die volle Leiter über
     * {@see standardFuer()} inklusive dessen Fallback „erste Darreichung nach id".
     *
     * ⚠️ **Bekannte Rest-Divergenz (V-059, NICHT in diesem Zug behoben):** genau dieser Fallback
     * unterscheidet die beiden Wege. Hat ein Gericht Darreichungen, aber keine mit `is_standard`,
     * liefert der geladene Weg `null` (⇒ Legacy-Spalte), der ungeladene die erste Darreichung.
     * Im Bestand ist der Fall leer (0 von 31 Gerichten mit Darreichung, Dev-MySQL 2026-07-27);
     * ihn aufzulösen heißt, entweder alle Darreichungen eager zu laden oder den Fallback
     * fallenzulassen — eine Auswahl-Entscheidung, die Dominique gehört.
     *
     * @return array{vk: ?float, quelle: 'darreichung'|'legacy'|'keine'}
     */
    public function vkNettoMitQuelle(FoodAlchemistRecipe $recipe): array
    {
        $standard = $recipe->relationLoaded('standardPresentation')
            ? $recipe->standardPresentation
            : $this->standardFuer($recipe);

        if ($standard?->sales_net !== null) {
            return ['vk' => (float) $standard->sales_net, 'quelle' => 'darreichung'];
        }

        if ($recipe->sales_net !== null) {
            return ['vk' => (float) $recipe->sales_net, 'quelle' => 'legacy'];
        }

        return ['vk' => null, 'quelle' => 'keine'];
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
