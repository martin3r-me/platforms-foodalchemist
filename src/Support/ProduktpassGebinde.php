<?php

namespace Platform\FoodAlchemist\Support;

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;

/**
 * Gebinde aus dem Produktpass: Menge UND Einheit sind EIN Paar, und die Einheit ist die der Preisrechnung (kg, l, Stk).
 *
 * Erster Praxistest auf io (LA 319735, Mousse): Der Pass las „660 g", der Artikel hatte 0,660 kg. Angeboten wurden „qty 660" UND „unit g" einzeln;
 * die übernommene Einheit „g" machte den Vergleichspreis kaputt. Deshalb: die Pass-Menge wird VOR dem Vergleich in die Einheit der Preisrechnung
 * umgerechnet (g → kg, ml/cl/dl → l), gleichwertig heißt kein Vorschlag, und die Einheit wird nie allein angeboten oder geschrieben.
 * Eine Verpackungsbeschreibung („Kunststoffform mit Stülpdeckel") ist kein Einheitencode: sie wird ein Hinweis-Vorschlag, nie `packaging_unit`.
 */
final class ProduktpassGebinde
{
    /** Einheit des Dokuments → [Einheit der Preisrechnung, Faktor]. Schreibbar sind nur kg, l und Stk (SupplierItemService::UNIT_CODES). */
    private const EINHEITEN = [
        'kg' => ['kg', 1.0], 'g' => ['kg', 0.001], 'gr' => ['kg', 0.001],
        'l' => ['l', 1.0], 'liter' => ['l', 1.0], 'ml' => ['l', 0.001], 'cl' => ['l', 0.01], 'dl' => ['l', 0.1],
        'stk' => ['Stk', 1.0], 'stück' => ['Stk', 1.0], 'stueck' => ['Stk', 1.0], 'st' => ['Stk', 1.0],
    ];

    /** Verbreitete Verpackungs-Kürzel der Lieferanten; dazu kommt, was im Bestand schon als packaging_unit steht. */
    private const CODES = ['STK', 'ST', 'KG', 'KRT', 'KT', 'BEU', 'FL', 'SA', 'EI', 'PAK', 'PCK', 'DS', 'TÜ', 'BTL', 'KAN', 'EIM', 'SCH', 'KI', 'TRY', 'CT', 'PA'];

    /** @var list<string>|null */
    private static ?array $bestand = null;

    public static function zuruecksetzen(): void
    {
        self::$bestand = null;
    }

    /** @return array{0:string,1:float}|null [Einheit der Preisrechnung, Faktor] */
    public static function einheit(?string $roh): ?array
    {
        $k = mb_strtolower(trim((string) $roh));

        return $k === '' ? null : (self::EINHEITEN[$k] ?? null);
    }

    /**
     * Das Paar aus dem Extrakt: Menge in der Einheit der Preisrechnung. Einheit aus `gebinde.unit_code`, sonst aus `einheit` an der Menge.
     *
     * @param  array<string,array<string,mixed>>  $felder
     * @return array{qty:float,unit:string,konfidenz:float,roh:string}|null
     */
    public static function menge(array $felder): ?array
    {
        $q = $felder['gebinde.qty'] ?? null;
        if ($q === null || ! is_numeric($q['wert'] ?? null) || (float) $q['wert'] <= 0) {
            return null;
        }
        $unitEintrag = $felder['gebinde.unit_code'] ?? null;
        $einheit = self::einheit($unitEintrag['wert'] ?? null) ?? self::einheit($q['einheit'] ?? null);
        if ($einheit === null) {
            return null;   // ohne erkennbare Einheit keine Menge (Preisfalle)
        }
        $konf = (float) ($q['konfidenz'] ?? 0.0);
        if ($unitEintrag !== null && self::einheit($unitEintrag['wert'] ?? null) !== null) {
            $konf = min($konf, (float) ($unitEintrag['konfidenz'] ?? 0.0));
        }
        $roh = rtrim(rtrim(number_format((float) $q['wert'], 4, ',', ''), '0'), ',').' '.trim((string) ($unitEintrag['wert'] ?? $q['einheit'] ?? ''));

        return ['qty' => round((float) $q['wert'] * $einheit[1], 6), 'unit' => $einheit[0], 'konfidenz' => $konf, 'roh' => $roh];
    }

    /** Hat der Artikel schon dieselbe Menge (in der Einheit der Preisrechnung, Toleranz 0,1 %)? */
    public static function gleichwertig(FoodAlchemistSupplierItem $item, array $menge): bool
    {
        $einheit = self::einheit($item->unit_code);
        if ($einheit === null || $item->qty === null || (float) $item->qty <= 0 || $einheit[0] !== $menge['unit']) {
            return false;
        }
        $a = (float) $item->qty * $einheit[1];

        return abs($a - $menge['qty']) <= max(0.000001, 0.001 * max($a, $menge['qty']));
    }

    /** Menge des Artikels (qty + unit_code) ist leer: nichts oder 0. */
    public static function artikelLeer(FoodAlchemistSupplierItem $item): bool
    {
        return $item->qty === null || (float) $item->qty <= 0;
    }

    /** Ein bekannter Verpackungs-Code (kurz, im Bestand oder in der Liste) — sonst ist es eine Beschreibung. */
    public static function verpackungscode(?string $roh): ?string
    {
        $s = trim((string) $roh);
        if ($s === '' || mb_strlen($s) > 8 || preg_match('/\s/', $s)) {
            return null;
        }
        self::$bestand ??= DB::table('foodalchemist_supplier_items')->whereNotNull('packaging_unit')->where('packaging_unit', '!=', '')
            ->whereRaw('LENGTH(packaging_unit) <= 8')->distinct()->limit(300)->pluck('packaging_unit')->map(fn ($c) => mb_strtoupper((string) $c))->all();
        $u = mb_strtoupper($s);

        return in_array($u, self::CODES, true) || in_array($u, self::$bestand, true) ? $s : null;
    }
}
