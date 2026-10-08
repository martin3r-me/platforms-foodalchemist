<?php

namespace Platform\FoodAlchemist\Services;

use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\Core\Models\User;
use Platform\Core\Services\ContextFileService;
use Platform\FoodAlchemist\Enums\FaRolle;
use Platform\FoodAlchemist\Models\FoodAlchemistDeliveryNote;
use Platform\FoodAlchemist\Models\FoodAlchemistDeliveryNoteLine;
use Platform\FoodAlchemist\Models\FoodAlchemistOrder;
use Platform\FoodAlchemist\Models\FoodAlchemistOrderLine;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierInvoice;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierInvoiceLine;

/**
 * Spec 75b · Lieferanten-Rechnung erfassen, prüfen, freigeben, bezahlen.
 *
 * Erfassen (ab Kuratieren) belegt aus gebuchten, noch nicht abgerechneten Lieferscheinen des
 * Lieferanten vor — Menge aus dem Lieferschein, Preis aus der Bestellung; Sammelrechnungen über
 * mehrere Lieferscheine sind der Normalfall. Freigeben (ab Freigeben, Spec 61) verlangt: Summe der
 * Positionen = Summe laut Beleg und jede Abweichung im Triple Match begründet. Erst dann schreibt
 * die Rechnung Menge und gewichteten Preis auf die Bestellzeile (`OrderService::updateInvoiceLine`,
 * damit Einkaufsjournal und Controlling den Ist-Preis sehen) und den Rechnungskopf an die Bestellung.
 */
class LieferantenRechnungService
{
    private const ANHANG_MIMES = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif'];

    public function __construct(
        private OrderService $orders,
        private TripleMatchService $match,
        private FaRechte $rechte,
    ) {}

    // ── Lesen ──────────────────────────────────────────────────────────────

    /**
     * Positionen für eine neue Rechnung: gebuchte Lieferschein-Positionen des Lieferanten, die noch auf
     * keiner (nicht stornierten) Rechnung stehen. `$deliveryNoteIds` schränkt auf einzelne Lieferscheine ein.
     *
     * @param  list<int>|null  $deliveryNoteIds
     * @return list<array<string,mixed>>
     */
    public function vorbelegen(Team $team, int $supplierId, ?array $deliveryNoteIds = null): array
    {
        $schonAbgerechnet = FoodAlchemistSupplierInvoiceLine::whereNotNull('delivery_note_line_id')
            ->whereHas('invoice', fn ($q) => $q->where('team_id', $team->id)->where('status', '!=', FoodAlchemistSupplierInvoice::STATUS_STORNIERT))
            ->pluck('delivery_note_line_id')->all();

        return FoodAlchemistDeliveryNoteLine::query()
            ->whereHas('deliveryNote', function ($q) use ($team, $supplierId, $deliveryNoteIds) {
                $q->where('team_id', $team->id)->where('supplier_id', $supplierId)->where('status', FoodAlchemistDeliveryNote::STATUS_GEBUCHT);
                if ($deliveryNoteIds !== null && $deliveryNoteIds !== []) {
                    $q->whereIn('id', $deliveryNoteIds);
                }
            })
            ->whereNotIn('id', $schonAbgerechnet)
            ->with(['deliveryNote', 'orderLine'])
            ->orderBy('delivery_note_id')->orderBy('position')
            ->get()
            ->filter(fn ($l) => $l->order_line_id === null || (float) $l->qty_packs > 0.0001)
            ->map(fn (FoodAlchemistDeliveryNoteLine $l) => [
                'delivery_note_line_id' => (int) $l->id,
                'delivery_note_id' => (int) $l->delivery_note_id,
                'lieferschein' => $l->deliveryNote?->delivery_note_number ?? 'ohne Nr.',
                'order_line_id' => $l->order_line_id !== null ? (int) $l->order_line_id : null,
                'nummer' => $l->orderLine?->order_id !== null ? 'ord-'.(int) $l->orderLine->order_id : null,
                'designation' => (string) ($l->designation ?? '—'),
                'packaging_unit' => $l->orderLine?->packaging_unit,
                'qty_packs' => $l->order_line_id !== null ? round((float) $l->qty_packs, 2) : round((float) $l->menge, 3),
                'pack_price' => $l->orderLine?->pack_price !== null ? round((float) $l->orderLine->pack_price, 4) : null,
                'ohne_bestellung' => $l->order_line_id === null,
            ])->values()->all();
    }

