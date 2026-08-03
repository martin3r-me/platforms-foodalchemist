<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\LeadLaStrategie;
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
        private LeadLaService $leadLa,
    ) {
    }

    // ── Schiene holen/anlegen ─────────────────────────────────────────────

    /**
     * Ein-offener-draft-Guard je (team, supplier, Liefertag): Transaktion + Lock gegen
     * Doppelklick. `$deliveryDate` = Liefertag (Y-m-d) oder null (undatierter Bucket) — so
     * koexistieren mehrere offene Bestellungen desselben Lieferanten für verschiedene
     * Liefertage. Der Liefertag ist damit Teil des Schlüssels, nicht nur ein Kopf-Feld.
     */
    public function draftForSupplier(Team $team, int $supplierId, ?string $deliveryDate = null, ?int $userId = null): FoodAlchemistOrder
    {
        $deliveryDate = ($deliveryDate !== null && $deliveryDate !== '') ? $deliveryDate : null;

        return DB::transaction(function () use ($team, $supplierId, $deliveryDate, $userId) {
            $draft = FoodAlchemistOrder::where('team_id', $team->id)
                ->where('supplier_id', $supplierId)
                ->where('status', OrderStatus::Draft->value)
                ->when($deliveryDate !== null, fn ($q) => $q->whereDate('desired_delivery_date', $deliveryDate))
                ->when($deliveryDate === null, fn ($q) => $q->whereNull('desired_delivery_date'))
                ->lockForUpdate()
                ->first();

            return $draft ?? FoodAlchemistOrder::create([
                'team_id' => $team->id,
                'supplier_id' => $supplierId,
                'status' => OrderStatus::Draft->value,
                'desired_delivery_date' => $deliveryDate,
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
     * Spec 20 · E3: optionaler $strategieOverride (Preisstrategie) steuert die Lead-LA-Wahl
     * des Vorschlags — so wechselt die Übernahme dieselbe Schiene wie eine spätere „Neu quellen".
     *
     * $deliveryDate (Liefertag, Y-m-d oder null) bestimmt, in welche Bestellung je Lieferant der
     * Bedarf läuft — Bestellungen sind je (Lieferant, Liefertag) getrennt.
     *
     * @param  array{concept_id?:int, recipe_id?:int, persons?:int|float, portions?:int|float}  $ziel
     * @return array{orders:list<int>, skipped_ohne_la:list<string>, warnungen:list<string>}
     */
    public function addNeedFromTarget(Team $team, array $ziel, string $sourceRef, ?int $userId = null, ?LeadLaStrategie $strategieOverride = null, ?string $deliveryDate = null): array
    {
        $vorschlag = $this->planung->bestellvorschlag($team, $ziel, $strategieOverride);
        $touched = [];
        $skipped = [];

        foreach ($vorschlag['lieferanten'] as $grp) {
            $supplierId = $grp['supplier_id'] ?? null;
            if ($supplierId === null) {            // Bucket „ohne Lead-LA" → nicht bestellbar (Sourcing/R9)
                $skipped[] = (string) ($grp['lieferant'] ?? '—');

                continue;
            }
            $draft = $this->draftForSupplier($team, (int) $supplierId, $deliveryDate, $userId);
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

    /**
     * Spec 20 · E2 — „Neue Bestellung": eine (leere) Draft-Schiene für einen Lieferanten
     * anlegen bzw. die bestehende offene zurückgeben (findOrCreate, idempotent je (team,
     * supplier, Liefertag)). Nur team-sichtbare Lieferanten (D1). Optionale Kopf-Felder werden
     * direkt gesetzt (reference/desired_delivery_date/note); der Liefertag ist Teil des Schlüssels.
     *
     * @param  array{reference?:?string, desired_delivery_date?:?string, note?:?string}  $header
     */
    public function createDraft(Team $team, int $supplierId, array $header = [], ?int $userId = null): FoodAlchemistOrder
    {
        $supplier = FoodAlchemistSupplier::visibleToTeam($team)->find($supplierId);
        if ($supplier === null) {
            throw new \RuntimeException('Lieferant nicht gefunden.');
        }
        // Liefertag ist Teil des Draft-Schlüssels → schon beim find-or-create setzen.
        $deliveryDate = ($header['desired_delivery_date'] ?? null) ?: null;
        $draft = $this->draftForSupplier($team, (int) $supplier->id, $deliveryDate, $userId);

        $kopf = array_intersect_key($header, array_flip(['reference', 'note']));
        if ($kopf !== []) {
            $draft = $this->updateHeader($team, (int) $draft->id, $kopf);
        }

        return $draft;
    }

    /**
     * Spec 20 · E2 — Direktbestellung: eine Zeile MANUELL an die Draft-Schiene des
     * Lieferanten hängen (unabhängig von jeder Produktion/jedem Ziel). Legt die Schiene
     * an, wenn nötig; die Zeile trägt `is_manual_qty=true` + leeres `source_contributions`
     * (der Cleanup-Guard in recomputeOrder verschont sie), Preis-Snapshot über den
     * recomputeLine-Pfad (needed_base_g bleibt 0 = kein Ziel-Bedarf). Existiert für den
     * Artikel bereits eine Zeile in der Schiene, wird deren Menge manuell übersteuert
     * (Setter-Semantik, idempotent) statt eine Dublette anzulegen.
     */
    public function addManualLine(Team $team, int $supplierItemId, float $qtyPacks, ?string $note = null, ?int $userId = null, ?string $deliveryDate = null): FoodAlchemistOrderLine
    {
        // D1: nur team-sichtbare Artikel (eigenes Team + Master-Kette/Seed) sind bestellbar.
        $la = FoodAlchemistSupplierItem::visibleToTeam($team)->with('structure')->find($supplierItemId);
        if ($la === null) {
            throw new \RuntimeException('Lieferantenartikel nicht gefunden.');
        }
        if ($la->supplier_id === null) {
            throw new \RuntimeException('Lieferantenartikel ohne Lieferant — nicht bestellbar.');
        }
        $qty = max(0.0, (float) $qtyPacks);
        $draft = $this->draftForSupplier($team, (int) $la->supplier_id, $deliveryDate, $userId);

        $line = FoodAlchemistOrderLine::where('order_id', $draft->id)
            ->where('supplier_item_id', $la->id)
            ->first();
        if ($line === null) {
            $line = new FoodAlchemistOrderLine([
                'team_id' => $team->id,
                'order_id' => $draft->id,
                'supplier_item_id' => $la->id,
                'gp_id' => $la->structure?->gp_id,
                'source_contributions' => [],
                'position' => (int) FoodAlchemistOrderLine::where('order_id', $draft->id)->max('position') + 1,
            ]);
        }
        $line->is_manual_qty = true;
        $line->qty_packs = $qty;
        if ($note !== null) {
            $line->note = $note !== '' ? $note : null;
        }
        $line->save();

        $this->recomputeOrder($draft->refresh());

        return $line->refresh();
    }

    /**
     * Spec 20 · E1 — Kopf-Felder einer OFFENEN Schiene pflegen (Anlass, Wunsch-Liefertermin,
     * Notiz). Nur eigene Belege, nur solange draft; gesendete Belege sind eingefroren (E2).
     *
     * @param  array{reference?:?string, desired_delivery_date?:?string, note?:?string}  $input
     */
    public function updateHeader(Team $team, int $orderId, array $input): FoodAlchemistOrder
    {
        $order = $this->ownedOrder($team, $orderId);
        $status = $order->status instanceof OrderStatus ? $order->status : OrderStatus::from((string) $order->status);
        if (! $status->istOffen()) {
            throw new \RuntimeException('Nur ein offener Entwurf ist editierbar.');
        }
        if (array_key_exists('reference', $input)) {
            $order->reference = ($input['reference'] ?? '') !== '' ? $input['reference'] : null;
        }
        if (array_key_exists('note', $input)) {
            $order->note = ($input['note'] ?? '') !== '' ? $input['note'] : null;
        }
        if (array_key_exists('desired_delivery_date', $input)) {
            $neuerLiefertag = ($input['desired_delivery_date'] ?? '') !== '' ? $input['desired_delivery_date'] : null;
            // Liefertag ist Teil des Draft-Schlüssels: kein zweiter offener Entwurf je (Lieferant, Liefertag).
            $kollision = FoodAlchemistOrder::where('team_id', $team->id)
                ->where('supplier_id', $order->supplier_id)
                ->where('status', OrderStatus::Draft->value)
                ->where('id', '!=', $order->id)
                ->when($neuerLiefertag !== null, fn ($q) => $q->whereDate('desired_delivery_date', $neuerLiefertag))
                ->when($neuerLiefertag === null, fn ($q) => $q->whereNull('desired_delivery_date'))
                ->exists();
            if ($kollision) {
                throw new \RuntimeException('Für diesen Lieferanten gibt es an diesem Liefertag bereits eine offene Bestellung.');
            }
            $order->desired_delivery_date = $neuerLiefertag;
        }
        $order->save();

        return $order->refresh();
    }

    /**
     * Spec 20 · E3 — „Neu quellen": die OFFENE Schiene mit einer (optionalen) Preisstrategie
     * neu auflösen. Je Bedarfs-Zeile (mit source_contributions) wird der Lead-LA über
     * effektiverLead($strategie) neu bestimmt:
     *   • gleicher Lieferant, anderer LA ⇒ nur der Artikel der Zeile wechselt;
     *   • anderer Lieferant ⇒ die Beiträge wandern idempotent (E10) in dessen Draft-Schiene,
     *     die Quell-Zeile fällt weg (Contribution „verschoben", nicht dupliziert).
     * Manuelle Zeilen (is_manual_qty) und Zeilen ohne Lead bleiben unangetastet; E3-Aggregat-
     * Rundung läuft über recomputeOrder(). $apply=false ⇒ reine Vorschau (nichts persistiert,
     * gleicher Report-Shape) für den „Neu quellen"-Dialog. Der gewählte Override wird an der
     * Schiene vermerkt (orders.sourcing_strategy; NULL = Team-Haupteinstellung).
     *
     * @return array{strategie:?string, wechsel:list<array{gp_id:int, gp:string, von_la_id:?int, nach_la_id:int, nach_artikel:?string, nach_lieferant:?string, schiene_wechsel:bool}>, orders:list<int>}
     */
    public function resourceOrder(Team $team, int $orderId, ?LeadLaStrategie $strategie = null, bool $apply = true, ?int $userId = null): array
    {
        $order = $this->ownedOrder($team, $orderId);
        $status = $order->status instanceof OrderStatus ? $order->status : OrderStatus::from((string) $order->status);
        if (! $status->istOffen()) {
            throw new \RuntimeException('Nur ein offener Entwurf kann neu gequellt werden.');
        }

        $wechsel = [];
        $moves = [];   // [FoodAlchemistOrderLine $line, bool $sameSupplier, int $newSupplierId, int $newLaId, int $gpId]
        foreach ($order->lines()->get() as $line) {
            // E3: manuelle Zeilen + reine Direktbestellungen (leere contributions) bleiben unberührt.
            if ($line->is_manual_qty || empty($line->source_contributions) || $line->gp_id === null) {
                continue;
            }
            $gp = FoodAlchemistGp::find($line->gp_id);
            if ($gp === null) {
                continue;
            }
            $lead = $this->leadLa->effektiverLead($gp, $team, $strategie);
            $newLaId = $lead?->id !== null ? (int) $lead->id : null;
            $newSupplierId = $lead?->supplier_id !== null ? (int) $lead->supplier_id : null;
            if ($newLaId === null || $newSupplierId === null) {
                continue;   // kein bestellbarer Lead unter dieser Strategie → Zeile bleibt
            }
            $altLaId = $line->supplier_item_id !== null ? (int) $line->supplier_item_id : null;
            if ($newLaId === $altLaId) {
                continue;   // Strategie ändert für diesen GP nichts
            }

            $sameSupplier = $newSupplierId === (int) $order->supplier_id;
            $wechsel[] = [
                'gp_id' => (int) $gp->id,
                'gp' => $gp->name,
                'von_la_id' => $altLaId,
                'nach_la_id' => $newLaId,
                'nach_artikel' => $lead->designation,
                'nach_lieferant' => $lead->supplier_name,
                'schiene_wechsel' => ! $sameSupplier,
            ];
            $moves[] = [$line, $sameSupplier, $newSupplierId, $newLaId, (int) $gp->id];
        }

        if (! $apply) {
            return ['strategie' => $strategie?->value, 'wechsel' => $wechsel, 'orders' => [(int) $order->id]];
        }

        $touched = [(int) $order->id => $order];
        foreach ($moves as [$line, $sameSupplier, $newSupplierId, $newLaId, $gpId]) {
            $target = $this->applyLaSwitch($team, $line, $sameSupplier, $newSupplierId, $newLaId, $gpId, $userId);
            if ($target !== null) {
                $touched[(int) $target->id] = $target;
            }
        }

        $order->sourcing_strategy = $strategie?->value;   // Override an der Schiene vermerken (NULL = Haupteinstellung)
        $order->save();
        foreach ($touched as $o) {
            $this->recomputeOrder($o->refresh());
        }

        return [
            'strategie' => $strategie?->value,
            'wechsel' => $wechsel,
            'orders' => array_values(array_map(fn ($o) => (int) $o->id, $touched)),
        ];
    }

    /**
     * Spec 20 · E3 — eine Zeile auf einen anderen Lead-LA umziehen (gemeinsamer Motor für
     * resourceOrder + E3b-Einzelwechsel). Gleicher Lieferant ⇒ nur der Artikel der Zeile
     * wechselt (Schiene bleibt, gibt NULL zurück). Anderer Lieferant ⇒ die Beiträge wandern
     * idempotent (E10) in dessen Draft-Schiene und die Quell-Zeile fällt weg — bei einer
     * beitragslosen (manuellen) Zeile wandert stattdessen die manuelle Menge mit. Rückgabe:
     * die Ziel-Draft-Schiene bei Lieferanten-Wechsel, sonst NULL.
     */
    private function applyLaSwitch(Team $team, FoodAlchemistOrderLine $line, bool $sameSupplier, int $newSupplierId, int $newLaId, int $gpId, ?int $userId): ?FoodAlchemistOrder
    {
        if ($sameSupplier) {
            $line->supplier_item_id = $newLaId;   // Artikel wechselt, Schiene bleibt
            $line->save();

            return null;
        }
        $targetDraft = $this->draftForSupplier($team, $newSupplierId, $userId);
        $contribs = $line->source_contributions ?? [];
        if (! empty($contribs)) {
            foreach ($contribs as $ref => $g) {
                $this->upsertContribution($team, $targetDraft, ['lead_la_id' => $newLaId, 'gp_id' => $gpId, 'menge_g' => $g], (string) $ref);
            }
        } else {
            // Beitragslose (manuelle) Zeile: manuelle Menge in die Ziel-Schiene übernehmen.
            $this->addManualLine($team, $newLaId, (float) $line->qty_packs, $line->note, $userId);
        }
        $line->delete();   // Beitrag/Menge verschoben, nicht dupliziert

        return $targetDraft;
    }

    /**
     * Spec 20 · E3b — Ausweichquellen einer Bedarfs-Zeile fürs Panel-2-Dropdown: die
     * GP-Rangliste (V-27) ohne den aktuell gewählten LA, jeweils mit Vergleichspreis und
     * Schienen-Wechsel-Hinweis (anderer Lieferant als die aktuelle Schiene). Nur eigene,
     * offene Belege; leere Liste bei Zeile ohne GP.
     *
     * @return list<array{la_id:int, designation:?string, supplier:?string, supplier_id:?int, vergleichspreis:?float, vergleichspreis_einheit:?string, ist_stamm:bool, gesperrt:bool, schiene_wechsel:bool}>
     */
    public function lineAlternativen(Team $team, int $lineId): array
    {
        $line = $this->ownedDraftLine($team, $lineId);
        if ($line->gp_id === null) {
            return [];
        }
        $gp = FoodAlchemistGp::find($line->gp_id);
        if ($gp === null) {
            return [];
        }
        $aktuelleSchieneSupplier = (int) $line->order->supplier_id;
        $aktuellLa = $line->supplier_item_id !== null ? (int) $line->supplier_item_id : null;

        return $this->leadLa->rangliste($gp, $team)
            ->reject(fn ($la) => (int) $la->id === $aktuellLa)   // Rang > „aktuell" (der gewählte LA fällt raus)
            ->map(fn ($la) => [
                'la_id' => (int) $la->id,
                'designation' => $la->designation,
                'supplier' => $la->supplier_name,
                'supplier_id' => $la->supplier_id !== null ? (int) $la->supplier_id : null,
                'vergleichspreis' => $la->vergleichspreis_wert !== null ? (float) $la->vergleichspreis_wert : null,
                'vergleichspreis_einheit' => $la->vergleichspreis['unit'] ?? null,
                'ist_stamm' => (bool) $la->ist_stamm,
                'gesperrt' => (bool) $la->locked,
                'schiene_wechsel' => $la->supplier_id !== null && (int) $la->supplier_id !== $aktuelleSchieneSupplier,
            ])
            ->values()->all();
    }

    /**
     * Spec 20 · E3b — eine einzelne OFFENE Bedarfs-Zeile manuell auf einen Ausweich-LA ihrer
     * GP-Rangliste umstellen (Alternativ-Artikel-Dropdown). Gleicher Lieferant ⇒ nur der
     * Artikel wechselt; anderer Lieferant ⇒ die Zeile wandert in dessen Draft-Schiene (über
     * denselben applyLaSwitch-Motor wie „Neu quellen"). Der Ziel-LA muss ein Rangliste-
     * Kandidat der GP sein (I2-analog). Nur eigene, offene Belege.
     *
     * @return array{order_id:int, target_order_id:?int, schiene_wechsel:bool}
     */
    public function switchLineArticle(Team $team, int $lineId, int $newLaId, ?int $userId = null): array
    {
        $line = $this->ownedDraftLine($team, $lineId);
        if ($line->gp_id === null) {
            throw new \RuntimeException('Zeile ohne Grundprodukt — kein Alternativ-Artikel wählbar.');
        }
        $gp = FoodAlchemistGp::find($line->gp_id);
        if ($gp === null) {
            throw new \RuntimeException('Grundprodukt nicht gefunden.');
        }
        $kandidat = $this->leadLa->rangliste($gp, $team)->firstWhere('id', $newLaId);
        if ($kandidat === null) {
            throw new \RuntimeException('Artikel gehört nicht zu den Ausweichquellen dieses Grundprodukts.');
        }
        if ($kandidat->supplier_id === null) {
            throw new \RuntimeException('Artikel ohne Lieferant — nicht bestellbar.');
        }
        $sourceOrderId = (int) $line->order_id;
        if ((int) $newLaId === ($line->supplier_item_id !== null ? (int) $line->supplier_item_id : null)) {
            return ['order_id' => $sourceOrderId, 'target_order_id' => null, 'schiene_wechsel' => false];
        }

        $sameSupplier = (int) $kandidat->supplier_id === (int) $line->order->supplier_id;
        $target = $this->applyLaSwitch($team, $line, $sameSupplier, (int) $kandidat->supplier_id, (int) $newLaId, (int) $gp->id, $userId);

        $sourceOrder = FoodAlchemistOrder::find($sourceOrderId);
        if ($sourceOrder !== null) {
            $this->recomputeOrder($sourceOrder);
        }
        if ($target !== null) {
            $this->recomputeOrder($target->refresh());
        }

        return [
            'order_id' => $sourceOrderId,
            'target_order_id' => $target?->id !== null ? (int) $target->id : null,
            'schiene_wechsel' => $target !== null,
        ];
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

        // Einkauf E2: FA-Einkauf → Journal. Storno entfernt die Ist-Buchungen; das Erreichen
        // des konfigurierten Auslöse-Status (sent|delivered, TeamSettingsService) spiegelt die
        // Zeilen als Ist-Einkäufe (idempotent). So zählt der in FA getätigte Einkauf auf Spend.
        $journal = app(\Platform\FoodAlchemist\Services\PurchaseJournalService::class);
        if ($ziel === OrderStatus::Cancelled) {
            $journal->entferneOrder($order);
        } elseif ($ziel->value === app(\Platform\FoodAlchemist\Services\TeamSettingsService::class)->purchaseJournalTrigger($team)) {
            $journal->spiegelOrder($order);
        }

        return $order;
    }

    // ── Lesen / Aggregate ─────────────────────────────────────────────────

    /**
     * Bestell-Liste fürs Team, optional nach Status + Datumsfenster gefiltert.
     * `$filters`: datumsbasis ('liefertag'|'bestelldatum'), von/bis (Y-m-d oder null).
     *  • liefertag   → Fenster/Sortierung auf `desired_delivery_date` (aufsteigend, undatiert ans Ende);
     *  • bestelldatum → Fenster/Sortierung auf `created_at` (angelegt, absteigend).
     *
     * @param  array{datumsbasis?:string, von?:?string, bis?:?string}  $filters
     * @return Collection<int, FoodAlchemistOrder>
     */
    public function listForTeam(Team $team, ?string $status = null, array $filters = []): Collection
    {
        $basis = ($filters['datumsbasis'] ?? 'liefertag') === 'bestelldatum' ? 'bestelldatum' : 'liefertag';
        $spalte = $basis === 'bestelldatum' ? 'created_at' : 'desired_delivery_date';
        $von = ($filters['von'] ?? null) ?: null;
        $bis = ($filters['bis'] ?? null) ?: null;

        $q = FoodAlchemistOrder::visibleToTeam($team)
            ->with('supplier:id,name')
            ->when($status !== null, fn ($q) => $q->where('status', $status))
            ->when($von !== null, fn ($q) => $q->whereDate($spalte, '>=', $von))
            ->when($bis !== null, fn ($q) => $q->whereDate($spalte, '<=', $bis));

        if ($basis === 'bestelldatum') {
            $q->orderByDesc('created_at');
        } else {
            // Liefertag aufsteigend (anstehende zuerst), undatierte ans Ende, dann jüngste zuerst.
            $q->orderByRaw('desired_delivery_date IS NULL')
                ->orderBy('desired_delivery_date')
                ->orderByDesc('updated_at');
        }

        return $q->get();
    }

    /** Detail-Aggregat für UI/MCP inkl. MOQ-Ampel. */
    public function detail(Team $team, int $orderId): array
    {
        $order = FoodAlchemistOrder::visibleToTeam($team)
            ->with(['supplier', 'lines.gp:id,piece_default_g'])
            ->findOrFail($orderId);
        $status = $order->status instanceof OrderStatus ? $order->status : OrderStatus::from((string) $order->status);

        $alleRefs = [];
        $zeilen = $order->lines->map(function ($l) use (&$alleRefs) {
            [$bedarf, $bedarfEinheit] = $this->zeileBedarf($l);
            $refs = array_keys($l->source_contributions ?? []);
            $alleRefs = array_merge($alleRefs, $refs);

            return [
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
                'needed_display' => $bedarf,          // E1: korrekte Bedarfsmenge in der Grundeinheit des LA
                'needed_unit' => $bedarfEinheit,      // kg / l / Stk (nie mehr fälschlich „kg" bei Stück)
                'note' => $l->note,
                'herkunft' => $this->parseHerkunft($refs),
                'bestellbar' => $l->pack_price !== null && (float) $l->qty_packs > 0,
            ];
        })->all();

        return [
            'id' => (int) $order->id,
            'supplier_id' => (int) $order->supplier_id,
            'supplier' => $order->supplier?->name,
            'status' => $status->value,
            'status_label' => $status->label(),
            'reference' => $order->reference,
            'desired_delivery_date' => $order->desired_delivery_date?->toDateString(),
            'note' => $order->note,
            'sourcing_strategy' => $order->sourcing_strategy,   // E3: Preisstrategie-Override je Schiene (NULL = Haupteinstellung)
            'total_net' => (float) $order->total_net,
            'is_owned' => $order->isOwnedBy($team),
            'editierbar' => $status->istOffen() && $order->isOwnedBy($team),
            'moq' => $this->moqAmpel($order),
            'herkunft' => $this->herkunftAggregat($alleRefs),   // E1: Schienen-weite Quellen-Übersicht (dedupliziert, mit Links)
            'zeilen' => $zeilen,
        ];
    }

    /**
     * E1-Bug-Fix: Bedarf in der korrekten Grundeinheit des Lead-LA. `needed_base_g` liegt
     * IMMER in Gramm (Basis); Stück-Artikel werden über das Stückgewicht zurückgerechnet
     * (spiegelt GebindeRechner), statt fälschlich als „kg" ausgewiesen zu werden.
     *
     * @return array{0:float, 1:string}  [Menge, Einheit]
     */
    private function zeileBedarf(FoodAlchemistOrderLine $line): array
    {
        $g = (float) $line->needed_base_g;
        $unit = $line->unit_code;

        if ($unit === 'Stk') {
            $pieceG = $line->gp?->piece_default_g !== null ? (float) $line->gp->piece_default_g : null;
            if ($pieceG !== null && $pieceG > 0.0) {
                return [round($g / $pieceG, 2), 'Stk'];
            }

            return [round($g, 0), 'g'];   // ohne Stückgewicht nicht in Stück umrechenbar → Gramm belassen
        }

        // kg/l (Dichte 1.0) und Fallback für sonstige/leere Einheiten.
        return [round($g / 1000.0, 3), $unit === 'l' ? 'l' : 'kg'];
    }

    /**
     * E1: source_ref-Schlüssel in menschenlesbare Herkunft übersetzen. Kein FK — der Präfix
     * kodiert die Quelle: `produktion:{id}:…` (Produktionsauftrag), `concept:{id}@…`,
     * `recipe:{id|key}@…`, `event:…`. Unbekanntes bleibt als Roh-Label erhalten.
     *
     * @param  list<string>  $refs
     * @return list<array{ref:string, type:string, label:string, production_order_id:?int}>
     */
    public function parseHerkunft(array $refs): array
    {
        $out = [];
        foreach ($refs as $ref) {
            $ref = (string) $ref;
            if ($ref === '') {
                continue;
            }
            $type = 'sonstige';
            $label = $ref;
            $prodId = null;

            if (preg_match('/^produktion:(\d+):/', $ref, $m)) {
                $type = 'produktion';
                $prodId = (int) $m[1];
                $label = 'Produktion #' . $prodId;
            } elseif (preg_match('/^concept:([^@:]+)/', $ref, $m)) {
                $type = 'concept';
                $label = 'Konzept ' . $m[1];
            } elseif (preg_match('/^recipe:([^@:]+)/', $ref, $m)) {
                $type = 'recipe';
                $label = 'Gericht ' . $m[1];
            } elseif (preg_match('/^event:(.+)$/', $ref, $m)) {
                $type = 'event';
                $label = $m[1];
            }

            $out[] = ['ref' => $ref, 'type' => $type, 'label' => $label, 'production_order_id' => $prodId];
        }

        return $out;
    }

    /**
     * E1: dedupliziertes Schienen-Aggregat der Herkunft — je Quelle einmal (Produktion nach
     * Auftrag, sonst nach Präfix bis zum ersten „@"), für Panel 3 „Herkunft mit Links".
     *
     * @param  list<string>  $refs
     * @return list<array{key:string, type:string, label:string, production_order_id:?int}>
     */
    private function herkunftAggregat(array $refs): array
    {
        $byKey = [];
        foreach ($this->parseHerkunft($refs) as $h) {
            $key = $h['production_order_id'] !== null
                ? 'produktion:' . $h['production_order_id']
                : $h['type'] . ':' . $h['label'];
            $byKey[$key] = [
                'key' => $key,
                'type' => $h['type'],
                'label' => $h['label'],
                'production_order_id' => $h['production_order_id'],
            ];
        }

        return array_values($byKey);
    }

    /** S3: Volldaten für Bestell-Dokument (PDF/Druck/CSV) — Lieferant-Stammdaten + Zeilen. */
    public function dokument(Team $team, int $orderId): array
    {
        $order = FoodAlchemistOrder::visibleToTeam($team)->with(['supplier', 'lines'])->findOrFail($orderId);
        $status = $order->status instanceof OrderStatus ? $order->status : OrderStatus::from((string) $order->status);
        $sup = $order->supplier;

        return [
            'id' => (int) $order->id,
            'status' => $status->value,
            'status_label' => $status->label(),
            'reference' => $order->reference,
            'desired_delivery_date' => $order->desired_delivery_date?->toDateString(),
            'created_at' => $order->created_at?->format('d.m.Y'),
            'sent_at' => $order->sent_at?->format('d.m.Y H:i'),
            'total_net' => (float) $order->total_net,
            'moq' => $this->moqAmpel($order),
            'lieferant' => [
                'name' => $sup?->name,
                'email_order' => $sup?->email_order,
                'address' => $sup?->address,
                'postal_code' => $sup?->postal_code,
                'city' => $sup?->city,
            ],
            'zeilen' => $order->lines->map(fn ($l) => [
                'article_number' => $l->article_number,
                'designation' => $l->designation,
                'packaging_unit' => $l->packaging_unit,
                'pack_qty' => $l->pack_qty !== null ? (float) $l->pack_qty : null,
                'unit_code' => $l->unit_code,
                'qty_packs' => (float) $l->qty_packs,
                'pack_price' => $l->pack_price !== null ? (float) $l->pack_price : null,
                'line_total' => (float) $l->line_total,
                'needed_base_g' => (float) $l->needed_base_g,
                'bestellbar' => $l->pack_price !== null && (float) $l->qty_packs > 0,
            ])->all(),
        ];
    }

    /** S3: vorbefüllte E-Mail an den Lieferanten (Bestellweg suppliers.email_order). */
    public function mailtoData(Team $team, int $orderId): array
    {
        $d = $this->dokument($team, $orderId);
        $name = $d['lieferant']['name'] ?? 'Lieferant';
        $betreff = 'Bestellung ' . $name . ' — ' . ($d['reference'] ?: ('#' . $d['id']));

        $z = ['Guten Tag,', '', 'bitte folgende Bestellung:', ''];
        foreach ($d['zeilen'] as $l) {
            $menge = rtrim(rtrim(number_format($l['qty_packs'], 2, ',', '.'), '0'), ',');
            $geb = trim(($l['packaging_unit'] ?? '') . ' ' . ($l['designation'] ?? ''));
            $z[] = "- {$menge}× {$geb}" . ($l['article_number'] ? " (Art. {$l['article_number']})" : '');
        }
        $z[] = '';
        if ($d['desired_delivery_date']) {
            $z[] = 'Wunsch-Liefertermin: ' . $d['desired_delivery_date'];
        }
        $z[] = 'Netto gesamt: ' . number_format($d['total_net'], 2, ',', '.') . ' €';
        $z[] = '';
        $z[] = 'Vielen Dank.';

        return ['to' => $d['lieferant']['email_order'] ?? '', 'subject' => $betreff, 'body' => implode("\n", $z)];
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
