<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistInventoryBatch;
use Platform\FoodAlchemist\Models\FoodAlchemistInventoryLocation;
use Platform\FoodAlchemist\Models\FoodAlchemistInventoryMovement;
use Platform\FoodAlchemist\Models\FoodAlchemistInventoryStock;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;

/**
 * Spec 69 · Eigenproduktion im Lager: Rezept als Lagerartikel mit Chargen.
 *
 * - Einlagern = neue Charge (Nummer C{JJMMTT}-{nn} je Team und Tag), Bestand + Bewegung `eigenproduktion`,
 *   Bewertung zum Rezept-EK (Basisrezept je g bzw. Stück, Gericht je Portion).
 * - „Verbrauchen bis" aus Lagerart + Haltbarkeit am Rezept (TK ab Einfrierdatum), überschreibbar.
 * - Entnahme FIFO nach Haltbarkeit (älteste zuerst), wählbar je Charge. Grund `verbrauch` = Produktion
 *   (Bewegung `entnahme`), sonst Abgang mit Grund wie Spec 67 (verderb, bruch, …) → zählt im Controlling.
 * - Inventur gleicht die Chargen nach (Minus: älteste zuerst, Plus: jüngste offene Charge).
 *
 * Team-strikt (`where team_id`).
 */
class EigenproduktionService
{
    public const LAGERARTEN = ['gekuehlt' => 'Gekühlt', 'tiefgekuehlt' => 'Tiefgekühlt', 'trocken' => 'Trocken'];

    public const ENTNAHME_GRUENDE = ['verbrauch' => 'Verbrauch in der Produktion', 'verderb' => 'Verderb (abgelaufen)', 'bruch' => 'Bruch', 'schwund' => 'Schwund', 'personal' => 'Personalessen', 'probe' => 'Probe / Verkostung', 'korrektur' => 'Korrektur'];

    /** Basiseinheit eines Rezepts im Lager: Gericht = Portion, Stück-Ertrag = Stück, sonst Gramm. */
    public function einheit(FoodAlchemistRecipe $r): string
    {
        return $r->is_sales_recipe ? 'Port' : ($r->istStueckErtrag() ? 'Stk' : 'g');
    }

    /** Eingabe-Einheit für die Anzeige (kg statt g). */
    public function anzeigeEinheit(string $base): string
    {
        return ['g' => 'kg', 'Port' => 'Portionen', 'Stk' => 'Stück'][$base] ?? $base;
    }

    public function anzeigeMenge(float $basis, string $base): float
    {
        return $base === 'g' ? round($basis / 1000, 3) : round($basis, 3);
    }

    /** Rezept-EK je Basiseinheit (€ je g / Stück / Portion). null ohne Kalkulation. */
    public function preisJeBasis(FoodAlchemistRecipe $r): ?float
    {
        $ek = (float) ($r->ek_total_eur ?? 0);
        if ($r->is_sales_recipe) {
            $portion = $r->standardPresentation?->ek_portion;
            if ($portion !== null && (float) $portion > 0) {
                return round((float) $portion, 6);
            }
            $n = (float) ($r->sales_unit_count ?? 0);

            return $ek > 0 && $n > 0 ? round($ek / $n, 6) : null;
        }
        if ($r->istStueckErtrag()) {
            $n = (float) ($r->yield_pieces ?? 0);

            return $ek > 0 && $n > 0 ? round($ek / $n, 6) : null;
        }
        $kg = (float) ($r->yield_kg_manual ?? $r->yield_kg ?? 0);

        return $ek > 0 && $kg > 0 ? round($ek / ($kg * 1000), 6) : null;
    }