    /** @return list<array<string,mixed>> */
    public function liste(Team $team, array $filter = []): array
    {
        $q = FoodAlchemistSupplierInvoice::where('team_id', $team->id)->with(['supplier', 'lines'])
            ->orderByDesc('invoice_date')->orderByDesc('id');
        if (! empty($filter['supplier_id'])) {
            $q->where('supplier_id', (int) $filter['supplier_id']);
        }
        if (! empty($filter['status'])) {
            $q->where('status', (string) $filter['status']);
        }
        if (! empty($filter['suche'])) {
            $q->where('invoice_number', 'like', '%'.trim((string) $filter['suche']).'%');
        }

        return $q->limit(500)->get()->map(fn ($i) => $this->kopf($team, $i))->all();
    }

    /** @return array<string,mixed> */
    public function detail(Team $team, int $id): array
    {
        $inv = $this->eigene($team, $id)->load(['supplier', 'lines.orderLine', 'lines.deliveryNoteLine.deliveryNote']);
        $tol = $this->match->toleranz($team);
        $olIds = $inv->lines->pluck('order_line_id')->filter()->unique()->values()->all();
        $andere = $this->match->rechnungJeZeile($team, $olIds, null, (int) $inv->id);

        $zeilen = $inv->lines->map(function (FoodAlchemistSupplierInvoiceLine $l) use ($tol, $andere) {
            $ol = $l->orderLine;
            $befund = null;
            if ($l->art === 'ware' && $ol !== null) {
                // berechnet = diese Rechnung + andere Rechnungen auf dieselbe Bestellzeile
                $menge = (float) $l->qty_packs + (float) ($andere[$ol->id]['menge'] ?? 0);
                $befund = $this->match->befund((float) $ol->qty_packs, $ol->received_qty_packs !== null ? (float) $ol->received_qty_packs : null,
                    $menge, $ol->pack_price !== null ? (float) $ol->pack_price : null, $l->pack_price !== null ? (float) $l->pack_price : null, $tol);
            }

            return [
                'id' => (int) $l->id, 'art' => $l->art, 'art_label' => FoodAlchemistSupplierInvoiceLine::ARTEN[$l->art] ?? $l->art,
                'delivery_note_line_id' => $l->delivery_note_line_id !== null ? (int) $l->delivery_note_line_id : null,
                'lieferschein' => $l->deliveryNoteLine?->deliveryNote?->delivery_note_number,
                'order_line_id' => $l->order_line_id !== null ? (int) $l->order_line_id : null,
                'nummer' => $ol?->order_id !== null ? 'ord-'.(int) $ol->order_id : null,
                'designation' => (string) ($l->designation ?? $ol?->designation ?? '—'),
                'bestellt' => $ol !== null ? round((float) $ol->qty_packs, 2) : null,
                'geliefert' => $ol?->received_qty_packs !== null ? round((float) $ol->received_qty_packs, 2) : ($l->deliveryNoteLine?->menge !== null ? round((float) $l->deliveryNoteLine->menge, 3) : null),
                'qty_packs' => $l->qty_packs !== null ? round((float) $l->qty_packs, 2) : null,
                'preis_bestellt' => $ol?->pack_price !== null ? round((float) $ol->pack_price, 4) : null,
                'pack_price' => $l->pack_price !== null ? round((float) $l->pack_price, 4) : null,
                'line_net' => $l->line_net !== null ? round((float) $l->line_net, 2) : null,
                'befund' => $befund,
                'abweichung' => $befund !== null && ! in_array($befund['re'], ['ok', 'offen'], true),
                'begruendung' => $l->begruendung,
                'note' => $l->note,
            ];
        })->all();

        $offen = array_values(array_filter($zeilen, fn ($z) => $z['abweichung'] && ($z['begruendung'] ?? '') === ''));
        $kopf = $this->kopf($team, $inv);

        return $kopf + [
            'zeilen' => $zeilen,
            'unbegruendet' => count($offen),
            'summen_passen' => $kopf['total_net'] === null || abs($kopf['summe_positionen'] - $kopf['total_net']) < 0.01,
            'freigebbar' => $inv->status === FoodAlchemistSupplierInvoice::STATUS_ERFASST && $offen === []
                && ($kopf['total_net'] === null || abs($kopf['summe_positionen'] - $kopf['total_net']) < 0.01),
            'toleranz' => $tol,
        ];
    }

    // ── Schreiben ──────────────────────────────────────────────────────────

