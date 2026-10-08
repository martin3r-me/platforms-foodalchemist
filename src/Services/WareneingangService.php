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
use Platform\FoodAlchemist\Enums\OrderStatus;
use Platform\FoodAlchemist\Models\FoodAlchemistDeliveryNote;
use Platform\FoodAlchemist\Models\FoodAlchemistDeliveryNoteLine;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistInventoryLocation;
use Platform\FoodAlchemist\Models\FoodAlchemistOrder;
use Platform\FoodAlchemist\Models\FoodAlchemistOrderLine;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;

/**
 * Spec 75a · Wareneingang über Lieferscheine.
 *
 * Ein Lieferschein gehört einem Lieferanten und trägt Positionen aus beliebig vielen offenen
 * Bestellungen dieses Lieferanten (n:m) sowie Ware ohne Bestellung. Buchen schreibt die gelieferte
 * Menge als ZUWACHS auf `received_qty_packs` der Bestellzeile — über `OrderService::updateReceiptLine`,
 * also denselben Weg, auf dem Lager, Kontingent und Einkaufsjournal schon hängen. Kein zweiter
 * Buchungsweg. Zuwachs statt Summe, weil bis Stufe 75c auch der Bestell-Editor direkt Mengen setzt.
 *
 * Ware ohne Bestellung läuft als Lagerzugang von Hand (Spec 67, Grund „ohne_bestellung").
 * Rechte (Spec 61): jede schreibende Methode verlangt mindestens Kuratieren — geprüft HIER, damit
 * Oberfläche und MCP dieselbe Regel haben. Lesen darf jedes Mitglied.
 */
class WareneingangService
{
    /** Bestellstatus, auf die ein Lieferschein buchen darf (spiegelt OrderService::guardReceiptLine). */
    public const OFFENE_STATUS = [OrderStatus::Sent, OrderStatus::Confirmed];

    private const ANHANG_MIMES = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif'];

    private const ANHANG_MAX_KB = 15360;

    public function __construct(
        private OrderService $orders,
        private LagerBewegungService $lager,
        private FaRechte $rechte,
    ) {}

    // ── Lesen ──────────────────────────────────────────────────────────────

    /**
     * Gesendete/bestätigte Bestellungen mit offener Ware, nach Liefertag sortiert.
     *
     * @return list<array<string,mixed>>
     */
    public function erwarteteLieferungen(Team $team, ?string $bis = null): array
    {
        $heute = now()->startOfDay();
        $orders = FoodAlchemistOrder::where('team_id', $team->id)
            ->whereIn('status', array_map(fn ($s) => $s->value, self::OFFENE_STATUS))
            ->with(['supplier', 'lines'])
            ->get();

        $rows = [];
        foreach ($orders as $order) {
            $liefertag = $order->confirmed_delivery_date ?? $order->desired_delivery_date;
            if ($bis !== null && $liefertag !== null && $liefertag->gt(Carbon::parse($bis)->endOfDay())) {
                continue;
            }
            $offen = $order->lines->filter(fn ($l) => $this->offenPacks($l) > 0.0001)->count();
            $rows[] = [
                'order_id' => (int) $order->id,
                'nummer' => 'ord-'.(int) $order->id,
                'reference' => $order->reference,
                'supplier_id' => (int) $order->supplier_id,
                'lieferant' => (string) ($order->supplier?->name ?? '—'),
                'status' => $order->status->value,
                'status_label' => $order->status->label(),
                'liefertag' => $liefertag?->toDateString(),
                'ueberfaellig' => $liefertag !== null && $liefertag->lt($heute),
                'heute' => $liefertag !== null && $liefertag->isSameDay($heute),
                'positionen' => $order->lines->count(),
                'offene_positionen' => $offen,
                'teilweise_geliefert' => $order->lines->contains(fn ($l) => $l->received_qty_packs !== null),
                'total_net' => round((float) $order->total_net, 2),
            ];
        }
        usort($rows, fn ($a, $b) => [$a['liefertag'] ?? '9999', $a['lieferant']] <=> [$b['liefertag'] ?? '9999', $b['lieferant']]);

        return $rows;
    }

    /**
     * Vorschlag für einen neuen Lieferschein: alle offenen Positionen des Lieferanten über alle
     * offenen Bestellungen, Menge = noch offen. `$orderIds` schränkt auf einzelne Bestellungen ein.
     *
     * @param  list<int>|null  $orderIds
     * @return list<array<string,mixed>>
     */
    public function vorbelegen(Team $team, int $supplierId, ?array $orderIds = null): array
    {
        $lines = FoodAlchemistOrderLine::query()
            ->whereHas('order', function ($q) use ($team, $supplierId, $orderIds) {
                $q->where('team_id', $team->id)->where('supplier_id', $supplierId)
                    ->whereIn('status', array_map(fn ($s) => $s->value, self::OFFENE_STATUS));
                if ($orderIds !== null && $orderIds !== []) {
                    $q->whereIn('id', $orderIds);
                }
            })
            ->with('order')
            ->orderBy('order_id')->orderBy('position')->orderBy('id')
            ->get();

        return $lines->map(fn (FoodAlchemistOrderLine $l) => $this->vorschlagZeile($l))
            ->filter(fn ($row) => $orderIds !== null || $row['offen'] > 0.0001)
            ->values()->all();
    }

