<?php

namespace Platform\FoodAlchemist\Support;

use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;

/**
 * Bedarfs-Hochrechnung am Basisrezept: EINE Rechnung für das Druck-Dokument (ReportExportService,
 * „Bedarf hochrechnen") und die Ansicht (Rezept-Detail am Computer und am Handy, #1003).
 *
 * Faktor = Ziel ÷ Ansatz. Ansatz = Kalkulations-Ausbeute (A-3: gepflegte vor errechneter), dieselbe
 * Zahl wie Kachel „Ausbeute" und Recompute. Oder „nach Zutat": Faktor = vorhandene Menge ÷ Rezeptmenge.
 * Ohne Ansatz gibt es keinen Faktor, nur einen Hinweis — nichts wird geschätzt. Reine Rechnung,
 * speichert nichts.
 */
final class Hochrechnung
{
    public const OHNE_AUSBEUTE = 'Keine Ausbeute hinterlegt — ohne Ansatz-Menge lässt sich der Bedarf nicht hochrechnen.';

    public const OHNE_ZUTATMENGE = 'Für diese Zutat ist keine Menge hinterlegt — danach lässt sich nicht hochrechnen.';

    /** Ansatz in kg (A-3: COALESCE(yield_kg_manual, yield_kg)); null ohne kg-Ausbeute. */
    public static function ansatzKg(FoodAlchemistRecipe $recipe): ?float
    {
        $kg = $recipe->yield_kg_manual ?? $recipe->yield_kg;

        return $kg !== null && (float) $kg > 0 ? (float) $kg : null;
    }

    /**
     * Faktor aus Ziel und Basis (Ziel-kg ÷ Ansatz-kg bzw. vorhanden ÷ Rezeptmenge).
     * Ohne Ziel kein Faktor und kein Hinweis; ohne Basis kein Faktor, aber der Hinweis.
     *
     * @return array{faktor: ?float, hinweis: ?string}
     */
    public static function faktor(float $ziel, ?float $basis, string $hinweisOhneBasis = self::OHNE_AUSBEUTE): array
    {
        if ($ziel <= 0) {
            return ['faktor' => null, 'hinweis' => null];
        }
        if ($basis === null || $basis <= 0) {
            return ['faktor' => null, 'hinweis' => $hinweisOhneBasis];
        }

        return ['faktor' => $ziel / $basis, 'hinweis' => null];
    }

    /** Eingabe „2,5" / „2.5" → 2.5; leer, Unsinn oder negativ → 0. */
    public static function zahl(mixed $eingabe): float
    {
        return max(0.0, (float) str_replace(',', '.', trim((string) $eingabe)));
    }

    /** Menge × Faktor. Ohne Faktor unverändert, NULL bleibt NULL — eine fehlende Zahl wird durch Skalieren nicht besser. */
    public static function skaliere(mixed $wert, ?float $faktor): mixed
    {
        if ($faktor === null || $wert === null) {
            return $wert;
        }

        return round((float) $wert * $faktor, 4);
    }

    /** „1.234,5 Kilogramm" — Menge mit ausgeschriebener Einheit (display_de, sonst slug). */
    public static function mengeText(mixed $quantity, ?object $unit): ?string
    {
        if ($quantity === null || $quantity === '') {
            return null;
        }

        return rtrim(rtrim(number_format((float) $quantity, 3, ',', '.'), '0'), ',')
            . ($unit ? ' ' . ($unit->display_de ?? $unit->slug) : '');
    }

    /**
     * Kompakte Mengen (Dominique 2026-10-05): „0,015 Kilogramm" → „15 g", „0,25 Liter" → „250 ml".
     * Greift NUR bei eindeutig deutsch formatierten Zahlen (1.234,5) mit genau dieser Einheit —
     * alles andere (Stück, Bund, „1.5 Kilogramm", Freitext) bleibt unverändert. Reine Anzeige.
     */
    public static function kurz(mixed $text): string
    {
        $m = (string) $text;
        if (! preg_match('/^\s*(\d{1,3}(?:\.\d{3})*(?:,\d+)?)\s*(Kilogramm|Gramm|Liter|Milliliter)\s*$/u', $m, $t)) {
            return $m;
        }
        $wert = (float) str_replace(['.', ','], ['', '.'], $t[1]);
        $fmt = fn ($v) => rtrim(rtrim(number_format($v, 3, ',', '.'), '0'), ',');

        return match ($t[2]) {
            'Kilogramm' => $wert < 1 ? $fmt($wert * 1000) . ' g' : $fmt($wert) . ' kg',
            'Liter' => $wert < 1 ? $fmt($wert * 1000) . ' ml' : $fmt($wert) . ' l',
            'Gramm' => $fmt($wert) . ' g',
            'Milliliter' => $fmt($wert) . ' ml',
        };
    }

    /** Hochgerechnete Menge als kurzer Text, wie im Dokument („520 g", „1,04 kg", „3 Stück"). */
    public static function mengeKurz(mixed $quantity, ?object $unit, ?float $faktor): ?string
    {
        $text = self::mengeText(self::skaliere($quantity, $faktor), $unit);

        return $text === null ? null : self::kurz($text);
    }

    /**
     * Wert zum Speichern („Als neuen Ansatz übernehmen", Kurator 30.10.): g/ml auf ganze (unter 10 eine Stelle,
     * damit 0,4 g nicht 0 wird), kg/l auf 3 Stellen. Andere Einheiten (Stück, Bund, EL …) nie still runden —
     * genauer Wert, und ist er nicht ganz, steht die Zeile in „bitte prüfen".
     *
     * @return array{wert: float, pruefen: bool}
     */
    public static function speicherWert(float $wert, ?object $unit): array
    {
        return match ((string) ($unit?->slug ?? '')) {
            'g', 'ml' => ['wert' => round($wert, $wert < 10 ? 1 : 0), 'pruefen' => false],
            'kg', 'l' => ['wert' => round($wert, 3), 'pruefen' => false],
            default => ['wert' => round($wert, 4), 'pruefen' => abs($wert - round($wert)) > 0.0001],
        };
    }

    /** „12,48" — Zahl ohne Einheit, bis 3 Stellen, ohne Null-Schwanz. */
    public static function zahlText(float $wert): string
    {
        return rtrim(rtrim(number_format($wert, 3, ',', '.'), '0'), ',');
    }

    /** „×1,04"; kleine Faktoren mit einer Stelle mehr („×0,025"). */
    public static function faktorText(float $faktor): string
    {
        return '×' . number_format($faktor, $faktor < 0.1 ? 3 : 2, ',', '.');
    }
}