    /**
     * Charge einlagern.
     *
     * @param  array{recipe_id:int, location_id?:?int, menge:mixed, lagerart?:?string, produziert_am?:?string,
     *               eingefroren_am?:?string, verbrauchen_bis?:?string, notiz?:?string, production_order_line_id?:?int}  $in
     */
    public function einlagern(Team $team, array $in, ?int $userId = null): FoodAlchemistInventoryBatch
    {
        $r = FoodAlchemistRecipe::visibleToTeam($team)->with('standardPresentation')->findOrFail((int) ($in['recipe_id'] ?? 0));
        $ort = ! empty($in['location_id'])
            ? FoodAlchemistInventoryLocation::where('team_id', $team->id)->findOrFail((int) $in['location_id'])
            : $this->standardLager($team);
        $base = $this->einheit($r);
        $menge = $this->zahl($in['menge'] ?? null, 'Menge');
        if ($menge === null || $menge <= 0) {
            throw new \RuntimeException('Die Menge muss größer als 0 sein.');
        }
        $qty = $base === 'g' ? $menge * 1000 : $menge;
        $lagerart = (string) ($in['lagerart'] ?? '') ?: ($r->storage_type ?: 'gekuehlt');
        if (! array_key_exists($lagerart, self::LAGERARTEN)) {
            throw new \RuntimeException('Lagerart muss gekuehlt, tiefgekuehlt oder trocken sein.');
        }
        $produziert = $this->datum($in['produziert_am'] ?? null) ?? now()->startOfDay();
        $eingefroren = $this->datum($in['eingefroren_am'] ?? null) ?? ($lagerart === 'tiefgekuehlt' ? $produziert->copy() : null);
        $bis = $this->datum($in['verbrauchen_bis'] ?? null) ?? $this->haltbarBis($r, $lagerart, $produziert, $eingefroren);
        $preis = $this->preisJeBasis($r);

        return DB::transaction(function () use ($team, $r, $ort, $base, $qty, $lagerart, $produziert, $eingefroren, $bis, $preis, $in, $userId) {
            $stock = $this->stock($team, (int) $ort->id, (int) $r->id, $base);
            $stock->update(['qty_base' => round((float) $stock->qty_base + $qty, 4)]);
            $batch = FoodAlchemistInventoryBatch::create([
                'team_id' => $team->id, 'inventory_location_id' => $ort->id, 'stock_id' => $stock->id, 'recipe_id' => $r->id,
                'charge' => $this->naechsteCharge($team, $produziert), 'produced_at' => $produziert, 'frozen_at' => $eingefroren,
                'best_before' => $bis, 'storage_type' => $lagerart, 'qty_initial' => round($qty, 4), 'qty_rest' => round($qty, 4),
                'base_unit' => $base, 'price_per_base' => $preis,
                'production_order_line_id' => ! empty($in['production_order_line_id']) ? (int) $in['production_order_line_id'] : null,
                'note' => isset($in['notiz']) && trim((string) $in['notiz']) !== '' ? mb_substr(trim((string) $in['notiz']), 0, 255) : null,
                'created_by' => $userId,
            ]);
            $this->bewegung($team, $stock, $batch, 'in', 'eigenproduktion', null, $qty, 'Charge ' . $batch->charge, $userId);

            return $batch->refresh();
        });
    }

    /**
     * Entnahme: FIFO über die offenen Chargen des Rezepts am Lagerort (älteste Haltbarkeit zuerst) oder
     * gezielt aus einer Charge.
     *
     * @param  array{recipe_id?:?int, batch_id?:?int, location_id?:?int, menge:mixed, grund?:?string, notiz?:?string}  $in
     * @return list<array{charge:string, menge_basis:float}>
     */
    public function entnehmen(Team $team, array $in, ?int $userId = null): array
    {
        $grund = (string) ($in['grund'] ?? 'verbrauch');
        if (! array_key_exists($grund, self::ENTNAHME_GRUENDE)) {
            throw new \RuntimeException('Unbekannter Grund.');
        }
        if (! empty($in['batch_id'])) {
            $einzel = FoodAlchemistInventoryBatch::where('team_id', $team->id)->findOrFail((int) $in['batch_id']);
            $chargen = collect([$einzel]);
        } else {
            $r = FoodAlchemistRecipe::visibleToTeam($team)->findOrFail((int) ($in['recipe_id'] ?? 0));
            $chargen = $this->offeneChargen($team, (int) $r->id, ! empty($in['location_id']) ? (int) $in['location_id'] : null);
        }
        if ($chargen->isEmpty() || ! $chargen->first()->istOffen()) {
            throw new \RuntimeException('Keine offene Charge im Lager.');
        }
        $base = (string) $chargen->first()->base_unit;
        $menge = $this->zahl($in['menge'] ?? null, 'Menge');
        if ($menge === null || $menge <= 0) {
            throw new \RuntimeException('Die Menge muss größer als 0 sein.');
        }
        $rest = $base === 'g' ? $menge * 1000 : $menge;
        $verfuegbar = (float) $chargen->sum(fn ($b) => (float) $b->qty_rest);
        if ($rest > $verfuegbar + 0.0001) {
            throw new \RuntimeException('Nur ' . rtrim(rtrim(number_format($this->anzeigeMenge($verfuegbar, $base), 3, ',', '.'), '0'), ',') . ' ' . $this->anzeigeEinheit($base) . ' im Lager.');
        }
        $notiz = isset($in['notiz']) && trim((string) $in['notiz']) !== '' ? trim((string) $in['notiz']) : null;

        return DB::transaction(function () use ($team, $chargen, $rest, $grund, $notiz, $userId) {
            $out = [];
            foreach ($chargen as $b) {
                if ($rest <= 0.0001) {
                    break;
                }
                $b = FoodAlchemistInventoryBatch::whereKey($b->id)->lockForUpdate()->first();
                $nimm = min($rest, (float) $b->qty_rest);
                if ($nimm <= 0) {
                    continue;
                }
                $b->update(['qty_rest' => round((float) $b->qty_rest - $nimm, 4), 'closed_at' => (float) $b->qty_rest - $nimm <= 0.0001 ? now() : null]);
                $stock = FoodAlchemistInventoryStock::whereKey($b->stock_id)->lockForUpdate()->first()
                    ?? $this->stock($team, (int) $b->inventory_location_id, (int) $b->recipe_id, (string) $b->base_unit);
                $stock->update(['qty_base' => round((float) $stock->qty_base - $nimm, 4)]);
                $this->bewegung($team, $stock, $b, 'out', $grund === 'verbrauch' ? 'entnahme' : 'abgang', $grund === 'verbrauch' ? null : $grund,
                    $nimm, trim('Charge ' . $b->charge . ($notiz ? ' · ' . $notiz : '')), $userId);
                $out[] = ['charge' => (string) $b->charge, 'menge_basis' => round($nimm, 4)];
                $rest -= $nimm;
            }

            return $out;
        });
    }