    /** @return list<array<string,mixed>> */
    public function liste(Team $team, array $filter = []): array
    {
        $q = FoodAlchemistDeliveryNote::where('team_id', $team->id)->with(['supplier', 'lines.orderLine'])
            ->orderByDesc('delivered_on')->orderByDesc('id');
        if (! empty($filter['supplier_id'])) {
            $q->where('supplier_id', (int) $filter['supplier_id']);
        }
        if (! empty($filter['status'])) {
            $q->where('status', (string) $filter['status']);
        }
        if (! empty($filter['von'])) {
            $q->whereDate('delivered_on', '>=', Carbon::parse($filter['von'])->toDateString());
        }
        if (! empty($filter['bis'])) {
            $q->whereDate('delivered_on', '<=', Carbon::parse($filter['bis'])->toDateString());
        }
        if (! empty($filter['suche'])) {
            $s = '%'.trim((string) $filter['suche']).'%';
            $q->where(fn ($w) => $w->where('delivery_note_number', 'like', $s)
                ->orWhereHas('lines', fn ($l) => $l->where('designation', 'like', $s)));
        }
        $rows = $q->limit(500)->get()->map(fn (FoodAlchemistDeliveryNote $n) => $this->kopf($n))->all();
        if (! empty($filter['nur_abweichung'])) {
            $rows = array_values(array_filter($rows, fn ($r) => $r['abweichungen'] > 0));
        }

        return $rows;
    }

    /** @return array<string,mixed> */
    public function detail(Team $team, int $id): array
    {
        $note = $this->eigener($team, $id)->load(['supplier', 'lines.orderLine.order', 'lines.gp']);

        return $this->kopf($note) + [
            'zeilen' => $note->lines->map(fn (FoodAlchemistDeliveryNoteLine $l) => $this->zeile($l))->all(),
            'bestellungen' => $note->lines->pluck('orderLine.order')->filter()->unique('id')
                ->map(fn ($o) => [
                    'order_id' => (int) $o->id, 'nummer' => 'ord-'.(int) $o->id, 'reference' => $o->reference,
                    'status' => $o->status->value, 'status_label' => $o->status->label(),
                    'abschliessbar' => in_array($o->status, self::OFFENE_STATUS, true),
                ])->values()->all(),
        ];
    }

    // ── Schreiben ──────────────────────────────────────────────────────────

