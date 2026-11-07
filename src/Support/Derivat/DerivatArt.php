<?php

namespace Platform\FoodAlchemist\Support\Derivat;

use Platform\FoodAlchemist\Models\FoodAlchemistGp;

/**
 * Spec 96 · Zwei Arten von Derivat (Dominique 05.11.):
 *  - nebenprodukt: fällt beim Verarbeiten der Mutter ohnehin an (Abschnitte, Parüren, Knochen, Karkassen, Stiele, Gemüseschalen).
 *    Kein Einkauf, EK 0 € („bei Abschnitten ohne Preis“), Abschnitte werden gesammelt — keine Bilanz Bedarf gegen Anfall.
 *  - zweck: die Mutter wird gezielt dafür gekauft (Saft, Abrieb, Zeste; Schale bei Obst). EK und Bedarf laufen über die Mutter ÷ Ausbeute.
 *
 * Die Spalte `derivat_art` gewinnt; ohne Wert wird die Art aus der Derivat-Form im Namen abgeleitet (Kurator 06.11.: Standard-Arten wie
 * vorgeschlagen). Unbekannte Form → nebenprodukt (klassischer §11.2-Fall).
 */
final class DerivatArt
{
    public const NEBENPRODUKT = 'nebenprodukt';

    public const ZWECK = 'zweck';

    public const ARTEN = [self::NEBENPRODUKT, self::ZWECK];

    /** Formen, für die die Mutter gezielt gekauft wird — immer. */
    private const ZWECK_IMMER = ['saft', 'abrieb', 'zeste', 'zesten'];

    /** Formen, die nur bei Obst (WG 02) Zweck sind; bei Gemüse ist die Schale Abfall/Fond-Ware. */
    private const ZWECK_OBST = ['schale', 'schalen'];

    /** null = kein Derivat. */
    public static function von(FoodAlchemistGp $gp, ?FoodAlchemistGp $mutter = null): ?string
    {
        if (! $gp->is_derivat) {
            return null;
        }
        $spalte = (string) ($gp->derivat_art ?? '');
        if (in_array($spalte, self::ARTEN, true)) {
            return $spalte;
        }

        return self::ausName((string) $gp->name, $mutter?->commodity_group_code ?? self::mutter($gp)?->commodity_group_code);
    }

    public static function ausName(string $name, ?string $mutterWg): string
    {
        $form = str_contains($name, ':') ? (string) substr($name, (int) strpos($name, ':') + 1) : $name;
        $form = strtr(mb_strtolower($form), ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
        $tokens = preg_split('/[^a-z]+/', $form, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($tokens as $t) {
            if (in_array($t, self::ZWECK_IMMER, true) || str_ends_with($t, 'saft') || str_ends_with($t, 'abrieb')) {
                return self::ZWECK;
            }
            if ($mutterWg === '02' && in_array($t, self::ZWECK_OBST, true)) {
                return self::ZWECK;
            }
        }

        return self::NEBENPRODUKT;
    }

    public static function mutter(FoodAlchemistGp $gp): ?FoodAlchemistGp
    {
        if (! $gp->is_derivat || $gp->derivat_von_gp_id === null) {
            return null;
        }

        return $gp->relationLoaded('derivedFrom') ? $gp->derivedFrom : FoodAlchemistGp::find((int) $gp->derivat_von_gp_id);
    }
}
