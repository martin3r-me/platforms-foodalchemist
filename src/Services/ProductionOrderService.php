<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\ProductionOrderStatus;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistOrder;
use Platform\FoodAlchemist\Models\FoodAlchemistOrderLine;
use Platform\FoodAlchemist\Models\FoodAlchemistProductionOrder;
use Platform\FoodAlchemist\Models\FoodAlchemistProductionOrderLine;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;

/**
 * Spec 18 — Produktionsaufträge (N-Track, Ableger von Spec 17/Bestellwesen).
 *
 * EIN Auftrag je (team, production_date) aggregiert mehrere Ziele (Konzept+Personen
 * ODER Gericht+Portionen) desselben Produktionstags. Sub-Rezept-Ansätze runden auf
 * ganze Batches (ceil) — ceil(a)+ceil(b) ≠ ceil(a+b), daher wird bei JEDER Ziel-Änderung
 * die GESAMTE Explosion neu gerechnet (über PlanungsblattService::produktionsblattFuerZiele,
 * unverändert wiederverwendet) und die Zeilen komplett ersetzt (nie additiv gepatcht).
 * Manuelle Notizen je Rezept überstehen den Ersatz (vor dem Löschen per recipe_id gesichert).
 *
 * Schreibt nur eigene Team-Belege (isOwnedBy) und nur solange `planned`.
 */
class ProductionOrderService
{
    public function __construct(
        private PlanungsblattService $planung,
    ) {
    }

    // ── Auftrag holen/anlegen ──────────────────────────────────────────────

    /** Ein-offener-Auftrag-Guard je (team, production_date): Transaktion + Lock gegen Doppelklick. */
    public function draftForDate(Team $team, string $productionDate, ?int $userId = null): FoodAlchemistProductionOrder
    {
        return DB::transaction(function () use ($team, $productionDate, $userId) {
            $draft = FoodAlchemistProductionOrder::where('team_id', $team->id)
                ->whereDate('production_date', $productionDate) // date-Cast persistiert inkl. Zeitanteil (Y-m-d H:i:s) — whereDate() vergleicht robust nur das Datum
                ->where('status', ProductionOrderStatus::Planned->value)
                ->lockForUpdate()
                ->first();

            return $draft ?? FoodAlchemistProductionOrder::create([
                'team_id' => $team->id,
                'production_date' => $productionDate,
                'status' => ProductionOrderStatus::Planned->value,
                'targets' => [],
                'created_by' => $userId,
            ]);
        });
    }

    /**
     * Vom Editor-Modal genutzt: legt in einer Transaktion den Auftrag an und setzt
     * targets[] in einem Rutsch (kein Zwischenstand während der Eingabe im Editor).
     *
     * @param  list<array{source_ref:string, concept_id?:int, recipe_id?:int, persons?:int|float, portions?:int|float}>  $targets
     */
    public function saveNew(Team $team, string $productionDate, array $targets, ?string $reference = null, ?string $note = null, ?int $userId = null): FoodAlchemistProductionOrder
    {
        return DB::transaction(function () use ($team, $productionDate, $targets, $reference, $note, $userId) {
            $order = $this->draftForDate($team, $productionDate, $userId);
            $order->targets = $this->mitLabels($team, $targets);
            $order->reference = $reference;
            $order->note = $note;
            $order->save();
            $this->recomputeOrder($team, $order);

            return $order->refresh();
        });
    }

    /** Vom Editor beim Bearbeiten eines bestehenden, noch offenen Auftrags genutzt. */
    public function replaceTargets(Team $team, int $orderId, array $targets): FoodAlchemistProductionOrder
    {
        $order = $this->ownedOpenOrder($team, $orderId);
        $order->targets = $this->mitLabels($team, $targets);
        $order->save();
        $this->recomputeOrder($team, $order);

        return $order->refresh();
    }

    // ── Granulare Einzel-Ziel-Operationen (MCP/Agent-Nutzung) ──────────────