    /**
     * Lieferschein-Entwurf anlegen oder ändern (nur Entwurf).
     *
     * `$in`: supplier_id, delivery_note_number, delivered_on, note, inventory_location_id,
     * lines: [{order_line_id, qty_packs, abweichung_grund?, note?} | {gp_id, menge, designation?, note?}].
     * Ohne `lines` beim Anlegen: alle offenen Positionen des Lieferanten mit offener Menge.
     */
    public function speichern(Team $team, array $in, ?int $userId = null, ?int $id = null): FoodAlchemistDeliveryNote
    {
        $this->rechte->pruefeId($userId, $team, FaRolle::Kuratieren, 'Lieferschein erfassen');
        $note = $id !== null ? $this->eigener($team, $id) : null;
        if ($note !== null && $note->status !== FoodAlchemistDeliveryNote::STATUS_ENTWURF) {
            throw new \RuntimeException('Nur ein Lieferschein im Entwurf lässt sich ändern — einen gebuchten zuerst stornieren.');
        }
        $supplierId = (int) ($in['supplier_id'] ?? $note?->supplier_id ?? 0);
        $supplier = FoodAlchemistSupplier::visibleToTeam($team)->find($supplierId);
        if ($supplier === null) {
            throw new \RuntimeException('Bitte einen Lieferanten wählen.');
        }
        if ($note !== null && (int) $note->supplier_id !== $supplierId) {
            throw new \RuntimeException('Der Lieferant eines Lieferscheins lässt sich nicht wechseln.');
        }

        $nummer = array_key_exists('delivery_note_number', $in)
            ? (trim((string) $in['delivery_note_number']) !== '' ? mb_substr(trim((string) $in['delivery_note_number']), 0, 64) : null)
            : $note?->delivery_note_number;
        if ($nummer !== null) {
            $doppelt = FoodAlchemistDeliveryNote::where('team_id', $team->id)->where('supplier_id', $supplierId)
                ->where('delivery_note_number', $nummer)->where('status', '!=', FoodAlchemistDeliveryNote::STATUS_STORNIERT)
                ->when($note !== null, fn ($q) => $q->where('id', '!=', $note->id))->exists();
            if ($doppelt) {
                throw new \RuntimeException("Lieferschein {$nummer} von {$supplier->name} ist schon erfasst.");
            }
        }
        $datum = ! empty($in['delivered_on']) ? Carbon::parse((string) $in['delivered_on'])->toDateString()
            : ($note?->delivered_on?->toDateString() ?? now()->toDateString());
        $ortId = array_key_exists('inventory_location_id', $in) ? ($in['inventory_location_id'] ? (int) $in['inventory_location_id'] : null) : $note?->inventory_location_id;
        if ($ortId !== null && ! FoodAlchemistInventoryLocation::where('team_id', $team->id)->whereKey($ortId)->exists()) {
            throw new \RuntimeException('Lagerort nicht gefunden.');
        }

        $zeilen = array_key_exists('lines', $in) ? $this->pruefeZeilen($team, $supplierId, (array) $in['lines'])
            : ($note === null ? $this->pruefeZeilen($team, $supplierId, array_map(fn ($r) => ['order_line_id' => $r['order_line_id'], 'qty_packs' => $r['qty_packs']], $this->vorbelegen($team, $supplierId))) : null);

        return DB::transaction(function () use ($team, $note, $supplierId, $nummer, $datum, $ortId, $in, $zeilen, $userId) {
            $note ??= new FoodAlchemistDeliveryNote(['team_id' => $team->id, 'supplier_id' => $supplierId,
                'status' => FoodAlchemistDeliveryNote::STATUS_ENTWURF, 'created_by' => $userId]);
            $note->fill([
                'delivery_note_number' => $nummer,
                'delivered_on' => $datum,
                'inventory_location_id' => $ortId,
                'note' => array_key_exists('note', $in) ? (trim((string) $in['note']) !== '' ? trim((string) $in['note']) : null) : $note->note,
            ]);
            $note->save();

            if ($zeilen !== null) {
                $note->lines()->delete();
                foreach ($zeilen as $i => $z) {
                    $note->lines()->create($z + ['team_id' => $team->id, 'position' => $i]);
                }
            }

            return $note->refresh();
        });
    }

    /**
     * Lieferschein buchen: Wareneingang je Bestellzeile (Zuwachs), Lagerzugang ohne Bestellung, und
     * die genannten Bestellungen auf „geliefert" setzen. `$abschliessen` = null: jede Bestellung,
     * deren Positionen danach vollständig geliefert sind.
     *
     * @param  list<int>|null  $abschliessen
     */
    public function buchen(Team $team, int $id, ?int $userId = null, ?array $abschliessen = null): FoodAlchemistDeliveryNote
    {
        $this->rechte->pruefeId($userId, $team, FaRolle::Kuratieren, 'Lieferschein buchen');
        $note = $this->eigener($team, $id)->load('lines.orderLine.order');
        if ($note->status !== FoodAlchemistDeliveryNote::STATUS_ENTWURF) {
            throw new \RuntimeException('Dieser Lieferschein ist schon gebucht oder storniert.');
        }
        if ($note->lines->isEmpty()) {
            throw new \RuntimeException('Der Lieferschein hat keine Positionen.');
        }
        foreach ($note->lines as $l) {
            if ($l->order_line_id !== null) {
                $this->pruefeBuchbar($l->orderLine);
            }
        }
        $ohneOrt = $note->lines->contains(fn ($l) => $l->order_line_id === null) ? $this->ortFuerOhneBestellung($team, $note) : null;

        return DB::transaction(function () use ($team, $note, $userId, $abschliessen, $ohneOrt) {
            $orderIds = [];
            foreach ($note->lines as $l) {
                if ($l->order_line_id !== null) {
                    $ol = $l->orderLine->refresh();
                    $neu = round((float) ($ol->received_qty_packs ?? 0) + (float) $l->qty_packs, 2);
                    $this->orders->schreibeWareneingangAbleitung($team, (int) $ol->id, $neu, $this->wareneingangsNotiz($note, $l));
                    $orderIds[(int) $ol->order_id] = true;

                    continue;
                }
                [$m] = $this->lager->buchen($team, [
                    'art' => 'zugang', 'grund' => 'ohne_bestellung', 'gp_id' => (int) $l->gp_id, 'location_id' => $ohneOrt,
                    'menge' => (float) $l->menge, 'datum' => $note->delivered_on->toDateString(),
                    'notiz' => trim('Lieferschein '.($note->delivery_note_number ?? 'ohne Nr.').' · '.($l->note ?? ''), ' ·'),
                ], $userId);
                $l->update(['inventory_movement_id' => $m->id]);
            }

            $kandidaten = $abschliessen ?? array_values(array_filter(array_keys($orderIds), fn ($oid) => $this->vollstaendigGeliefert($oid)));
            foreach (array_unique(array_map('intval', $kandidaten)) as $oid) {
                if (! isset($orderIds[$oid])) {
                    throw new \RuntimeException("Bestellung ord-{$oid} ist nicht Teil dieses Lieferscheins.");
                }
                $this->abschliessen($team, $oid);
            }

            $note->update(['status' => FoodAlchemistDeliveryNote::STATUS_GEBUCHT, 'booked_at' => now(), 'booked_by' => $userId]);

            return $note->refresh();
        });
    }

