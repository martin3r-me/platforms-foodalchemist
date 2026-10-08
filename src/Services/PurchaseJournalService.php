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

    /**
     * Spec 66 §4 · Einkauf eines Grundprodukts über die Zeit: Menge + € je Monat (lückenlos über
     * $monate), je Lieferant, Summe. Mengen in kg / l / Stk (g/ml umgerechnet); gemischte Einheiten
     * werden je Einheit geführt, die Monatsreihe zeigt die häufigste. Team-strikt.
     *
     * @return array{einheit: ?string, summe_menge: float, summe_eur: float, monate: list<array>, lieferanten: list<array>, letzter_kauf: ?string, positionen: int}
     */
    public function gpEinkauf(Team $team, int $gpId, int $monate = 12): array
    {
        $monate = max(1, min(36, $monate));
        $von = now()->startOfMonth()->subMonths($monate - 1);
        $zeilen = FoodAlchemistPurchaseTransaction::query()
            ->where('team_id', $team->id)->where('gp_id', $gpId)
            ->whereDate('purchased_at', '>=', $von->toDateString())
            ->with('supplier:id,name')
            ->get(['id', 'supplier_id', 'supplier_name_raw', 'unit_code', 'qty', 'line_total', 'purchased_at']);

        $norm = function ($z): array {
            $u = (string) $z->unit_code;
            $q = (float) $z->qty;

            return match (mb_strtolower($u)) {
                'g' => ['kg', $q / 1000],
                'ml' => ['l', $q / 1000],
                'kg' => ['kg', $q],
                'l', 'lt' => ['l', $q],
                'stk', 'st', 'stück' => ['Stk', $q],
                default => [$u !== '' ? $u : '?', $q],
            };
        };
        $einheitZaehler = [];
        foreach ($zeilen as $z) {
            $e = $norm($z)[0];
            $einheitZaehler[$e] = ($einheitZaehler[$e] ?? 0) + 1;
        }
        arsort($einheitZaehler);
        $einheit = array_key_first($einheitZaehler);

        $reihe = [];
        for ($i = 0; $i < $monate; $i++) {
            $m = $von->copy()->addMonths($i);
            $reihe[$m->format('Y-m')] = ['monat' => $m->format('Y-m'), 'label' => $m->locale('de')->isoFormat('MMM YY'), 'menge' => 0.0, 'eur' => 0.0];
        }
        $lief = [];
        $summeMenge = 0.0;
        $summeEur = 0.0;
        foreach ($zeilen as $z) {
            [$e, $q] = $norm($z);
            $eur = (float) $z->line_total;
            $key = $z->purchased_at?->format('Y-m');
            if ($key !== null && isset($reihe[$key])) {
                $reihe[$key]['eur'] += $eur;
                if ($e === $einheit) {
                    $reihe[$key]['menge'] += $q;
                }
            }
            if ($e === $einheit) {
                $summeMenge += $q;
            }
            $summeEur += $eur;
            $name = $z->supplier?->name ?? ($z->supplier_name_raw ?: 'ohne Lieferant');
            $lief[$name] ??= ['lieferant' => $name, 'menge' => 0.0, 'eur' => 0.0, 'einheit' => $einheit, 'zuletzt' => null, 'positionen' => 0];
            $lief[$name]['eur'] += $eur;
            $lief[$name]['positionen']++;
            if ($e === $einheit) {
                $lief[$name]['menge'] += $q;
            }
            $d = $z->purchased_at?->toDateString();
            if ($d !== null && ($lief[$name]['zuletzt'] === null || $d > $lief[$name]['zuletzt'])) {
                $lief[$name]['zuletzt'] = $d;
            }
        }
        $lief = array_values($lief);
        usort($lief, fn ($a, $b) => $b['eur'] <=> $a['eur']);
        foreach ($lief as &$l) {
            $l['menge'] = round($l['menge'], 3);
            $l['eur'] = round($l['eur'], 2);
            $l['preis_je_einheit'] = $l['menge'] > 0 ? round($l['eur'] / $l['menge'], 2) : null;
        }
        unset($l);

        return [
            'einheit' => $einheit,
            'summe_menge' => round($summeMenge, 3),
            'summe_eur' => round($summeEur, 2),
            'monate' => array_values(array_map(fn ($r) => ['menge' => round($r['menge'], 3), 'eur' => round($r['eur'], 2)] + $r, $reihe)),
            'lieferanten' => $lief,
            'letzter_kauf' => $zeilen->max(fn ($z) => $z->purchased_at?->toDateString()),
            'positionen' => $zeilen->count(),
            'gemischte_einheiten' => count($einheitZaehler) > 1,
        ];
    }

    private function basisQuery(Team $team, ?string $von, ?string $bis)
    {
        // Spec 77c: Team-Brille — Oberteam liest auf Wunsch den Einkauf seiner Standorte (konsolidiert)
        return app(StandortService::class)->leseBereich(FoodAlchemistPurchaseTransaction::query(), $team)
            ->when($von !== null, fn ($q) => $q->whereDate('purchased_at', '>=', $von))
            ->when($bis !== null, fn ($q) => $q->whereDate('purchased_at', '<=', $bis));
    }
}
