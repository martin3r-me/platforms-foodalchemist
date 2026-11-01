<?php

namespace Platform\FoodAlchemist\Support;

use Platform\FoodAlchemist\Models\FoodAlchemistItemAllergen;
use Platform\FoodAlchemist\Models\FoodAlchemistItemDeclaration;

/**
 * Spec 82 §2 — „Ein GP ist allergen- und zusatzstoffgleich“ (Dominique 13.10.).
 *
 * Vergleichsregel, an EINER Stelle für Verknüpfungs-Guard und Bericht:
 *  - Allergene: Es zählt nur „enthalten“. `spuren` und `nicht_enthalten` gelten beide als „nicht enthalten“,
 *    Spuren zählen NICHT mit.
 *  - Fehlende Daten (NULL, `unbekannt`, fehlende Zeile) sind „unbekannt“, nicht „gesperrt“ und nie ein Widerspruch.
 *  - Zusatzstoffe (GL-09: 0 = k. A., 1 = nein, 3 = ja): Nur 1 gegen 3 ist ein Widerspruch.
 *  - Reine Verpackungsangaben ({@see VERPACKUNG}) sind kein Produktunterschied und zählen nie mit (Dominique 01.11.: Serrano-Schinken 11593 → 11592
 *    wurde wegen „unter Schutzatmosphäre verpackt“ abgelehnt). Das gilt für Guard UND Bericht ({@see zaehlt}).
 */
final class DeklarationsProfil
{
    /**
     * Deklarations-Felder, die nur die VERPACKUNG betreffen, nicht das Produkt: ignorieren beim Vergleich. Bewusst NICHT hier (bleiben streng): die
     * Warnhinweise Phenylalanin, abführend und Aufmerksamkeit bei Kindern — sie gehören zu Süßungsmitteln/Koffein und betreffen Gesundheit (PKU); ein
     * Artikel mit und einer ohne solchen Hinweis ist ein echter Unterschied. Neue reine Verpackungsfelder hier eintragen.
     *
     * @var list<string>
     */
    public const VERPACKUNG = ['packaged_modified_atmosphere'];

    /** Zählt dieses Zusatzstoff-Feld beim Profil-Vergleich mit? (Verpackungsangaben nein) */
    public static function zaehlt(string $feld): bool
    {
        return ! in_array($feld, self::VERPACKUNG, true);
    }

    /** Wert des Allergen-Felds bekannt? (NULL und `unbekannt` sind es nicht) */
    public static function allergenBekannt(mixed $wert): bool
    {
        return $wert !== null && $wert !== '' && $wert !== 'unbekannt';
    }

    /** Hat der Artikel gar keine verwertbare Allergenangabe (keine Zeile oder alle 14 Felder unbekannt)? */
    public static function istUnbekannt(?FoodAlchemistItemAllergen $allergene): bool
    {
        if ($allergene === null) {
            return true;
        }
        foreach (array_keys(FoodAlchemistItemAllergen::ALLERGENE) as $feld) {
            if (self::allergenBekannt($allergene->{"allergen_{$feld}"})) {
                return false;
            }
        }

        return true;
    }

    /**
     * Abweichungen zwischen zwei Artikelprofilen (Labels; leer = einheitlich oder nicht belegbar).
     *
     * @return list<string>
     */
    public static function abweichungen(
        ?FoodAlchemistItemAllergen $allergeneA,
        ?FoodAlchemistItemDeclaration $stoffeA,
        ?FoodAlchemistItemAllergen $allergeneB,
        ?FoodAlchemistItemDeclaration $stoffeB,
    ): array {
        $out = [];
        foreach (FoodAlchemistItemAllergen::ALLERGENE as $feld => $label) {
            $a = $allergeneA?->{"allergen_{$feld}"};
            $b = $allergeneB?->{"allergen_{$feld}"};
            if (self::allergenBekannt($a) && self::allergenBekannt($b) && ($a === 'enthalten') !== ($b === 'enthalten')) {
                $out[] = 'Allergen „' . $label . '“';
            }
        }
        foreach (FoodAlchemistItemDeclaration::STOFFE as $feld => $label) {
            if (! self::zaehlt($feld)) {
                continue;
            }
            $a = (int) ($stoffeA?->{$feld});
            $b = (int) ($stoffeB?->{$feld});
            if (in_array($a, [1, 3], true) && in_array($b, [1, 3], true) && $a !== $b) {
                $out[] = 'Zusatzstoff „' . $label . '“';
            }
        }

        return $out;
    }
}