    /**
     * @param  array{concept_id?:int, recipe_id?:int, persons?:int|float, portions?:int|float}  $ziel
     */
    public function addTarget(Team $team, int $orderId, array $ziel, string $sourceRef): FoodAlchemistProductionOrder
    {
        $order = $this->ownedOpenOrder($team, $orderId);
        $targets = collect($order->targets ?? [])
            ->reject(fn ($t) => ($t['source_ref'] ?? null) === $sourceRef)
            ->values()->all();
        $targets[] = array_merge($ziel, ['source_ref' => $sourceRef, 'label' => $this->labelFor($team, $ziel)]);
        $order->targets = $targets;
        $order->save();
        $this->recomputeOrder($team, $order);

        return $order->refresh();
    }

    public function removeTarget(Team $team, int $orderId, string $sourceRef): FoodAlchemistProductionOrder
    {
        $order = $this->ownedOpenOrder($team, $orderId);
        $order->targets = collect($order->targets ?? [])
            ->reject(fn ($t) => ($t['source_ref'] ?? null) === $sourceRef)
            ->values()->all();
        $order->save();
        $this->recomputeOrder($team, $order);

        return $order->refresh();
    }

    // ── Recompute (Kern: vollständige Neu-Explosion, nie additiv) ──────────

    /** Zeilen komplett aus targets[] neu erzeugen; Notizen je recipe_id überstehen den Ersatz. */
    public function recomputeOrder(Team $team, FoodAlchemistProductionOrder $order): void
    {
        $status = $order->status instanceof ProductionOrderStatus ? $order->status : ProductionOrderStatus::from((string) $order->status);
        if (! $status->istOffen()) {
            return;
        }

        $ziele = collect($order->targets ?? [])
            ->map(fn ($t) => Arr::except($t, ['source_ref', 'label']))
            ->values()->all();

        $existingNotes = $order->lines()->pluck('note', 'recipe_id')->all();
        $order->lines()->delete();

        if ($ziele === []) {
            $order->warnungen = [];
            $order->save();

            return;
        }

        $blatt = $this->planung->produktionsblattFuerZiele($team, $ziele);

        foreach ($blatt['rezepte'] as $i => $r) {
            $order->lines()->create([
                'team_id' => $order->team_id,
                'recipe_id' => $r['recipe_id'],
                'is_basisrezept' => $r['ist_basisrezept'],
                'tiefe' => $r['tiefe'],
                'ansaetze' => $r['ansaetze'],
                'benoetigt_ansaetze' => $r['benoetigt_ansaetze'],
                'portionen' => $r['portionen'],
                'basis_yield_kg' => $r['basis_yield_kg'],
                'produzierte_menge_kg' => $r['produzierte_menge_kg'],
                'arbeitszeit_min' => $r['arbeitszeit_min'],
                'zubereitung' => $r['zubereitung'],
                'darreichung' => $r['darreichung'],
                'zutaten' => $r['zutaten'],
                'note' => $existingNotes[$r['recipe_id']] ?? null,
                'position' => $i,
            ]);
        }

        $order->warnungen = $blatt['warnungen'];
        $order->save();
    }

    // ── Manuelle Pflege (nur im planned, nur Besitzer) ─────────────────────

    public function updateLine(Team $team, int $lineId, array $input): FoodAlchemistProductionOrderLine
    {
        $line = $this->ownedOpenLine($team, $lineId);
        if (array_key_exists('note', $input)) {
            $line->note = ($input['note'] ?? '') ?: null;
        }
        $line->save();

        return $line->refresh();
    }

    // ── Status-Lebenszyklus (guarded) ───────────────────────────────────────

    public function setStatus(Team $team, int $orderId, ProductionOrderStatus $ziel): FoodAlchemistProductionOrder
    {
        $order = $this->ownedOrder($team, $orderId);
        $aktuell = $order->status instanceof ProductionOrderStatus ? $order->status : ProductionOrderStatus::from((string) $order->status);
        if ($aktuell === $ziel) {
            return $order;
        }
        if (! $aktuell->darfWechselnZu($ziel)) {
            throw new \RuntimeException("Status {$aktuell->value} → {$ziel->value} nicht erlaubt.");
        }
        // Beim Start: letzten planned-Stand rechnen = Snapshot einfrieren, dann Status setzen.
        if ($ziel === ProductionOrderStatus::InProgress) {
            $this->recomputeOrder($team, $order);
            $order->started_at = now();
        } elseif ($ziel === ProductionOrderStatus::Done) {
            $order->finished_at = now();
        } elseif ($ziel === ProductionOrderStatus::Cancelled) {
            $order->cancelled_at = now();
        }
        $order->status = $ziel;
        $order->save();

        return $order;
    }

