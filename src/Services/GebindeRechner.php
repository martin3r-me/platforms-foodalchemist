<?php

namespace Platform\FoodAlchemist\Services;

/**
 * S0 (Spec 17 — Bestellwesen) — Bedarfs-Primitive: rechnet den je Lieferant
 * AGGREGIERTEN GP-Bedarf (Gramm) in GANZE Bestell-Gebinde des Lead-LA um.
 *
 * Regeln (Spec 17 §E3):
 *  - kg/l: Gebinde-Inhalt = qty·1000 g (Dichte 1.0, wie die gesamte Kaskade);
 *    qty_packs = ceil(Bedarf ÷ Gebinde-Inhalt). Aufgerundet wird auf dem
 *    aggregierten Bedarf — der Aufrufer summiert vorher pro GP/Schiene (nie pro
 *    Quell-Zeile), sonst entsteht Doppel-Aufrundung.
 *  - Stk: Gramm→Stück NUR über das Stückgewicht (gp.piece_default_g); ohne
 *    Stückgewicht NICHT berechenbar (kein Gramm-÷-Stück-Unsinn).
 *  - qty NULL/0 (GL-03-Preisfalle), fehlendes Stückgewicht oder unbekannte
 *    Einheit ⇒ nicht berechenbar: der Aufrufer zeigt den Grundeinheit-Bedarf +
 *    Warnung, nie eine stille Schätzung.
 *
 * Pure/read-only: kein DB-Zugriff, kein State — mit Plain-Objekten testbar.
 * Der Lead-LA wird duck-typed erwartet (qty, unit_code, packaging_unit,
 * article_number, aktiver_preis — genau wie LeadLaService::rangliste() annotiert).
 */
class GebindeRechner
{
    /**
     * @param  object|null  $leadLa    Lead-Lieferantenartikel (aus LeadLaService) oder null
     * @param  float         $needG     aggregierter Bedarf in Gramm
     * @param  float|null    $pieceG    Stückgewicht des GP (gp.piece_default_g) — nur für Stk-Gebinde nötig
     * @return array{berechenbar:bool, qty_packs:?int, pack_qty:?float, pack_unit_code:?string, packaging_unit:?string, article_number:?string, pack_price:?float, line_total:?float, needed_base:?float, needed_base_unit:?string, ueberkauf_base:?float, grund:?string}
     */
    public function berechne(?object $leadLa, float $needG, ?float $pieceG = null): array
    {
        $leer = [
            'berechenbar' => false, 'qty_packs' => null, 'pack_qty' => null, 'pack_unit_code' => null,
            'packaging_unit' => null, 'article_number' => null, 'pack_price' => null, 'line_total' => null,
            'needed_base' => null, 'needed_base_unit' => null, 'ueberkauf_base' => null, 'grund' => null,
        ];

        if ($leadLa === null) {
            return [...$leer, 'grund' => 'Kein Lead-Lieferantenartikel — Gebinde nicht bestimmbar.'];
        }

        $qty = $leadLa->qty !== null ? (float) $leadLa->qty : null;
        $unit = $leadLa->unit_code ?? null;
        $preis = isset($leadLa->aktiver_preis) && $leadLa->aktiver_preis !== null ? (float) $leadLa->aktiver_preis : null;
        $stamm = [
            'pack_qty' => $qty, 'pack_unit_code' => $unit,
            'packaging_unit' => $leadLa->packaging_unit ?? null,
            'article_number' => $leadLa->article_number ?? null,
            'pack_price' => $preis,
        ];

        if ($qty === null || $qty <= 0.0) {
            return [...$leer, ...$stamm, 'grund' => 'Gebinde-Menge fehlt (Preisfalle) — Bedarf in Grundeinheit.'];
        }
        if ($needG <= 0.0) {
            return [...$leer, ...$stamm, 'grund' => 'Kein Bedarf.'];
        }

        if ($unit === 'kg' || $unit === 'l') {
            $neededBase = $needG / 1000.0;                 // kg bzw. l (Dichte 1.0)
            $unitLabel = $unit;
        } elseif ($unit === 'Stk') {
            if ($pieceG === null || $pieceG <= 0.0) {
                return [...$leer, ...$stamm, 'grund' => 'Stück-Artikel ohne Stückgewicht — Bedarf nicht in Gebinde umrechenbar (in Gramm belassen).'];
            }
            $neededBase = $needG / $pieceG;                // Stück
            $unitLabel = 'Stk';
        } else {
            return [...$leer, ...$stamm, 'grund' => "Einheit „{$unit}“ nicht bestellbar — Bedarf in Grundeinheit."];
        }

        $packs = max(1, (int) ceil($neededBase / $qty - 1e-9));
        $bestellt = $packs * $qty;

        return [
            'berechenbar' => true,
            'qty_packs' => $packs,
            'pack_qty' => $qty,
            'pack_unit_code' => $unit,
            'packaging_unit' => $leadLa->packaging_unit ?? null,
            'article_number' => $leadLa->article_number ?? null,
            'pack_price' => $preis,
            'line_total' => $preis !== null ? round($packs * $preis, 2) : null,
            'needed_base' => round($neededBase, 3),
            'needed_base_unit' => $unitLabel,
            'ueberkauf_base' => round($bestellt - $neededBase, 3),
            'grund' => null,
        ];
    }
}
