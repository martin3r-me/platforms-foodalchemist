<?php

namespace Platform\FoodAlchemist\Services\Preis;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\PriceService;

/**
 * Rückfall „letzter bekannter Preis“ eingestellter Artikel (Dominique 27.10.2026) für das Rezept-EK — aus RecipeRecomputeService ausgelagert (Dateigrößen-
 * Wächter, Spec 85 §9). Die Preisquelle selbst bleibt EINE Stelle: {@see PriceService::mitLetztemPreis}. Hier nur: Artikel mit Rechenpreis laden und
 * kennzeichnen, Vorrang aktiver Preise im Durchschnitt, Rezept-Zähler.
 */
class LetzterPreisService
{
    public const QUELLE_AKTIV = 'aktiv';

    public const QUELLE_LETZTER = 'letzter_eingestellt';

    private static ?bool $spalte = null;

    public function __construct(private readonly PriceService $preise) {}

    /** Artikel-Abfrage (mit Spalte aktiver_preis) um den letzten Preis ergänzen, laden und je Artikel den Rechenpreis setzen. */
    public function laden(Builder $query, ?Team $team): Collection
    {
        return $this->preise->mitLetztemPreis($query, 'foodalchemist_supplier_items.id', $team)->get()->each(fn ($la) => $this->effektiv($la));
    }

    /**
     * Hat ein eingestellter Artikel keinen aktiven Preis, rechnet das EK mit seinem letzten bekannten Preis — gekennzeichnet über `preis_quelle`
     * (aktiv | letzter_eingestellt). `aktiver_preis` trägt danach den Rechenpreis; bestellbare Artikel bleiben unberührt (letzter_preis ist dort NULL).
     */
    public function effektiv(FoodAlchemistSupplierItem $la): void
    {
        if ($la->aktiver_preis === null && $la->letzter_preis !== null) {
            $la->setAttribute('aktiver_preis', $la->letzter_preis);
            $la->setAttribute('preis_quelle', self::QUELLE_LETZTER);
        } else {
            $la->setAttribute('preis_quelle', $la->aktiver_preis !== null ? self::QUELLE_AKTIV : null);
        }
    }

    /** Vorrang: gibt es Artikel mit aktivem Preis, zählt der letzte Preis eingestellter Artikel im Durchschnitt nicht mit. */
    public function vorrang(Collection $las): Collection
    {
        return $las->contains(fn ($la) => $la->preis_quelle === self::QUELLE_AKTIV) ? $las->filter(fn ($la) => $la->preis_quelle !== self::QUELLE_LETZTER) : $las;
    }

    /** Rezept-Zähler „EK enthält n letzte Preise eingestellter Artikel“ (Spalte ek_n_letzter_preis, falls migriert). */
    public static function zaehlerSetzen(FoodAlchemistRecipe $recipe, ?int $n): void
    {
        if (self::$spalte ??= Schema::hasColumn('foodalchemist_recipes', 'ek_n_letzter_preis')) {
            $recipe->ek_n_letzter_preis = $n;
        }
    }
}
