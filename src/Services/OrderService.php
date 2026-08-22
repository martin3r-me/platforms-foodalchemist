<?php

namespace Platform\FoodAlchemist\Services;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\LeadLaStrategie;
use Platform\FoodAlchemist\Enums\OrderStatus;
use Platform\FoodAlchemist\Enums\ProductionOrderStatus;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistOrder;
use Platform\FoodAlchemist\Models\FoodAlchemistOrderLine;
use Platform\FoodAlchemist\Models\FoodAlchemistOrderRound;
use Platform\FoodAlchemist\Models\FoodAlchemistProductionOrder;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
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
        private InventoryService $inventory,
    ) {}

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

    /**
     * Reine Einkaufsvorschau aus Cockpit-Quellen. Schreibt keine Drafts, bündelt aber schon
     * exakt wie die echte Bestellung: je Lieferant + Liefertag.
     *
     * @param  list<array{type:string,id:int|string,qty?:int|float|string,unit?:string,delivery_date?:?string,reference?:?string}>  $sources
     * @param  array<string,int>  $overrides  override_key => lead_la_id
     * @return array{orders_preview:list<array>, unresolved:list<array>, warnings:list<string>, totals:array}
     */
    public function previewFromSources(Team $team, array $sources, LeadLaStrategie|string|null $strategy = null, array $overrides = []): array
    {
        $strategie = $this->strategieAusWert($strategy);
        $gruppen = [];
        $unresolved = [];
        $warnings = [];
        $sourceCount = 0;

        foreach (array_values($sources) as $idx => $source) {
            $sourceCount++;
            $type = (string) ($source['type'] ?? '');
            $date = ($source['delivery_date'] ?? null) ?: null;
            $reference = trim((string) ($source['reference'] ?? ''));
            $label = $this->sourceLabel($team, $source);
            $sourceRef = $this->sourceRef($source, $idx);

            try {
                if ($type === 'supplier_item') {
                    $this->previewSupplierItem($team, $gruppen, $unresolved, $source, $date, $reference, $label, $sourceRef);
                } elseif ($type === 'gp') {
                    $this->previewGp($team, $gruppen, $unresolved, $source, $date, $reference, $label, $sourceRef, $strategie, $overrides);
                } elseif ($type === 'recipe') {
                    $this->previewZiel($team, $gruppen, $unresolved, $warnings, $this->zielAusRecipeSource($source), $date, $reference, $label, $sourceRef, $strategie, $overrides);
                } elseif ($type === 'production') {
                    $production = FoodAlchemistProductionOrder::visibleToTeam($team)->find((int) ($source['id'] ?? 0));
                    if ($production === null) {
                        $unresolved[] = $this->unresolved($source, $label, 'produktion_fehlt', 'Produktion nicht sichtbar oder nicht vorhanden.');

                        continue;
                    }
                    $targets = $this->releasedProductionTargets($production);
                    $prodDate = $date ?: $production->production_date?->toDateString();
                    foreach ($targets as $targetIdx => $target) {
                        $targetRef = ProductionOrderService::sourceRefFor((int) $production->id, (string) ($target['source_ref'] ?? ('target-'.$targetIdx)));
                        $this->previewZiel(
                            $team,
                            $gruppen,
                            $unresolved,
                            $warnings,
                            $this->zielOhneMeta($target),
                            $prodDate,
                            $reference !== '' ? $reference : (string) ($target['label'] ?? ''),
                            $label,
                            $targetRef,
                            $strategie,
                            $overrides
                        );
                    }
                } else {
                    $unresolved[] = $this->unresolved($source, $label, 'quelle_unbekannt', 'Quelltyp wird noch nicht unterstützt.');
                }
            } catch (\Throwable $e) {
                $unresolved[] = $this->unresolved($source, $label, 'quelle_fehlerhaft', $e->getMessage());
            }
        }

        $ordersPreview = $this->finalisierePreviewGruppen($gruppen);
        $totalNet = round(array_sum(array_map(fn ($g) => (float) ($g['total_net'] ?? 0), $ordersPreview)), 2);

        return [
            'orders_preview' => $ordersPreview,
            'unresolved' => array_values($unresolved),
            'warnings' => array_values(array_unique($warnings)),
            'totals' => [
                'sources' => $sourceCount,
                'groups' => count($ordersPreview),
                'positions' => array_sum(array_map(fn ($g) => count($g['positionen'] ?? []), $ordersPreview)),
                'unresolved' => count($unresolved),
                'total_net' => $totalNet,
            ],
        ];
    }

    /**
     * Speichert Cockpit-Quellen als Draft-Bestellungen. Bestellbare Quellen werden je
     * Lieferant+Liefertag idempotent in Drafts übernommen; ungeklärte Quellen bleiben im Report.
     *
     * @param  list<array{type:string,id:int|string,qty?:int|float|string,unit?:string,delivery_date?:?string,reference?:?string}>  $sources
     * @param  array<string,int>  $overrides  override_key => lead_la_id
     * @param  array{id?:?int,label?:?string,desired_delivery_date?:?string,note?:?string,replace_production_ids?:list<int>}  $round
     * @return array{orders:list<int>, unresolved:list<array>, warnings:list<string>, preview:array, round:?array}
     */
    public function generateDraftsFromSources(Team $team, array $sources, LeadLaStrategie|string|null $strategy = null, ?int $userId = null, array $overrides = [], array $round = []): array
    {
        $replacementSources = $sources;
        foreach (($round['replace_production_ids'] ?? []) as $productionId) {
            if ((int) $productionId > 0) {
                $replacementSources[] = ['type' => 'production', 'id' => (int) $productionId];
            }
        }
        $this->assertProductionSourcesReplaceable($team, $replacementSources);
        $strategie = $this->strategieAusWert($strategy);
        $preview = $this->previewFromSources($team, $sources, $strategie, $overrides);
        $cleared = $this->clearSourceRefsFromDrafts($team, $this->replacementSourceRefs($team, $replacementSources, $preview));
        $touched = [];

        if (! empty($overrides)) {
            foreach ($preview['orders_preview'] as $gruppe) {
                $supplierId = (int) ($gruppe['supplier_id'] ?? 0);
                if ($supplierId <= 0) {
                    continue;
                }
                $draft = $this->draftForSupplier($team, $supplierId, ($gruppe['delivery_date'] ?? null) ?: null, $userId);
                foreach (($gruppe['positionen'] ?? []) as $pos) {
                    if (($pos['type'] ?? '') === 'supplier_item') {
                        $this->addManualLine($team, (int) ($pos['lead_la_id'] ?? 0), max(0.0, (float) ($pos['qty_packs'] ?? 0)), null, $userId, ($gruppe['delivery_date'] ?? null) ?: null);
                    } elseif (($pos['gp_id'] ?? null) !== null && ($pos['lead_la_id'] ?? null) !== null && (float) ($pos['needed_base_g'] ?? 0) > 0) {
                        $this->upsertContribution($team, $draft, [
                            'lead_la_id' => (int) $pos['lead_la_id'],
                            'gp_id' => (int) $pos['gp_id'],
                            'menge_g' => (float) $pos['needed_base_g'],
                        ], (string) ($pos['source_ref'] ?? 'preview:'.($pos['override_key'] ?? Str::uuid())));
                    }
                    if (($pos['reference'] ?? '') !== '') {
                        $this->kopfAusQuelle($team, (int) $draft->id, (string) $pos['reference']);
                    }
                }
                $this->recomputeOrder($draft->refresh());
                $touched[] = (int) $draft->id;
            }
            $this->markProductionSourcesHandedOver($team, $sources);
            $this->stampStrategyOnOrders($team, $touched, $strategie);
            $this->deleteEmptyReplannedDrafts($team, $cleared);

            $roundDetail = $this->persistRound($team, $touched, $strategie, $userId, $round);

            return [
                'orders' => array_values(array_unique(array_map('intval', $touched))),
                'unresolved' => $preview['unresolved'],
                'warnings' => $preview['warnings'],
                'preview' => $preview,
                'round' => $roundDetail,
            ];
        }

        foreach (array_values($sources) as $idx => $source) {
            $type = (string) ($source['type'] ?? '');
            $date = ($source['delivery_date'] ?? null) ?: null;
            $reference = trim((string) ($source['reference'] ?? ''));
            $sourceRef = $this->sourceRef($source, $idx);

            if ($type === 'supplier_item') {
                $line = $this->addManualLine($team, (int) ($source['id'] ?? 0), max(0.0, $this->sourceQty($source)), null, $userId, $date);
                $touched[] = (int) $line->order_id;
                $this->kopfAusQuelle($team, (int) $line->order_id, $reference);
            } elseif ($type === 'gp') {
                $gp = FoodAlchemistGp::visibleToTeam($team)->find((int) ($source['id'] ?? 0));
                if ($gp === null) {
                    continue;
                }
                $lead = $this->leadLa->effektiverLead($gp, $team, $strategie);
                if ($lead?->supplier_id === null || $lead?->id === null) {
                    continue;
                }
                $grams = $this->sourceGramsForGp($gp, $source);
                if ($grams <= 0) {
                    continue;
                }
                $draft = $this->draftForSupplier($team, (int) $lead->supplier_id, $date, $userId);
                $this->upsertContribution($team, $draft, ['lead_la_id' => (int) $lead->id, 'gp_id' => (int) $gp->id, 'menge_g' => $grams], $sourceRef);
                $this->recomputeOrder($draft->refresh());
                $this->kopfAusQuelle($team, (int) $draft->id, $reference);
                $touched[] = (int) $draft->id;
            } elseif ($type === 'recipe') {
                $res = $this->addNeedFromTarget($team, $this->zielAusRecipeSource($source), $sourceRef, $userId, $strategie, $date);
                foreach ($res['orders'] as $id) {
                    $this->kopfAusQuelle($team, (int) $id, $reference);
                }
                $touched = array_merge($touched, $res['orders']);
            } elseif ($type === 'production') {
                $production = FoodAlchemistProductionOrder::visibleToTeam($team)->find((int) ($source['id'] ?? 0));
                if ($production === null) {
                    continue;
                }
                $targets = $this->releasedProductionTargets($production);
                $prodDate = $date ?: $production->production_date?->toDateString();
                foreach ($targets as $targetIdx => $target) {
                    $targetRef = ProductionOrderService::sourceRefFor((int) $production->id, (string) ($target['source_ref'] ?? ('target-'.$targetIdx)));
                    $res = $this->addNeedFromTarget($team, $this->zielOhneMeta($target), $targetRef, $userId, $strategie, $prodDate);
                    foreach ($res['orders'] as $id) {
                        $this->kopfAusQuelle($team, (int) $id, $reference !== '' ? $reference : (string) ($target['label'] ?? ''));
                    }
                    $touched = array_merge($touched, $res['orders']);
                }
            }
        }
        $this->stampStrategyOnOrders($team, $touched, $strategie);
        $this->deleteEmptyReplannedDrafts($team, $cleared);
        $roundDetail = $this->persistRound($team, $touched, $strategie, $userId, $round);

        return [
            'orders' => array_values(array_unique(array_map('intval', $touched))),
            'unresolved' => $preview['unresolved'],
            'warnings' => $preview['warnings'],
            'preview' => $preview,
            'round' => $roundDetail,
        ];
    }

    /** @return list<array> */
    private function releasedProductionTargets(FoodAlchemistProductionOrder $production): array
    {
        if ($production->procurement_released_at === null || empty($production->procurement_targets_snapshot)) {
            throw new \RuntimeException('Materialbedarf dieser Produktion ist noch nicht freigegeben.');
        }
        if ($production->procurement_targets_hash !== ProductionOrderService::targetsHash($production->targets)) {
            throw new \RuntimeException('Materialbedarf wurde nach der Freigabe geändert. Bitte in Produktion erneut freigeben.');
        }

        return array_values($production->procurement_targets_snapshot);
    }

    /**
     * @param  list<int>  $orderIds
     * @param  array{id?:?int,label?:?string,desired_delivery_date?:?string,note?:?string}  $metadata
     */
    private function persistRound(Team $team, array $orderIds, ?LeadLaStrategie $strategy, ?int $userId, array $metadata): ?array
    {
        $orderIds = array_values(array_unique(array_map('intval', $orderIds)));
        if ($orderIds === []) {
            return null;
        }

        return DB::transaction(function () use ($team, $orderIds, $strategy, $userId, $metadata) {
            $roundId = isset($metadata['id']) ? (int) $metadata['id'] : null;
            $round = $roundId !== null && $roundId > 0
                ? FoodAlchemistOrderRound::visibleToTeam($team)->lockForUpdate()->findOrFail($roundId)
                : new FoodAlchemistOrderRound(['team_id' => $team->id, 'created_by' => $userId]);

            if ($round->exists && ! $round->isOwnedBy($team)) {
                throw new \RuntimeException('Bestellrunde nicht im Schreibzugriff (D1).');
            }

            $round->fill([
                'label' => trim((string) ($metadata['label'] ?? '')) ?: ($round->label ?: 'Bestellrunde '.now()->format('d.m.Y · H:i')),
                'desired_delivery_date' => ($metadata['desired_delivery_date'] ?? null) ?: $round->desired_delivery_date,
                'sourcing_strategy' => $strategy?->value,
                'note' => trim((string) ($metadata['note'] ?? '')) ?: $round->note,
            ]);
            $round->save();

            $ownedIds = FoodAlchemistOrder::query()
                ->where('team_id', $team->id)
                ->whereIn('id', $orderIds)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
            if (count($ownedIds) !== count($orderIds)) {
                throw new \RuntimeException('Mindestens eine Bestellung gehört nicht zum aktuellen Team.');
            }

            $round->orders()->syncWithoutDetaching(collect($ownedIds)->mapWithKeys(fn ($id) => [$id => ['team_id' => $team->id]])->all());

            return $this->roundDetail($team, (int) $round->id);
        });
    }

    public function roundsForTeam(Team $team): Collection
    {
        return FoodAlchemistOrderRound::visibleToTeam($team)
            ->with(['orders.supplier', 'orders.lines.supplierItem'])
            ->latest('id')
            ->get()
            ->map(fn (FoodAlchemistOrderRound $round) => $this->roundSummary($round));
    }

    public function productionDemandsForTeam(Team $team): Collection
    {
        return FoodAlchemistProductionOrder::visibleToTeam($team)
            ->whereNotNull('procurement_released_at')
            ->where('status', '!=', ProductionOrderStatus::Cancelled->value)
            ->orderBy('production_date')
            ->get()
            ->map(function (FoodAlchemistProductionOrder $production) use ($team) {
                $linked = app(ProductionOrderService::class)->verknuepfteOrders($team, (int) $production->id);
                $round = $linked->flatMap(fn (FoodAlchemistOrder $order) => $order->rounds)
                    ->sortByDesc('id')->first();
                $stale = $production->procurement_targets_hash !== ProductionOrderService::targetsHash($production->targets);
                $triggered = $linked->contains(fn (FoodAlchemistOrder $order) => in_array(
                    $order->status instanceof OrderStatus ? $order->status : OrderStatus::from((string) $order->status),
                    [OrderStatus::Sent, OrderStatus::Confirmed, OrderStatus::Delivered],
                    true,
                ));

                return [
                    'id' => (int) $production->id,
                    'name' => $production->name ?: 'Produktion #'.$production->id,
                    'production_date' => $production->production_date?->toDateString(),
                    'released_at' => $production->procurement_released_at?->toIso8601String(),
                    'stale' => $stale,
                    'targets' => count($production->procurement_targets_snapshot ?? []),
                    'orders' => $linked->count(),
                    'round_id' => $round?->id !== null ? (int) $round->id : null,
                    'round_label' => $round?->label,
                    'triggered' => $triggered,
                    'status' => $triggered ? ($stale ? 'korrektur nötig' : 'ausgelöst') : ($stale ? 'geaendert' : ($linked->isEmpty() ? 'offen' : 'geplant')),
                ];
            });
    }

    /**
     * Zieht den Bedarf einer stornierten Produktion aus allen offenen Entwürfen zurück.
     * Bereits ausgelöste Belege bleiben als unveränderlicher Einkaufsstand erhalten.
     *
     * @return array{updated_orders:list<int>,deleted_orders:list<int>,locked_orders:list<int>}
     */
    public function withdrawProductionDemand(Team $team, int $productionOrderId): array
    {
        $prefix = ProductionOrderService::sourceRefPrefix($productionOrderId);
        $draftLines = FoodAlchemistOrderLine::query()
            ->where('team_id', $team->id)
            ->whereHas('order', fn ($query) => $query
                ->where('team_id', $team->id)
                ->where('status', OrderStatus::Draft->value))
            ->where('source_contributions', 'like', '%'.$prefix.'%')
            ->get(['source_contributions']);

        $sourceRefs = $draftLines
            ->flatMap(fn (FoodAlchemistOrderLine $line) => array_keys((array) $line->source_contributions))
            ->filter(fn (string $ref) => str_starts_with($ref, $prefix))
            ->unique()->values()->all();

        $lockedOrderIds = FoodAlchemistOrderLine::query()
            ->where('team_id', $team->id)
            ->whereHas('order', fn ($query) => $query
                ->where('team_id', $team->id)
                ->whereIn('status', [
                    OrderStatus::Sent->value,
                    OrderStatus::Confirmed->value,
                    OrderStatus::Delivered->value,
                ]))
            ->where('source_contributions', 'like', '%'.$prefix.'%')
            ->distinct()->pluck('order_id')->map(fn ($id) => (int) $id)->values()->all();

        $updatedOrderIds = $this->clearSourceRefsFromDrafts($team, $sourceRefs);
        $deletedOrderIds = FoodAlchemistOrder::query()
            ->where('team_id', $team->id)
            ->whereIn('id', $updatedOrderIds)
            ->where('status', OrderStatus::Draft->value)
            ->whereDoesntHave('lines')
            ->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
        $this->deleteEmptyReplannedDrafts($team, $updatedOrderIds);

        return [
            'updated_orders' => array_values(array_diff($updatedOrderIds, $deletedOrderIds)),
            'deleted_orders' => $deletedOrderIds,
            'locked_orders' => $lockedOrderIds,
        ];
    }

    public function roundDetail(Team $team, int $roundId): array
    {
        $round = FoodAlchemistOrderRound::visibleToTeam($team)
            ->with(['orders.supplier', 'orders.lines'])
            ->findOrFail($roundId);

        return $this->roundSummary($round);
    }

    public function sendRound(Team $team, int $roundId): array
    {
        $round = FoodAlchemistOrderRound::visibleToTeam($team)->with('orders')->findOrFail($roundId);
        if (! $round->isOwnedBy($team)) {
            throw new \RuntimeException('Bestellrunde nicht im Schreibzugriff (D1).');
        }

        $drafts = $round->orders->filter(fn (FoodAlchemistOrder $order) => $order->status === OrderStatus::Draft);
        foreach ($drafts as $order) {
            $blockers = $this->sendBlockers($order);
            if ($blockers !== []) {
                throw new \RuntimeException(($order->supplier?->name ?? 'Bestellung #'.$order->id).': '.implode(', ', $blockers));
            }
        }

        DB::transaction(function () use ($team, $drafts) {
            foreach ($drafts as $order) {
                $this->setStatus($team, (int) $order->id, OrderStatus::Sent);
            }
        });

        return $this->roundDetail($team, $roundId);
    }

    /**
     * Löst alle aktuell versandfähigen Entwürfe aus. Gesperrte Belege bleiben zur Klärung offen.
     *
     * @return array{sent:int,blocked:int,blockers:list<string>}
     */
    public function sendReadyDrafts(Team $team): array
    {
        $ids = FoodAlchemistOrder::visibleToTeam($team)
            ->where('status', OrderStatus::Draft->value)
            ->pluck('id')->map(fn ($id) => (int) $id)->all();

        return $this->sendSelectedDrafts($team, $ids);
    }

    /** @return list<int> */
    public function readyDraftIds(Team $team): array
    {
        return FoodAlchemistOrder::visibleToTeam($team)
            ->with(['supplier', 'lines'])
            ->where('status', OrderStatus::Draft->value)
            ->get()
            ->filter(fn (FoodAlchemistOrder $order) => $this->sendBlockers($order) === [])
            ->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
    }

    /** @return array{orders:list<array>,selected:int,ready:int,blocked:int,total_net:float} */
    public function selectedDraftPreview(Team $team, array $orderIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $orderIds))));
        $orders = FoodAlchemistOrder::visibleToTeam($team)
            ->with(['supplier', 'lines'])
            ->whereIn('id', $ids)
            ->where('status', OrderStatus::Draft->value)
            ->get()
            ->map(function (FoodAlchemistOrder $order) {
                $blockers = $this->sendBlockers($order);

                return [
                    'id' => (int) $order->id,
                    'supplier' => $order->supplier?->name ?? '—',
                    'created_at' => $order->created_at?->toDateString(),
                    'desired_delivery_date' => $order->desired_delivery_date?->toDateString(),
                    'positions' => $order->lines->count(),
                    'total_net' => (float) $order->total_net,
                    'sendable' => $blockers === [],
                    'blockers' => $blockers,
                ];
            })->sortBy('supplier')->values();

        return [
            'orders' => $orders->all(),
            'selected' => $orders->count(),
            'ready' => $orders->where('sendable', true)->count(),
            'blocked' => $orders->where('sendable', false)->count(),
            'total_net' => round((float) $orders->where('sendable', true)->sum('total_net'), 2),
        ];
    }

    /** @return array{sent:int,blocked:int,blockers:list<string>,sent_ids:list<int>,sent_at:string} */
    public function sendSelectedDrafts(Team $team, array $orderIds): array
    {
        $preview = $this->selectedDraftPreview($team, $orderIds);
        $readyIds = collect($preview['orders'])->where('sendable', true)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $blocked = collect($preview['orders'])->where('sendable', false);

        DB::transaction(function () use ($team, $readyIds) {
            foreach ($readyIds as $orderId) {
                $this->setStatus($team, $orderId, OrderStatus::Sent);
            }
        });

        return [
            'sent' => count($readyIds),
            'blocked' => $blocked->count(),
            'blockers' => $blocked->map(fn (array $order) => $order['supplier'].': '.implode(', ', $order['blockers']))->values()->all(),
            'sent_ids' => $readyIds,
            'sent_at' => now()->format('d.m.Y H:i'),
        ];
    }

    /** @return array{cancelled:int,cancelled_ids:list<int>} */
    public function cancelSelectedDrafts(Team $team, array $orderIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $orderIds))));
        $draftIds = FoodAlchemistOrder::visibleToTeam($team)
            ->whereIn('id', $ids)
            ->where('status', OrderStatus::Draft->value)
            ->pluck('id')->map(fn ($id) => (int) $id)->all();

        DB::transaction(function () use ($team, $draftIds) {
            foreach ($draftIds as $orderId) {
                $this->setStatus($team, $orderId, OrderStatus::Cancelled);
            }
        });

        return ['cancelled' => count($draftIds), 'cancelled_ids' => $draftIds];
    }

    private function roundSummary(FoodAlchemistOrderRound $round): array
    {
        $orders = $round->orders;
        $drafts = $orders->filter(fn (FoodAlchemistOrder $order) => $order->status === OrderStatus::Draft);
        $blockers = $drafts->flatMap(fn (FoodAlchemistOrder $order) => $this->sendBlockers($order))->unique()->values()->all();
        $productionIds = $orders
            ->flatMap(fn (FoodAlchemistOrder $order) => $order->lines
                ->flatMap(fn (FoodAlchemistOrderLine $line) => array_keys((array) $line->source_contributions)))
            ->map(fn (string $ref) => preg_match('/^produktion:(\d+):/', $ref, $match) ? (int) $match[1] : null)
            ->filter()->unique()->values()->all();

        return [
            'id' => (int) $round->id,
            'uuid' => (string) $round->uuid,
            'label' => $round->label ?: 'Bestellrunde '.($round->created_at?->format('d.m.Y · H:i') ?? '#'.$round->id),
            'desired_delivery_date' => $round->desired_delivery_date?->toDateString(),
            'sourcing_strategy' => $round->sourcing_strategy,
            'note' => $round->note,
            'created_at' => $round->created_at?->toIso8601String(),
            'production_ids' => $productionIds,
            'orders' => $orders->map(fn (FoodAlchemistOrder $order) => [
                'id' => (int) $order->id,
                'supplier' => $order->supplier?->name ?? '—',
                'status' => ($order->status instanceof OrderStatus ? $order->status : OrderStatus::from((string) $order->status))->value,
                'status_label' => ($order->status instanceof OrderStatus ? $order->status : OrderStatus::from((string) $order->status))->label(),
                'desired_delivery_date' => $order->desired_delivery_date?->toDateString(),
                'positions' => $order->lines->count(),
                'total_net' => (float) $order->total_net,
                'warnings' => $this->orderWarnings($order),
            ])->values()->all(),
            'order_count' => $orders->count(),
            'supplier_count' => $orders->pluck('supplier_id')->unique()->count(),
            'position_count' => $orders->sum(fn (FoodAlchemistOrder $order) => $order->lines->count()),
            'total_net' => round((float) $orders->sum('total_net'), 2),
            'draft_count' => $drafts->count(),
            'editable' => $orders->isNotEmpty() && $drafts->count() === $orders->count(),
            'sendable' => $drafts->isNotEmpty() && $blockers === [],
            'blockers' => $blockers,
        ];
    }

    /**
     * Entfernt alte Beiträge derselben Quellen aus offenen Drafts, bevor ein Override-Pfad
     * die Vorschau neu schreibt. So wandert ein Bedarf wirklich zwischen Lieferanten-Schienen,
     * statt als Doppelbedarf in alter und neuer Schiene zu landen.
     *
     * @param  list<string>  $sourceRefs
     * @return list<int> betroffene Order-IDs
     */
    private function clearSourceRefsFromDrafts(Team $team, array $sourceRefs): array
    {
        $sourceRefs = array_values(array_unique(array_filter($sourceRefs)));
        if ($sourceRefs === []) {
            return [];
        }

        $touched = [];
        $lines = FoodAlchemistOrderLine::query()
            ->where('team_id', $team->id)
            ->whereHas('order', fn ($q) => $q
                ->where('team_id', $team->id)
                ->where('status', OrderStatus::Draft->value))
            ->get();

        foreach ($lines as $line) {
            $contrib = (array) ($line->source_contributions ?? []);
            $original = $contrib;
            foreach ($sourceRefs as $ref) {
                unset($contrib[$ref]);
            }
            if ($contrib === $original) {
                continue;
            }

            $touched[] = (int) $line->order_id;
            if ($contrib === []) {
                $line->delete();
            } else {
                $line->source_contributions = $contrib;
                $line->save();
                $this->recomputeLine($line->refresh());
            }
        }

        foreach (array_unique($touched) as $orderId) {
            $order = FoodAlchemistOrder::where('team_id', $team->id)->find($orderId);
            if ($order !== null) {
                $this->recomputeOrder($order->refresh());
            }
        }

        return array_values(array_unique($touched));
    }

    /**
     * Aktuelle Preview-Refs plus frühere Refs derselben Produktionen. So verschwinden beim
     * erneuten Planen auch entfernte Ziele und Beiträge an einem alten Liefertag.
     *
     * @return list<string>
     */
    private function replacementSourceRefs(Team $team, array $sources, array $preview): array
    {
        $refs = $this->previewSourceRefs($preview);
        $prefixes = collect($sources)
            ->filter(fn (array $source) => ($source['type'] ?? null) === 'production' && (int) ($source['id'] ?? 0) > 0)
            ->map(fn (array $source) => ProductionOrderService::sourceRefPrefix((int) $source['id']))
            ->unique()->values();
        if ($prefixes->isEmpty()) {
            return $refs;
        }

        FoodAlchemistOrderLine::query()
            ->where('team_id', $team->id)
            ->whereHas('order', fn ($query) => $query
                ->where('team_id', $team->id)
                ->where('status', OrderStatus::Draft->value))
            ->get(['source_contributions'])
            ->each(function (FoodAlchemistOrderLine $line) use (&$refs, $prefixes) {
                foreach (array_keys((array) $line->source_contributions) as $ref) {
                    if ($prefixes->contains(fn (string $prefix) => str_starts_with((string) $ref, $prefix))) {
                        $refs[] = (string) $ref;
                    }
                }
            });

        return array_values(array_unique($refs));
    }

    private function assertProductionSourcesReplaceable(Team $team, array $sources): void
    {
        $prefixes = collect($sources)
            ->filter(fn (array $source) => ($source['type'] ?? null) === 'production' && (int) ($source['id'] ?? 0) > 0)
            ->map(fn (array $source) => ProductionOrderService::sourceRefPrefix((int) $source['id']))
            ->unique()->values();
        if ($prefixes->isEmpty()) {
            return;
        }

        $locked = FoodAlchemistOrderLine::query()
            ->where('team_id', $team->id)
            ->whereHas('order', fn ($query) => $query
                ->where('team_id', $team->id)
                ->whereIn('status', [OrderStatus::Sent->value, OrderStatus::Confirmed->value, OrderStatus::Delivered->value]))
            ->get(['source_contributions'])
            ->contains(function (FoodAlchemistOrderLine $line) use ($prefixes) {
                return collect(array_keys((array) $line->source_contributions))
                    ->contains(fn (string $ref) => $prefixes->contains(fn (string $prefix) => str_starts_with($ref, $prefix)));
            });

        if ($locked) {
            throw new \RuntimeException('Mindestens eine zugehörige Bestellung wurde bereits ausgelöst. Der bestehende Stand bleibt eingefroren; Änderungen benötigen einen Korrekturbedarf.');
        }
    }

    /** @param list<int> $orderIds */
    private function deleteEmptyReplannedDrafts(Team $team, array $orderIds): void
    {
        if ($orderIds === []) {
            return;
        }

        FoodAlchemistOrder::visibleToTeam($team)
            ->where('team_id', $team->id)
            ->whereIn('id', array_values(array_unique(array_map('intval', $orderIds))))
            ->where('status', OrderStatus::Draft->value)
            ->whereDoesntHave('lines')
            ->get()
            ->each->delete();
    }

    /** @return list<string> */
    private function previewSourceRefs(array $preview): array
    {
        return collect($preview['orders_preview'] ?? [])
            ->flatMap(fn ($gruppe) => collect($gruppe['positionen'] ?? [])->pluck('source_ref'))
            ->filter()
            ->map(fn ($ref) => (string) $ref)
            ->unique()
            ->values()
            ->all();
    }

    private function markProductionSourcesHandedOver(Team $team, array $sources): void
    {
        foreach ($sources as $source) {
            if (($source['type'] ?? '') !== 'production') {
                continue;
            }
            $production = FoodAlchemistProductionOrder::visibleToTeam($team)->find((int) ($source['id'] ?? 0));
            if ($production === null) {
                continue;
            }
            $production->last_handover_at = now();
            $production->handover_targets_hash = ProductionOrderService::targetsHash($production->targets);
            $production->save();
        }
    }

    private function stampStrategyOnOrders(Team $team, array $orderIds, ?LeadLaStrategie $strategie): void
    {
        foreach (array_unique(array_map('intval', $orderIds)) as $orderId) {
            if ($orderId <= 0) {
                continue;
            }
            $order = FoodAlchemistOrder::where('team_id', $team->id)->find($orderId);
            if ($order === null) {
                continue;
            }
            $order->sourcing_strategy = $strategie?->value;
            $order->save();
        }
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

    private function previewSupplierItem(Team $team, array &$gruppen, array &$unresolved, array $source, ?string $date, string $reference, string $label, string $sourceRef): void
    {
        $la = FoodAlchemistSupplierItem::visibleToTeam($team)
            ->with(['supplier:id,name', 'structure.gp:id,name,piece_default_g'])
            ->find((int) ($source['id'] ?? 0));

        if ($la === null || $la->supplier_id === null) {
            $unresolved[] = $this->unresolved($source, $label, 'artikel_fehlt', 'Lieferantenartikel fehlt oder hat keinen Lieferanten.');

            return;
        }

        $qty = max(0.0, $this->sourceQty($source));
        if ($qty <= 0) {
            $unresolved[] = $this->unresolved($source, $label, 'menge_fehlt', 'Menge muss größer 0 sein.');

            return;
        }

        $ctx = (object) [
            'qty' => $la->qty !== null ? (float) $la->qty : null,
            'unit_code' => $la->unit_code,
            'packaging_unit' => $la->packaging_unit,
            'article_number' => $la->article_number,
            'designation' => $la->designation,
            'aktiver_preis' => $this->preise->activeFor((int) $la->id)?->price,
        ];
        $geb = $this->gebinde->berechne($ctx, 0.0, $la->structure?->gp?->piece_default_g !== null ? (float) $la->structure->gp->piece_default_g : null);
        $packPrice = $geb['pack_price'] ?? null;
        $lineTotal = $packPrice !== null ? round($qty * (float) $packPrice, 2) : 0.0;
        $this->previewAddPosition($gruppen, (int) $la->supplier_id, $la->supplier?->name ?? '—', $date, [
            'source_ref' => $sourceRef,
            'source_label' => $label,
            'reference' => $reference,
            'type' => 'supplier_item',
            'gp_id' => $la->structure?->gp?->id !== null ? (int) $la->structure->gp->id : null,
            'gp' => $la->structure?->gp?->name,
            'lead_la_id' => (int) $la->id,
            'override_key' => $this->overrideKey($sourceRef, $la->structure?->gp?->id !== null ? (int) $la->structure->gp->id : null),
            'article_number' => $la->article_number,
            'designation' => $la->designation,
            'qty_packs' => $qty,
            'packaging_unit' => $la->packaging_unit,
            'pack_price' => $packPrice !== null ? (float) $packPrice : null,
            'line_total' => $lineTotal,
            'needed_base_g' => 0.0,
            'needed_display' => null,
            'needed_unit' => null,
            'bestellbar' => $packPrice !== null && $qty > 0,
        ]);

        if ($packPrice === null) {
            $unresolved[] = $this->unresolved($source, $label, 'preis_fehlt', 'Preis am Lieferantenartikel fehlt; Position wird als 0-Euro-Klärfall angezeigt.');
        }
    }

    private function previewGp(Team $team, array &$gruppen, array &$unresolved, array $source, ?string $date, string $reference, string $label, string $sourceRef, ?LeadLaStrategie $strategie, array $overrides = []): void
    {
        $gp = FoodAlchemistGp::visibleToTeam($team)->find((int) ($source['id'] ?? 0));
        if ($gp === null) {
            $unresolved[] = $this->unresolved($source, $label, 'gp_fehlt', 'Grundprodukt nicht sichtbar oder nicht vorhanden.');

            return;
        }

        $grams = $this->sourceGramsForGp($gp, $source);
        if ($grams <= 0) {
            $unresolved[] = $this->unresolved($source, $label, 'menge_fehlt', 'Menge muss größer 0 sein.');

            return;
        }

        $overrideKey = $this->overrideKey($sourceRef, (int) $gp->id);
        $lead = $this->leadLa->effektiverLead($gp, $team, $strategie);
        $lead = $this->overrideLead($team, $gp, $overrides[$overrideKey] ?? null) ?? $lead;
        if ($lead?->supplier_id === null || $lead?->id === null) {
            $unresolved[] = $this->unresolved($source, $label, 'lead_la_fehlt', 'Kein bestellbarer Lead-Artikel für dieses Grundprodukt.');

            return;
        }

        $pieceG = $gp->piece_default_g !== null ? (float) $gp->piece_default_g : null;
        $geb = $this->gebinde->berechne($lead, $grams, $pieceG);
        $packPrice = $geb['pack_price'] ?? null;
        $this->previewAddPosition($gruppen, (int) $lead->supplier_id, $lead->supplier_name ?? '—', $date, [
            'source_ref' => $sourceRef,
            'source_label' => $label,
            'reference' => $reference,
            'type' => 'gp',
            'gp_id' => (int) $gp->id,
            'gp' => $gp->name,
            'lead_la_id' => (int) $lead->id,
            'override_key' => $overrideKey,
            'article_number' => $lead->article_number,
            'designation' => $lead->designation,
            'qty_packs' => (float) ($geb['qty_packs'] ?? 0),
            'packaging_unit' => $geb['packaging_unit'] ?? $lead->packaging_unit,
            'pack_price' => $packPrice !== null ? (float) $packPrice : null,
            'line_total' => ($geb['line_total'] ?? null) !== null ? (float) $geb['line_total'] : 0.0,
            'needed_base_g' => $grams,
            'needed_display' => $this->displayMenge($grams, (string) ($source['unit'] ?? 'kg'), $pieceG),
            'needed_unit' => (string) ($source['unit'] ?? 'kg'),
            'bestellbar' => $packPrice !== null && (float) ($geb['qty_packs'] ?? 0) > 0,
        ]);

        if ($packPrice === null || ! ($geb['berechenbar'] ?? false)) {
            $unresolved[] = $this->unresolved($source, $label, 'gebinde_preis_fehlt', 'Gebinde oder Preis ist nicht vollständig berechenbar.');
        }
    }

    private function previewZiel(Team $team, array &$gruppen, array &$unresolved, array &$warnings, array $ziel, ?string $date, string $reference, string $label, string $sourceRef, ?LeadLaStrategie $strategie, array $overrides = []): void
    {
        $vorschlag = $this->planung->bestellvorschlag($team, $ziel, $strategie);
        $warnings = array_merge($warnings, $vorschlag['warnungen'] ?? []);

        foreach ($vorschlag['lieferanten'] ?? [] as $grp) {
            $supplierId = $grp['supplier_id'] ?? null;
            if ($supplierId === null) {
                foreach (($grp['positionen'] ?? []) as $pos) {
                    $unresolved[] = $this->unresolved(
                        ['type' => 'recipe', 'id' => $ziel['recipe_id'] ?? null],
                        $label.($pos['gp'] ? ' · '.$pos['gp'] : ''),
                        'lead_la_fehlt',
                        'Kein bestellbarer Lead-Artikel für diese Bedarfsposition.'
                    );
                }

                continue;
            }

            foreach (($grp['positionen'] ?? []) as $pos) {
                $geb = $pos['gebinde'] ?? [];
                $gpId = $pos['gp_id'] !== null ? (int) $pos['gp_id'] : null;
                $neededG = (float) ($pos['menge_kg'] ?? 0) * 1000.0;
                $overrideKey = $this->overrideKey($sourceRef, $gpId);
                $leadLaId = $pos['lead_la_id'] !== null ? (int) $pos['lead_la_id'] : null;
                $supplierForGroup = (int) $supplierId;
                $supplierName = (string) ($grp['lieferant'] ?? '—');
                $articleNumber = $pos['lead_artikel_nr'] ?? ($geb['article_number'] ?? null);
                $designation = $pos['lead_artikel'] ?? null;
                $packagingUnit = $geb['packaging_unit'] ?? null;
                $usedOverride = false;
                if ($gpId !== null && $overrideKey !== null && array_key_exists($overrideKey, $overrides)) {
                    $gp = FoodAlchemistGp::visibleToTeam($team)->find($gpId);
                    $overrideLead = $gp !== null ? $this->overrideLead($team, $gp, $overrides[$overrideKey] ?? null) : null;
                    if ($overrideLead !== null && $overrideLead->supplier_id !== null) {
                        $usedOverride = true;
                        $supplierForGroup = (int) $overrideLead->supplier_id;
                        $supplierName = (string) ($overrideLead->supplier_name ?? '—');
                        $leadLaId = (int) $overrideLead->id;
                        $pieceG = $gp?->piece_default_g !== null ? (float) $gp->piece_default_g : null;
                        $geb = $this->gebinde->berechne($overrideLead, $neededG, $pieceG);
                        $articleNumber = $overrideLead->article_number;
                        $designation = $overrideLead->designation;
                        $packagingUnit = $geb['packaging_unit'] ?? $overrideLead->packaging_unit;
                    }
                }
                $packPrice = $geb['pack_price'] ?? null;
                $this->previewAddPosition($gruppen, $supplierForGroup, $supplierName, $date, [
                    'source_ref' => $sourceRef,
                    'source_label' => $label,
                    'reference' => $reference,
                    'type' => 'recipe',
                    'gp_id' => $gpId,
                    'gp' => $pos['gp'] ?? null,
                    'lead_la_id' => $leadLaId,
                    'override_key' => $overrideKey,
                    'article_number' => $articleNumber,
                    'designation' => $designation,
                    'qty_packs' => (float) ($geb['qty_packs'] ?? 0),
                    'packaging_unit' => $packagingUnit,
                    'pack_price' => $packPrice !== null ? (float) $packPrice : null,
                    'line_total' => ($geb['line_total'] ?? null) !== null ? (float) $geb['line_total'] : ($usedOverride ? 0.0 : ($pos['bestell_ek_eur'] !== null ? (float) $pos['bestell_ek_eur'] : 0.0)),
                    'needed_base_g' => $neededG,
                    'needed_display' => (float) ($pos['menge_kg'] ?? 0),
                    'needed_unit' => 'kg',
                    'bestellbar' => $leadLaId !== null && $packPrice !== null && (float) ($geb['qty_packs'] ?? 0) > 0,
                ]);

                if ($leadLaId === null || $packPrice === null || ! ($geb['berechenbar'] ?? false)) {
                    $unresolved[] = $this->unresolved(
                        ['type' => 'recipe', 'id' => $ziel['recipe_id'] ?? null],
                        $label.($pos['gp'] ? ' · '.$pos['gp'] : ''),
                        $leadLaId === null ? 'lead_la_fehlt' : 'gebinde_preis_fehlt',
                        $leadLaId === null ? 'Kein Lead-Artikel gefunden.' : 'Gebinde oder Preis ist nicht vollständig berechenbar.'
                    );
                }
            }
        }
    }

    private function previewAddPosition(array &$gruppen, int $supplierId, string $supplierName, ?string $date, array $position): void
    {
        $key = $supplierId.'|'.($date ?: '');
        $gruppen[$key] ??= [
            'supplier_id' => $supplierId,
            'supplier' => $supplierName,
            'delivery_date' => $date,
            'positionen' => [],
            'total_net' => 0.0,
            'references' => [],
            'source_refs' => [],
        ];

        $gruppen[$key]['positionen'][] = $position;
        $gruppen[$key]['total_net'] = round((float) $gruppen[$key]['total_net'] + (float) ($position['line_total'] ?? 0), 2);
        if (($position['reference'] ?? '') !== '') {
            $gruppen[$key]['references'][$position['reference']] = true;
        }
        if (($position['source_ref'] ?? '') !== '') {
            $gruppen[$key]['source_refs'][$position['source_ref']] = true;
        }
    }

    private function finalisierePreviewGruppen(array $gruppen): array
    {
        $lieferanten = FoodAlchemistSupplier::query()
            ->whereIn('id', collect($gruppen)->pluck('supplier_id')->filter()->unique()->values())
            ->get(['id', 'min_order_value', 'free_shipping_threshold', 'delivery_days', 'order_cutoff_time', 'order_lead_days'])
            ->keyBy('id');

        return collect($gruppen)
            ->sortBy(fn ($g) => (($g['delivery_date'] ?? '9999-12-31').'|'.($g['supplier'] ?? '')))
            ->map(function ($g) use ($lieferanten) {
                $supplier = $lieferanten[(int) $g['supplier_id']] ?? null;
                $min = $supplier?->min_order_value !== null ? (float) $supplier->min_order_value : null;
                $free = $supplier?->free_shipping_threshold !== null ? (float) $supplier->free_shipping_threshold : null;
                $total = (float) ($g['total_net'] ?? 0);
                $g['references'] = array_keys($g['references'] ?? []);
                $g['source_refs'] = array_keys($g['source_refs'] ?? []);
                $g['warnings'] = $this->previewLogistikWarnings($supplier, ($g['delivery_date'] ?? null) ?: null);
                $g['moq'] = [
                    'min_order_value' => $min,
                    'free_shipping_threshold' => $free,
                    'unter_mindestbestellwert' => $min !== null && $total < $min,
                    'fehlt_bis_min' => $min !== null && $total < $min ? round($min - $total, 2) : 0.0,
                    'frei_haus' => $free !== null && $total >= $free,
                    'fehlt_bis_frei_haus' => $free !== null && $total < $free ? round($free - $total, 2) : 0.0,
                ];

                return $g;
            })->values()->all();
    }

    private function sourceQty(array $source): float
    {
        return (float) str_replace(',', '.', trim((string) ($source['qty'] ?? 1)));
    }

    private function sourceGramsForGp(FoodAlchemistGp $gp, array $source): float
    {
        $qty = $this->sourceQty($source);
        $unit = (string) ($source['unit'] ?? 'kg');

        return match ($unit) {
            'g' => $qty,
            'stk' => $gp->piece_default_g !== null ? $qty * (float) $gp->piece_default_g : 0.0,
            default => $qty * 1000.0,
        };
    }

    private function displayMenge(float $grams, string $unit, ?float $pieceG): float
    {
        return match ($unit) {
            'g' => round($grams, 1),
            'stk' => $pieceG !== null && $pieceG > 0 ? round($grams / $pieceG, 2) : round($grams, 1),
            default => round($grams / 1000.0, 3),
        };
    }

    private function zielAusRecipeSource(array $source): array
    {
        $recipe = FoodAlchemistRecipe::find((int) ($source['id'] ?? 0));
        $qty = max(0.0, $this->sourceQty($source));
        $unit = (string) ($source['unit'] ?? ($recipe?->is_sales_recipe ? 'portions' : 'ansaetze'));
        $ziel = ['recipe_id' => (int) ($source['id'] ?? 0)];
        if ($unit === 'kg') {
            $ziel['amount_kg'] = $qty;
        } else {
            $ziel['portions'] = $qty;
        }

        return $ziel;
    }

    private function zielOhneMeta(array $target): array
    {
        return array_diff_key($target, array_flip(['source_ref', 'label']));
    }

    private function sourceLabel(Team $team, array $source): string
    {
        $id = (int) ($source['id'] ?? 0);

        return match ((string) ($source['type'] ?? '')) {
            'supplier_item' => (string) (FoodAlchemistSupplierItem::visibleToTeam($team)->find($id)?->designation ?? ('Artikel #'.$id)),
            'gp' => (string) (FoodAlchemistGp::visibleToTeam($team)->find($id)?->name ?? ('GP #'.$id)),
            'recipe' => (string) (FoodAlchemistRecipe::visibleToTeam($team)->find($id)?->name ?? ('Rezept #'.$id)),
            'production' => (string) (FoodAlchemistProductionOrder::visibleToTeam($team)->find($id)?->name ?? ('Produktion #'.$id)),
            default => 'Quelle',
        };
    }

    private function sourceRef(array $source, int $idx): string
    {
        if (($source['type'] ?? '') === 'production') {
            return 'production-source:'.(int) ($source['id'] ?? 0);
        }

        $basis = implode(':', [
            (string) ($source['type'] ?? 'source'),
            (string) ($source['id'] ?? $idx),
            (string) ($source['qty'] ?? 1),
            (string) ($source['unit'] ?? ''),
            (string) ($source['delivery_date'] ?? ''),
            (string) ($source['reference'] ?? ''),
        ]);

        return Str::slug((string) ($source['type'] ?? 'source'), '_').':'.(string) ($source['id'] ?? $idx).'@'.substr(sha1($basis), 0, 10);
    }

    private function overrideKey(string $sourceRef, ?int $gpId): ?string
    {
        return $gpId !== null ? $sourceRef.'|gp:'.$gpId : null;
    }

    private function overrideLead(Team $team, FoodAlchemistGp $gp, int|string|null $leadLaId): ?object
    {
        if ($leadLaId === null || (int) $leadLaId <= 0) {
            return null;
        }

        $la = FoodAlchemistSupplierItem::visibleToTeam($team)
            ->with(['supplier:id,name', 'structure.gp:id,name'])
            ->find((int) $leadLaId);
        if ($la === null || $la->supplier_id === null || (int) ($la->structure?->gp?->id ?? 0) !== (int) $gp->id) {
            return null;
        }

        return (object) [
            'id' => (int) $la->id,
            'supplier_id' => (int) $la->supplier_id,
            'supplier_name' => $la->supplier?->name,
            'qty' => $la->qty !== null ? (float) $la->qty : null,
            'unit_code' => $la->unit_code,
            'packaging_unit' => $la->packaging_unit,
            'article_number' => $la->article_number,
            'designation' => $la->designation,
            'aktiver_preis' => $this->preise->activeFor((int) $la->id)?->price,
        ];
    }

    private function unresolved(array $source, string $label, string $code, string $message): array
    {
        return [
            'type' => (string) ($source['type'] ?? ''),
            'id' => $source['id'] ?? null,
            'label' => $label,
            'code' => $code,
            'message' => $message,
        ];
    }

    private function strategieAusWert(LeadLaStrategie|string|null $strategy): ?LeadLaStrategie
    {
        if ($strategy instanceof LeadLaStrategie) {
            return $strategy;
        }

        return $strategy !== null && $strategy !== '' ? LeadLaStrategie::tryFrom((string) $strategy) : null;
    }

    private function kopfAusQuelle(Team $team, int $orderId, string $reference): void
    {
        if ($reference === '') {
            return;
        }
        $order = FoodAlchemistOrder::find($orderId);
        if ($order === null || ($order->reference ?? '') !== '') {
            return;
        }
        $this->updateHeader($team, $orderId, ['reference' => $reference]);
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

    /** Entfernt leere eigene Entwurfs-Bestellungen aus der operativen Liste. */
    public function deleteEmptyDrafts(Team $team): int
    {
        $drafts = FoodAlchemistOrder::visibleToTeam($team)
            ->where('team_id', $team->id)
            ->where('status', OrderStatus::Draft->value)
            ->whereDoesntHave('lines')
            ->get();

        foreach ($drafts as $draft) {
            $draft->delete();
        }

        return $drafts->count();
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
     * Lieferantenbestätigung nach dem Absenden pflegen: Nummer, bestätigter Liefertag, Notiz.
     *
     * @param  array{supplier_order_number?:?string, confirmed_delivery_date?:?string, supplier_confirmation_note?:?string}  $input
     */
    public function updateSupplierConfirmation(Team $team, int $orderId, array $input): FoodAlchemistOrder
    {
        $order = $this->ownedOrder($team, $orderId);
        $status = $order->status instanceof OrderStatus ? $order->status : OrderStatus::from((string) $order->status);
        if (! in_array($status, [OrderStatus::Sent, OrderStatus::Confirmed, OrderStatus::Delivered], true)) {
            throw new \RuntimeException('Lieferantenbestätigung ist erst nach dem Absenden möglich.');
        }

        if (array_key_exists('supplier_order_number', $input)) {
            $order->supplier_order_number = trim((string) ($input['supplier_order_number'] ?? '')) ?: null;
        }
        if (array_key_exists('confirmed_delivery_date', $input)) {
            $order->confirmed_delivery_date = ($input['confirmed_delivery_date'] ?? '') !== '' ? $input['confirmed_delivery_date'] : null;
        }
        if (array_key_exists('supplier_confirmation_note', $input)) {
            $order->supplier_confirmation_note = trim((string) ($input['supplier_confirmation_note'] ?? '')) ?: null;
        }
        if ($status === OrderStatus::Sent) {
            $order->status = OrderStatus::Confirmed;
            $order->confirmed_at ??= now();
        }
        $order->save();

        return $order->refresh();
    }

    /**
     * Rechnungskopf pflegen; die zeilenweise Prüfung bleibt in `updateInvoiceLine`.
     *
     * @param  array{invoice_number?:?string, invoice_date?:?string, invoice_note?:?string}  $input
     */
    public function updateInvoiceHeader(Team $team, int $orderId, array $input): FoodAlchemistOrder
    {
        $order = $this->ownedOrder($team, $orderId);
        $status = $order->status instanceof OrderStatus ? $order->status : OrderStatus::from((string) $order->status);
        if (! in_array($status, [OrderStatus::Sent, OrderStatus::Confirmed, OrderStatus::Delivered], true)) {
            throw new \RuntimeException('Rechnungskopf ist erst nach dem Absenden möglich.');
        }

        if (array_key_exists('invoice_number', $input)) {
            $order->invoice_number = trim((string) ($input['invoice_number'] ?? '')) ?: null;
        }
        if (array_key_exists('invoice_date', $input)) {
            $order->invoice_date = ($input['invoice_date'] ?? '') !== '' ? $input['invoice_date'] : null;
        }
        if (array_key_exists('invoice_note', $input)) {
            $order->invoice_note = trim((string) ($input['invoice_note'] ?? '')) ?: null;
        }
        $order->save();

        return $order->refresh();
    }

    /**
     * Offene-Posten-light: Zahlungsstatus am Rechnungsbeleg pflegen.
     *
     * @param  array{payment_status?:?string, invoice_paid_at?:?string, payment_note?:?string}  $input
     */
    public function updatePayment(Team $team, int $orderId, array $input): FoodAlchemistOrder
    {
        $order = $this->ownedOrder($team, $orderId);
        $status = $order->status instanceof OrderStatus ? $order->status : OrderStatus::from((string) $order->status);
        if (! in_array($status, [OrderStatus::Sent, OrderStatus::Confirmed, OrderStatus::Delivered], true)) {
            throw new \RuntimeException('Zahlungsstatus ist erst nach dem Absenden möglich.');
        }
        if ($order->invoice_number === null && $order->invoice_date === null) {
            throw new \RuntimeException('Zahlungsstatus braucht zuerst einen Rechnungskopf.');
        }

        if (array_key_exists('payment_status', $input)) {
            $paymentStatus = trim((string) ($input['payment_status'] ?? ''));
            if ($paymentStatus !== '' && ! in_array($paymentStatus, ['open', 'paid', 'disputed'], true)) {
                throw new \RuntimeException('Unbekannter Zahlungsstatus. Erlaubt: open, paid, disputed.');
            }
            $order->payment_status = $paymentStatus !== '' ? $paymentStatus : null;
        }
        if (array_key_exists('invoice_paid_at', $input)) {
            $order->invoice_paid_at = ($input['invoice_paid_at'] ?? '') !== '' ? $input['invoice_paid_at'] : null;
        }
        if (array_key_exists('payment_note', $input)) {
            $order->payment_note = trim((string) ($input['payment_note'] ?? '')) ?: null;
        }
        if (($order->payment_status ?? null) === 'paid' && $order->invoice_paid_at === null) {
            $order->invoice_paid_at = now()->toDateString();
        }
        if (($order->payment_status ?? null) !== 'paid' && array_key_exists('payment_status', $input)) {
            $order->invoice_paid_at = array_key_exists('invoice_paid_at', $input) ? $order->invoice_paid_at : null;
        }
        $order->save();

        return $order->refresh();
    }

    /**
     * Freigabe-light am Bestellkopf. Kein Rollenworkflow, aber ein prüfbarer Zustand vor Versand.
     *
     * @param  array{approval_status?:?string, approval_note?:?string}  $input
     */
    public function updateApproval(Team $team, int $orderId, array $input, ?int $userId = null): FoodAlchemistOrder
    {
        $order = $this->ownedOrder($team, $orderId);
        $status = $order->status instanceof OrderStatus ? $order->status : OrderStatus::from((string) $order->status);
        if (! in_array($status, [OrderStatus::Draft, OrderStatus::Sent, OrderStatus::Confirmed], true)) {
            throw new \RuntimeException('Freigabe ist nur vor Abschluss der Bestellung editierbar.');
        }

        if (array_key_exists('approval_status', $input)) {
            $approvalStatus = trim((string) ($input['approval_status'] ?? ''));
            if ($approvalStatus !== '' && ! in_array($approvalStatus, ['requested', 'approved', 'rejected'], true)) {
                throw new \RuntimeException('Unbekannter Freigabestatus. Erlaubt: requested, approved, rejected.');
            }
            $order->approval_status = $approvalStatus !== '' ? $approvalStatus : null;
            if ($approvalStatus === 'requested') {
                $order->approval_requested_at ??= now();
                $order->approved_at = null;
                $order->approved_by = null;
            } elseif ($approvalStatus === 'approved') {
                $order->approved_at = now();
                $order->approved_by = $userId;
            } elseif ($approvalStatus === 'rejected' || $approvalStatus === '') {
                $order->approved_at = null;
                $order->approved_by = null;
            }
        }
        if (array_key_exists('approval_note', $input)) {
            $order->approval_note = trim((string) ($input['approval_note'] ?? '')) ?: null;
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
        $deliveryDate = $line->order?->desired_delivery_date?->toDateString();
        $targetDraft = $this->draftForSupplier($team, $newSupplierId, $deliveryDate, $userId);
        $contribs = $line->source_contributions ?? [];
        if (! empty($contribs)) {
            foreach ($contribs as $ref => $g) {
                $this->upsertContribution($team, $targetDraft, ['lead_la_id' => $newLaId, 'gp_id' => $gpId, 'menge_g' => $g], (string) $ref);
            }
        } else {
            // Beitragslose (manuelle) Zeile: manuelle Menge in die Ziel-Schiene übernehmen.
            $this->addManualLine($team, $newLaId, (float) $line->qty_packs, $line->note, $userId, $deliveryDate);
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
     * Ausweichquellen für eine noch nicht gespeicherte Cockpit-/Vorschau-Position. Gleicher
     * Shape wie `lineAlternativen`, aber ohne Draft-Line als Voraussetzung.
     *
     * @return list<array{la_id:int, designation:?string, supplier:?string, supplier_id:?int, vergleichspreis:?float, vergleichspreis_einheit:?string, ist_stamm:bool, gesperrt:bool, schiene_wechsel:bool}>
     */
    public function gpAlternativen(Team $team, int $gpId, ?int $currentSupplierId = null, ?int $currentLaId = null): array
    {
        $gp = FoodAlchemistGp::visibleToTeam($team)->find($gpId);
        if ($gp === null) {
            return [];
        }

        return $this->leadLa->rangliste($gp, $team)
            ->reject(fn ($la) => $currentLaId !== null && (int) $la->id === $currentLaId)
            ->map(fn ($la) => [
                'la_id' => (int) $la->id,
                'designation' => $la->designation,
                'supplier' => $la->supplier_name,
                'supplier_id' => $la->supplier_id !== null ? (int) $la->supplier_id : null,
                'vergleichspreis' => $la->vergleichspreis_wert !== null ? (float) $la->vergleichspreis_wert : null,
                'vergleichspreis_einheit' => $la->vergleichspreis['unit'] ?? null,
                'ist_stamm' => (bool) $la->ist_stamm,
                'gesperrt' => (bool) $la->locked,
                'schiene_wechsel' => $currentSupplierId !== null && $la->supplier_id !== null && (int) $la->supplier_id !== $currentSupplierId,
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
            $blocker = $this->sendBlockers($order->refresh());
            if ($blocker !== []) {
                throw new \RuntimeException('Bestellung kann nicht versendet werden: '.implode(', ', $blocker));
            }
            $order->sent_at = now();
        } elseif ($ziel === OrderStatus::Confirmed) {
            $order->confirmed_at = now();
        } elseif ($ziel === OrderStatus::Delivered) {
            $this->fillReceiptFromOrder($order);
            $order->delivered_at = now();
        }
        $order->status = $ziel;
        $order->save();

        // Einkauf E2: FA-Einkauf → Journal. Storno entfernt die Ist-Buchungen; das Erreichen
        // des konfigurierten Auslöse-Status (sent|delivered, TeamSettingsService) spiegelt die
        // Zeilen als Ist-Einkäufe (idempotent). So zählt der in FA getätigte Einkauf auf Spend.
        $journal = app(PurchaseJournalService::class);
        if ($ziel === OrderStatus::Cancelled) {
            $journal->entferneOrder($order);
        } elseif ($ziel->value === app(TeamSettingsService::class)->purchaseJournalTrigger($team)) {
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
            ->with([
                'supplier:id,name,min_order_value,free_shipping_threshold,delivery_days,order_cutoff_time,order_lead_days',
                'lines:id,order_id,supplier_item_id,gp_id,article_number,designation,packaging_unit,qty_packs,pack_price,received_qty_packs,quota_consumed_packs,source_contributions,note,received_note,invoice_note,claim_note',
                'lines.supplierItem:id,designation,article_number',
                'lines.gp:id,name',
            ])
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
            ->with(['supplier', 'lines.gp:id,piece_default_g', 'lines.supplierItem'])
            ->findOrFail($orderId);
        $status = $order->status instanceof OrderStatus ? $order->status : OrderStatus::from((string) $order->status);

        $alleRefs = [];
        $zeilen = $order->lines->map(function ($l) use (&$alleRefs, $order, $team) {
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
                'received_qty_packs' => $l->received_qty_packs !== null ? (float) $l->received_qty_packs : null,
                'received_note' => $l->received_note,
                'received_at' => $l->received_at?->format('Y-m-d H:i'),
                'receipt_diff_packs' => $l->received_qty_packs !== null ? round((float) $l->received_qty_packs - (float) $l->qty_packs, 2) : null,
                'receipt_status' => $this->lineReceiptStatus($l),
                'invoice_qty_packs' => $l->invoice_qty_packs !== null ? (float) $l->invoice_qty_packs : null,
                'invoice_pack_price' => $l->invoice_pack_price !== null ? (float) $l->invoice_pack_price : null,
                'invoice_note' => $l->invoice_note,
                'invoice_checked_at' => $l->invoice_checked_at?->format('Y-m-d H:i'),
                'invoice_line_total' => $l->invoice_qty_packs !== null && $l->invoice_pack_price !== null
                    ? round((float) $l->invoice_qty_packs * (float) $l->invoice_pack_price, 2)
                    : null,
                'invoice_diff_net' => $this->lineInvoiceDiffNet($l),
                'invoice_status' => $this->lineInvoiceStatus($l),
                'claim_status' => $l->claim_status,
                'claim_status_label' => $this->claimStatusLabel($l->claim_status),
                'claim_qty_packs' => $l->claim_qty_packs !== null ? (float) $l->claim_qty_packs : null,
                'credit_expected_net' => $l->credit_expected_net !== null ? (float) $l->credit_expected_net : null,
                'claim_note' => $l->claim_note,
                'quota_consumed_packs' => $l->quota_consumed_packs !== null ? (float) $l->quota_consumed_packs : null,
                'quota_consumed_at' => $l->quota_consumed_at?->format('Y-m-d H:i'),
                'quota' => $this->lineQuotaSummary($l, $order->desired_delivery_date ?? now()),
                'inventory' => $this->inventory->lineStockSummary($team, $l),
                'needed_base_g' => (float) $l->needed_base_g,
                'needed_display' => $bedarf,          // E1: korrekte Bedarfsmenge in der Grundeinheit des LA
                'needed_unit' => $bedarfEinheit,      // kg / l / Stk (nie mehr fälschlich „kg" bei Stück)
                'note' => $l->note,
                'herkunft' => $this->parseHerkunft($refs),
                'bestellbar' => $l->pack_price !== null && (float) $l->qty_packs > 0,
            ];
        })->all();

        $herkunft = $this->herkunftMitProduktionsnamen($team, $this->herkunftAggregat($alleRefs));
        $warnings = $this->orderWarnings($order);
        $sendBlockers = $this->sendBlockers($order);
        $invoiceDueDate = $this->invoiceDueDate($order);

        return [
            'id' => (int) $order->id,
            'supplier_id' => (int) $order->supplier_id,
            'supplier' => $order->supplier?->name,
            'status' => $status->value,
            'status_label' => $status->label(),
            'reference' => $order->reference,
            'desired_delivery_date' => $order->desired_delivery_date?->toDateString(),
            'supplier_order_number' => $order->supplier_order_number,
            'confirmed_delivery_date' => $order->confirmed_delivery_date?->toDateString(),
            'supplier_confirmation_note' => $order->supplier_confirmation_note,
            'invoice_number' => $order->invoice_number,
            'invoice_date' => $order->invoice_date?->toDateString(),
            'invoice_due_date' => $invoiceDueDate?->toDateString(),
            'payment_term_days' => $order->supplier?->payment_term_days,
            'payment_status' => $order->payment_status,
            'invoice_paid_at' => $order->invoice_paid_at?->toDateString(),
            'payment_note' => $order->payment_note,
            'approval_status' => $order->approval_status,
            'approval_requested_at' => $order->approval_requested_at?->format('Y-m-d H:i'),
            'approved_at' => $order->approved_at?->format('Y-m-d H:i'),
            'approved_by' => $order->approved_by !== null ? (int) $order->approved_by : null,
            'approval_note' => $order->approval_note,
            'invoice_note' => $order->invoice_note,
            'note' => $order->note,
            'sourcing_strategy' => $order->sourcing_strategy,   // E3: Preisstrategie-Override je Schiene (NULL = Haupteinstellung)
            'total_net' => (float) $order->total_net,
            'is_owned' => $order->isOwnedBy($team),
            'editierbar' => $status->istOffen() && $order->isOwnedBy($team),
            'wareneingang_editierbar' => in_array($status, [OrderStatus::Sent, OrderStatus::Confirmed], true) && $order->isOwnedBy($team),
            'rechnung_editierbar' => in_array($status, [OrderStatus::Sent, OrderStatus::Confirmed, OrderStatus::Delivered], true) && $order->isOwnedBy($team),
            'receipt' => $this->receiptSummary($order),
            'invoice' => $this->invoiceSummary($order),
            'payment' => $this->paymentSummary($order),
            'approval' => $this->approvalSummary($order),
            'claims' => $this->claimSummary($order),
            'quota' => $this->quotaSummary($order),
            'inventory' => $this->inventorySummaryFromLines($zeilen),
            'moq' => $this->moqAmpel($order),
            'warnings' => $warnings,
            'send_blockers' => $sendBlockers,
            'logistik' => $this->logistikInfo($order),
            'herkunft' => $herkunft,   // E1: Schienen-weite Quellen-Übersicht (dedupliziert, mit Links)
            'zeilen' => $zeilen,
        ];
    }

    /**
     * E1-Bug-Fix: Bedarf in der korrekten Grundeinheit des Lead-LA. `needed_base_g` liegt
     * IMMER in Gramm (Basis); Stück-Artikel werden über das Stückgewicht zurückgerechnet
     * (spiegelt GebindeRechner), statt fälschlich als „kg" ausgewiesen zu werden.
     *
     * @return array{0:float, 1:string} [Menge, Einheit]
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
                $label = 'Produktion #'.$prodId;
            } elseif (preg_match('/^concept:([^@:]+)/', $ref, $m)) {
                $type = 'concept';
                $label = 'Konzept '.$m[1];
            } elseif (preg_match('/^recipe:([^@:]+)/', $ref, $m)) {
                $type = 'recipe';
                $label = 'Gericht '.$m[1];
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
    public function herkunftAggregat(array $refs): array
    {
        $byKey = [];
        foreach ($this->parseHerkunft($refs) as $h) {
            $key = $h['production_order_id'] !== null
                ? 'produktion:'.$h['production_order_id']
                : $h['type'].':'.$h['label'];
            $byKey[$key] = [
                'key' => $key,
                'type' => $h['type'],
                'label' => $h['label'],
                'production_order_id' => $h['production_order_id'],
            ];
        }

        return array_values($byKey);
    }

    /**
     * Produktion-Herkunft als echten Produktionskontext beschriften, nicht nur als ID.
     *
     * @param  list<array{key:string, type:string, label:string, production_order_id:?int}>  $herkunft
     * @return list<array{key:string, type:string, label:string, production_order_id:?int}>
     */
    public function herkunftMitProduktionsnamen(Team $team, array $herkunft): array
    {
        $ids = collect($herkunft)->pluck('production_order_id')->filter()->map(fn ($id) => (int) $id)->unique()->values();
        if ($ids->isEmpty()) {
            return $herkunft;
        }

        return $this->beschrifteHerkunft($herkunft, $this->produktionsNamen($team, $ids));
    }

    /**
     * #6 N+1: wie {@see herkunftMitProduktionsnamen()}, aber fuer VIELE Bestellungen in EINEM Query.
     * Vorher wurde die Einzel-Methode je Order aufgerufen (Orders/Index) → 1 ProductionOrder-Query
     * pro Zeile. Hier gehen ALLE Produktions-IDs ueber alle Orders in ein whereIn; danach wird jede
     * Order in-memory beschriftet.
     *
     * @param  array<int, list<array{key:string, type:string, label:string, production_order_id:?int}>>  $herkunftProOrder
     * @return array<int, list<array{key:string, type:string, label:string, production_order_id:?int}>>
     */
    public function herkunftMitProduktionsnamenBulk(Team $team, array $herkunftProOrder): array
    {
        $ids = collect($herkunftProOrder)
            ->flatMap(fn ($herkunft) => collect($herkunft)->pluck('production_order_id')->all())
            ->filter()->map(fn ($id) => (int) $id)->unique()->values();
        if ($ids->isEmpty()) {
            return $herkunftProOrder;
        }

        $namen = $this->produktionsNamen($team, $ids);
        $out = [];
        foreach ($herkunftProOrder as $orderId => $herkunft) {
            $out[$orderId] = $this->beschrifteHerkunft($herkunft, $namen);
        }

        return $out;
    }

    /** @return \Illuminate\Support\Collection<int, string> production_order_id ⇒ Label (ein Query) */
    private function produktionsNamen(Team $team, \Illuminate\Support\Collection $ids): \Illuminate\Support\Collection
    {
        return FoodAlchemistProductionOrder::visibleToTeam($team)
            ->whereIn('id', $ids)
            ->get(['id', 'name', 'production_date'])
            ->mapWithKeys(fn ($p) => [
                (int) $p->id => trim(($p->name ?: 'Produktion #'.$p->id).($p->production_date ? ' · '.$p->production_date->format('d.m.Y') : '')),
            ]);
    }

    /**
     * @param  list<array{key:string, type:string, label:string, production_order_id:?int}>  $herkunft
     * @return list<array{key:string, type:string, label:string, production_order_id:?int}>
     */
    private function beschrifteHerkunft(array $herkunft, \Illuminate\Support\Collection $namen): array
    {
        return collect($herkunft)->map(function ($h) use ($namen) {
            if (($h['production_order_id'] ?? null) !== null) {
                $h['label'] = $namen[(int) $h['production_order_id']] ?? $h['label'];
            }

            return $h;
        })->values()->all();
    }

    /** S3: Volldaten für Bestell-Dokument (PDF/Druck/CSV) — Lieferant-Stammdaten + Zeilen. */
    public function dokument(Team $team, int $orderId): array
    {
        $order = FoodAlchemistOrder::visibleToTeam($team)->with(['supplier', 'lines.supplierItem'])->findOrFail($orderId);
        $status = $order->status instanceof OrderStatus ? $order->status : OrderStatus::from((string) $order->status);
        $sup = $order->supplier;
        $invoiceDueDate = $this->invoiceDueDate($order);

        return [
            'id' => (int) $order->id,
            'status' => $status->value,
            'status_label' => $status->label(),
            'reference' => $order->reference,
            'desired_delivery_date' => $order->desired_delivery_date?->toDateString(),
            'created_at' => $order->created_at?->format('d.m.Y'),
            'sent_at' => $order->sent_at?->format('d.m.Y H:i'),
            'confirmed_at' => $order->confirmed_at?->format('d.m.Y H:i'),
            'delivered_at' => $order->delivered_at?->format('d.m.Y H:i'),
            'supplier_order_number' => $order->supplier_order_number,
            'confirmed_delivery_date' => $order->confirmed_delivery_date?->toDateString(),
            'supplier_confirmation_note' => $order->supplier_confirmation_note,
            'invoice_number' => $order->invoice_number,
            'invoice_date' => $order->invoice_date?->toDateString(),
            'invoice_due_date' => $invoiceDueDate?->toDateString(),
            'payment_term_days' => $sup?->payment_term_days,
            'payment_status' => $order->payment_status,
            'invoice_paid_at' => $order->invoice_paid_at?->toDateString(),
            'payment_note' => $order->payment_note,
            'approval_status' => $order->approval_status,
            'approval_requested_at' => $order->approval_requested_at?->format('Y-m-d H:i'),
            'approved_at' => $order->approved_at?->format('Y-m-d H:i'),
            'approved_by' => $order->approved_by !== null ? (int) $order->approved_by : null,
            'approval_note' => $order->approval_note,
            'invoice_note' => $order->invoice_note,
            'total_net' => (float) $order->total_net,
            'moq' => $this->moqAmpel($order),
            'receipt' => $this->receiptSummary($order),
            'invoice' => $this->invoiceSummary($order),
            'payment' => $this->paymentSummary($order),
            'approval' => $this->approvalSummary($order),
            'claims' => $this->claimSummary($order),
            'quota' => $this->quotaSummary($order),
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
                'received_qty_packs' => $l->received_qty_packs !== null ? (float) $l->received_qty_packs : null,
                'receipt_diff_packs' => $l->received_qty_packs !== null ? round((float) $l->received_qty_packs - (float) $l->qty_packs, 2) : null,
                'received_note' => $l->received_note,
                'invoice_qty_packs' => $l->invoice_qty_packs !== null ? (float) $l->invoice_qty_packs : null,
                'invoice_pack_price' => $l->invoice_pack_price !== null ? (float) $l->invoice_pack_price : null,
                'invoice_line_total' => $l->invoice_qty_packs !== null && $l->invoice_pack_price !== null
                    ? round((float) $l->invoice_qty_packs * (float) $l->invoice_pack_price, 2)
                    : null,
                'invoice_diff_net' => $this->lineInvoiceDiffNet($l),
                'invoice_note' => $l->invoice_note,
                'claim_status' => $l->claim_status,
                'claim_status_label' => $this->claimStatusLabel($l->claim_status),
                'claim_qty_packs' => $l->claim_qty_packs !== null ? (float) $l->claim_qty_packs : null,
                'credit_expected_net' => $l->credit_expected_net !== null ? (float) $l->credit_expected_net : null,
                'claim_note' => $l->claim_note,
                'quota_consumed_packs' => $l->quota_consumed_packs !== null ? (float) $l->quota_consumed_packs : null,
                'quota_consumed_at' => $l->quota_consumed_at?->format('Y-m-d H:i'),
                'quota' => $this->lineQuotaSummary($l, $order->desired_delivery_date ?? now()),
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
        $betreff = 'Bestellung '.$name.' — '.($d['reference'] ?: ('#'.$d['id']));

        $z = ['Guten Tag,', '', 'bitte folgende Bestellung:', ''];
        foreach ($d['zeilen'] as $l) {
            $menge = rtrim(rtrim(number_format($l['qty_packs'], 2, ',', '.'), '0'), ',');
            $geb = trim(($l['packaging_unit'] ?? '').' '.($l['designation'] ?? ''));
            $z[] = "- {$menge}× {$geb}".($l['article_number'] ? " (Art. {$l['article_number']})" : '');
        }
        $z[] = '';
        if ($d['desired_delivery_date']) {
            $z[] = 'Wunsch-Liefertermin: '.$d['desired_delivery_date'];
        }
        $z[] = 'Netto gesamt: '.number_format($d['total_net'], 2, ',', '.').' €';
        $z[] = '';
        $z[] = 'Vielen Dank.';

        return ['to' => $d['lieferant']['email_order'] ?? '', 'subject' => $betreff, 'body' => implode("\n", $z)];
    }

    /** Vorbefüllte Storno-Mail für einen bereits ausgelösten Lieferantenbeleg. */
    public function cancellationMailtoData(Team $team, int $orderId): array
    {
        $order = FoodAlchemistOrder::visibleToTeam($team)->findOrFail($orderId);
        $status = $order->status instanceof OrderStatus ? $order->status : OrderStatus::from((string) $order->status);
        if (! in_array($status, [OrderStatus::Sent, OrderStatus::Confirmed], true)) {
            return ['to' => '', 'subject' => '', 'body' => ''];
        }

        $d = $this->dokument($team, $orderId);
        $name = $d['lieferant']['name'] ?? 'Lieferant';
        $kennung = $d['reference'] ?: ('#'.$d['id']);
        $subject = 'Stornierung unserer Bestellung '.$kennung;
        $body = [
            'Guten Tag,',
            '',
            'bitte stornieren Sie unsere Bestellung '.$kennung.' vollständig.',
            'Lieferant: '.$name,
        ];
        if ($d['desired_delivery_date']) {
            $body[] = 'Geplanter Liefertermin: '.$d['desired_delivery_date'];
        }
        $body[] = '';
        $body[] = 'Bitte bestätigen Sie uns die Stornierung kurz schriftlich.';
        $body[] = '';
        $body[] = 'Vielen Dank.';

        return [
            'to' => $d['lieferant']['email_order'] ?? '',
            'subject' => $subject,
            'body' => implode("\n", $body),
        ];
    }

    /**
     * Lieferantenkommunikation beim Produktionsstorno. Ein reiner Produktionsbeleg wird
     * vollständig storniert; bei einem Sammelbeleg werden nur die entfallenden Anteile gemeldet.
     *
     * @return array{to:string,subject:string,body:string,kind:'full'|'partial'}
     */
    public function productionCancellationMailtoData(Team $team, int $orderId, int $productionOrderId): array
    {
        $order = FoodAlchemistOrder::visibleToTeam($team)->with(['supplier', 'lines'])->findOrFail($orderId);
        $prefix = ProductionOrderService::sourceRefPrefix($productionOrderId);
        $affected = [];
        $hasOtherDemand = false;

        foreach ($order->lines as $line) {
            $contributions = (array) $line->source_contributions;
            $cancelledGrams = collect($contributions)
                ->filter(fn ($value, string $ref) => str_starts_with($ref, $prefix))
                ->sum(fn ($value) => (float) $value);
            if ($cancelledGrams > 0) {
                $affected[] = [
                    'article_number' => $line->article_number,
                    'designation' => $line->designation,
                    'cancelled_grams' => (float) $cancelledGrams,
                ];
            }
            if ($line->is_manual_qty || collect(array_keys($contributions))->contains(fn (string $ref) => ! str_starts_with($ref, $prefix))) {
                $hasOtherDemand = true;
            }
        }

        $identifier = $order->reference ?: ('#'.$order->id);
        $kind = $hasOtherDemand ? 'partial' : 'full';
        $subject = $kind === 'full'
            ? 'Stornierung unserer Bestellung '.$identifier
            : 'Änderung unserer Bestellung '.$identifier;
        $body = ['Guten Tag,', ''];

        if ($kind === 'full') {
            $body[] = 'bitte stornieren Sie unsere Bestellung '.$identifier.' vollständig.';
        } else {
            $body[] = 'bei unserer Bestellung '.$identifier.' entfällt ein Teil des Bedarfs.';
            $body[] = 'Bitte reduzieren Sie folgende Positionen und senden Sie uns eine aktualisierte Bestätigung:';
            $body[] = '';
            foreach ($affected as $line) {
                $kg = rtrim(rtrim(number_format($line['cancelled_grams'] / 1000, 3, ',', '.'), '0'), ',');
                $article = $line['article_number'] ? ' (Art. '.$line['article_number'].')' : '';
                $body[] = '- '.($line['designation'] ?: 'Position').$article.': entfallender Bedarfsanteil '.$kg.' kg';
            }
        }
        $body[] = '';
        $body[] = 'Bitte bestätigen Sie uns die Änderung kurz schriftlich.';
        $body[] = '';
        $body[] = 'Vielen Dank.';

        return [
            'to' => (string) ($order->supplier?->email_order ?? ''),
            'subject' => $subject,
            'body' => implode("\n", $body),
            'kind' => $kind,
        ];
    }

    /**
     * Bucht die tatsächlich gelieferte Gebinde-Menge einer Bestellzeile. Der Lagerzugang
     * wird idempotent als Delta gegen die bisherige Wareneingangsbuchung gespiegelt.
     */
    public function updateReceiptLine(Team $team, int $lineId, int|float|string|null $receivedQtyPacks, ?string $note = null): FoodAlchemistOrderLine
    {
        $line = FoodAlchemistOrderLine::with('order')->findOrFail($lineId);
        $this->guardReceiptLine($team, $line);

        if ($receivedQtyPacks === '' || $receivedQtyPacks === null) {
            $line->received_qty_packs = null;
            $line->received_at = null;
        } else {
            $line->received_qty_packs = max(0, (float) $receivedQtyPacks);
            $line->received_at = now();
        }
        if ($note !== null) {
            $line->received_note = trim($note) !== '' ? trim($note) : null;
        }
        $line->save();
        $this->syncQuotaConsumption($line->refresh(), $line->received_qty_packs !== null ? (float) $line->received_qty_packs : null);
        $this->inventory->syncReceiptLine($line->refresh());

        return $line->refresh();
    }

    /** Setzt alle Wareneingangs-Mengen einer Bestellung auf die bestellte Menge. */
    public function completeReceipt(Team $team, int $orderId): FoodAlchemistOrder
    {
        $order = $this->ownedOrder($team, $orderId)->load('lines');
        $status = $order->status instanceof OrderStatus ? $order->status : OrderStatus::from((string) $order->status);
        if (! in_array($status, [OrderStatus::Sent, OrderStatus::Confirmed], true)) {
            throw new \RuntimeException('Wareneingang ist nur für gesendete oder bestätigte Bestellungen möglich.');
        }

        foreach ($order->lines as $line) {
            $line->received_qty_packs = (float) $line->qty_packs;
            $line->received_at = now();
            $line->save();
            $this->syncQuotaConsumption($line->refresh(), (float) $line->received_qty_packs);
            $this->inventory->syncReceiptLine($line->refresh());
        }

        return $order->refresh();
    }

    /**
     * Erstellt aus unterlieferten Wareneingangszeilen eine neue Draft-Nachlieferung.
     *
     * @return array{order_id:int, lines:int, total_qty_packs:float}
     */
    public function createBackorderFromReceipt(Team $team, int $orderId, ?string $deliveryDate = null, ?int $userId = null): array
    {
        $source = $this->ownedOrder($team, $orderId)->load(['supplier', 'lines']);
        $missing = $source->lines
            ->filter(fn ($line) => $line->supplier_item_id !== null && $line->received_qty_packs !== null)
            ->map(function ($line) {
                $diff = round((float) $line->qty_packs - (float) $line->received_qty_packs, 2);

                return ['line' => $line, 'missing' => $diff];
            })
            ->filter(fn ($row) => $row['missing'] > 0.0)
            ->values();

        if ($missing->isEmpty()) {
            throw new \RuntimeException('Keine unterlieferten Wareneingangszeilen für eine Nachlieferung.');
        }

        $date = ($deliveryDate !== null && trim($deliveryDate) !== '') ? Carbon::parse($deliveryDate)->toDateString() : null;
        $draft = $this->draftForSupplier($team, (int) $source->supplier_id, $date, $userId);
        $draft->reference = trim('Nachlieferung ord-'.(int) $source->id.($source->reference ? ' · '.$source->reference : ''));
        $draft->note = trim(($draft->note ? $draft->note."\n" : '').'Nachlieferung aus Wareneingang ord-'.(int) $source->id);
        $draft->save();

        $total = 0.0;
        foreach ($missing as $row) {
            /** @var FoodAlchemistOrderLine $line */
            $line = $row['line'];
            $qty = (float) $row['missing'];
            $newLine = $this->addManualLine(
                $team,
                (int) $line->supplier_item_id,
                $qty,
                'Nachlieferung zu ord-'.(int) $source->id.' · Zeile '.(int) $line->id,
                $userId,
                $date
            );
            if ((int) $newLine->order_id !== (int) $draft->id) {
                $draft = $newLine->order()->first() ?? $draft;
            }
            $total += $qty;
        }

        return [
            'order_id' => (int) $draft->id,
            'lines' => $missing->count(),
            'total_qty_packs' => round($total, 2),
        ];
    }

    public function updateReceiptNote(Team $team, int $lineId, ?string $note = null): FoodAlchemistOrderLine
    {
        $line = FoodAlchemistOrderLine::with('order')->findOrFail($lineId);
        $this->guardReceiptLine($team, $line);

        $line->received_note = trim((string) $note) !== '' ? trim((string) $note) : null;
        $line->save();

        return $line->refresh();
    }

    /**
     * Rechnungsprüfung light: berechnete Menge/Preis je Zeile erfassen und gegen Bestellung
     * sowie Wareneingang auswerten.
     */
    public function updateInvoiceLine(Team $team, int $lineId, int|float|string|null $qtyPacks, int|float|string|null $packPrice, ?string $note = null): FoodAlchemistOrderLine
    {
        $line = FoodAlchemistOrderLine::with('order')->findOrFail($lineId);
        $this->guardInvoiceLine($team, $line);

        $qtyEmpty = $qtyPacks === '' || $qtyPacks === null;
        $priceEmpty = $packPrice === '' || $packPrice === null;
        $line->invoice_qty_packs = $qtyEmpty ? null : max(0, (float) $qtyPacks);
        $line->invoice_pack_price = $priceEmpty ? null : max(0, (float) $packPrice);
        $line->invoice_checked_at = $qtyEmpty && $priceEmpty ? null : now();
        if ($note !== null) {
            $line->invoice_note = trim($note) !== '' ? trim($note) : null;
        }
        $line->save();

        return $line->refresh();
    }

    /** Übernimmt die Rechnung aus Wareneingang; falls kein WE gebucht ist, aus der Bestellung. */
    public function completeInvoiceFromReceipt(Team $team, int $orderId): FoodAlchemistOrder
    {
        $order = $this->ownedOrder($team, $orderId)->load('lines');
        $status = $order->status instanceof OrderStatus ? $order->status : OrderStatus::from((string) $order->status);
        if (! in_array($status, [OrderStatus::Sent, OrderStatus::Confirmed, OrderStatus::Delivered], true)) {
            throw new \RuntimeException('Rechnungsprüfung ist erst nach dem Absenden möglich.');
        }

        foreach ($order->lines as $line) {
            $line->invoice_qty_packs = $line->received_qty_packs !== null ? (float) $line->received_qty_packs : (float) $line->qty_packs;
            $line->invoice_pack_price = $line->pack_price !== null ? (float) $line->pack_price : null;
            $line->invoice_checked_at = now();
            $line->save();
        }

        return $order->refresh();
    }

    public function updateInvoiceNote(Team $team, int $lineId, ?string $note = null): FoodAlchemistOrderLine
    {
        $line = FoodAlchemistOrderLine::with('order')->findOrFail($lineId);
        $this->guardInvoiceLine($team, $line);

        $line->invoice_note = trim((string) $note) !== '' ? trim((string) $note) : null;
        $line->save();

        return $line->refresh();
    }

    /**
     * Reklamation/Gutschrift light pro Position: Status, reklamierte Menge, erwarteter
     * Gutschriftbetrag und Notiz. Keine Kreditorenbuchung, nur Nachverfolgung am Beleg.
     *
     * @param  array{claim_status?:?string, claim_qty_packs?:mixed, credit_expected_net?:mixed, claim_note?:?string}  $input
     */
    public function updateClaimLine(Team $team, int $lineId, array $input): FoodAlchemistOrderLine
    {
        $line = FoodAlchemistOrderLine::with('order')->findOrFail($lineId);
        $this->guardInvoiceLine($team, $line);

        if (array_key_exists('claim_status', $input)) {
            $status = trim((string) ($input['claim_status'] ?? ''));
            if ($status !== '' && ! in_array($status, ['open', 'credit_expected', 'credited', 'resolved'], true)) {
                throw new \RuntimeException('Unbekannter Reklamationsstatus. Erlaubt: open, credit_expected, credited, resolved.');
            }
            $line->claim_status = $status !== '' ? $status : null;
        }
        if (array_key_exists('claim_qty_packs', $input)) {
            $value = $input['claim_qty_packs'];
            $line->claim_qty_packs = $value === '' || $value === null ? null : max(0, (float) $value);
        }
        if (array_key_exists('credit_expected_net', $input)) {
            $value = $input['credit_expected_net'];
            $line->credit_expected_net = $value === '' || $value === null ? null : max(0, (float) $value);
        }
        if (array_key_exists('claim_note', $input)) {
            $line->claim_note = trim((string) ($input['claim_note'] ?? '')) ?: null;
        }
        if (
            ($line->claim_status === null || $line->claim_status === '')
            && ($line->claim_qty_packs !== null || $line->credit_expected_net !== null || $line->claim_note !== null)
        ) {
            $line->claim_status = 'open';
        }
        $line->save();

        return $line->refresh();
    }

    /**
     * Kontingent/Rahmenabruf am Lieferantenartikel über eine Bestellzeile pflegen.
     *
     * @param  array{quota_qty_packs?:mixed, quota_used_packs?:mixed, quota_valid_from?:?string, quota_valid_to?:?string, quota_note?:?string}  $input
     */
    public function updateLineQuota(Team $team, int $lineId, array $input): FoodAlchemistOrderLine
    {
        $line = FoodAlchemistOrderLine::with('order')->findOrFail($lineId);
        $order = $line->order;
        if ($order === null || ! $order->isOwnedBy($team)) {
            throw new \RuntimeException('Bestellzeile nicht im Schreibzugriff (D1).');
        }
        if ($line->supplier_item_id === null) {
            throw new \RuntimeException('Zeile hat keinen Lieferantenartikel für ein Kontingent.');
        }

        $item = FoodAlchemistSupplierItem::visibleToTeam($team)->find((int) $line->supplier_item_id);
        if ($item === null) {
            throw new \RuntimeException('Lieferantenartikel nicht im Zugriff.');
        }

        foreach (['quota_qty_packs', 'quota_used_packs'] as $field) {
            if (array_key_exists($field, $input)) {
                $value = $input[$field];
                $item->{$field} = $value === null || $value === '' ? null : max(0.0, (float) $value);
            }
        }
        foreach (['quota_valid_from', 'quota_valid_to'] as $field) {
            if (array_key_exists($field, $input)) {
                $value = trim((string) ($input[$field] ?? ''));
                $item->{$field} = $value !== '' ? Carbon::parse($value)->toDateString() : null;
            }
        }
        if (array_key_exists('quota_note', $input)) {
            $item->quota_note = trim((string) ($input['quota_note'] ?? '')) ?: null;
        }
        $item->save();

        return $line->refresh();
    }

    /**
     * Schreibt den Verbrauch idempotent auf den Lieferantenartikel. Korrekturen im
     * Wareneingang addieren nur die Differenz zum bereits verbuchten Verbrauch.
     */
    private function syncQuotaConsumption(FoodAlchemistOrderLine $line, ?float $targetPacks): void
    {
        if ($line->supplier_item_id === null) {
            return;
        }

        DB::transaction(function () use ($line, $targetPacks) {
            $freshLine = FoodAlchemistOrderLine::lockForUpdate()->find($line->id);
            if ($freshLine === null || $freshLine->supplier_item_id === null) {
                return;
            }

            $item = FoodAlchemistSupplierItem::lockForUpdate()->find((int) $freshLine->supplier_item_id);
            if ($item === null || $item->quota_qty_packs === null) {
                return;
            }

            $old = (float) ($freshLine->quota_consumed_packs ?? 0);
            $new = $targetPacks !== null ? max(0.0, round($targetPacks, 2)) : 0.0;
            $delta = round($new - $old, 2);
            if (abs($delta) >= 0.01) {
                $item->quota_used_packs = max(0.0, round((float) ($item->quota_used_packs ?? 0) + $delta, 2));
                $item->save();
            }

            $freshLine->quota_consumed_packs = $new > 0 ? $new : null;
            $freshLine->quota_consumed_at = $new > 0 ? now() : null;
            $freshLine->save();
        });
    }

    private function guardReceiptLine(Team $team, FoodAlchemistOrderLine $line): void
    {
        $order = $line->order;
        if ($order === null || ! $order->isOwnedBy($team)) {
            throw new \RuntimeException('Bestellzeile nicht im Schreibzugriff (D1).');
        }

        $status = $order->status instanceof OrderStatus ? $order->status : OrderStatus::from((string) $order->status);
        if (! in_array($status, [OrderStatus::Sent, OrderStatus::Confirmed], true)) {
            throw new \RuntimeException('Wareneingang ist nur für gesendete oder bestätigte Bestellungen möglich.');
        }
    }

    private function guardInvoiceLine(Team $team, FoodAlchemistOrderLine $line): void
    {
        $order = $line->order;
        if ($order === null || ! $order->isOwnedBy($team)) {
            throw new \RuntimeException('Bestellzeile nicht im Schreibzugriff (D1).');
        }

        $status = $order->status instanceof OrderStatus ? $order->status : OrderStatus::from((string) $order->status);
        if (! in_array($status, [OrderStatus::Sent, OrderStatus::Confirmed, OrderStatus::Delivered], true)) {
            throw new \RuntimeException('Rechnungsprüfung ist erst nach dem Absenden möglich.');
        }
    }

    /** Alle noch offenen Wareneingangs-Mengen mit der bestellten Menge vorbelegen. */
    private function fillReceiptFromOrder(FoodAlchemistOrder $order): void
    {
        foreach ($order->lines()->get() as $line) {
            if ($line->received_qty_packs === null) {
                $line->received_qty_packs = (float) $line->qty_packs;
                $line->received_at = now();
                $line->save();
            }
            $this->syncQuotaConsumption($line->refresh(), (float) $line->received_qty_packs);
            $this->inventory->syncReceiptLine($line->refresh());
        }
    }

    /**
     * @param  list<array<string,mixed>>  $lines
     * @return array{tracked:int, covered:int, shortage:int}
     */
    private function inventorySummaryFromLines(array $lines): array
    {
        $tracked = 0;
        $covered = 0;
        $shortage = 0;

        foreach ($lines as $line) {
            $inventory = $line['inventory'] ?? null;
            if (! is_array($inventory)) {
                continue;
            }
            $tracked++;
            if ((float) ($inventory['shortage_base'] ?? 0) > 0.0001) {
                $shortage++;
            } else {
                $covered++;
            }
        }

        return [
            'tracked' => $tracked,
            'covered' => $covered,
            'shortage' => $shortage,
        ];
    }

    /** @return array{lines:int, booked:int, missing:int, differences:int, backorderable:int, received_net:float} */
    public function receiptSummary(FoodAlchemistOrder $order): array
    {
        $order->loadMissing('lines');
        $booked = 0;
        $differences = 0;
        $backorderable = 0;
        $receivedNet = 0.0;

        foreach ($order->lines as $line) {
            if ($line->received_qty_packs !== null) {
                $booked++;
                $receivedNet += $line->pack_price !== null ? (float) $line->received_qty_packs * (float) $line->pack_price : 0.0;
                if (abs((float) $line->received_qty_packs - (float) $line->qty_packs) >= 0.01) {
                    $differences++;
                }
                if ((float) $line->received_qty_packs < (float) $line->qty_packs) {
                    $backorderable++;
                }
            }
        }

        $lines = $order->lines->count();

        return [
            'lines' => $lines,
            'booked' => $booked,
            'missing' => max(0, $lines - $booked),
            'differences' => $differences,
            'backorderable' => $backorderable,
            'received_net' => round($receivedNet, 2),
        ];
    }

    /** @return array{lines:int, checked:int, missing:int, differences:int, invoice_net:float, diff_net:float} */
    public function invoiceSummary(FoodAlchemistOrder $order): array
    {
        $order->loadMissing('lines');
        $checked = 0;
        $differences = 0;
        $invoiceNet = 0.0;
        $diffNet = 0.0;

        foreach ($order->lines as $line) {
            if ($line->invoice_qty_packs !== null || $line->invoice_pack_price !== null) {
                $checked++;
                $lineTotal = $line->invoice_qty_packs !== null && $line->invoice_pack_price !== null
                    ? round((float) $line->invoice_qty_packs * (float) $line->invoice_pack_price, 2)
                    : 0.0;
                $invoiceNet += $lineTotal;
                $diff = $this->lineInvoiceDiffNet($line);
                $diffNet += $diff ?? 0.0;
                if ($this->lineInvoiceStatus($line) !== 'ok') {
                    $differences++;
                }
            }
        }

        $lines = $order->lines->count();

        return [
            'lines' => $lines,
            'checked' => $checked,
            'missing' => max(0, $lines - $checked),
            'differences' => $differences,
            'invoice_net' => round($invoiceNet, 2),
            'diff_net' => round($diffNet, 2),
        ];
    }

    /** @return array{status:?string, label:string, state:string, due_date:?string, paid_at:?string, overdue_days:int, note:?string} */
    public function paymentSummary(FoodAlchemistOrder $order): array
    {
        $order->loadMissing('supplier');
        $dueDate = $this->invoiceDueDate($order);
        $status = $order->payment_status;
        if ($status === null && ($order->invoice_number !== null || $order->invoice_date !== null)) {
            $status = 'open';
        }

        $state = 'no_invoice';
        $label = 'keine Rechnung';
        $overdueDays = 0;
        if ($status === 'paid') {
            $state = 'paid';
            $label = 'bezahlt';
        } elseif ($status === 'disputed') {
            $state = 'disputed';
            $label = 'strittig';
        } elseif ($status === 'open') {
            $state = 'open';
            $label = 'offen';
            if ($dueDate !== null) {
                $today = Carbon::today();
                if ($dueDate->isPast() && ! $dueDate->isSameDay($today)) {
                    $state = 'overdue';
                    $label = 'überfällig';
                    $overdueDays = $dueDate->diffInDays($today);
                } elseif ($dueDate->isSameDay($today)) {
                    $state = 'due_today';
                    $label = 'heute fällig';
                }
            }
        }

        return [
            'status' => $status,
            'label' => $label,
            'state' => $state,
            'due_date' => $dueDate?->toDateString(),
            'paid_at' => $order->invoice_paid_at?->toDateString(),
            'overdue_days' => $overdueDays,
            'note' => $order->payment_note,
        ];
    }

    /** @return array{lines:int, open:int, credit_expected:int, credited:int, resolved:int, credit_expected_net:float} */
    public function claimSummary(FoodAlchemistOrder $order): array
    {
        $order->loadMissing('lines');
        $summary = [
            'lines' => 0,
            'open' => 0,
            'credit_expected' => 0,
            'credited' => 0,
            'resolved' => 0,
            'credit_expected_net' => 0.0,
        ];

        foreach ($order->lines as $line) {
            $status = $line->claim_status;
            if ($status === null || $status === '') {
                continue;
            }
            $summary['lines']++;
            if (array_key_exists($status, $summary)) {
                $summary[$status]++;
            }
            if ($line->credit_expected_net !== null) {
                $summary['credit_expected_net'] += (float) $line->credit_expected_net;
            }
        }

        $summary['credit_expected_net'] = round($summary['credit_expected_net'], 2);

        return $summary;
    }

    /** @return array{lines:int, invalid:int, exceeded:int, remaining_after:float} */
    public function quotaSummary(FoodAlchemistOrder $order): array
    {
        $order->loadMissing('lines.supplierItem');
        $date = $order->desired_delivery_date ?? now();
        $summary = ['lines' => 0, 'invalid' => 0, 'exceeded' => 0, 'remaining_after' => 0.0];

        foreach ($order->lines as $line) {
            $quota = $this->lineQuotaSummary($line, $date);
            if ($quota === null) {
                continue;
            }
            $summary['lines']++;
            if (! $quota['is_valid_date']) {
                $summary['invalid']++;
            }
            if ($quota['exceeded']) {
                $summary['exceeded']++;
            }
            $summary['remaining_after'] += (float) $quota['remaining_after_packs'];
        }

        $summary['remaining_after'] = round($summary['remaining_after'], 2);

        return $summary;
    }

    /**
     * @return array{qty_packs:float, used_packs:float, consumed_packs:float, remaining_before_packs:float, remaining_after_packs:float, valid_from:?string, valid_to:?string, is_valid_date:bool, exceeded:bool, note:?string}|null
     */
    private function lineQuotaSummary(FoodAlchemistOrderLine $line, Carbon|\DateTimeInterface|string|null $date = null): ?array
    {
        $item = $line->supplierItem ?? ($line->supplier_item_id !== null ? FoodAlchemistSupplierItem::find((int) $line->supplier_item_id) : null);
        if ($item === null || $item->quota_qty_packs === null) {
            return null;
        }

        $checkDate = $date instanceof Carbon
            ? $date->copy()
            : ($date instanceof \DateTimeInterface ? Carbon::instance($date) : Carbon::parse($date ?: now()));
        $qty = max(0.0, (float) $item->quota_qty_packs);
        $used = max(0.0, (float) ($item->quota_used_packs ?? 0));
        $consumed = max(0.0, (float) ($line->quota_consumed_packs ?? 0));
        $pendingConsumption = $consumed > 0 ? $consumed : (float) $line->qty_packs;
        $remainingBefore = round($qty - $used + $consumed, 2);
        $remainingAfter = round($remainingBefore - $pendingConsumption, 2);
        $validFrom = $item->quota_valid_from;
        $validTo = $item->quota_valid_to;
        $isValidDate = true;
        if ($validFrom !== null && $checkDate->lt($validFrom)) {
            $isValidDate = false;
        }
        if ($validTo !== null && $checkDate->gt($validTo)) {
            $isValidDate = false;
        }

        return [
            'qty_packs' => round($qty, 2),
            'used_packs' => round($used, 2),
            'consumed_packs' => round($consumed, 2),
            'remaining_before_packs' => $remainingBefore,
            'remaining_after_packs' => $remainingAfter,
            'valid_from' => $validFrom?->toDateString(),
            'valid_to' => $validTo?->toDateString(),
            'is_valid_date' => $isValidDate,
            'exceeded' => $remainingAfter < 0,
            'note' => $item->quota_note,
        ];
    }

    /** @return array{status:?string, label:string, state:string, requested_at:?string, approved_at:?string, approved_by:?int, note:?string} */
    public function approvalSummary(FoodAlchemistOrder $order): array
    {
        $status = $order->approval_status;
        $state = $status ?: 'not_required';

        return [
            'status' => $status,
            'label' => match ($status) {
                'requested' => 'Freigabe angefragt',
                'approved' => 'freigegeben',
                'rejected' => 'abgelehnt',
                default => 'keine Freigabe',
            },
            'state' => $state,
            'requested_at' => $order->approval_requested_at?->format('Y-m-d H:i'),
            'approved_at' => $order->approved_at?->format('Y-m-d H:i'),
            'approved_by' => $order->approved_by !== null ? (int) $order->approved_by : null,
            'note' => $order->approval_note,
        ];
    }

    private function claimStatusLabel(?string $status): string
    {
        return match ($status) {
            'open' => 'offen',
            'credit_expected' => 'Gutschrift erwartet',
            'credited' => 'gutgeschrieben',
            'resolved' => 'erledigt',
            default => '—',
        };
    }

    private function invoiceDueDate(FoodAlchemistOrder $order): ?Carbon
    {
        $invoiceDate = $order->invoice_date;
        $paymentTermDays = $order->supplier?->payment_term_days;
        if ($invoiceDate === null || $paymentTermDays === null) {
            return null;
        }

        return $invoiceDate->copy()->addDays(max(0, (int) $paymentTermDays));
    }

    private function lineReceiptStatus(FoodAlchemistOrderLine $line): string
    {
        if ($line->received_qty_packs === null) {
            return 'offen';
        }

        $diff = round((float) $line->received_qty_packs - (float) $line->qty_packs, 2);
        if (abs($diff) < 0.01) {
            return 'ok';
        }

        return $diff < 0 ? 'unterliefert' : 'überliefert';
    }

    private function lineInvoiceDiffNet(FoodAlchemistOrderLine $line): ?float
    {
        if ($line->invoice_qty_packs === null || $line->invoice_pack_price === null) {
            return null;
        }

        $invoiceTotal = (float) $line->invoice_qty_packs * (float) $line->invoice_pack_price;
        $basisTotal = $line->received_qty_packs !== null
            ? (float) $line->received_qty_packs * (float) ($line->pack_price ?? 0)
            : (float) $line->line_total;

        return round($invoiceTotal - $basisTotal, 2);
    }

    private function lineInvoiceStatus(FoodAlchemistOrderLine $line): string
    {
        if ($line->invoice_qty_packs === null && $line->invoice_pack_price === null) {
            return 'offen';
        }
        if ($line->invoice_qty_packs === null || $line->invoice_pack_price === null) {
            return 'unvollständig';
        }

        $qtyBasis = $line->received_qty_packs !== null ? (float) $line->received_qty_packs : (float) $line->qty_packs;
        $qtyDiff = round((float) $line->invoice_qty_packs - $qtyBasis, 2);
        $priceDiff = round((float) $line->invoice_pack_price - (float) ($line->pack_price ?? 0), 4);
        if (abs($qtyDiff) < 0.01 && abs($priceDiff) < 0.0001) {
            return 'ok';
        }

        return 'abweichung';
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

    /**
     * WaWi-Hinweise für operative Einkaufssteuerung. Die Liste bleibt label-basiert, damit
     * bestehende Views ohne neues DTO weiterarbeiten können.
     *
     * @return list<string>
     */
    public function orderWarnings(FoodAlchemistOrder $order): array
    {
        $order->loadMissing(['supplier', 'lines']);
        $warnings = [];
        $lines = $order->lines;
        $total = (float) $order->total_net;

        if ($lines->count() === 0) {
            $warnings[] = 'leer';
        }
        if ($total <= 0.0 || $lines->contains(fn ($line) => $line->pack_price === null || (float) $line->qty_packs <= 0.0)) {
            $warnings[] = 'Klärung';
        }

        $moq = $this->moqAmpel($order);
        if ($lines->count() > 0 && $moq['unter_mindestbestellwert']) {
            $warnings[] = 'Mindestwert';
        }
        if ($this->receiptSummary($order)['differences'] > 0) {
            $warnings[] = 'WE-Differenz';
        }
        if ($this->invoiceSummary($order)['differences'] > 0) {
            $warnings[] = 'RE-Differenz';
        }
        $claims = $this->claimSummary($order);
        if ($claims['open'] > 0 || $claims['credit_expected'] > 0) {
            $warnings[] = 'Reklamation offen';
        }
        $quota = $this->quotaSummary($order);
        if ($quota['invalid'] > 0) {
            $warnings[] = 'Kontingent nicht gültig';
        }
        if ($quota['exceeded'] > 0) {
            $warnings[] = 'Kontingent überschritten';
        }
        if (
            $order->confirmed_delivery_date !== null
            && $order->desired_delivery_date !== null
            && ! $order->confirmed_delivery_date->isSameDay($order->desired_delivery_date)
        ) {
            $warnings[] = 'Liefertag abweichend';
        }
        if ($this->paymentSummary($order)['state'] === 'overdue') {
            $warnings[] = 'Zahlung überfällig';
        }
        $approval = $this->approvalSummary($order);
        if ($approval['state'] === 'requested') {
            $warnings[] = 'Freigabe offen';
        } elseif ($approval['state'] === 'rejected') {
            $warnings[] = 'Freigabe abgelehnt';
        }

        $warnings = array_merge($warnings, $this->logistikWarnings($order));

        return array_values(array_unique($warnings));
    }

    /**
     * Harte Versandsperren: diese Belege darf ein WaWi-Cockpit nicht als Bestellung ausgeben.
     *
     * @return list<string>
     */
    public function sendBlockers(FoodAlchemistOrder $order): array
    {
        $hart = ['leer', 'Klärung', 'Liefertag nicht beliefert', 'Bestellschluss verpasst', 'Freigabe abgelehnt'];

        return array_values(array_filter(
            $this->orderWarnings($order),
            fn ($warning) => in_array($warning, $hart, true)
        ));
    }

    /**
     * @return array{delivery_days:list<int>, cutoff:?string, lead_days:int, deadline:?string}
     */
    public function logistikInfo(FoodAlchemistOrder $order): array
    {
        $supplier = $order->supplier ?? FoodAlchemistSupplier::find($order->supplier_id);
        $deadline = $this->orderDeadline($order);

        return [
            'delivery_days' => $this->lieferTage($supplier),
            'cutoff' => $supplier?->order_cutoff_time ?: null,
            'lead_days' => max(0, (int) ($supplier?->order_lead_days ?? 0)),
            'deadline' => $deadline?->format('Y-m-d H:i'),
        ];
    }

    /** @return list<string> */
    private function logistikWarnings(FoodAlchemistOrder $order): array
    {
        $supplier = $order->supplier ?? FoodAlchemistSupplier::find($order->supplier_id);
        $date = $order->desired_delivery_date;
        if ($supplier === null || $date === null) {
            return [];
        }

        return $this->supplierLogistikWarnings($supplier, $date->copy());
    }

    /** @return list<string> */
    private function previewLogistikWarnings(?FoodAlchemistSupplier $supplier, ?string $date): array
    {
        if ($supplier === null || $date === null || $date === '') {
            return [];
        }

        return $this->supplierLogistikWarnings($supplier, Carbon::parse($date));
    }

    /** @return list<string> */
    private function supplierLogistikWarnings(FoodAlchemistSupplier $supplier, Carbon $date): array
    {
        $warnings = [];
        $deliveryDays = $this->lieferTage($supplier);
        if ($deliveryDays !== [] && ! in_array((int) $date->isoWeekday(), $deliveryDays, true)) {
            $warnings[] = 'Liefertag nicht beliefert';
        }

        $deadline = $this->supplierDeadline($supplier, $date);
        if ($deadline !== null) {
            $now = Carbon::now();
            if ($now->greaterThan($deadline)) {
                $warnings[] = 'Bestellschluss verpasst';
            } elseif ($now->isSameDay($deadline)) {
                $warnings[] = 'Bestellschluss heute';
            }
        }

        return $warnings;
    }

    private function orderDeadline(FoodAlchemistOrder $order): ?Carbon
    {
        $supplier = $order->supplier ?? FoodAlchemistSupplier::find($order->supplier_id);
        $date = $order->desired_delivery_date;
        if ($supplier === null || $date === null) {
            return null;
        }

        return $this->supplierDeadline($supplier, $date->copy());
    }

    private function supplierDeadline(FoodAlchemistSupplier $supplier, Carbon $date): ?Carbon
    {
        $leadDays = max(0, (int) ($supplier->order_lead_days ?? 0));
        $deadline = $date->copy()->subDays($leadDays)->startOfDay();
        $cutoff = trim((string) ($supplier->order_cutoff_time ?? ''));
        if (preg_match('/^(\d{1,2}):(\d{2})$/', $cutoff, $m)) {
            $deadline->setTime(min(23, (int) $m[1]), min(59, (int) $m[2]));
        } else {
            $deadline->endOfDay();
        }

        return $deadline;
    }

    /** @return list<int> */
    private function lieferTage(?FoodAlchemistSupplier $supplier): array
    {
        if ($supplier === null || trim((string) $supplier->delivery_days) === '') {
            return [];
        }

        return collect(explode(',', (string) $supplier->delivery_days))
            ->map(fn ($day) => (int) trim($day))
            ->filter(fn ($day) => $day >= 1 && $day <= 7)
            ->unique()
            ->values()
            ->all();
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
