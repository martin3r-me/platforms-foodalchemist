<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Str;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\ProductionLineStatus;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistInventoryMovement;
use Platform\FoodAlchemist\Models\FoodAlchemistInventoryStock;
use Platform\FoodAlchemist\Models\FoodAlchemistProductionOrder;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;

/**
 * Spec 72 · Produktion bucht aus dem Lager. Beim Fertigmelden eines Produktionsauftrags wird der
 * Verbrauch exakt gebucht (auch kleinste Mengen):
 *  - Grundprodukte: Zutatenmenge × Ansätze jeder produzierten Zeile (gestrichen/übersprungen zählt nicht),
 *    aus den aktiven Lagerorten (Standard-Lagerort zuerst). Nie unter 0 — was fehlt, wird gemeldet.
 *  - Komponenten aus Eigenproduktion: ein Sub-Rezept, das in diesem Auftrag NICHT produziert wurde,
 *    kommt aus dem Lager → FIFO-Entnahme der Chargen (Verbrauch).
 * Idempotent je Auftrag (`production_order_id` an der Bewegung). Quelle `produktion` zählt im
 * Controlling nicht als erklärter Abgang, sie senkt den Soll-Bestand der nächsten Inventur.
 */
class ProduktionsVerbrauchService
{
    public function __construct(
        private RecipeRecomputeService $recompute,
        private InventurService $inventur,
        private EigenproduktionService $eigen,
    ) {}

    /** @return array{gebucht: list<array>, fehlt: list<array>, bereits_gebucht: bool} */
    public function ausbuchen(Team $team, FoodAlchemistProductionOrder $order, ?int $userId = null): array
    {
        if (FoodAlchemistInventoryMovement::where('team_id', $team->id)->where('production_order_id', $order->id)->exists()) {
            return ['gebucht' => [], 'fehlt' => [], 'bereits_gebucht' => true];
        }
        [$gpGramm, $rezeptGramm] = $this->verbrauch($order);
        $gebucht = [];
        $fehlt = [];
        $gps = FoodAlchemistGp::whereIn('id', array_keys($gpGramm) ?: [0])->get(['id', 'name', 'piece_default_g'])->keyBy('id');
        foreach ($gpGramm as $gpId => $gramm) {
            $gp = $gps[$gpId] ?? null;
            if ($gp === null || $gramm <= 0) {
                continue;
            }
            [$weg, $rest] = $this->gpAusbuchen($team, $order, $gp, $gramm, $userId);
            if ($weg > 0) {
                $gebucht[] = ['art' => 'gp', 'id' => (int) $gpId, 'name' => $gp->name, 'menge_g' => round($weg, 4)];
            }
            if ($rest > 0.5) {
                $fehlt[] = ['art' => 'gp', 'id' => (int) $gpId, 'name' => $gp->name, 'menge_g' => round($rest, 1)];
            }
        }
        $lager = $this->eigen->lagerJeRezept($team, array_keys($rezeptGramm));
        $namen = FoodAlchemistRecipe::whereIn('id', array_keys($rezeptGramm) ?: [0])->pluck('name', 'id');
        foreach ($rezeptGramm as $rid => $gramm) {
            $l = $lager[$rid] ?? null;
            $nimm = $l !== null && $l['base'] === 'g' ? min($gramm, (float) $l['menge']) : 0.0;
            if ($nimm > 0.0001) {
                $this->eigen->entnehmen($team, ['recipe_id' => $rid, 'menge' => $nimm / 1000, 'grund' => 'verbrauch',
                    'notiz' => 'Produktion ' . ($order->name ?: '#' . $order->id), 'production_order_id' => (int) $order->id], $userId);
                $gebucht[] = ['art' => 'rezept', 'id' => (int) $rid, 'name' => (string) ($namen[$rid] ?? ''), 'menge_g' => round($nimm, 4)];
            }
            if ($gramm - $nimm > 0.5) {
                $fehlt[] = ['art' => 'rezept', 'id' => (int) $rid, 'name' => (string) ($namen[$rid] ?? ''), 'menge_g' => round($gramm - $nimm, 1)];
            }
        }

        return ['gebucht' => $gebucht, 'fehlt' => $fehlt, 'bereits_gebucht' => false];
    }