    // ── Lesen / Aggregate ────────────────────────────────────────────────────

    /** @return Collection<int, FoodAlchemistProductionOrder> */
    public function listForTeam(Team $team, ?string $status = null): Collection
    {
        return FoodAlchemistProductionOrder::visibleToTeam($team)
            ->when($status !== null, fn ($q) => $q->where('status', $status))
            ->orderByRaw("CASE status WHEN 'planned' THEN 0 ELSE 1 END")
            ->orderBy('production_date')
            ->get();
    }

    /** Detail-Aggregat für UI/MCP. */
    public function detail(Team $team, int $orderId): array
    {
        $order = FoodAlchemistProductionOrder::visibleToTeam($team)->with('lines.recipe:id,name')->findOrFail($orderId);
        $status = $order->status instanceof ProductionOrderStatus ? $order->status : ProductionOrderStatus::from((string) $order->status);

        return [
            'id' => (int) $order->id,
            'production_date' => $order->production_date?->toDateString(),
            'status' => $status->value,
            'status_label' => $status->label(),
            'reference' => $order->reference,
            'targets' => $order->targets ?? [],
            'note' => $order->note,
            'is_owned' => $order->isOwnedBy($team),
            'editierbar' => $status->istOffen() && $order->isOwnedBy($team),
            'warnungen' => $order->warnungen ?? [],
            'ansaetze_gesamt' => (float) $order->lines->sum('ansaetze'),
            'portionen_gesamt' => (int) $order->lines->sum('portionen'),
            'arbeitszeit_gesamt_min' => (int) $order->lines->sum('arbeitszeit_min'),
            'zeilen' => $order->lines->map(fn ($l) => [
                'id' => (int) $l->id,
                'recipe_id' => (int) $l->recipe_id,
                'name' => $l->recipe?->name,
                'ist_basisrezept' => (bool) $l->is_basisrezept,
                'ansaetze' => (float) $l->ansaetze,
                'benoetigt_ansaetze' => (float) $l->benoetigt_ansaetze,
                'portionen' => $l->portionen !== null ? (int) $l->portionen : null,
                'produzierte_menge_kg' => $l->produzierte_menge_kg !== null ? (float) $l->produzierte_menge_kg : null,
                'arbeitszeit_min' => $l->arbeitszeit_min !== null ? (int) $l->arbeitszeit_min : null,
                'zubereitung' => $l->zubereitung,
                'darreichung' => $l->darreichung,
                'zutaten' => $l->zutaten,
                'note' => $l->note,
            ])->all(),
        ];
    }

    /**
     * Findet die Bestellschienen, die aus diesem Produktionsauftrag heraus per
     * „An Bestellung übergeben" entstanden sind — es gibt keine FK, die Verknüpfung
     * läuft über den `source_ref`-Präfix `produktion:{orderId}:` in den
     * `source_contributions`-Keys der Bestellzeilen (siehe DetailPanel::anBestellungUebergeben()).
     *
     * @return Collection<int, FoodAlchemistOrder>
     */
    public function verknuepfteOrders(Team $team, int $productionOrderId): Collection
    {
        $prefix = 'produktion:' . $productionOrderId . ':';
        $orderIds = FoodAlchemistOrderLine::query()
            ->whereNotNull('source_contributions')
            ->get(['order_id', 'source_contributions'])
            ->filter(fn ($l) => collect(array_keys($l->source_contributions ?? []))->contains(fn ($k) => str_starts_with($k, $prefix)))
            ->pluck('order_id')->unique()->values();

        if ($orderIds->isEmpty()) {
            return collect();
        }

        return FoodAlchemistOrder::visibleToTeam($team)->with('supplier:id,name')->whereIn('id', $orderIds)->get();
    }

