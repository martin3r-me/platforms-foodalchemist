<?php

namespace Platform\FoodAlchemist\Support;

/**
 * Maße eines Non-Food-Grundprodukts (Warengruppe 16): gespeichert in mm bzw. ml, gesagt und im Namen geschrieben wird, was man im Betrieb sagt —
 * Ø und L×B×H in cm mit Dezimalkomma („Ø 27 cm“, „30 x 20 x 5 cm“, „Ø 7,5 cm“), Volumen in ml bzw. ab 1000 in l („212 ml“, „1,5 l“).
 *
 * Bei mehreren Maßen gilt für die EINE Größe im Namen die Reihenfolge Ø, dann L×B×H, dann Volumen (Regelwerk §6.4: `Produkt: Eigenschaft, Größe`);
 * alle Maße bleiben als Felder erhalten.
 */
final class NonFoodMasse
{
    public const FELDER = ['diameter_mm', 'length_mm', 'width_mm', 'height_mm', 'volume_ml'];

    /** Eingabe-Schlüssel (cm bzw. ml, wie im Betrieb gesagt) → Spalte (mm bzw. ml). */
    public const EINGABE = ['diameter_cm' => 'diameter_mm', 'length_cm' => 'length_mm', 'width_cm' => 'width_mm', 'height_cm' => 'height_mm', 'volume_ml' => 'volume_ml'];

    /** mm → „27“ bzw. „7,5“ (Zentimeter, Dezimalkomma, ohne überflüssige Nullen). */
    public static function cm(float $mm): string
    {
        return self::zahl($mm / 10);
    }

    /** „27“ / „7,5“ / „7.5“ → mm; leer/ungültig → null. */
    public static function mmAusCm(mixed $eingabe): ?float
    {
        $zahl = self::lese($eingabe);

        return $zahl === null ? null : round($zahl * 10, 1);
    }

    /** „212“ / „1,5“ → ml (nur ml; Eingabe in l geht über volumenAusText). */
    public static function lese(mixed $eingabe): ?float
    {
        if ($eingabe === null || trim((string) $eingabe) === '') {
            return null;
        }
        $t = str_replace(',', '.', trim((string) $eingabe));
        if (! is_numeric($t) || (float) $t <= 0) {
            return null;
        }

        return (float) $t;
    }

    /**
     * Die eine Größe für den Namen: Ø → L×B×H → Volumen; null ohne jedes Maß.
     *
     * @param  array<string, mixed>  $mm  Schlüssel aus {@see FELDER} (mm bzw. ml)
     */
    public static function groesse(array $mm): ?string
    {
        $w = fn (string $k) => isset($mm[$k]) && is_numeric($mm[$k]) && (float) $mm[$k] > 0 ? (float) $mm[$k] : null;

        if (($d = $w('diameter_mm')) !== null) {
            return 'Ø '.self::cm($d).' cm';
        }
        $abmessungen = array_values(array_filter([$w('length_mm'), $w('width_mm'), $w('height_mm')], fn ($v) => $v !== null));
        if ($abmessungen !== []) {
            return implode(' x ', array_map(fn (float $v) => self::cm($v), $abmessungen)).' cm';
        }
        if (($v = $w('volume_ml')) !== null) {
            return self::volumen($v);
        }

        return null;
    }

    /** ml → „212 ml“; ab 1000 → „1,5 l“. */
    public static function volumen(float $ml): string
    {
        return $ml >= 1000 ? self::zahl($ml / 1000).' l' : self::zahl($ml).' ml';
    }

    private static function zahl(float $v): string
    {
        $text = rtrim(rtrim(number_format($v, 1, ',', ''), '0'), ',');

        return $text === '' ? '0' : $text;
    }
}