    /**
     * Rechnung anlegen/ändern (nur Status „erfasst"). `$in`: supplier_id, invoice_number, invoice_date,
     * total_net, note, lines: [{delivery_note_line_id?, order_line_id?, art?, designation?, qty_packs,
     * pack_price, line_net?, begruendung?, note?}]. Ohne `lines` beim Anlegen: aus allen offenen
     * Lieferschein-Positionen des Lieferanten vorbelegt (bzw. `delivery_note_ids`).
     */
    public function speichern(Team $team, array $in, ?int $userId = null, ?int $id = null): FoodAlchemistSupplierInvoice
    {
        $this->rechte->pruefeId($userId, $team, FaRolle::Kuratieren, 'Rechnung erfassen');
        $inv = $id !== null ? $this->eigene($team, $id) : null;
        if ($inv !== null && $inv->status !== FoodAlchemistSupplierInvoice::STATUS_ERFASST) {
            throw new \RuntimeException('Nur eine Rechnung in Prüfung lässt sich ändern — eine freigegebene zuerst stornieren.');
        }
        $supplierId = (int) ($in['supplier_id'] ?? $inv?->supplier_id ?? 0);
        $supplier = FoodAlchemistSupplier::visibleToTeam($team)->find($supplierId) ?? throw new \RuntimeException('Bitte einen Lieferanten wählen.');
        if ($inv !== null && (int) $inv->supplier_id !== $supplierId) {
            throw new \RuntimeException('Der Lieferant einer Rechnung lässt sich nicht wechseln.');
        }
        $nummer = array_key_exists('invoice_number', $in)
            ? (trim((string) $in['invoice_number']) !== '' ? mb_substr(trim((string) $in['invoice_number']), 0, 64) : null)
            : $inv?->invoice_number;
        if ($nummer !== null && FoodAlchemistSupplierInvoice::where('team_id', $team->id)->where('supplier_id', $supplierId)
            ->where('invoice_number', $nummer)->where('status', '!=', FoodAlchemistSupplierInvoice::STATUS_STORNIERT)
            ->when($inv !== null, fn ($q) => $q->where('id', '!=', $inv->id))->exists()) {
            throw new \RuntimeException("Rechnung {$nummer} von {$supplier->name} ist schon erfasst.");
        }
        $datum = ! empty($in['invoice_date']) ? Carbon::parse((string) $in['invoice_date']) : ($inv?->invoice_date ?? now());
        $total = array_key_exists('total_net', $in) ? $this->zahl($in['total_net']) : ($inv?->total_net !== null ? (float) $inv->total_net : null);

        $zeilen = null;
        if (array_key_exists('lines', $in)) {
            $zeilen = $this->pruefeZeilen($team, $supplierId, (array) $in['lines'], $inv?->id);
        } elseif ($inv === null) {
            $dn = isset($in['delivery_note_ids']) ? array_map('intval', (array) $in['delivery_note_ids']) : null;
            // Ware ohne Bestellung hat keinen Bestellpreis — die kommt nur per Hand (UI/`lines`) auf die Rechnung.
            $vorschlag = array_values(array_filter($this->vorbelegen($team, $supplierId, $dn), fn ($r) => $r['pack_price'] !== null));
            $zeilen = $this->pruefeZeilen($team, $supplierId, $vorschlag, null);
        }

        return DB::transaction(function () use ($team, $inv, $supplier, $nummer, $datum, $total, $in, $zeilen, $userId) {
            $inv ??= new FoodAlchemistSupplierInvoice(['team_id' => $team->id, 'supplier_id' => $supplier->id,
                'status' => FoodAlchemistSupplierInvoice::STATUS_ERFASST, 'created_by' => $userId]);
            $tage = $supplier->payment_term_days !== null ? (int) $supplier->payment_term_days : null;
            $inv->fill([
                'invoice_number' => $nummer,
                'invoice_date' => $datum->toDateString(),
                'due_date' => $tage !== null ? $datum->copy()->addDays($tage)->toDateString() : null,
                'total_net' => $total,
                'note' => array_key_exists('note', $in) ? (trim((string) $in['note']) !== '' ? trim((string) $in['note']) : null) : $inv->note,
            ]);
            $inv->save();
            if ($zeilen !== null) {
                $inv->lines()->delete();
                foreach ($zeilen as $i => $z) {
                    $inv->lines()->create($z + ['team_id' => $team->id, 'position' => $i]);
                }
            }

            return $inv->refresh();
        });
    }

