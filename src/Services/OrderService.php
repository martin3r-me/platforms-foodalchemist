<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\OrderStatus;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistOrder;
use Platform\FoodAlchemist\Models\FoodAlchemistOrderLine;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;

/**
 * Spec 17 / S2 — Bestellschienen-Motor (N-Track, OHNE Bestand).
 *
 * Eine Schiene = ein offener `draft`-Order je (team, supplier), der Bedarf sammelt (E1).
 * `addNeedFromTarget` übernimmt Bedarf beliebiger Granularität — Konzept/Event, Gericht
 * oder einzelne Produktion (E9) — je Lieferant in seine Schiene. Die Bestellzeile liegt
 * PRO ARTIKEL; `source_contributions` {source_ref: base_g} trägt die Quell-Beiträge:
 * needed_base_g = Summe, qty_packs = ceil(Summe ÷ Gebinde) via GebindeRechner — Aufrundung
 * IMMER aufs Aggregat (E3), Re-Import einer Quelle überschreibt nur ihren Schlüssel (E10).
 * Im draft werden Snapshot/Preis live aus dem aktiven Preis aufgefrischt (E11); `send`
 * friert den Beleg ein (E2). Rechen-Wahrheit = derselbe `GebindeRechner` wie S0 (kein Drift).
 *
 * Schreibt nur eigene Team-Belege (isOwnedBy) und nur solange `draft`.
 */
class OrderService
{
    public function __construct(
        private PlanungsblattService $planung,
        private PriceService $preise,
        private GebindeRechner $gebinde,
    ) {
    }

    // ── Schiene holen/anlegen ─────────────────────────────────────────────

    /** Ein-offener-draft-Guard je (team, supplier): Transaktion + Lock gegen Doppelklick. */
    public function draftForSupplier(Team $team, int $supplierId, ?int $userId = null): FoodAlchemistOrder
    {
        return DB::transaction(function () use ($team, $supplierId, $userId) {
            $draft = FoodAlchemistOrder::where('team_id', $team->id)
                ->where('supplier_id', $supplierId)
                ->where('status', OrderStatus::Draft->value)
                ->lockForUpdate()
                ->first();

            return $draft ?? FoodAlchemistOrder::create([
                'team_id' => $team->id,
                'supplier_id' => $supplierId,
                'status' => OrderStatus::Draft->value,
                'created_by' => $userId,
                'total_net' => 0,
            ]);
        });
    }

    // ── Bedarf in die Schienen übernehmen (E9 Granularität, E10 idempotent) ──

    /**
     * Übernimmt den Bedarf EINES Ziels (concept_id|recipe_id + Menge) in die passenden
     * Lieferanten-Schienen. `sourceRef` identifiziert die Quelle (Re-Import ersetzt sie, E10).
     *
     * @param  array{concept_id?:int, recipe_id?:int, persons?:int|float, portions?:int|float}  $ziel
     * @return array{orders:list<int>, skipped_ohne_la:list<string>, warnungen:list<string>}
     */
    public function addNeedFromTarget(Team $team, array $ziel, string $sourceRef, ?int $userId = null): array
    {
        $vorschlag = $this->planung->bestellvorschlag($team, $ziel);
        $touched = [];
        $skipped = [];

        foreach ($vorschlag['lieferanten'] as $grp) {
            $supplierId = $grp['supplier_id'] ?? null;
            if ($supplierId === null) {            // Bucket „ohne Lead-LA" → nicht bestellbar (Sourcing/R9)
                $skipped[] = (string) ($grp['lieferant'] ?? '—');

                continue;
            }
            $draft = $this->draftForSupplier($team, (int) $supplierId, $userId);
            foreach ($grp['positionen'] as $pos) {
                $this->upsertContribution($team, $draft, $pos, $sourceRef);
            }
            $this->recomputeOrder($draft->refresh());
            $touched[] = (int) $draft->id;
        }

        return [
            'orders' => array_values(array_unique($touched)),
            'skipped_ohne_la' => array_values(array_unique($skipped)),
            'warnungen' => $vorschlag['warnungen'] ?? [],
        ];
    }

