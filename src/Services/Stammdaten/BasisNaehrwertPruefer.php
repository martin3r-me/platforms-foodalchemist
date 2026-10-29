<?php

namespace Platform\FoodAlchemist\Services\Stammdaten;

/**
 * Standard-Prüfer bis die gemeinsame Prüfung der GP-Session steht. `SignalDetektorService::naehrwertPlausi` arbeitet auf gespeicherten Rezepten
 * und erzeugt ein Signal — auf Datei-Werte vor der Übernahme ist es nicht anwendbar. Hier gilt dieselbe Regel („davon“ ≤ Oberwert, Toleranz 0,1 g)
 * plus Grundsätze, an denen jede Datenblatt-Zeile scheitert, wenn Einheit oder Spalte verrutscht sind.
 */
class BasisNaehrwertPruefer implements NaehrwertPruefer
{
    private const TOLERANZ_G = 0.1;

    public function pruefe(array $werte, ?string $warengruppe = null, ?string $sub = null): array
    {
        $h = [];
        $v = fn (string $k): ?float => isset($werte[$k]) ? (float) $werte[$k] : null;

        if (($z = $v('sugar')) !== null && ($kh = $v('carbs_absorbable')) !== null && $z > $kh + self::TOLERANZ_G) {
            $h[] = $this->befund('zucker_ueber_kh', 'hinweis', 'sugar', $z, $kh,  "Zucker ({$z} g) liegt über den Kohlenhydraten ({$kh} g).");
        }
        if (($gs = $v('saturated_fat')) !== null && ($f = $v('fat')) !== null && $gs > $f + self::TOLERANZ_G) {
            $h[] = $this->befund('ges_fett_ueber_fett', 'hinweis', 'saturated_fat', $gs, $f,  "Gesättigte Fettsäuren ({$gs} g) liegen über dem Fett ({$f} g).");
        }
        foreach (['protein', 'fat', 'carbs_absorbable', 'sugar', 'saturated_fat'] as $k) {
            if (($x = $v($k)) !== null && $x > 100.0) {
                $h[] = $this->befund('ueber_100g', 'hart', $k, $x, 100.0,  "{$k}: {$x} g je 100 g ist nicht möglich — Einheit oder Spalte prüfen.");
            }
        }
        $summe = ($v('protein') ?? 0.0) + ($v('fat') ?? 0.0) + ($v('carbs_absorbable') ?? 0.0);
        if ($summe > 100.0 + self::TOLERANZ_G) {
            $h[] = $this->befund('summe_ueber_100g', 'hart', null, $summe, 100.0,  'Eiweiß + Fett + Kohlenhydrate ergeben mehr als 100 g je 100 g.');
        }
        if (($kcal = $v('energy_kcal')) !== null && ($kj = $v('energy_kj')) !== null && $kcal > 0 && abs($kj - $kcal * 4.184) > max(25.0, $kj * 0.08)) {
            $h[] = $this->befund('kcal_kj', 'hinweis', 'energy_kj', $kj, round($kcal * 4.184, 1),  "kcal ({$kcal}) und kJ ({$kj}) passen nicht zusammen (1 kcal = 4,184 kJ).");
        }
        if ($kcal !== null && $kcal > 900.0) {
            $h[] = $this->befund('kcal_ueber_900', 'hart', 'energy_kcal', $kcal, 900.0,  "{$kcal} kcal je 100 g liegt über dem Maximum von reinem Fett (ca. 900).");
        }

        return $h;
    }

    /** @return array{regel: string, schwere: string, feld: ?string, wert: mixed, erwartet: mixed, vorschlag: mixed, text: string} */
    private function befund(string $regel, string $schwere, ?string $feld, mixed $wert, mixed $erwartet, string $text): array
    {
        return ['regel' => $regel, 'schwere' => $schwere, 'feld' => $feld, 'wert' => $wert, 'erwartet' => $erwartet, 'vorschlag' => null, 'text' => $text];
    }
}
