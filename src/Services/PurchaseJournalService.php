<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistOrder;
use Platform\FoodAlchemist\Models\FoodAlchemistPurchaseTransaction;

/**
 * Einkauf E2 — Einkaufsjournal (Ist-Einkäufe).
 *
 * Hier läuft die FA-native Quelle des Journals zusammen: eine Bestellschiene, die den
 * konfigurierten Auslöse-Status erreicht (sent|delivered, TeamSettingsService), wird
 * Zeile für Zeile als Ist-Transaktion gespiegelt (source=fa_order). Idempotent über
 * source_hash (= sha1 der Order/Line-Ref): erneutes Spiegeln UPDATET dieselbe Zeile,
 * Storno/Rücknahme entfernt sie wieder. Der Necta-Bulk-Import (source=necta_import)
 * schreibt in dieselbe Tabelle, teilt sich aber NICHT diesen Pfad.
 *
 * team-scoped; KEIN customer_id (Kunden-Dimension = eigene Session), aber die
 * Aggregat-Reads sind so geschnitten, dass später eine customer_id-Achse danebenpasst.
 */
class PurchaseJournalService
{
    /** Zeilen einer Bestellschiene als Ist-Einkäufe ins Journal spiegeln (idempotent). */
    public function spiegelOrder(FoodAlchemistOrder $order): int
    {
        $order->loadMissing('lines');
        $datum = ($order->delivered_at ?? $order->sent_at ?? now())->toDateString();

        // Warengruppen der referenzierten GPs einmal batchen (für WG-scoped Optimierung/Rückvergütung).
        $gpIds = $order->lines->pluck('gp_id')->filter()->unique()->all();
        $wgMap = $gpIds === []
            ? []
            : DB::table('foodalchemist_gps')->whereIn('id', $gpIds)
                ->pluck('commodity_group_code', 'id')->all();

        $n = 0;
        foreach ($order->lines as $line) {
            // Ist-Einkauf = berechnete Menge (Rechnung) → gelieferte Menge (Wareneingang) → bestellte
            // Menge; Preis aus der Rechnung, sonst Bestellpreis (Spec 66 §1). Vorher immer „bestellt".
            $packs = (float) ($line->invoice_qty_packs ?? $line->received_qty_packs ?? $line->qty_packs);
            if ($packs <= 0) {
                // Leerzeile / nichts geliefert — kein Ist-Einkauf; eine früher gespiegelte Zeile fällt raus.
                FoodAlchemistPurchaseTransaction::where('team_id', $order->team_id)
                    ->where('source_hash', sha1("order:{$order->id}:line:{$line->id}"))->delete();

                continue;
            }
            $packPrice = $line->invoice_pack_price ?? $line->pack_price;
            $packQty = $line->pack_qty !== null ? (float) $line->pack_qty : null;
            $qty = $packQty !== null && $packQty > 0 ? $packs * $packQty : $packs;
            $unitPrice = $packQty !== null && $packQty > 0 && $packPrice !== null
                ? round((float) $packPrice / $packQty, 4)
                : ($packPrice !== null ? (float) $packPrice : null);
            $lineTotal = $packPrice !== null ? round($packs * (float) $packPrice, 2) : (float) $line->line_total;

            $ref = "order:{$order->id}:line:{$line->id}";
            FoodAlchemistPurchaseTransaction::updateOrCreate(
                ['team_id' => $order->team_id, 'source_hash' => sha1($ref)],
                [
                    'supplier_id' => $order->supplier_id,
                    'supplier_item_id' => $line->supplier_item_id,
                    'gp_id' => $line->gp_id,
                    'designation_raw' => (string) ($line->designation ?? ''),
                    'unit_code' => $line->unit_code,
                    'qty' => $qty,
                    'unit_price' => $unitPrice,
                    'line_total' => $lineTotal,
                    'purchased_at' => $datum,
                    'commodity_group' => $line->gp_id !== null ? ($wgMap[$line->gp_id] ?? null) : null,
                    'source' => 'fa_order',
                    'source_ref' => $ref,
                    'deleted_at' => null,   // eine zuvor stornierte Zeile bei Wieder-Buchung reaktivieren
                ]
            );
            $n++;
        }

        return $n;
    }

    /** Steht diese Bestellung schon im Journal? (Dann ziehen Wareneingang/Rechnung es nach.) */
    public function istGespiegelt(FoodAlchemistOrder $order): bool
    {
        return FoodAlchemistPurchaseTransaction::where('team_id', $order->team_id)
            ->where('source', 'fa_order')->where('source_ref', 'like', "order:{$order->id}:line:%")->exists();
    }

    /** Alle FA-Order-Transaktionen einer Bestellschiene entfernen (Storno/Rücknahme). */
    public function entferneOrder(FoodAlchemistOrder $order): int
    {
        return FoodAlchemistPurchaseTransaction::where('team_id', $order->team_id)
            ->where('source', 'fa_order')
            ->where('source_ref', 'like', "order:{$order->id}:%")
            ->delete();
    }

    /** Ist-Spend (Summe line_total, netto) — team-scoped, optional je Lieferant + Zeitfenster. */
    public function spend(Team $team, ?int $supplierId = null, ?string $von = null, ?string $bis = null): float
    {
        return (float) $this->basisQuery($team, $von, $bis)
            ->when($supplierId !== null, fn ($q) => $q->where('supplier_id', $supplierId))
            ->sum('line_total');
    }

    /**
     * Ist-Spend je Lieferant (absteigend) — Grundlage für erreichte Rückvergütungs-Stufe
     * und Bündelungs-Ranking aus ECHTEN Daten (statt Nutzungs-Proxy).
     *
     * @return array<int, float> supplier_id => spend
     */
    public function spendProLieferant(Team $team, ?string $von = null, ?string $bis = null): array
    {
        return $this->basisQuery($team, $von, $bis)
            ->whereNotNull('supplier_id')
            ->selectRaw('supplier_id, SUM(line_total) AS spend')
            ->groupBy('supplier_id')
            ->pluck('spend', 'supplier_id')
            ->map(fn ($v) => (float) $v)->all();
    }

    private function basisQuery(Team $team, ?string $von, ?string $bis)
    {
        return FoodAlchemistPurchaseTransaction::query()
            ->where('team_id', $team->id)
            ->when($von !== null, fn ($q) => $q->whereDate('purchased_at', '>=', $von))
            ->when($bis !== null, fn ($q) => $q->whereDate('purchased_at', '<=', $bis));
    }
}
