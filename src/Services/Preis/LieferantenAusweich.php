<?php

namespace Platform\FoodAlchemist\Services\Preis;

use Illuminate\Support\Collection;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\PriceService;

/**
 * Inaktiver oder gesperrter Lieferant (Kurator 29.10.2026) — aus RecipeRecomputeService ausgelagert (Dateigrößen-Wächter, Spec 85 §9).
 * Gemeinsame Stelle „Lead übergehen“ fürs Rezept-EK: so ein Artikel ist nie Lead und nie Preisquelle, solange ein bestellbarer Lieferant den GP
 * beliefert; gibt es sonst nichts, bleibt er Ausweich. Das Kennzeichen `lieferant_inaktiv` setzen die Artikel-Abfragen ({@see \Platform\FoodAlchemist\Models\FoodAlchemistSupplier::nichtBestellbarSql}).
 */
class LieferantenAusweich
{
    public function __construct(private readonly PriceService $preise) {}

    public function uebergehen(?FoodAlchemistSupplierItem $la): bool
    {
        return $la !== null && (bool) ($la->lieferant_inaktiv ?? false);
    }

    /** Ø-Pfad: Artikel nicht bestellbarer Lieferanten zählen nicht mit — außer es bliebe nichts. */
    public function nurBestellbare(Collection $las): Collection
    {
        $ok = $las->reject(fn ($la) => $this->uebergehen($la));

        return $ok->isNotEmpty() ? $ok->values() : $las;
    }

    /** Günstigster freier Kandidat (bepreist, nicht eingestellt) eines bestellbaren Lieferanten; null, wenn es keinen gibt. */
    public function beste(Collection $frei): ?FoodAlchemistSupplierItem
    {
        return $frei->filter(fn ($la) => ! $this->uebergehen($la))
            ->sortBy(fn ($la) => $this->preise->vergleichspreis($la, (float) $la->aktiver_preis)['value'] ?? PHP_FLOAT_MAX)->first();
    }
}