    /**
     * Gebuchten Lieferschein stornieren: Wareneingang zurücknehmen, Zugänge ohne Bestellung gegenbuchen.
     * Geht nur, solange keine betroffene Bestellung abgeschlossen („geliefert") ist.
     */
    public function stornieren(Team $team, int $id, ?int $userId = null): FoodAlchemistDeliveryNote
    {
        $this->rechte->pruefeId($userId, $team, FaRolle::Kuratieren, 'Lieferschein stornieren');
        $note = $this->eigener($team, $id)->load('lines.orderLine.order');
        if ($note->status !== FoodAlchemistDeliveryNote::STATUS_GEBUCHT) {
            throw new \RuntimeException('Nur ein gebuchter Lieferschein lässt sich stornieren.');
        }
        foreach ($note->lines as $l) {
            if ($l->order_line_id !== null && $l->orderLine?->order !== null && ! in_array($l->orderLine->order->status, self::OFFENE_STATUS, true)) {
                throw new \RuntimeException('Bestellung ord-'.(int) $l->orderLine->order_id.' ist schon abgeschlossen ('
                    .$l->orderLine->order->status->label().') — der Lieferschein lässt sich nicht mehr stornieren.');
            }
        }

        return DB::transaction(function () use ($team, $note, $userId) {
            foreach ($note->lines as $l) {
                if ($l->order_line_id !== null) {
                    $ol = $l->orderLine->refresh();
                    $rest = round(max(0, (float) ($ol->received_qty_packs ?? 0) - (float) $l->qty_packs), 2);
                    $weitere = FoodAlchemistDeliveryNoteLine::where('order_line_id', $ol->id)->where('id', '!=', $l->id)
                        ->whereHas('deliveryNote', fn ($q) => $q->where('status', FoodAlchemistDeliveryNote::STATUS_GEBUCHT))->exists();
                    $this->orders->schreibeWareneingangAbleitung($team, (int) $ol->id, $rest <= 0.0 && ! $weitere ? null : $rest);
                } elseif ($l->inventory_movement_id !== null) {
                    $this->lager->stornieren($team, (int) $l->inventory_movement_id, $userId);
                }
            }
            $note->update(['status' => FoodAlchemistDeliveryNote::STATUS_STORNIERT, 'cancelled_at' => now()]);

            return $note->refresh();
        });
    }

    public function loeschen(Team $team, int $id, ?int $userId = null): void
    {
        $this->rechte->pruefeId($userId, $team, FaRolle::Kuratieren, 'Lieferschein löschen');
        $note = $this->eigener($team, $id);
        if ($note->status !== FoodAlchemistDeliveryNote::STATUS_ENTWURF) {
            throw new \RuntimeException('Nur ein Entwurf lässt sich löschen — einen gebuchten Lieferschein stornieren.');
        }
        DB::transaction(function () use ($note) {
            $note->lines()->delete();
            $note->delete();
        });
    }

    /**
     * Unterlieferte Bestellung abschließen: Fehlmenge als Nachlieferungs-Entwurf (OrderService),
     * die Ursprungsbestellung auf „geliefert". Der Rest kommt später als Lieferschein auf die Nachlieferung.
     *
     * @return array{order_id:int, lines:int, total_qty_packs:float}
     */
    public function nachlieferung(Team $team, int $orderId, ?string $liefertag = null, ?int $userId = null): array
    {
        $this->rechte->pruefeId($userId, $team, FaRolle::Kuratieren, 'Nachlieferung anlegen');
        $order = FoodAlchemistOrder::where('team_id', $team->id)->findOrFail($orderId);
        if (! in_array($order->status, self::OFFENE_STATUS, true)) {
            throw new \RuntimeException('Nachlieferung geht nur aus einer gesendeten oder bestätigten Bestellung.');
        }

        return DB::transaction(function () use ($team, $orderId, $liefertag, $userId) {
            $this->nichtErfassteAufNull($team, $orderId);
            $res = $this->orders->createBackorderFromReceipt($team, $orderId, $liefertag, $userId);
            $this->orders->setStatus($team, $orderId, OrderStatus::Delivered);

            return $res;
        });
    }

