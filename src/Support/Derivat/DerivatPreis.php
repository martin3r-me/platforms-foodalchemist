<?php

namespace Platform\FoodAlchemist\Support\Derivat;

use Platform\FoodAlchemist\Enums\EkPriceBasis;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;

/**
 * Spec 96 · EK eines Derivats ohne eigenen Lieferantenartikel (Dominique 05.11.: „Bei Saft nehmen wir den Preis der Mutter, bei
 * Abschnitten ohne Preis“). Eigene Klasse, weil RecipeRecomputeService unter dem Größen-Wächter steht (Spec 85 §4); dort hängt je
 * Preis-Kaskade (€/g, €/Stk) nur ein Aufruf, die Mutter-Preise kommen über dieselbe Kaskade zurück (Team, Lead-Pin, Ausweich).
 *
 *  - nebenprodukt: 0 €, bepreist (bewusste Entscheidung, keine Lücke). Basis „lead“: die Zahl ist entschieden, nicht gemittelt.
 *  - zweck: €/g Derivat = €/Stk Mutter ÷ g je Stück bzw. €/g Mutter ÷ Anteil; €/Stk Derivat = €/Stk Mutter (eine Frucht).
 *    Basis erbt die Mutter. Ohne Ausbeute oder Mutter-Preis → unbepreist.
 *
 * Greift nur bei einem Derivat ohne eigenen Lead-LA; eine Mutter, die selbst Derivat ist, wird nicht weiter verfolgt (eine Ebene, wie
 * die Allergen-Vererbung in GpAggregateService).
 */
final class DerivatPreis
{
    /**
     * @param  callable(FoodAlchemistGp): array{0: ?float, 1: ?EkPriceBasis}  $gramm  €/g-Kaskade für die Mutter
     * @param  callable(FoodAlchemistGp): array{0: ?float, 1: ?EkPriceBasis}  $stueck  €/Stk-Kaskade für die Mutter
     * @return array{0: ?float, 1: ?EkPriceBasis}|null  null = kein Derivat-Fall, die normale Kaskade läuft
     */
    public static function proGramm(FoodAlchemistGp $gp, callable $gramm, callable $stueck): ?array
    {
        if (($fall = self::fall($gp)) === null) {
            return null;
        }
        [$art, $mutter, $a] = $fall;
        if ($art === DerivatArt::NEBENPRODUKT) {
            return [0.0, EkPriceBasis::Lead];
        }
        if ($mutter === null || $a === null) {
            return [null, null];
        }
        if ($a['basis'] === DerivatAusbeute::PROZENT) {
            [$pg, $b] = $gramm($mutter);

            return $pg !== null ? [$pg / ($a['wert'] / 100), $b] : [null, null];
        }
        [$ps, $b] = self::mutterStueck($mutter, $gramm, $stueck);

        return $ps !== null ? [$ps / $a['wert'], $b] : [null, null];
    }

    /** @return array{0: ?float, 1: ?EkPriceBasis}|null */
    public static function proStueck(FoodAlchemistGp $gp, callable $gramm, callable $stueck): ?array
    {
        if (($fall = self::fall($gp)) === null) {
            return null;
        }
        [$art, $mutter, $a] = $fall;
        if ($art === DerivatArt::NEBENPRODUKT) {
            return [0.0, EkPriceBasis::Lead];
        }
        if ($mutter === null || $a === null || $a['basis'] !== DerivatAusbeute::G_JE_STUECK) {
            return [null, null];
        }

        return self::mutterStueck($mutter, $gramm, $stueck);
    }

    /** @return array{0: string, 1: ?FoodAlchemistGp, 2: ?array{wert: float, basis: string}}|null */
    private static function fall(FoodAlchemistGp $gp): ?array
    {
        if (! $gp->is_derivat || $gp->derivat_von_gp_id === null || $gp->lead_la_supplier_item_id !== null) {
            return null;
        }
        $mutter = DerivatArt::mutter($gp);
        $art = DerivatArt::von($gp, $mutter);
        if ($mutter !== null && $mutter->is_derivat) {
            $mutter = null;
        }

        return [$art, $mutter, DerivatAusbeute::von($gp)];
    }

    /** €/Stk der Mutter: eigener Stück-Preis, sonst €/g × Stückgewicht. */
    private static function mutterStueck(FoodAlchemistGp $mutter, callable $gramm, callable $stueck): array
    {
        [$ps, $b] = $stueck($mutter);
        if ($ps !== null) {
            return [$ps, $b];
        }
        $g = $mutter->piece_default_g !== null ? (float) $mutter->piece_default_g : 0.0;
        if ($g > 0) {
            [$pg, $b] = $gramm($mutter);
            if ($pg !== null) {
                return [$pg * $g, $b];
            }
        }

        return [null, null];
    }
}
