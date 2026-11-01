<?php

namespace Platform\FoodAlchemist\Observers;

use Platform\FoodAlchemist\Models\FoodAlchemistItemAllergen;
use Platform\FoodAlchemist\Services\GpAggregateService;

/**
 * Ändert sich das Allergenprofil eines Lieferantenartikels (Artikel-Modal, Produktpass, Import über Eloquent), zieht die Allergen-Konfidenz der GPs, auf die
 * der Artikel zeigt, sofort nach (statt erst beim nächsten Backfill-Befehl). Die Rechnung selbst bleibt in {@see GpAggregateService::backfillAllergenKonfidenz}.
 * Massenwege über Query-Builder (Necta-Delta) feuern keine Events: dort holt der Backfill-Befehl nach.
 */
class ItemAllergenKonfidenzObserver
{
    public function saved(FoodAlchemistItemAllergen $a): void
    {
        if ($a->wasRecentlyCreated || $a->wasChanged()) {
            app(GpAggregateService::class)->konfidenzNachziehenFuerArtikel((int) $a->supplier_item_id);
        }
    }

    public function deleted(FoodAlchemistItemAllergen $a): void
    {
        app(GpAggregateService::class)->konfidenzNachziehenFuerArtikel((int) $a->supplier_item_id);
    }
}
