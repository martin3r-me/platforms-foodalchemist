<?php

namespace Platform\FoodAlchemist\Services\Konfigurator;

/**
 * Spec 94 · Paketgrenzen vorschlagen — deterministisch, ohne KI. Teil des
 * {@see \Platform\FoodAlchemist\Services\KonfiguratorPricingService}, das die genauen Preise je Gästezahl liefert.
 *
 * Ab Untergrenze u wird das Paket im Raster so weit nach oben geschoben, wie für jede Gästezahl p im Paket und jeden Fall
 * |Preis(Referenz) − Genau(p)| / Genau(p) ≤ Toleranz gilt. Hält schon die Mindestbreite die Toleranz nicht, bleibt es bei
 * der Mindestbreite (markiert). Ein Rest unter der Mindestbreite wird dem letzten Paket zugeschlagen. Lückenlos bis zur Obergrenze.
 */
final class KonfiguratorPaketVorschlag
{
    /**
     * @param  array<int, array<int, float>>  $kurven  je Fall: Gästezahl → genauer Preis pro Person
     * @param  callable(int, int): int  $referenz  Referenz-Pax eines Bereichs
     * @return list<array{von_pax:int, bis_pax:int, referenz_pax:int, max_abweichung_pct:float, toleranz_gehalten:bool}>
     */
    public function grenzen(array $kurven, int $von, int $bis, float $toleranz, int $raster, int $mindestbreite, callable $referenz): array
    {
        $raster = max(1, $raster);
        $mindestbreite = max(1, $mindestbreite);
        $pakete = [];
        $u = $von;

        while ($u <= $bis) {
            $e = min($bis, $this->rasterEnde($u + $mindestbreite - 1, $raster));
            $abw = $this->abweichung($kurven, $u, $e, $referenz($u, $e));
            if ($abw <= $toleranz + 1e-9) {
                while ($e < $bis) {
                    $naechstes = min($bis, $e + $raster);
                    $a = $this->abweichung($kurven, $u, $naechstes, $referenz($u, $naechstes));
                    if ($a > $toleranz + 1e-9) {
                        break;
                    }
                    [$e, $abw] = [$naechstes, $a];
                }
            }
            if ($e < $bis && $bis - $e < $mindestbreite) {   // kein Zwergpaket am Ende
                $e = $bis;
                $abw = $this->abweichung($kurven, $u, $e, $referenz($u, $e));
            }
            $pakete[] = ['von_pax' => $u, 'bis_pax' => $e, 'referenz_pax' => $referenz($u, $e),
                'max_abweichung_pct' => round($abw * 100, 1), 'toleranz_gehalten' => $abw <= $toleranz + 1e-9];
            $u = $e + 1;
        }

        return $pakete;
    }

    /** Kleinstes Ende ≥ `$mindestEnde`, nach dem eine glatte Grenze beginnt (z. B. 74 → nächstes Paket ab 75). */
    private function rasterEnde(int $mindestEnde, int $raster): int
    {
        return (int) (ceil(($mindestEnde + 1) / $raster) * $raster) - 1;
    }

    /** Größte relative Abweichung im Bereich, über alle Fälle, gegen den Preis an der Referenz. */
    private function abweichung(array $kurven, int $u, int $e, int $r): float
    {
        $max = 0.0;
        foreach ($kurven as $kurve) {
            $preis = $kurve[$r] ?? null;
            if ($preis === null) {
                continue;
            }
            for ($p = $u; $p <= $e; $p++) {
                $genau = $kurve[$p] ?? 0.0;
                if ($genau > 0) {
                    $max = max($max, abs($preis - $genau) / $genau);
                }
            }
        }

        return $max;
    }
}
