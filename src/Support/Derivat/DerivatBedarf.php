<?php

namespace Platform\FoodAlchemist\Support\Derivat;

use Platform\FoodAlchemist\Models\FoodAlchemistGp;

/**
 * Spec 96 · Bedarf und Bestellung eines Derivats laufen über die Mutter.
 *
 *  - zweck (Saft, Abrieb): Mutter-Menge = Derivat-Menge ÷ Ausbeute (Stück aufgerundet). Mehrere Derivate derselben Mutter (Saft +
 *    Abrieb derselben Limette): es zählt der GRÖSSERE Bedarf, nicht die Summe (Dominique 06.11.) — dieselbe Frucht liefert beides.
 *    Braucht das Rezept die Mutter auch selbst (Limettenscheiben), kommt dieser Bedarf dazu.
 *  - nebenprodukt (Abschnitte, Parüren, Knochen): kein Einkauf. „Abschnitte werden ja gesammelt, daher kann man gar nicht sagen, es
 *    fehlt“ (Dominique 06.11.) — keine Bilanz, keine Bestellung, keine Mutter-Erhöhung; nur eine Info-Zeile „aus Sammelbestand“.
 *  - zweck ohne Ausbeute oder ohne Stückgewicht der Mutter: bleibt als Derivat stehen (landet ehrlich „ohne Lieferant“).
 */
final class DerivatBedarf
{
    /**
     * @param  array<int|string, array<string, mixed>>  $gpBedarf  Einträge mit gp_id, name, menge_g, menge_kg, ek_eur, ek_bekannt
     * @param  callable(FoodAlchemistGp): ?float  $preisProGramm  €/g der Mutter (Team-Kaskade)
     * @return array{bedarf: list<array<string, mixed>>, sammelbestand: list<array<string, mixed>>}
     */
    public static function aufMutter(array $gpBedarf, callable $preisProGramm): array
    {
        $bleibt = [];
        $sammel = [];
        $direkt = [];      // mutter_id => Eintrag (Mutter direkt im Bedarf)
        $ausDerivat = [];  // mutter_id => ['menge_g' => max, 'derivate' => [...], 'mutter' => GP]
        foreach ($gpBedarf as $b) {
            $gp = FoodAlchemistGp::find((int) $b['gp_id']);
            $mutter = $gp !== null ? DerivatArt::mutter($gp) : null;
            $art = $gp !== null ? DerivatArt::von($gp, $mutter) : null;
            if ($art === DerivatArt::NEBENPRODUKT && $gp->lead_la_supplier_item_id === null) {
                $sammel[] = [...$b, 'ek_eur' => 0.0, 'ek_bekannt' => true, 'sammelbestand' => true];

                continue;
            }
            $m = ($art === DerivatArt::ZWECK && $mutter !== null && ! $mutter->is_derivat && $gp->lead_la_supplier_item_id === null)
                ? DerivatAusbeute::mutterMenge($gp, $mutter, (float) $b['menge_g']) : null;
            if ($m === null) {
                $bleibt[] = $b;

                continue;
            }
            $mid = (int) $mutter->id;
            $ausDerivat[$mid] ??= ['menge_g' => 0.0, 'derivate' => [], 'mutter' => $mutter];
            $ausDerivat[$mid]['menge_g'] = max($ausDerivat[$mid]['menge_g'], $m['menge_g']);
            $ausDerivat[$mid]['derivate'][] = (string) $b['name'];
        }
        foreach ($bleibt as $i => $b) {
            if (isset($ausDerivat[(int) $b['gp_id']])) {
                $direkt[(int) $b['gp_id']] = $b;
                unset($bleibt[$i]);
            }
        }
        foreach ($ausDerivat as $mid => $d) {
            $g = $d['menge_g'] + (float) ($direkt[$mid]['menge_g'] ?? 0);
            $pg = $preisProGramm($d['mutter']);
            $bleibt[] = [
                'gp_id' => $mid,
                'name' => (string) $d['mutter']->name,
                'warengruppe' => $d['mutter']->commodity_group_code,
                'menge_g' => round($g, 1),
                'menge_kg' => round($g / 1000, 3),
                'ek_eur' => $pg !== null ? round($g * $pg, 2) : null,
                'ek_bekannt' => $pg !== null,
                'aus_derivaten' => $d['derivate'],
            ];
        }

        return ['bedarf' => array_values($bleibt), 'sammelbestand' => $sammel];
    }

    /**
     * Einzelner Bestell-Bedarf auf ein Derivat (Bestellung direkt auf ein GP): Mutter + Menge, Sammelbestand, oder null = unverändert.
     *
     * @return array{sammelbestand: bool, gp: ?FoodAlchemistGp, menge_g: float}|null
     */
    public static function direkt(FoodAlchemistGp $gp, float $mengeG): ?array
    {
        if (! $gp->is_derivat || $gp->lead_la_supplier_item_id !== null) {
            return null;
        }
        $mutter = DerivatArt::mutter($gp);
        $art = DerivatArt::von($gp, $mutter);
        if ($art === DerivatArt::NEBENPRODUKT) {
            return ['sammelbestand' => true, 'gp' => null, 'menge_g' => $mengeG];
        }
        $m = $mutter !== null && ! $mutter->is_derivat ? DerivatAusbeute::mutterMenge($gp, $mutter, $mengeG) : null;

        return $m !== null ? ['sammelbestand' => false, 'gp' => $mutter, 'menge_g' => $m['menge_g']] : null;
    }

    /**
     * Bestellziel für einen direkt bestellten GP (OrderService): [gp, menge_g] — Zweck-Derivat → Mutter, sonst unverändert;
     * null = Nebenprodukt aus dem Sammelbestand, wird nie bestellt.
     *
     * @return array{0: FoodAlchemistGp, 1: float}|null
     */
    public static function bestellziel(FoodAlchemistGp $gp, float $mengeG): ?array
    {
        $u = self::direkt($gp, $mengeG);
        if ($u === null) {
            return [$gp, $mengeG];
        }

        return $u['sammelbestand'] ? null : [$u['gp'], $u['menge_g']];
    }

    /**
     * Lieferanten-Gruppen eines Bedarfsvorschlags ohne die Sammelbestand-Gruppe (Nebenprodukte werden nie bestellt).
     *
     * @param  array<int|string, array<string, mixed>>  $gruppen
     * @return array<int|string, array<string, mixed>>
     */
    public static function ohneSammelbestand(array $gruppen): array
    {
        return array_filter($gruppen, fn ($g) => ! ($g['sammelbestand'] ?? false));
    }
}