    /** Setzt/ersetzt den Beitrag EINER Quelle an der Artikel-Zeile (E10). */
    private function upsertContribution(Team $team, FoodAlchemistOrder $draft, array $pos, string $sourceRef): void
    {
        $laId = $pos['lead_la_id'] ?? null;
        $gpId = $pos['gp_id'] ?? null;
        $g = round((float) ($pos['menge_g'] ?? 0), 2);
        if ($g <= 0) {
            return;
        }

        $query = FoodAlchemistOrderLine::where('order_id', $draft->id);
        $laId !== null
            ? $query->where('supplier_item_id', $laId)
            : $query->whereNull('supplier_item_id')->where('gp_id', $gpId);
        $line = $query->first();

        if ($line === null) {
            $line = new FoodAlchemistOrderLine([
                'team_id' => $team->id,
                'order_id' => $draft->id,
                'supplier_item_id' => $laId,
                'gp_id' => $gpId,
                'source_contributions' => [],
                'position' => (int) FoodAlchemistOrderLine::where('order_id', $draft->id)->max('position') + 1,
            ]);
        }

        $contrib = $line->source_contributions ?? [];
        $contrib[$sourceRef] = $g;                 // E10: gleiche Quelle ersetzt ihren Beitrag
        $line->source_contributions = $contrib;
        $line->save();
    }

    // ── Recompute (E3 Aggregat-Rundung, E11 Live-Preis im draft) ──────────

    /** Zeile neu rechnen: needed_base_g = Σ Beiträge, Gebinde/Preis via GebindeRechner. */
    public function recomputeLine(FoodAlchemistOrderLine $line): void
    {
        $sum = array_sum(array_map('floatval', $line->source_contributions ?? []));
        $line->needed_base_g = round($sum, 2);

        [$ctx, $pieceG] = $this->leadContext($line);
        $geb = $this->gebinde->berechne($ctx, $sum, $pieceG);

        // Snapshot im draft immer auffrischen (E11); beim send bleibt der letzte Stand stehen (E2).
        $line->article_number = $geb['article_number'] ?? ($ctx->article_number ?? null);
        $line->designation = $ctx->designation ?? $line->designation;
        $line->packaging_unit = $geb['packaging_unit'] ?? ($ctx->packaging_unit ?? null);
        $line->pack_qty = $geb['pack_qty'];
        $line->unit_code = $geb['pack_unit_code'] ?? ($ctx->unit_code ?? null);
        $line->pack_price = $geb['pack_price'];

        if (! $line->is_manual_qty) {
            $line->qty_packs = $geb['qty_packs'] ?? 0;
        }
        $packs = (float) $line->qty_packs;
        $line->line_total = $geb['pack_price'] !== null ? round($packs * (float) $geb['pack_price'], 2) : 0;
        $line->save();
    }

    /** Lead-Kontext (Plain-Objekt für GebindeRechner) + Stückgewicht aus dem konkreten LA. */
    private function leadContext(FoodAlchemistOrderLine $line): array
    {
        if ($line->supplier_item_id === null) {
            return [null, null];
        }
        $la = FoodAlchemistSupplierItem::find($line->supplier_item_id);
        if ($la === null) {
            return [null, null];
        }
        $price = $this->preise->activeFor((int) $la->id);
        $ctx = (object) [
            'qty' => $la->qty !== null ? (float) $la->qty : null,
            'unit_code' => $la->unit_code,
            'packaging_unit' => $la->packaging_unit,
            'article_number' => $la->article_number,
            'designation' => $la->designation,
            'aktiver_preis' => $price?->price !== null ? (float) $price->price : null,
        ];
        $pieceG = null;
        if ($line->gp_id !== null) {
            $gp = FoodAlchemistGp::find($line->gp_id);
            $pieceG = $gp?->piece_default_g !== null ? (float) $gp->piece_default_g : null;
        }

        return [$ctx, $pieceG];
    }