    /** @return Collection<int, FoodAlchemistInventoryBatch> offene Chargen, älteste Haltbarkeit zuerst */
    public function offeneChargen(Team $team, ?int $recipeId = null, ?int $locationId = null): Collection
    {
        return FoodAlchemistInventoryBatch::where('team_id', $team->id)->whereNull('closed_at')->where('qty_rest', '>', 0)
            ->when($recipeId !== null, fn ($q) => $q->where('recipe_id', $recipeId))
            ->when($locationId !== null, fn ($q) => $q->where('inventory_location_id', $locationId))
            ->with('recipe:id,name,is_sales_recipe', 'location:id,name')
            ->orderByRaw('best_before IS NULL')->orderBy('best_before')->orderBy('produced_at')->orderBy('id')->get();
    }

    /** Chargen, die in ≤ $tage ablaufen oder abgelaufen sind. */
    public function ablaufend(Team $team, int $tage = 3): Collection
    {
        return $this->offeneChargen($team)->filter(fn ($b) => $b->best_before !== null && $b->tageBisAblauf() <= $tage)->values();
    }

    /** Bestand je Rezept (Summe offener Chargen) — für Produktion „im Lager". @return array<int, array{menge:float, base:string, chargen:int, aeltestes_bis:?string}> */
    public function lagerJeRezept(Team $team, array $recipeIds): array
    {
        $out = [];
        foreach ($this->offeneChargen($team)->whereIn('recipe_id', $recipeIds)->groupBy('recipe_id') as $rid => $bs) {
            $out[(int) $rid] = ['menge' => (float) $bs->sum(fn ($b) => (float) $b->qty_rest), 'base' => (string) $bs->first()->base_unit,
                'chargen' => $bs->count(), 'aeltestes_bis' => $bs->first()->best_before?->toDateString()];
        }

        return $out;
    }

    /**
     * Inventur-Differenz auf die Chargen verteilen: Minus = älteste zuerst; Plus = auf die jüngste offene
     * Charge (gibt es keine, eine Korrektur-Charge ohne Haltbarkeit).
     */
    public function inventurAbgleich(Team $team, FoodAlchemistInventoryStock $stock, float $differenz, Carbon $stichtag): void
    {
        if (abs($differenz) < 0.0001 || $stock->recipe_id === null) {
            return;
        }
        $chargen = $this->offeneChargen($team, (int) $stock->recipe_id, (int) $stock->inventory_location_id);
        if ($differenz < 0) {
            $rest = -$differenz;
            foreach ($chargen as $b) {
                $nimm = min($rest, (float) $b->qty_rest);
                $b->update(['qty_rest' => round((float) $b->qty_rest - $nimm, 4), 'closed_at' => (float) $b->qty_rest - $nimm <= 0.0001 ? now() : null]);
                $rest -= $nimm;
                if ($rest <= 0.0001) {
                    break;
                }
            }

            return;
        }
        $juengste = $chargen->sortByDesc('produced_at')->first();
        if ($juengste !== null) {
            $juengste->update(['qty_rest' => round((float) $juengste->qty_rest + $differenz, 4)]);

            return;
        }
        $r = FoodAlchemistRecipe::with('standardPresentation')->find($stock->recipe_id);
        FoodAlchemistInventoryBatch::create([
            'team_id' => $team->id, 'inventory_location_id' => $stock->inventory_location_id, 'stock_id' => $stock->id, 'recipe_id' => $stock->recipe_id,
            'charge' => $this->naechsteCharge($team, $stichtag), 'produced_at' => $stichtag->toDateString(), 'storage_type' => $r?->storage_type ?: 'gekuehlt',
            'qty_initial' => round($differenz, 4), 'qty_rest' => round($differenz, 4), 'base_unit' => $stock->base_unit,
            'price_per_base' => $r !== null ? $this->preisJeBasis($r) : null, 'note' => 'Inventur-Korrektur (Herkunft unbekannt)',
        ]);
    }

