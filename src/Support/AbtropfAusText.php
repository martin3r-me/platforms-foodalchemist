<?php

namespace Platform\FoodAlchemist\Support;

/**
 * Abtropf- und Füllgewicht aus dem Artikeltext (Entscheid Dominique 12.11.2026), deterministisch, ohne KI.
 * Quelle: Bezeichnung, Zusatztext, Marketingname. Beispiele: „Abtropfgewicht 2,8 kg“, „Abtropf. 900g“, „ATG 900 g“,
 * „Füllgewicht 4,6 kg“ / „Füllmenge …“, „(4,6 kg)“ zählt nur zusammen mit einer Abtropf-Angabe als Füllgewicht.
 *
 * Regel (nur eindeutige Treffer, sonst nichts setzen):
 *  - genau EIN Abtropf-Wert (mehrere verschiedene → mehrdeutig; Abtropf-Wort ohne lesbare Zahl → mehrdeutig),
 *  - höchstens EIN Füll-Wert, Abtropf ≤ Füll,
 *  - Kalkulationseinheit kg (Gewicht; l/Stk/leer → mehrdeutig, dort nur von Hand),
 *  - Bezug auf die Bestelleinheit wie `qty`: Füll ≈ Menge → Faktor 1; Füll (bzw. Einzelgewicht aus „N x M g“) × n ≈ Menge
 *    (n aus „N x M g“ bzw. Verpackungseinheiten je Bestelleinheit) → Faktor n; ohne Füll-Wert: Abtropf ≈ Menge → 1, Abtropf × n ≈ Menge → n; ohne jeden Hinweis auf
 *    Mehrfachgebinde (n = 1) gilt Faktor 1, auch wenn die Menge abweicht (das ist der Fall, den das Feld beheben soll).
 *    Sonst (Mehrfachgebinde ohne passenden Faktor, Abtropf > Menge, Menge fehlt) → mehrdeutig.
 */
final class AbtropfAusText
{
    public const QUELLE = 'text';

    private const ZAHL = '(\d+(?:[.,]\d+)?)\s*(kg|g)\b';

    /** Abtropf-Schlüsselwort ohne Zahl: zeigt, dass der Text etwas sagt (dann muss die Zahl lesbar sein). */
    private const ABTROPF_WORT = '/abtropf|abtr\.|\bATG\b/iu';

    /**
     * @return array{status: 'gesetzt'|'mehrdeutig'|'kein_treffer', abtropfgewicht: ?float, fuellgewicht: ?float, abweichung: bool, grund: ?string}
     */
    public static function erkenne(array $texte, mixed $menge, mixed $vePerBe, ?string $einheit): array
    {
        $text = implode(' | ', array_unique(array_filter(array_map(fn ($t) => trim((string) $t), $texte), fn ($t) => $t !== '')));
        $leer = ['abtropfgewicht' => null, 'fuellgewicht' => null, 'abweichung' => false, 'grund' => null];
        if (! preg_match(self::ABTROPF_WORT, $text)) {
            return ['status' => 'kein_treffer'] + $leer;
        }
        $mehrdeutig = fn (string $grund) => ['status' => 'mehrdeutig'] + ['grund' => $grund] + $leer;

        $abtropf = self::werte('/(?:abtropf(?:gewicht|gew\.?|\.)?|abtr\.\s*-?\s*gew(?:icht|\.)?|\bATG\b)\s*[:=]?\s*(?:ca\.?\s*)?'.self::ZAHL.'/iu', $text);
        if ($abtropf === []) {
            return $mehrdeutig('abtropf_zahl_nicht_lesbar');
        }
        if (count($abtropf) > 1) {
            return $mehrdeutig('mehrere_abtropfwerte');
        }
        $a = $abtropf[0];

        $fuell = self::werte('/(?:netto-?)?(?:füll|fuell)(?:gewicht|gew\.?|menge)\s*[:=]?\s*(?:ca\.?\s*)?'.self::ZAHL.'/iu', $text);
        $fuell = array_values(array_unique([...$fuell, ...self::werte('/\(\s*'.self::ZAHL.'\s*\)/iu', $text)], SORT_REGULAR));
        if (count($fuell) > 1) {
            return $mehrdeutig('mehrere_fuellwerte');
        }
        $f = $fuell[0] ?? null;
        if ($f !== null && $a > $f * 1.0001) {
            return $mehrdeutig('abtropf_groesser_als_fuell');
        }
        if ($einheit !== 'kg') {
            return $mehrdeutig('einheit_'.($einheit ?? 'leer'));
        }
        $q = $menge === null || $menge === '' ? null : (float) $menge;
        if ($q === null || $q <= 0) {
            return $mehrdeutig('menge_fehlt');
        }

        $faktoren = self::mehrfach($text, $vePerBe);
        $nahe = fn (float $soll) => abs($soll - $q) <= 0.02 * $q + 0.0005;
        $faktor = null;
        foreach ([[1, null], ...$faktoren] as [$n, $einzel]) {   // Füll-, Abtropf- oder Einzelgebinde-Wert × n trifft die Menge
            if (($f !== null && $nahe($f * $n)) || $nahe($a * $n) || ($einzel !== null && $nahe($einzel * $n))) {
                $faktor = $n;
                break;
            }
        }
        if ($faktor === null) {
            if ($faktoren !== []) {
                return $mehrdeutig('mehrfachgebinde_ohne_passenden_faktor');
            }
            if ($f === null && $a > $q) {
                return $mehrdeutig('abtropf_groesser_als_menge');
            }
            $faktor = 1;   // kein Mehrfachgebinde: der Text gilt je Bestelleinheit, die Menge weicht ab
        }

        $abtropfgewicht = round($a * $faktor, 4);

        return [
            'status' => 'gesetzt',
            'abtropfgewicht' => $abtropfgewicht,
            'fuellgewicht' => $f === null ? null : round($f * $faktor, 4),
            'abweichung' => ! $nahe($abtropfgewicht),
            'grund' => null,
        ];
    }

    /** Alle Treffer eines Musters in kg (gerundet, ohne Dubletten). */
    private static function werte(string $muster, string $text): array
    {
        preg_match_all($muster, $text, $m, PREG_SET_ORDER);
        $werte = [];
        foreach ($m as $t) {
            $wert = (float) str_replace(',', '.', $t[1]) * (mb_strtolower($t[2]) === 'g' ? 0.001 : 1.0);
            if ($wert > 0) {
                $werte[] = round($wert, 4);
            }
        }

        return array_values(array_unique($werte, SORT_REGULAR));
    }

    /**
     * Hinweise auf Mehrfachgebinde: „6 x 850 g“ im Text (Anzahl + Einzelgewicht in kg) und Verpackungseinheiten je Bestelleinheit > 1.
     *
     * @return list<array{0: int, 1: ?float}>
     */
    private static function mehrfach(string $text, mixed $vePerBe): array
    {
        $n = [];
        preg_match_all('/(\d+)\s*[x×]\s*(\d+(?:[.,]\d+)?)\s*(kg|g)\b/iu', $text, $m, PREG_SET_ORDER);
        foreach ($m as $t) {
            if ((int) $t[1] > 1) {
                $n[] = [(int) $t[1], (float) str_replace(',', '.', $t[2]) * (mb_strtolower($t[3]) === 'g' ? 0.001 : 1.0)];
            }
        }
        if ($vePerBe !== null && $vePerBe !== '' && (float) $vePerBe > 1 && floor((float) $vePerBe) === (float) $vePerBe) {
            $n[] = [(int) $vePerBe, null];
        }

        return $n;
    }
}
