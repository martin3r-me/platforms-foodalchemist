<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\OrderStatus;
use Platform\FoodAlchemist\Models\FoodAlchemistDeliveryNote;
use Platform\FoodAlchemist\Models\FoodAlchemistDeliveryNoteLine;
use Platform\FoodAlchemist\Models\FoodAlchemistOrder;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierInvoice;
use Platform\FoodAlchemist\Services\TripleMatchService;

/**
 * ONE-SHOT Spec 75c: Bestand übernehmen. Vor Spec 75 standen Wareneingang und Rechnungsprüfung nur als
 * Felder an der Bestellzeile (`received_qty_packs`, `invoice_qty_packs`/`invoice_pack_price`). Damit der
 * Abgleich auch die Vergangenheit zeigt und die Zeile die Summe ihrer Belege ist, legt das Kommando je
 * Bestellung einen Altbestand-Lieferschein und eine Altbestand-Rechnung (`source = altbestand`) für den
 * Teil an, den noch kein Beleg abdeckt.
 *
 * Schreibt NUR Belege — keine Ableitung, keine Lagerbuchung, kein Journal: die Werte an der Zeile
 * stimmen schon, das Lager ist schon gebucht (Hash je Bestellzeile). Idempotent: ein zweiter Lauf
 * findet nichts mehr offen. Ohne --apply nur Bericht.
 */
class WareneingangAltbestandCommand extends Command
{
    protected $signature = 'foodalchemist:wareneingang-altbestand
        {--team= : nur dieses Team (Default: alle)}
        {--apply : schreiben (ohne dieses Flag nur Bericht)}';

    protected $description = 'Spec 75c: Wareneingang/Rechnung aus den Bestellzeilen als Altbestand-Belege übernehmen (idempotent)';

    public function handle(TripleMatchService $tm): int
    {
        $apply = (bool) $this->option('apply');
        $orders = FoodAlchemistOrder::query()
            ->whereIn('status', [OrderStatus::Sent->value, OrderStatus::Confirmed->value, OrderStatus::Delivered->value])
            ->when($this->option('team'), fn ($q, $t) => $q->where('team_id', (int) $t))
            ->with('lines')->orderBy('id')->get();

        $ls = ['bestellungen' => 0, 'positionen' => 0];
        $re = ['bestellungen' => 0, 'positionen' => 0];
        foreach ($orders as $order) {
            $team = Team::find($order->team_id);
            if ($team === null) {
                continue;
            }
            $lsZeilen = [];
            $reZeilen = [];
            $abgerechnet = $tm->rechnungJeZeile($team, $order->lines->pluck('id')->all(),
                [FoodAlchemistSupplierInvoice::STATUS_FREIGEGEBEN, FoodAlchemistSupplierInvoice::STATUS_BEZAHLT]);
            foreach ($order->lines as $l) {
                if ($l->received_qty_packs !== null) {
                    $belegt = (float) FoodAlchemistDeliveryNoteLine::where('order_line_id', $l->id)
                        ->whereHas('deliveryNote', fn ($q) => $q->where('status', FoodAlchemistDeliveryNote::STATUS_GEBUCHT))->sum('qty_packs');
                    $hatBeleg = FoodAlchemistDeliveryNoteLine::where('order_line_id', $l->id)
                        ->whereHas('deliveryNote', fn ($q) => $q->where('status', FoodAlchemistDeliveryNote::STATUS_GEBUCHT))->exists();
                    $rest = round((float) $l->received_qty_packs - $belegt, 2);
                    if (abs($rest) >= 0.01 || (! $hatBeleg && abs((float) $l->received_qty_packs) < 0.01)) {
                        $lsZeilen[] = ['line' => $l, 'menge' => $rest];
                    }
                }
                if (($l->invoice_qty_packs !== null || $l->invoice_pack_price !== null) && ! isset($abgerechnet[$l->id])) {
                    $reZeilen[] = $l;
                }
            }
            if ($lsZeilen !== []) {
                $ls['bestellungen']++;
                $ls['positionen'] += count($lsZeilen);
            }
            if ($reZeilen !== []) {
                $re['bestellungen']++;
                $re['positionen'] += count($reZeilen);
            }
            if (! $apply || ($lsZeilen === [] && $reZeilen === [])) {
                continue;
            }
            DB::transaction(function () use ($order, $lsZeilen, $reZeilen) {
                if ($lsZeilen !== []) {
                    $datum = collect($lsZeilen)->pluck('line.received_at')->filter()->max() ?? $order->delivered_at ?? now();
                    $note = FoodAlchemistDeliveryNote::create([
                        'team_id' => $order->team_id, 'supplier_id' => $order->supplier_id, 'delivery_note_number' => null,
                        'delivered_on' => $datum->toDateString(), 'status' => FoodAlchemistDeliveryNote::STATUS_GEBUCHT, 'source' => 'altbestand',
                        'note' => 'Altbestand · ord-'.(int) $order->id, 'booked_at' => $datum,
                    ]);
                    foreach ($lsZeilen as $i => $z) {
                        $note->lines()->create(['team_id' => $order->team_id, 'order_line_id' => $z['line']->id, 'supplier_item_id' => $z['line']->supplier_item_id,
                            'gp_id' => $z['line']->gp_id, 'designation' => $z['line']->designation, 'qty_packs' => $z['menge'], 'expected_qty_packs' => $z['menge'],
                            'pack_price_snapshot' => $z['line']->pack_price, 'note' => $z['line']->received_note, 'position' => $i]);
                    }
                }
                if ($reZeilen !== []) {
                    $bezahlt = $order->payment_status === 'paid';
                    $inv = FoodAlchemistSupplierInvoice::create([
                        'team_id' => $order->team_id, 'supplier_id' => $order->supplier_id, 'invoice_number' => $order->invoice_number,
                        'invoice_date' => ($order->invoice_date ?? collect($reZeilen)->pluck('invoice_checked_at')->filter()->max() ?? now())->toDateString(),
                        'source' => 'altbestand', 'note' => 'Altbestand · ord-'.(int) $order->id,
                        'status' => $bezahlt ? FoodAlchemistSupplierInvoice::STATUS_BEZAHLT : FoodAlchemistSupplierInvoice::STATUS_FREIGEGEBEN,
                        'paid_at' => $bezahlt ? $order->invoice_paid_at : null, 'is_disputed' => $order->payment_status === 'disputed',
                        'approved_at' => now(),
                    ]);
                    foreach ($reZeilen as $i => $l) {
                        $inv->lines()->create(['team_id' => $order->team_id, 'order_line_id' => $l->id, 'supplier_item_id' => $l->supplier_item_id,
                            'art' => 'ware', 'designation' => $l->designation, 'qty_packs' => $l->invoice_qty_packs, 'pack_price' => $l->invoice_pack_price,
                            'line_net' => $l->invoice_qty_packs !== null && $l->invoice_pack_price !== null ? round((float) $l->invoice_qty_packs * (float) $l->invoice_pack_price, 2) : null,
                            'note' => $l->invoice_note, 'position' => $i]);
                    }
                }
            });
        }

        $this->info(($apply ? 'Übernommen' : 'Trockenlauf (ohne --apply)').': '
            ."Lieferscheine für {$ls['bestellungen']} Bestellung(en) / {$ls['positionen']} Position(en), "
            ."Rechnungen für {$re['bestellungen']} Bestellung(en) / {$re['positionen']} Position(en).");

        return self::SUCCESS;
    }
}