    /** Ganze Schiene neu rechnen: leere Zeilen wegräumen (draft), Zeilen auffrischen, total_net. */
    public function recomputeOrder(FoodAlchemistOrder $order): void
    {
        if ($order->status === OrderStatus::Draft) {
            foreach ($order->lines()->get() as $line) {
                if (empty($line->source_contributions) && ! $line->is_manual_qty) {
                    $line->delete();

                    continue;
                }
                $this->recomputeLine($line);
            }
        }
        $order->total_net = round((float) $order->lines()->sum('line_total'), 2);
        $order->save();
    }

    // ── Manuelle Pflege (nur im draft, nur Besitzer) ──────────────────────

    /** Gebinde-Anzahl manuell übersteuern (bleibt bei Recompute stehen) oder Notiz setzen. */
    public function updateLine(Team $team, int $lineId, array $input): FoodAlchemistOrderLine
    {
        $line = $this->ownedDraftLine($team, $lineId);
        if (array_key_exists('qty_packs', $input) && $input['qty_packs'] !== '' && $input['qty_packs'] !== null) {
            $line->qty_packs = max(0, (float) $input['qty_packs']);
            $line->is_manual_qty = true;
            $line->line_total = $line->pack_price !== null ? round((float) $line->qty_packs * (float) $line->pack_price, 2) : 0;
        }
        if (array_key_exists('reset_qty', $input) && $input['reset_qty']) {
            $line->is_manual_qty = false;   // nächster Recompute rechnet wieder automatisch
        }
        if (array_key_exists('note', $input)) {
            $line->note = ($input['note'] ?? '') ?: null;
        }
        $line->save();
        $this->recomputeOrder($line->order()->first());

        return $line->refresh();
    }

    public function removeLine(Team $team, int $lineId): void
    {
        $line = $this->ownedDraftLine($team, $lineId);
        $order = $line->order()->first();
        $line->delete();
        $this->recomputeOrder($order);
    }

    // ── Status-Lebenszyklus (guarded) ─────────────────────────────────────

    public function setStatus(Team $team, int $orderId, OrderStatus $ziel): FoodAlchemistOrder
    {
        $order = $this->ownedOrder($team, $orderId);
        $aktuell = $order->status instanceof OrderStatus ? $order->status : OrderStatus::from((string) $order->status);
        if ($aktuell === $ziel) {
            return $order;
        }
        if (! $aktuell->darfWechselnZu($ziel)) {
            throw new \RuntimeException("Status {$aktuell->value} → {$ziel->value} nicht erlaubt.");
        }
        // Beim Absenden: Snapshot einfrieren = letzten draft-Stand rechnen, dann Status setzen.
        if ($ziel === OrderStatus::Sent) {
            $this->recomputeOrder($order);
            $order->sent_at = now();
        } elseif ($ziel === OrderStatus::Confirmed) {
            $order->confirmed_at = now();
        } elseif ($ziel === OrderStatus::Delivered) {
            $order->delivered_at = now();   // manueller Haken, KEINE Bestandsbuchung (E4)
        }
        $order->status = $ziel;
        $order->save();

        return $order;
    }

    // ── Lesen / Aggregate ─────────────────────────────────────────────────

    /** @return Collection<int, FoodAlchemistOrder> */
    public function listForTeam(Team $team, ?string $status = null): Collection
    {
        return FoodAlchemistOrder::visibleToTeam($team)
            ->with('supplier:id,name')
            ->when($status !== null, fn ($q) => $q->where('status', $status))
            ->orderByRaw("CASE status WHEN 'draft' THEN 0 ELSE 1 END")
            ->orderByDesc('updated_at')
            ->get();
    }

