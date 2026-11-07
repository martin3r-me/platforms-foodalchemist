<?php

namespace Platform\FoodAlchemist\Support\Derivat;

use Platform\FoodAlchemist\Models\FoodAlchemistGp;

/**
 * Spec 96 · Ausbeute eines Derivats aus seiner Mutter.
 *  - g_je_stueck: Gramm Derivat aus EINEM Stück Mutter (Limette ≈ 30 g Saft). Ein „Stück“ Derivat ist dann der Ertrag einer Frucht.
 *  - prozent: Anteil der Mutter-Masse (Parüren 15 %).
 * Ohne Wert gilt das Zweck-Derivat als unbepreist und unbestellbar (Reife-Lücke „Ausbeute fehlt“) — nie ein geratener Wert.
 */
final class DerivatAusbeute
{
    public const G_JE_STUECK = 'g_je_stueck';

    public const PROZENT = 'prozent';

    public const BASEN = [self::G_JE_STUECK, self::PROZENT];

    public const QUELLEN = ['manual', 'ki', 'regelwerk'];

    /** @return array{wert: float, basis: string}|null */
    public static function von(FoodAlchemistGp $gp): ?array
    {
        $wert = $gp->derivat_ausbeute_wert !== null ? (float) $gp->derivat_ausbeute_wert : 0.0;
        $basis = (string) ($gp->derivat_ausbeute_basis ?? '');
        if ($wert <= 0 || ! in_array($basis, self::BASEN, true) || ($basis === self::PROZENT && $wert > 100)) {
            return null;
        }

        return ['wert' => $wert, 'basis' => $basis];
    }

    /** Ist das GP ein Zweck-Derivat, dem die Ausbeute fehlt? (Reife-Lücke, KI-Nachholen) */
    public static function fehlt(FoodAlchemistGp $gp): bool
    {
        return $gp->is_derivat && $gp->derivat_von_gp_id !== null && DerivatArt::von($gp) === DerivatArt::ZWECK && self::von($gp) === null;
    }

    /**
     * Gramm Mutter für eine Derivat-Menge. g_je_stueck rundet auf ganze Stück auf (man presst keine halbe Limette) und braucht das
     * Stückgewicht der Mutter; ohne es → null (ehrlich offen).
     *
     * @return array{menge_g: float, stueck: ?int}|null
     */
    public static function mutterMenge(FoodAlchemistGp $derivat, FoodAlchemistGp $mutter, float $derivatG): ?array
    {
        $a = self::von($derivat);
        if ($a === null || $derivatG <= 0) {
            return null;
        }
        if ($a['basis'] === self::PROZENT) {
            return ['menge_g' => $derivatG / ($a['wert'] / 100), 'stueck' => null];
        }
        $stueckG = $mutter->piece_default_g !== null ? (float) $mutter->piece_default_g : 0.0;
        if ($stueckG <= 0) {
            return null;
        }
        $stueck = (int) ceil($derivatG / $a['wert'] - 1e-9);

        return ['menge_g' => $stueck * $stueckG, 'stueck' => $stueck];
    }
}