    /**
     * Verbrauch je GP und je nicht-produziertem Sub-Rezept in g.
     *
     * @return array{0: array<int, float>, 1: array<int, float>}
     */
    public function verbrauch(FoodAlchemistProductionOrder $order): array
    {
        $zeilen = $order->lines()->where('is_struck', false)->whereNotNull('recipe_id')
            ->where(fn ($q) => $q->whereNull('line_status')->orWhere('line_status', '!=', ProductionLineStatus::Skipped->value))->get();
        $produziert = $zeilen->pluck('recipe_id')->map(fn ($id) => (int) $id)->all();
        $rezepte = FoodAlchemistRecipe::with(['ingredients.unit', 'ingredients.gp'])->whereIn('id', $produziert ?: [0])->get()->keyBy('id');
        $gp = [];
        $rezept = [];
        foreach ($zeilen as $zeile) {
            $r = $rezepte[(int) $zeile->recipe_id] ?? null;
            $ansaetze = (float) $zeile->ansaetze_effektiv;
            if ($r === null || $ansaetze <= 0) {
                continue;
            }
            foreach ($r->ingredients as $z) {
                if ($z->is_optional || $z->unit?->slug === 'qs') {
                    continue;
                }
                $g = $this->recompute->bruttoMasseG($z) * $ansaetze;
                if ($g <= 0) {
                    continue;
                }
                if ($z->referenced_recipe_id !== null) {
                    if (! in_array((int) $z->referenced_recipe_id, $produziert, true)) {
                        $rezept[(int) $z->referenced_recipe_id] = ($rezept[(int) $z->referenced_recipe_id] ?? 0) + $g;
                    }
                } elseif ($z->gp_id !== null) {
                    $gp[(int) $z->gp_id] = ($gp[(int) $z->gp_id] ?? 0) + $g;
                }
            }
        }

        return [$gp, $rezept];
    }

    /** @return array{0: float, 1: float} [gebucht g, fehlt g] */
    private function gpAusbuchen(Team $team, FoodAlchemistProductionOrder $order, FoodAlchemistGp $gp, float $gramm, ?int $userId): array
    {
        $bestaende = FoodAlchemistInventoryStock::query()
            ->where('foodalchemist_inventory_stocks.team_id', $team->id)->where('foodalchemist_inventory_stocks.gp_id', $gp->id)
            ->whereNull('foodalchemist_inventory_stocks.supplier_item_id')->where('qty_base', '>', 0)
            ->join('foodalchemist_inventory_locations as l', 'l.id', '=', 'foodalchemist_inventory_stocks.inventory_location_id')
            ->where('l.is_active', true)->whereNull('l.deleted_at')
            ->orderByDesc('l.is_default')->orderByDesc('qty_base')
            ->select('foodalchemist_inventory_stocks.*')->lockForUpdate()->get();
        $rest = $gramm;
        $weg = 0.0;
        foreach ($bestaende as $s) {
            $jeBasis = match ($s->base_unit) {
                'g', 'ml' => 1.0,
                'Stk' => (float) ($gp->piece_default_g ?? 0),
                default => 0.0,
            };
            if ($jeBasis <= 0 || $rest <= 0.0001) {
                continue;
            }
            $nimmBasis = min($rest / $jeBasis, (float) $s->qty_base);
            if ($nimmBasis <= 0) {
                continue;
            }
            $s->update(['qty_base' => round((float) $s->qty_base - $nimmBasis, 4)]);
            $preis = $this->inventur->preisJeBasis($team, $gp, null, (string) $s->base_unit);
            FoodAlchemistInventoryMovement::create([
                'team_id' => $team->id, 'stock_id' => $s->id, 'inventory_location_id' => $s->inventory_location_id, 'gp_id' => $gp->id,
                'direction' => 'out', 'qty_base' => round($nimmBasis, 4), 'base_unit' => $s->base_unit, 'source' => 'produktion',
                'source_hash' => sha1('fa_produktion:' . $order->id . ':' . $s->id . ':' . Str::uuid()), 'moved_at' => now(),
                'price_per_base' => $preis, 'value_eur' => $preis !== null ? round($preis * $nimmBasis, 2) : null,
                'booked_by' => $userId, 'production_order_id' => $order->id,
                'note' => 'Produktion ' . ($order->name ?: '#' . $order->id),
            ]);
            $rest -= $nimmBasis * $jeBasis;
            $weg += $nimmBasis * $jeBasis;
        }

        return [$weg, max(0.0, $rest)];
    }
}