    /** Abweichung einer Position bewusst akzeptieren (Begründung) — ab Kuratieren. */
    public function begruenden(Team $team, int $lineId, ?string $begruendung, ?int $userId = null): FoodAlchemistSupplierInvoiceLine
    {
        $this->rechte->pruefeId($userId, $team, FaRolle::Kuratieren, 'Abweichung begründen');
        $line = FoodAlchemistSupplierInvoiceLine::with('invoice')->findOrFail($lineId);
        if ($line->invoice === null || (int) $line->invoice->team_id !== (int) $team->id) {
            throw new \Illuminate\Database\Eloquent\ModelNotFoundException();
        }
        if ($line->invoice->status !== FoodAlchemistSupplierInvoice::STATUS_ERFASST) {
            throw new \RuntimeException('Die Rechnung ist schon freigegeben.');
        }
        $line->update(['begruendung' => trim((string) $begruendung) !== '' ? mb_substr(trim((string) $begruendung), 0, 255) : null]);

        return $line->refresh();
    }

    /** Freigeben (ab Rolle Freigeben): Summen passen + jede Abweichung begründet → Prüfwerte an die Bestellzeilen. */
    public function freigeben(Team $team, int $id, ?int $userId = null): FoodAlchemistSupplierInvoice
    {
        $this->rechte->pruefeId($userId, $team, FaRolle::Freigeben, 'Rechnung freigeben');
        $d = $this->detail($team, $id);
        if ($d['status'] !== FoodAlchemistSupplierInvoice::STATUS_ERFASST) {
            throw new \RuntimeException('Diese Rechnung ist nicht in Prüfung.');
        }
        if (! $d['summen_passen']) {
            throw new \RuntimeException('Summe der Positionen ('.number_format($d['summe_positionen'], 2, ',', '.').' €) ≠ Summe laut Beleg ('
                .number_format((float) $d['total_net'], 2, ',', '.').' €). Bitte Positionen oder Nebenkosten prüfen.');
        }
        if ($d['unbegruendet'] > 0) {
            throw new \RuntimeException($d['unbegruendet'].' Abweichung(en) ohne Begründung — begründen oder reklamieren, dann freigeben.');
        }

        return DB::transaction(function () use ($team, $id, $userId) {
            $inv = $this->eigene($team, $id)->load('lines');
            $inv->update(['status' => FoodAlchemistSupplierInvoice::STATUS_FREIGEGEBEN, 'approved_at' => now(), 'approved_by' => $userId]);
            $this->aufBestellungenSchreiben($team, $inv);

            return $inv->refresh();
        });
    }

    public function bezahlt(Team $team, int $id, ?string $datum = null, ?int $userId = null): FoodAlchemistSupplierInvoice
    {
        $this->rechte->pruefeId($userId, $team, FaRolle::Freigeben, 'Rechnung als bezahlt markieren');
        $inv = $this->eigene($team, $id)->load('lines.orderLine');
        if ($inv->status !== FoodAlchemistSupplierInvoice::STATUS_FREIGEGEBEN) {
            throw new \RuntimeException('Bezahlt geht nur für eine freigegebene Rechnung.');
        }
        $tag = $datum ? Carbon::parse($datum)->toDateString() : now()->toDateString();

        return DB::transaction(function () use ($team, $inv, $tag) {
            $inv->update(['status' => FoodAlchemistSupplierInvoice::STATUS_BEZAHLT, 'paid_at' => $tag, 'is_disputed' => false]);
            foreach ($inv->lines->pluck('orderLine.order_id')->filter()->unique() as $orderId) {
                $this->orders->updatePayment($team, (int) $orderId, ['payment_status' => 'paid', 'invoice_paid_at' => $tag]);
            }

            return $inv->refresh();
        });
    }

    public function strittig(Team $team, int $id, bool $strittig, ?int $userId = null): FoodAlchemistSupplierInvoice
    {
        $this->rechte->pruefeId($userId, $team, FaRolle::Kuratieren, 'Rechnung als strittig markieren');
        $inv = $this->eigene($team, $id);
        if (in_array($inv->status, [FoodAlchemistSupplierInvoice::STATUS_BEZAHLT, FoodAlchemistSupplierInvoice::STATUS_STORNIERT], true)) {
            throw new \RuntimeException('Bezahlte oder stornierte Rechnungen lassen sich nicht mehr als strittig markieren.');
        }
        $inv->update(['is_disputed' => $strittig]);

        return $inv->refresh();
    }