    // ── intern ──────────────────────────────────────────────────────────────

    public function haltbarBis(FoodAlchemistRecipe $r, string $lagerart, Carbon $produziert, ?Carbon $eingefroren): ?Carbon
    {
        if ($lagerart === 'tiefgekuehlt') {
            return $r->shelf_life_frozen_days !== null ? ($eingefroren ?? $produziert)->copy()->addDays((int) $r->shelf_life_frozen_days) : null;
        }

        return $r->shelf_life_chilled_days !== null ? $produziert->copy()->addDays((int) $r->shelf_life_chilled_days) : null;
    }

    private function naechsteCharge(Team $team, Carbon $tag): string
    {
        $praefix = 'C' . $tag->format('ymd') . '-';
        $n = FoodAlchemistInventoryBatch::withTrashed()->where('team_id', $team->id)->where('charge', 'like', $praefix . '%')->count();

        return $praefix . str_pad((string) ($n + 1), 2, '0', STR_PAD_LEFT);
    }

    private function stock(Team $team, int $ortId, int $recipeId, string $base): FoodAlchemistInventoryStock
    {
        return FoodAlchemistInventoryStock::where('team_id', $team->id)->where('inventory_location_id', $ortId)
            ->where('recipe_id', $recipeId)->where('base_unit', $base)->lockForUpdate()->first()
            ?? FoodAlchemistInventoryStock::create(['team_id' => $team->id, 'inventory_location_id' => $ortId, 'recipe_id' => $recipeId,
                'gp_id' => null, 'supplier_item_id' => null, 'qty_base' => 0, 'base_unit' => $base]);
    }

    private function bewegung(Team $team, FoodAlchemistInventoryStock $stock, FoodAlchemistInventoryBatch $b, string $dir, string $source, ?string $grund, float $qty, string $note, ?int $userId): void
    {
        $preis = $b->price_per_base !== null ? (float) $b->price_per_base : null;
        FoodAlchemistInventoryMovement::create([
            'team_id' => $team->id, 'stock_id' => $stock->id, 'inventory_location_id' => $stock->inventory_location_id,
            'recipe_id' => $b->recipe_id, 'batch_id' => $b->id, 'direction' => $dir, 'qty_base' => round($qty, 4), 'base_unit' => $b->base_unit,
            'source' => $source, 'reason' => $grund, 'price_per_base' => $preis, 'value_eur' => $preis !== null ? round($qty * $preis, 2) : null,
            'booked_by' => $userId, 'moved_at' => now(), 'note' => $note, 'source_hash' => sha1('fa_eigen:' . Str::uuid()),
        ]);
    }

    private function standardLager(Team $team): FoodAlchemistInventoryLocation
    {
        return FoodAlchemistInventoryLocation::where('team_id', $team->id)->where('is_active', true)->orderByDesc('is_default')->orderBy('name')->first()
            ?? FoodAlchemistInventoryLocation::create(['team_id' => $team->id, 'name' => 'Hauptlager', 'type' => 'warehouse', 'is_default' => true, 'is_active' => true]);
    }

    private function zahl(mixed $v, string $was): ?float
    {
        $roh = trim(str_replace(',', '.', (string) ($v ?? '')));
        if ($roh === '') {
            return null;
        }
        if (! is_numeric($roh) || (float) $roh < 0) {
            throw new \RuntimeException($was . ' braucht eine Zahl ≥ 0.');
        }

        return (float) $roh;
    }

    private function datum(mixed $v): ?Carbon
    {
        return $v !== null && trim((string) $v) !== '' ? Carbon::parse((string) $v)->startOfDay() : null;
    }
}