    /**
     * Bestellung auf „geliefert". Positionen, die auf keinem Lieferschein standen, gelten als nicht
     * geliefert (0) — sonst würde `setStatus` sie mit der bestellten Menge vorbelegen und Ware einbuchen,
     * die nie angekommen ist.
     */
    private function abschliessen(Team $team, int $orderId): void
    {
        $this->nichtErfassteAufNull($team, $orderId);
        $this->orders->setStatus($team, $orderId, OrderStatus::Delivered);
    }

    private function nichtErfassteAufNull(Team $team, int $orderId): void
    {
        FoodAlchemistOrderLine::where('order_id', $orderId)->whereNull('received_qty_packs')->pluck('id')
            ->each(fn ($lineId) => $this->kurzwegMenge($team, (int) $lineId, 0, 'nicht geliefert'));
    }

    // ── Kurzweg (Spec 75c): Bestell-Editor und alte MCP-Wege ───────────────

    /**
     * Wareneingang einer Bestellzeile auf eine absolute Menge setzen — wie früher im Editor, aber über
     * einen Editor-Lieferschein je Bestellung (`source = editor`). Dessen Position hält die Korrektur
     * „gewünscht minus Summe der anderen gebuchten Lieferscheine"; damit bleibt die Bestellzeile die
     * Summe ihrer Lieferscheine und Lager/Kontingent/Journal laufen über den einen Ableitungsweg.
     * `null` nimmt die Editor-Erfassung zurück. Mit angemeldetem Benutzer ab Kuratieren.
     */
    public function kurzwegMenge(Team $team, int $lineId, int|float|string|null $menge, ?string $note = null, ?User $user = null): FoodAlchemistOrderLine
    {
        $user ??= Auth::user();
        if ($user !== null) {
            $this->rechte->pruefe($user, $team, FaRolle::Kuratieren, 'Wareneingang buchen');
        }
        $ol = FoodAlchemistOrderLine::with('order')->findOrFail($lineId);
        if ($ol->order === null || (int) $ol->order->team_id !== (int) $team->id) {
            throw new \RuntimeException('Diese Bestellzeile gehört einem anderen Team und lässt sich hier nicht bearbeiten.');
        }
        if (! in_array($ol->order->status, self::OFFENE_STATUS, true)) {
            throw new \RuntimeException('Wareneingang ist nur für gesendete oder bestätigte Bestellungen möglich.');
        }
        $ziel = $menge === '' || $menge === null ? null : max(0, (float) str_replace(',', '.', (string) $menge));

        return DB::transaction(function () use ($team, $ol, $ziel, $note, $user) {
            $ls = $this->kurzwegLieferschein($team, $ol->order, $user?->id);
            $kurz = $ls->lines()->where('order_line_id', $ol->id)->first();
            $andere = (float) FoodAlchemistDeliveryNoteLine::where('order_line_id', $ol->id)
                ->when($kurz !== null, fn ($q) => $q->where('id', '!=', $kurz->id))
                ->whereHas('deliveryNote', fn ($q) => $q->where('status', FoodAlchemistDeliveryNote::STATUS_GEBUCHT))
                ->sum('qty_packs');

            if ($ziel === null) {
                $kurz?->delete();
                $neu = $andere > 0.0001 || FoodAlchemistDeliveryNoteLine::where('order_line_id', $ol->id)
                    ->whereHas('deliveryNote', fn ($q) => $q->where('status', FoodAlchemistDeliveryNote::STATUS_GEBUCHT))->exists() ? round($andere, 2) : null;
            } else {
                $korrektur = round($ziel - $andere, 2);
                $daten = ['qty_packs' => $korrektur, 'expected_qty_packs' => $korrektur, 'designation' => $ol->designation,
                    'supplier_item_id' => $ol->supplier_item_id, 'gp_id' => $ol->gp_id, 'pack_price_snapshot' => $ol->pack_price,
                    'note' => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 255) : $kurz?->note];
                $kurz !== null ? $kurz->update($daten) : $ls->lines()->create($daten + ['team_id' => $team->id, 'order_line_id' => $ol->id,
                    'position' => (int) $ls->lines()->max('position') + 1]);
                $neu = $ziel;
            }
            if ($ls->lines()->count() === 0) {
                $ls->delete();
            }

            return $this->orders->schreibeWareneingangAbleitung($team, (int) $ol->id, $neu, $note);
        });
    }

    /** „Alles wie bestellt" aus dem Editor bzw. `orders.UPDATE complete_receipt`. */
    public function kurzwegAllesWieBestellt(Team $team, int $orderId, ?User $user = null): void
    {
        $order = FoodAlchemistOrder::where('team_id', $team->id)->findOrFail($orderId);
        if (! in_array($order->status, self::OFFENE_STATUS, true)) {
            throw new \RuntimeException('Wareneingang ist nur für gesendete oder bestätigte Bestellungen möglich.');
        }
        DB::transaction(function () use ($team, $order, $user) {
            foreach ($order->lines()->get() as $l) {
                $this->kurzwegMenge($team, (int) $l->id, (float) $l->qty_packs, null, $user);
            }
        });
    }

    /** Editor-Lieferschein der Bestellung (gebucht, ohne Nummer); wird bei Bedarf angelegt. */
    private function kurzwegLieferschein(Team $team, FoodAlchemistOrder $order, ?int $userId): FoodAlchemistDeliveryNote
    {
        $ls = FoodAlchemistDeliveryNote::where('team_id', $team->id)->where('source', 'editor')
            ->where('status', FoodAlchemistDeliveryNote::STATUS_GEBUCHT)
            ->where('note', 'Erfasst im Bestell-Editor · ord-'.(int) $order->id)->first();

        return $ls ?? FoodAlchemistDeliveryNote::create([
            'team_id' => $team->id, 'supplier_id' => $order->supplier_id, 'delivery_note_number' => null,
            'delivered_on' => now()->toDateString(), 'status' => FoodAlchemistDeliveryNote::STATUS_GEBUCHT, 'source' => 'editor',
            'note' => 'Erfasst im Bestell-Editor · ord-'.(int) $order->id, 'booked_at' => now(), 'booked_by' => $userId, 'created_by' => $userId,
        ]);
    }

    // ── Beleg-Anhang (Foto / PDF) ──────────────────────────────────────────

    public function anhangSpeichern(Team $team, int $id, UploadedFile $file, ?int $userId = null): FoodAlchemistDeliveryNote
    {
        $this->rechte->pruefeId($userId, $team, FaRolle::Kuratieren, 'Beleg anhängen');
        $note = $this->eigener($team, $id);
        $mime = strtolower((string) $file->getMimeType());
        if (! in_array($mime, self::ANHANG_MIMES, true)) {
            throw new \RuntimeException('Bitte ein Foto (JPG, PNG, HEIC) oder ein PDF anhängen.');
        }
        if ($file->getSize() > self::ANHANG_MAX_KB * 1024) {
            throw new \RuntimeException('Die Datei ist größer als 15 MB.');
        }
        $files = app(ContextFileService::class);
        if ($note->attachment_context_file_id !== null) {
            $files->delete((int) $note->attachment_context_file_id, $team->id);
        }
        $res = $files->uploadForContext($file, 'foodalchemist.delivery_note', (int) $note->id, [
            'team_id' => $team->id, 'user_id' => $userId, 'folder' => 'foodalchemist/wareneingang/'.(int) $note->id,
            'keep_original' => true, 'generate_variants' => false,
        ]);
        $note->update(['attachment_context_file_id' => (int) $res['id'], 'attachment_name' => mb_substr((string) ($res['original_name'] ?? $file->getClientOriginalName()), 0, 255)]);

        return $note->refresh();
    }

    public function anhangEntfernen(Team $team, int $id, ?int $userId = null): FoodAlchemistDeliveryNote
    {
        $this->rechte->pruefeId($userId, $team, FaRolle::Kuratieren, 'Beleg entfernen');
        $note = $this->eigener($team, $id);
        if ($note->attachment_context_file_id !== null) {
            app(ContextFileService::class)->delete((int) $note->attachment_context_file_id, $team->id);
        }
        $note->update(['attachment_context_file_id' => null, 'attachment_name' => null]);

        return $note->refresh();
    }

    public function anhangUrl(Team $team, int $id): ?string
    {
        $note = $this->eigener($team, $id);

        return $note->attachment_context_file_id !== null
            ? app(ContextFileService::class)->getDownloadUrl((int) $note->attachment_context_file_id)
            : null;
    }

    // ── intern ─────────────────────────────────────────────────────────────

    private function eigener(Team $team, int $id): FoodAlchemistDeliveryNote
    {
        return FoodAlchemistDeliveryNote::where('team_id', $team->id)->findOrFail($id);
    }

    private function offenPacks(FoodAlchemistOrderLine $l): float
    {
        return round(max(0, (float) $l->qty_packs - (float) ($l->received_qty_packs ?? 0)), 2);
    }

    /** @return array<string,mixed> */
    private function vorschlagZeile(FoodAlchemistOrderLine $l): array
    {
        $offen = $this->offenPacks($l);

        return [
            'order_line_id' => (int) $l->id,
            'order_id' => (int) $l->order_id,
            'nummer' => 'ord-'.(int) $l->order_id,
            'reference' => $l->order?->reference,
            'designation' => (string) ($l->designation ?? '—'),
            'article_number' => $l->article_number,
            'packaging_unit' => $l->packaging_unit,
            'bestellt' => round((float) $l->qty_packs, 2),
            'bisher' => $l->received_qty_packs !== null ? round((float) $l->received_qty_packs, 2) : null,
            'offen' => $offen,
            'qty_packs' => $offen,
            'pack_price' => $l->pack_price !== null ? round((float) $l->pack_price, 4) : null,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $lines
     * @return list<array<string,mixed>>
     */
    private function pruefeZeilen(Team $team, int $supplierId, array $lines): array
    {
        $out = [];
        $gesehen = [];
        foreach ($lines as $z) {
            $grund = isset($z['abweichung_grund']) && $z['abweichung_grund'] !== '' ? (string) $z['abweichung_grund'] : null;
            if ($grund !== null && ! array_key_exists($grund, FoodAlchemistDeliveryNoteLine::GRUENDE)) {
                throw new \RuntimeException('Unbekannter Abweichungsgrund: '.$grund.'.');
            }
            $notiz = isset($z['note']) && trim((string) $z['note']) !== '' ? mb_substr(trim((string) $z['note']), 0, 255) : null;

            if (! empty($z['order_line_id'])) {
                $ol = FoodAlchemistOrderLine::with('order')->find((int) $z['order_line_id']);
                if ($ol === null || $ol->order === null || (int) $ol->order->team_id !== (int) $team->id) {
                    throw new \RuntimeException('Bestellposition nicht gefunden.');
                }
                if ((int) $ol->order->supplier_id !== $supplierId) {
                    throw new \RuntimeException('Position „'.$ol->designation.'" gehört zu einem anderen Lieferanten.');
                }
                $this->pruefeBuchbar($ol);
                if (isset($gesehen[$ol->id])) {
                    throw new \RuntimeException('Position „'.$ol->designation.'" steht doppelt auf dem Lieferschein.');
                }
                $gesehen[$ol->id] = true;
                $menge = $this->zahl($z['qty_packs'] ?? null);
                if ($menge === null || $menge < 0) {
                    throw new \RuntimeException('Bitte für „'.$ol->designation.'" eine gelieferte Menge ≥ 0 angeben.');
                }
                $out[] = [
                    'order_line_id' => (int) $ol->id, 'supplier_item_id' => $ol->supplier_item_id, 'gp_id' => $ol->gp_id,
                    'designation' => $ol->designation, 'qty_packs' => round($menge, 2), 'expected_qty_packs' => $this->offenPacks($ol),
                    'menge' => null, 'pack_price_snapshot' => $ol->pack_price, 'abweichung_grund' => $grund, 'note' => $notiz,
                ];

                continue;
            }

            $gp = ! empty($z['gp_id']) ? FoodAlchemistGp::visibleToTeam($team)->find((int) $z['gp_id']) : null;
            if ($gp === null) {
                throw new \RuntimeException('Ware ohne Bestellung braucht ein Grundprodukt.');
            }
            $menge = $this->zahl($z['menge'] ?? null);
            if ($menge === null || $menge <= 0) {
                throw new \RuntimeException('Bitte für „'.$gp->name.'" eine Menge in kg / l / Stk angeben.');
            }
            $out[] = [
                'order_line_id' => null, 'supplier_item_id' => ! empty($z['supplier_item_id']) ? (int) $z['supplier_item_id'] : null,
                'gp_id' => (int) $gp->id, 'designation' => mb_substr(trim((string) ($z['designation'] ?? '')) ?: (string) $gp->name, 0, 255),
                'qty_packs' => null, 'expected_qty_packs' => null, 'menge' => round($menge, 3), 'pack_price_snapshot' => null,
                'abweichung_grund' => $grund, 'note' => $notiz,
            ];
        }

        return $out;
    }

    private function pruefeBuchbar(?FoodAlchemistOrderLine $ol): void
    {
        $order = $ol?->order;
        if ($order === null) {
            throw new \RuntimeException('Bestellposition nicht gefunden.');
        }
        if (! in_array($order->status, self::OFFENE_STATUS, true)) {
            throw new \RuntimeException('Bestellung ord-'.(int) $order->id.' ist '.$order->status->label()
                .' — Wareneingang geht nur auf gesendete oder bestätigte Bestellungen. Rest bitte als Nachlieferung erfassen.');
        }
    }

    private function vollstaendigGeliefert(int $orderId): bool
    {
        return FoodAlchemistOrderLine::where('order_id', $orderId)->get()
            ->every(fn ($l) => $l->received_qty_packs !== null && (float) $l->received_qty_packs + 0.0001 >= (float) $l->qty_packs);
    }

    private function ortFuerOhneBestellung(Team $team, FoodAlchemistDeliveryNote $note): int
    {
        if ($note->inventory_location_id !== null) {
            return (int) $note->inventory_location_id;
        }
        $ort = FoodAlchemistInventoryLocation::where('team_id', $team->id)->where('is_active', true)
            ->orderByDesc('is_default')->orderBy('name')->value('id');
        if ($ort === null) {
            throw new \RuntimeException('Für Ware ohne Bestellung bitte zuerst einen Lagerort anlegen (Einstellungen → Einkauf).');
        }

        return (int) $ort;
    }

    private function wareneingangsNotiz(FoodAlchemistDeliveryNote $note, FoodAlchemistDeliveryNoteLine $l): ?string
    {
        if ($l->abweichung_grund === null && $l->note === null) {
            return null;
        }
        $teile = array_filter([
            'LS '.($note->delivery_note_number ?? 'ohne Nr.'),
            $l->abweichung_grund !== null ? FoodAlchemistDeliveryNoteLine::GRUENDE[$l->abweichung_grund] : null,
            $l->note,
        ]);

        return mb_substr(implode(' · ', $teile), 0, 255);
    }

    /** @return array<string,mixed> */
    private function kopf(FoodAlchemistDeliveryNote $n): array
    {
        $zeilen = $n->lines;

        return [
            'id' => (int) $n->id,
            'supplier_id' => (int) $n->supplier_id,
            'lieferant' => (string) ($n->supplier?->name ?? '—'),
            'delivery_note_number' => $n->delivery_note_number,
            'delivered_on' => $n->delivered_on?->toDateString(),
            'status' => $n->status,
            'status_label' => FoodAlchemistDeliveryNote::STATUS_LABELS[$n->status] ?? $n->status,
            'source' => (string) ($n->source ?? 'beleg'),
            'inventory_location_id' => $n->inventory_location_id !== null ? (int) $n->inventory_location_id : null,
            'note' => $n->note,
            'positionen' => $zeilen->count(),
            'ohne_bestellung' => $zeilen->whereNull('order_line_id')->count(),
            'abweichungen' => $zeilen->filter(fn ($l) => $this->abweichung($l) !== 'ok')->count(),
            'anzahl_bestellungen' => $zeilen->pluck('orderLine.order_id')->filter()->unique()->count(),
            'wert_net' => round($zeilen->sum(fn ($l) => (float) $l->qty_packs * (float) $l->pack_price_snapshot), 2),
            'anhang' => $n->attachment_name,
            'booked_at' => $n->booked_at?->toDateTimeString(),
        ];
    }

    /** @return array<string,mixed> */
    private function zeile(FoodAlchemistDeliveryNoteLine $l): array
    {
        return [
            'id' => (int) $l->id,
            'order_line_id' => $l->order_line_id !== null ? (int) $l->order_line_id : null,
            'order_id' => $l->orderLine?->order_id !== null ? (int) $l->orderLine->order_id : null,
            'nummer' => $l->orderLine?->order_id !== null ? 'ord-'.(int) $l->orderLine->order_id : null,
            'gp_id' => $l->gp_id !== null ? (int) $l->gp_id : null,
            'designation' => (string) ($l->designation ?? $l->gp?->name ?? '—'),
            'packaging_unit' => $l->orderLine?->packaging_unit,
            'bestellt' => $l->orderLine !== null ? round((float) $l->orderLine->qty_packs, 2) : null,
            'erwartet' => $l->expected_qty_packs !== null ? round((float) $l->expected_qty_packs, 2) : null,
            'qty_packs' => $l->qty_packs !== null ? round((float) $l->qty_packs, 2) : null,
            'menge' => $l->menge !== null ? round((float) $l->menge, 3) : null,
            'pack_price' => $l->pack_price_snapshot !== null ? round((float) $l->pack_price_snapshot, 4) : null,
            'abweichung' => $this->abweichung($l),
            'abweichung_grund' => $l->abweichung_grund,
            'abweichung_grund_label' => $l->abweichung_grund !== null ? FoodAlchemistDeliveryNoteLine::GRUENDE[$l->abweichung_grund] ?? $l->abweichung_grund : null,
            'note' => $l->note,
        ];
    }

    /** ok | zu_wenig | zu_viel | ohne_bestellung — gegen die beim Erfassen offene Menge. */
    private function abweichung(FoodAlchemistDeliveryNoteLine $l): string
    {
        if ($l->order_line_id === null) {
            return 'ohne_bestellung';
        }
        $diff = round((float) $l->qty_packs - (float) ($l->expected_qty_packs ?? 0), 2);
        if (abs($diff) < 0.01) {
            return 'ok';
        }

        return $diff < 0 ? 'zu_wenig' : 'zu_viel';
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