    /** In Prüfung: löschen (ab Kuratieren). Freigegeben: stornieren (ab Freigeben), Prüfwerte an den Bestellzeilen zurückrechnen. */
    public function stornieren(Team $team, int $id, ?int $userId = null): ?FoodAlchemistSupplierInvoice
    {
        $inv = $this->eigene($team, $id)->load('lines');
        if ($inv->status === FoodAlchemistSupplierInvoice::STATUS_ERFASST) {
            $this->rechte->pruefeId($userId, $team, FaRolle::Kuratieren, 'Rechnung löschen');
            DB::transaction(function () use ($inv) {
                $inv->lines()->delete();
                $inv->delete();
            });

            return null;
        }
        $this->rechte->pruefeId($userId, $team, FaRolle::Freigeben, 'Rechnung stornieren');
        if ($inv->status !== FoodAlchemistSupplierInvoice::STATUS_FREIGEGEBEN) {
            throw new \RuntimeException('Bezahlte oder stornierte Rechnungen lassen sich nicht stornieren.');
        }

        return DB::transaction(function () use ($team, $inv) {
            $inv->update(['status' => FoodAlchemistSupplierInvoice::STATUS_STORNIERT, 'cancelled_at' => now()]);
            $this->aufBestellungenSchreiben($team, $inv);

            return $inv->refresh();
        });
    }

    /** Aus dem Abgleich: Reklamation an der Bestellzeile anlegen (Menge/Betrag aus dem Befund vorgeschlagen). */
    public function reklamieren(Team $team, int $orderLineId, array $in, ?int $userId = null): FoodAlchemistOrderLine
    {
        $this->rechte->pruefeId($userId, $team, FaRolle::Kuratieren, 'Reklamation anlegen');

        return $this->orders->updateClaimLine($team, $orderLineId, [
            'claim_status' => (string) ($in['claim_status'] ?? 'open'),
            'claim_qty_packs' => $in['claim_qty_packs'] ?? null,
            'credit_expected_net' => $in['credit_expected_net'] ?? null,
            'claim_note' => $in['claim_note'] ?? null,
        ]);
    }

    // ── Kurzweg (Spec 75c): Rechnungsprüfung im Bestell-Editor und alte MCP-Wege ──

    /**
     * Rechnungsprüfung einer Bestellzeile wie früher im Editor (berechnete Menge, Preis) — aber über eine
     * Editor-Rechnung je Bestellung (`source = editor`). Mit Recht „Freigeben" (oder ohne Benutzer =
     * System) ist sie sofort freigegeben und die Prüfwerte stehen an der Zeile; ein Mitglied legt sie
     * „in Prüfung" an — freigegeben wird dann im Wareneingang. Die Position hält die Korrektur gegen
     * andere Rechnungen, die Zeile bleibt die Summe aller freigegebenen Rechnungen.
     */
    public function kurzwegRechnung(Team $team, int $lineId, int|float|string|null $menge, int|float|string|null $preis, ?string $note = null, ?User $user = null): FoodAlchemistOrderLine
    {
        $user ??= Auth::user();
        if ($user !== null) {
            $this->rechte->pruefe($user, $team, FaRolle::Kuratieren, 'Rechnung prüfen');
        }
        $ol = FoodAlchemistOrderLine::with('order')->findOrFail($lineId);
        if ($ol->order === null || (int) $ol->order->team_id !== (int) $team->id) {
            throw new \RuntimeException('Diese Bestellzeile gehört einem anderen Team und lässt sich hier nicht bearbeiten.');
        }
        if (! in_array($ol->order->status, [\Platform\FoodAlchemist\Enums\OrderStatus::Sent, \Platform\FoodAlchemist\Enums\OrderStatus::Confirmed, \Platform\FoodAlchemist\Enums\OrderStatus::Delivered], true)) {
            throw new \RuntimeException('Rechnungsprüfung ist erst nach dem Absenden möglich.');
        }
        $m = $this->zahl($menge);
        $p = $this->zahl($preis);
        $m = $m !== null ? max(0, $m) : null;
        $p = $p !== null ? max(0, $p) : null;
        $sofort = $user === null || $this->rechte->darf($user, $team, FaRolle::Freigeben);

        DB::transaction(function () use ($team, $ol, $m, $p, $note, $user, $sofort) {
            $inv = $this->kurzwegBeleg($team, $ol->order, $user?->id, $sofort);
            $kurz = $inv->lines()->where('order_line_id', $ol->id)->where('art', 'ware')->first();
            $andere = $this->match->rechnungJeZeile($team, [(int) $ol->id],
                [FoodAlchemistSupplierInvoice::STATUS_FREIGEGEBEN, FoodAlchemistSupplierInvoice::STATUS_BEZAHLT], (int) $inv->id)[$ol->id]['menge'] ?? 0.0;
            if ($m === null && $p === null) {
                $kurz?->delete();
            } else {
                $korrektur = $m !== null ? round($m - (float) $andere, 2) : null;
                $daten = ['qty_packs' => $korrektur, 'pack_price' => $p, 'line_net' => $korrektur !== null && $p !== null ? round($korrektur * $p, 2) : null,
                    'designation' => $ol->designation, 'supplier_item_id' => $ol->supplier_item_id,
                    'note' => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 255) : $kurz?->note];
                $kurz !== null ? $kurz->update($daten) : $inv->lines()->create($daten + ['team_id' => $team->id, 'order_line_id' => $ol->id,
                    'art' => 'ware', 'position' => (int) $inv->lines()->max('position') + 1]);
            }
            $inv->refresh()->load('lines');
            if ($inv->status === FoodAlchemistSupplierInvoice::STATUS_FREIGEGEBEN) {
                $this->aufBestellungenSchreiben($team, $inv, [(int) $ol->id]);
                if ($note !== null) {
                    $this->orders->updateInvoiceNote($team, (int) $ol->id, $note);   // Notiz wie früher an der Zeile
                }
            }
            if ($inv->lines()->count() === 0) {
                $inv->delete();
            }
        });