    /** Detail-Aggregat für UI/MCP inkl. MOQ-Ampel. */
    public function detail(Team $team, int $orderId): array
    {
        $order = FoodAlchemistOrder::visibleToTeam($team)->with(['supplier', 'lines'])->findOrFail($orderId);
        $status = $order->status instanceof OrderStatus ? $order->status : OrderStatus::from((string) $order->status);

        return [
            'id' => (int) $order->id,
            'supplier_id' => (int) $order->supplier_id,
            'supplier' => $order->supplier?->name,
            'status' => $status->value,
            'status_label' => $status->label(),
            'reference' => $order->reference,
            'desired_delivery_date' => $order->desired_delivery_date?->toDateString(),
            'note' => $order->note,
            'total_net' => (float) $order->total_net,
            'is_owned' => $order->isOwnedBy($team),
            'editierbar' => $status->istOffen() && $order->isOwnedBy($team),
            'moq' => $this->moqAmpel($order),
            'zeilen' => $order->lines->map(fn ($l) => [
                'id' => (int) $l->id,
                'gp_id' => $l->gp_id !== null ? (int) $l->gp_id : null,
                'supplier_item_id' => $l->supplier_item_id !== null ? (int) $l->supplier_item_id : null,
                'article_number' => $l->article_number,
                'designation' => $l->designation,
                'packaging_unit' => $l->packaging_unit,
                'pack_qty' => $l->pack_qty !== null ? (float) $l->pack_qty : null,
                'unit_code' => $l->unit_code,
                'qty_packs' => (float) $l->qty_packs,
                'is_manual_qty' => (bool) $l->is_manual_qty,
                'pack_price' => $l->pack_price !== null ? (float) $l->pack_price : null,
                'line_total' => (float) $l->line_total,
                'needed_base_g' => (float) $l->needed_base_g,
                'note' => $l->note,
                'bestellbar' => $l->pack_price !== null && (float) $l->qty_packs > 0,
            ])->all(),
        ];
    }

    /** MOQ-/Frei-Haus-Ampel: total_net gegen Lieferanten-Konditionen (R9). */
    public function moqAmpel(FoodAlchemistOrder $order): array
    {
        $supplier = $order->supplier ?? FoodAlchemistSupplier::find($order->supplier_id);
        $min = $supplier?->min_order_value !== null ? (float) $supplier->min_order_value : null;
        $frei = $supplier?->free_shipping_threshold !== null ? (float) $supplier->free_shipping_threshold : null;
        $total = (float) $order->total_net;

        return [
            'total_net' => $total,
            'min_order_value' => $min,
            'free_shipping_threshold' => $frei,
            'unter_mindestbestellwert' => $min !== null && $total < $min,
            'fehlt_bis_min' => $min !== null && $total < $min ? round($min - $total, 2) : 0.0,
            'frei_haus' => $frei !== null && $total >= $frei,
            'fehlt_bis_frei_haus' => $frei !== null && $total < $frei ? round($frei - $total, 2) : 0.0,
        ];
    }

    // ── Guards ────────────────────────────────────────────────────────────

    private function ownedOrder(Team $team, int $orderId): FoodAlchemistOrder
    {
        $order = FoodAlchemistOrder::visibleToTeam($team)->findOrFail($orderId);
        if (! $order->isOwnedBy($team)) {
            throw new \RuntimeException('Bestellung nicht im Schreibzugriff (D1).');
        }

        return $order;
    }

    private function ownedDraftLine(Team $team, int $lineId): FoodAlchemistOrderLine
    {
        $line = FoodAlchemistOrderLine::with('order')->findOrFail($lineId);
        $order = $line->order;
        if ($order === null || ! $order->isOwnedBy($team)) {
            throw new \RuntimeException('Bestellzeile nicht im Schreibzugriff (D1).');
        }
        $status = $order->status instanceof OrderStatus ? $order->status : OrderStatus::from((string) $order->status);
        if (! $status->istOffen()) {
            throw new \RuntimeException('Nur ein offener Entwurf ist editierbar.');
        }

        return $line;
    }
}