    /** S3: Volldaten für Produktionsschein-Dokument (PDF/Druck/CSV) — Auftrags-Kopf + Rezeptzeilen. */
    public function dokument(Team $team, int $orderId): array
    {
        $order = FoodAlchemistProductionOrder::visibleToTeam($team)->with('lines.recipe:id,name')->findOrFail($orderId);
        $status = $order->status instanceof ProductionOrderStatus ? $order->status : ProductionOrderStatus::from((string) $order->status);

        return [
            'id' => (int) $order->id,
            'production_date' => $order->production_date?->toDateString(),
            'status' => $status->value,
            'status_label' => $status->label(),
            'reference' => $order->reference,
            'note' => $order->note,
            'ziele' => collect($order->targets ?? [])->pluck('label')->filter()->values()->all(),
            'zeilen' => $order->lines->map(fn ($l) => [
                'name' => $l->recipe?->name,
                'ist_basisrezept' => (bool) $l->is_basisrezept,
                'ansaetze' => (float) $l->ansaetze,
                'portionen' => $l->portionen !== null ? (int) $l->portionen : null,
                'produzierte_menge_kg' => $l->produzierte_menge_kg !== null ? (float) $l->produzierte_menge_kg : null,
                'arbeitszeit_min' => $l->arbeitszeit_min !== null ? (int) $l->arbeitszeit_min : null,
                'zubereitung' => $l->zubereitung,
                'darreichung' => $l->darreichung,
                'zutaten' => $l->zutaten,
            ])->all(),
        ];
    }

    // ── Ziel-Label (Anzeige in targets[] ohne Recompute nötig) ─────────────

    /** @param  list<array>  $targets */
    private function mitLabels(Team $team, array $targets): array
    {
        return array_map(function ($t) use ($team) {
            $ziel = Arr::except($t, ['source_ref', 'label']);

            return array_merge($t, ['label' => $t['label'] ?? $this->labelFor($team, $ziel)]);
        }, $targets);
    }

    private function labelFor(Team $team, array $ziel): ?string
    {
        if (! empty($ziel['concept_id'])) {
            $name = FoodAlchemistConcept::visibleToTeam($team)->find((int) $ziel['concept_id'])?->name;
            $wert = $ziel['persons'] ?? null;

            return $name !== null ? $name . ($wert !== null ? " ({$wert} P.)" : '') : null;
        }
        if (! empty($ziel['recipe_id'])) {
            $name = FoodAlchemistRecipe::visibleToTeam($team)->find((int) $ziel['recipe_id'])?->name;
            $wert = $ziel['portions'] ?? $ziel['persons'] ?? null;

            return $name !== null ? $name . ($wert !== null ? " ({$wert} Port.)" : '') : null;
        }

        return null;
    }

    // ── Guards ───────────────────────────────────────────────────────────────

    private function ownedOrder(Team $team, int $orderId): FoodAlchemistProductionOrder
    {
        $order = FoodAlchemistProductionOrder::visibleToTeam($team)->findOrFail($orderId);
        if (! $order->isOwnedBy($team)) {
            throw new \RuntimeException('Produktionsauftrag nicht im Schreibzugriff (D1).');
        }

        return $order;
    }

    private function ownedOpenOrder(Team $team, int $orderId): FoodAlchemistProductionOrder
    {
        $order = $this->ownedOrder($team, $orderId);
        $status = $order->status instanceof ProductionOrderStatus ? $order->status : ProductionOrderStatus::from((string) $order->status);
        if (! $status->istOffen()) {
            throw new \RuntimeException('Nur ein geplanter Auftrag ist editierbar.');
        }

        return $order;
    }

    private function ownedOpenLine(Team $team, int $lineId): FoodAlchemistProductionOrderLine
    {
        $line = FoodAlchemistProductionOrderLine::with('productionOrder')->findOrFail($lineId);
        $order = $line->productionOrder;
        if ($order === null || ! $order->isOwnedBy($team)) {
            throw new \RuntimeException('Produktionszeile nicht im Schreibzugriff (D1).');
        }
        $status = $order->status instanceof ProductionOrderStatus ? $order->status : ProductionOrderStatus::from((string) $order->status);
        if (! $status->istOffen()) {
            throw new \RuntimeException('Nur ein geplanter Auftrag ist editierbar.');
        }

        return $line;
    }
}