        return $ol->refresh();
    }

    /** „Rechnung aus Wareneingang übernehmen" (Editor, `orders.UPDATE complete_invoice`). */
    public function kurzwegRechnungAusWareneingang(Team $team, int $orderId, ?User $user = null): void
    {
        $order = FoodAlchemistOrder::where('team_id', $team->id)->findOrFail($orderId);
        DB::transaction(function () use ($team, $order, $user) {
            foreach ($order->lines()->get() as $l) {
                $this->kurzwegRechnung($team, (int) $l->id, $l->received_qty_packs !== null ? (float) $l->received_qty_packs : (float) $l->qty_packs,
                    $l->pack_price !== null ? (float) $l->pack_price : null, null, $user);
            }
        });
    }

    /** Editor-Rechnung der Bestellung (eine offene je Bestellung); Rechnungskopf aus der Bestellung. */
    private function kurzwegBeleg(Team $team, FoodAlchemistOrder $order, ?int $userId, bool $sofort): FoodAlchemistSupplierInvoice
    {
        $inv = FoodAlchemistSupplierInvoice::where('team_id', $team->id)->where('source', 'editor')
            ->whereIn('status', [FoodAlchemistSupplierInvoice::STATUS_ERFASST, FoodAlchemistSupplierInvoice::STATUS_FREIGEGEBEN])
            ->where('note', 'Erfasst im Bestell-Editor · ord-'.(int) $order->id)->first();
        if ($inv !== null) {
            return $inv;
        }
        $datum = $order->invoice_date ?? now();

        return FoodAlchemistSupplierInvoice::create([
            'team_id' => $team->id, 'supplier_id' => $order->supplier_id, 'invoice_number' => $order->invoice_number,
            'invoice_date' => $datum->toDateString(), 'source' => 'editor', 'note' => 'Erfasst im Bestell-Editor · ord-'.(int) $order->id,
            'status' => $sofort ? FoodAlchemistSupplierInvoice::STATUS_FREIGEGEBEN : FoodAlchemistSupplierInvoice::STATUS_ERFASST,
            'approved_at' => $sofort ? now() : null, 'approved_by' => $sofort ? $userId : null, 'created_by' => $userId,
        ]);
    }

    public function anhangSpeichern(Team $team, int $id, UploadedFile $file, ?int $userId = null): FoodAlchemistSupplierInvoice
    {
        $this->rechte->pruefeId($userId, $team, FaRolle::Kuratieren, 'Beleg anhängen');
        $inv = $this->eigene($team, $id);
        if (! in_array(strtolower((string) $file->getMimeType()), self::ANHANG_MIMES, true)) {
            throw new \RuntimeException('Bitte ein Foto (JPG, PNG, HEIC) oder ein PDF anhängen.');
        }
        if ($file->getSize() > 15 * 1024 * 1024) {
            throw new \RuntimeException('Die Datei ist größer als 15 MB.');
        }
        $files = app(ContextFileService::class);
        if ($inv->attachment_context_file_id !== null) {
            $files->delete((int) $inv->attachment_context_file_id, $team->id);
        }
        $res = $files->uploadForContext($file, 'foodalchemist.supplier_invoice', (int) $inv->id, [
            'team_id' => $team->id, 'user_id' => $userId, 'folder' => 'foodalchemist/rechnungen/'.(int) $inv->id,
            'keep_original' => true, 'generate_variants' => false,
        ]);
        $inv->update(['attachment_context_file_id' => (int) $res['id'], 'attachment_name' => mb_substr((string) ($res['original_name'] ?? $file->getClientOriginalName()), 0, 255)]);

        return $inv->refresh();
    }

    public function anhangUrl(Team $team, int $id): ?string
    {
        $inv = $this->eigene($team, $id);

        return $inv->attachment_context_file_id !== null ? app(ContextFileService::class)->getDownloadUrl((int) $inv->attachment_context_file_id) : null;
    }

    // ── intern ─────────────────────────────────────────────────────────────

    private function eigene(Team $team, int $id): FoodAlchemistSupplierInvoice
    {
        return FoodAlchemistSupplierInvoice::where('team_id', $team->id)->findOrFail($id);
    }

    /**
     * Prüfwerte je betroffener Bestellzeile = Summe aller freigegebenen/bezahlten Rechnungen
     * (Menge, gewichteter Preis); keine mehr → Prüfung zurücksetzen. Rechnungskopf an der Bestellung.
     */
    /** @param  list<int>  $zusaetzlich  Bestellzeilen, die neu zu rechnen sind, auch wenn sie nicht (mehr) auf der Rechnung stehen */
    private function aufBestellungenSchreiben(Team $team, FoodAlchemistSupplierInvoice $inv, array $zusaetzlich = []): void
    {
        $olIds = $inv->lines->pluck('order_line_id')->filter()->merge($zusaetzlich)->unique()->values()->all();
        $gelten = [FoodAlchemistSupplierInvoice::STATUS_FREIGEGEBEN, FoodAlchemistSupplierInvoice::STATUS_BEZAHLT];
        $summe = $this->match->rechnungJeZeile($team, $olIds, $gelten);
        $orderIds = [];
        foreach ($olIds as $olId) {
            $s = $summe[$olId] ?? null;
            $this->orders->schreibeRechnungsAbleitung($team, (int) $olId, $s['menge'] ?? null, $s['preis'] ?? null,
                $s === null ? '' : ($s['nummern'] !== [] ? 'RE '.implode(', ', $s['nummern']) : null));
            $orderIds[(int) FoodAlchemistOrderLine::whereKey($olId)->value('order_id')] = true;
        }
        if ($inv->status === FoodAlchemistSupplierInvoice::STATUS_FREIGEGEBEN && $inv->invoice_number !== null) {
            foreach (array_keys($orderIds) as $orderId) {
                $this->orders->updateInvoiceHeader($team, $orderId, ['invoice_number' => $inv->invoice_number, 'invoice_date' => $inv->invoice_date?->toDateString()]);
            }
        }
    }

    /**
     * @param  list<array<string,mixed>>  $lines
     * @return list<array<string,mixed>>
     */
    private function pruefeZeilen(Team $team, int $supplierId, array $lines, ?int $invoiceId): array
    {
        $out = [];
        foreach ($lines as $z) {
            $art = (string) ($z['art'] ?? 'ware');
            if (! array_key_exists($art, FoodAlchemistSupplierInvoiceLine::ARTEN)) {
                throw new \RuntimeException('Unbekannte Positionsart: '.$art.'.');
            }
            $menge = $this->zahl($z['qty_packs'] ?? null);
            $preis = $this->zahl($z['pack_price'] ?? null);
            $netto = $this->zahl($z['line_net'] ?? null);
            $dnl = null;
            $ol = null;
            if (! empty($z['delivery_note_line_id'])) {
                $dnl = FoodAlchemistDeliveryNoteLine::with('deliveryNote')->find((int) $z['delivery_note_line_id']);
                if ($dnl === null || $dnl->deliveryNote === null || (int) $dnl->deliveryNote->team_id !== (int) $team->id
                    || (int) $dnl->deliveryNote->supplier_id !== $supplierId || $dnl->deliveryNote->status !== FoodAlchemistDeliveryNote::STATUS_GEBUCHT) {
                    throw new \RuntimeException('Lieferschein-Position gehört nicht zu einem gebuchten Lieferschein dieses Lieferanten.');
                }
                $doppelt = FoodAlchemistSupplierInvoiceLine::where('delivery_note_line_id', $dnl->id)
                    ->whereHas('invoice', fn ($q) => $q->where('status', '!=', FoodAlchemistSupplierInvoice::STATUS_STORNIERT)
                        ->when($invoiceId !== null, fn ($w) => $w->where('id', '!=', $invoiceId)))->exists();
                if ($doppelt) {
                    throw new \RuntimeException('„'.$dnl->designation.'“ ist schon auf einer anderen Rechnung abgerechnet.');
                }
            }
            $olId = $z['order_line_id'] ?? $dnl?->order_line_id;
            if (! empty($olId)) {
                $ol = FoodAlchemistOrderLine::with('order')->find((int) $olId);
                if ($ol === null || $ol->order === null || (int) $ol->order->team_id !== (int) $team->id || (int) $ol->order->supplier_id !== $supplierId) {
                    throw new \RuntimeException('Bestellposition gehört nicht zu diesem Lieferanten.');
                }
            }
            if ($art === 'ware' && ($menge === null || $preis === null)) {
                throw new \RuntimeException('Bitte für „'.($z['designation'] ?? $ol?->designation ?? $dnl?->designation ?? 'Position').'“ Menge und Preis angeben.');
            }
            if ($art !== 'ware' && $netto === null && ($menge === null || $preis === null)) {
                throw new \RuntimeException('Nebenkosten brauchen einen Betrag.');
            }
            $netto ??= round((float) $menge * (float) $preis, 2);
            if ($art === 'rabatt') {
                $netto = -abs($netto);
            }
            $out[] = [
                'delivery_note_line_id' => $dnl?->id, 'order_line_id' => $ol?->id,
                'supplier_item_id' => $ol?->supplier_item_id ?? $dnl?->supplier_item_id, 'art' => $art,
                'designation' => mb_substr(trim((string) ($z['designation'] ?? '')) ?: (string) ($ol?->designation ?? $dnl?->designation ?? FoodAlchemistSupplierInvoiceLine::ARTEN[$art]), 0, 255),
                'qty_packs' => $menge, 'pack_price' => $preis, 'line_net' => $netto,
                'begruendung' => isset($z['begruendung']) && trim((string) $z['begruendung']) !== '' ? mb_substr(trim((string) $z['begruendung']), 0, 255) : null,
                'note' => isset($z['note']) && trim((string) $z['note']) !== '' ? mb_substr(trim((string) $z['note']), 0, 255) : null,
            ];
        }

        return $out;
    }

    /** @return array<string,mixed> */
    private function kopf(Team $team, FoodAlchemistSupplierInvoice $i): array
    {
        $summe = round((float) $i->lines->sum(fn ($l) => (float) $l->line_net), 2);

        return [
            'id' => (int) $i->id,
            'supplier_id' => (int) $i->supplier_id,
            'lieferant' => (string) ($i->supplier?->name ?? '—'),
            'invoice_number' => $i->invoice_number,
            'invoice_date' => $i->invoice_date?->toDateString(),
            'due_date' => $i->due_date?->toDateString(),
            'ueberfaellig' => $i->due_date !== null && $i->status === FoodAlchemistSupplierInvoice::STATUS_FREIGEGEBEN && $i->due_date->lt(now()->startOfDay()),
            'total_net' => $i->total_net !== null ? round((float) $i->total_net, 2) : null,
            'summe_positionen' => $summe,
            'status' => $i->status,
            'status_label' => FoodAlchemistSupplierInvoice::STATUS_LABELS[$i->status] ?? $i->status,
            'source' => (string) ($i->source ?? 'beleg'),
            'strittig' => (bool) $i->is_disputed,
            'paid_at' => $i->paid_at?->toDateString(),
            'note' => $i->note,
            'positionen' => $i->lines->count(),
            'anhang' => $i->attachment_name,
            'approved_at' => $i->approved_at?->toDateTimeString(),
        ];
    }

    private function zahl(mixed $v): ?float
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_string($v)) {
            $v = str_replace(',', '.', trim($v));
        }

        return is_numeric($v) ? (float) $v : null;
    }
}
