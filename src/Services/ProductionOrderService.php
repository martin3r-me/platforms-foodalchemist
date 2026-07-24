<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\ProductionOrderStatus;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel;
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

    /**
     * MCP-Kompat-Pfad (findOrCreate je (team, production_date[, name])): der frühere
     * Ein-Auftrag-je-Tag-Guard. Ab Spec 20 P0 (V1) dürfen mehrere Aufträge pro Tag
     * koexistieren — das UI legt IMMER neu an (saveNew). Diese Methode bleibt nur für
     * MCP-Tools/Agenten, die einen Auftrag über das Datum (optional zusätzlich per Name)
     * adressieren, ohne die order_id zu kennen. Ohne `name` matcht sie den ersten
     * geplanten Auftrag des Tages; mit `name` genau den gleichnamigen.
     */
    public function draftForDate(Team $team, string $productionDate, ?int $userId = null, ?string $name = null): FoodAlchemistProductionOrder
    {
        return DB::transaction(function () use ($team, $productionDate, $userId, $name) {
            $draft = FoodAlchemistProductionOrder::where('team_id', $team->id)
                ->whereDate('production_date', $productionDate) // date-Cast persistiert inkl. Zeitanteil (Y-m-d H:i:s) — whereDate() vergleicht robust nur das Datum
                ->where('status', ProductionOrderStatus::Planned->value)
                ->when($name !== null && trim($name) !== '', fn ($q) => $q->where('name', trim($name)))
                ->lockForUpdate()
                ->first();

            return $draft ?? FoodAlchemistProductionOrder::create([
                'team_id' => $team->id,
                'production_date' => $productionDate,
                'name' => $this->auftragsName($name, $productionDate),
                'status' => ProductionOrderStatus::Planned->value,
                'targets' => [],
                'created_by' => $userId,
            ]);
        });
    }

    /**
     * Vom Editor-Modal genutzt: legt in einer Transaktion IMMER einen NEUEN Auftrag an und
     * setzt targets[] in einem Rutsch (kein Zwischenstand während der Eingabe im Editor).
     *
     * V1 (Spec 20 P0): Kein Tages-Merge mehr — Name+Datum = Identität, mehrere Aufträge pro
     * Tag sind gewollt. Wer einen bestehenden Auftrag ergänzen will, bearbeitet ihn per
     * order_id (replaceTargets/addTarget).
     *
     * @param  list<array{source_ref:string, concept_id?:int, recipe_id?:int, persons?:int|float, portions?:int|float}>  $targets
     */
    public function saveNew(Team $team, string $productionDate, string $name, array $targets, ?string $reference = null, ?string $note = null, ?int $userId = null): FoodAlchemistProductionOrder
    {
        return DB::transaction(function () use ($team, $productionDate, $name, $targets, $reference, $note, $userId) {
            $order = FoodAlchemistProductionOrder::create([
                'team_id' => $team->id,
                'production_date' => $productionDate,
                'name' => $this->auftragsName($name, $productionDate),
                'status' => ProductionOrderStatus::Planned->value,
                'reference' => $reference,
                'note' => $note,
                'targets' => $this->mitLabels($team, $targets),
                'created_by' => $userId,
            ]);
            $this->recomputeOrder($team, $order);

            return $order->refresh();
        });
    }

    /** Name-Fallback: leerer/kein Name ⇒ sprechendes Datums-Label. */
    private function auftragsName(?string $name, string $productionDate): string
    {
        $name = trim((string) $name);

        return $name !== '' ? $name : 'Produktion ' . \Illuminate\Support\Carbon::parse($productionDate)->format('d.m.Y');
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
        // forceDelete statt soft-delete: Zeilen sind ephemere Snapshots, die bei jeder
        // Ziel-Änderung neu erzeugt werden — soft-delete würde sonst unbegrenzt Tombstones
        // ansammeln. Notizen sind oben schon gesichert und werden per recipe_id neu gesetzt.
        $order->lines()->forceDelete();

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

    /**
     * MCP: adressiert einen Auftrag entweder direkt per order_id oder findet/legt ihn
     * per production_date (optional + name) an (Kompat-Pfad, V1). Genau eine Adressierung.
     */
    public function resolveOrCreate(Team $team, ?int $orderId, ?string $productionDate, ?string $name, ?int $userId): FoodAlchemistProductionOrder
    {
        if ($orderId !== null) {
            return $this->ownedOpenOrder($team, $orderId);
        }
        if ($productionDate === null || $productionDate === '') {
            throw new \InvalidArgumentException('order_id ODER production_date erforderlich.');
        }

        return $this->draftForDate($team, $productionDate, $userId, $name);
    }

    // ── Manuelle Pflege (nur im planned, nur Besitzer) ─────────────────────

    /** Kopf-Felder (Name/Anlass/Notiz/Datum) — nur im planned, nur Besitzer. */
    public function updateHeader(Team $team, int $orderId, array $input): FoodAlchemistProductionOrder
    {
        $order = $this->ownedOpenOrder($team, $orderId);
        if (array_key_exists('name', $input) && trim((string) $input['name']) !== '') {
            $order->name = trim((string) $input['name']);
        }
        if (array_key_exists('reference', $input)) {
            $order->reference = ($input['reference'] ?? '') !== '' ? $input['reference'] : null;
        }
        if (array_key_exists('note', $input)) {
            $order->note = ($input['note'] ?? '') !== '' ? $input['note'] : null;
        }
        if (array_key_exists('production_date', $input) && ! empty($input['production_date'])) {
            $order->production_date = $input['production_date'];
        }
        $order->save();

        return $order->refresh();
    }

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
            ->orderBy('name')
            ->get();
    }

    /** Detail-Aggregat für UI/MCP. */
    public function detail(Team $team, int $orderId): array
    {
        $order = FoodAlchemistProductionOrder::visibleToTeam($team)->with('lines.recipe:id,name')->findOrFail($orderId);
        $status = $order->status instanceof ProductionOrderStatus ? $order->status : ProductionOrderStatus::from((string) $order->status);

        return [
            'id' => (int) $order->id,
            'name' => $order->name,
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
        // Der trailing-Doppelpunkt disambiguiert #1 gegen #10 (produktion:1: matcht nicht
        // produktion:10:). Filterung auf DB-Ebene (JSON als Text) + Team-Scope über die
        // Order-Relation — kein Full-Table-Scan, kein Lesen fremder Teams.
        $prefix = 'produktion:' . $productionOrderId . ':';
        $orderIds = FoodAlchemistOrderLine::query()
            ->whereHas('order', fn ($q) => $q->visibleToTeam($team))
            ->where('source_contributions', 'like', '%' . $prefix . '%')
            ->distinct()->pluck('order_id');

        if ($orderIds->isEmpty()) {
            return collect();
        }

        return FoodAlchemistOrder::visibleToTeam($team)->with('supplier:id,name')->whereIn('id', $orderIds)->get();
    }

    /**
     * S3: Volldaten für Produktionsschein-Dokument (PDF/Druck/CSV).
     *
     * $mitEinkauf (Default true) hängt die Einkaufs-Sektion an — GP-Bedarf nach Lieferant
     * gruppiert, in ganzen Gebinden mit EK (frisch aus den Zielen via PlanungsblattService,
     * wie der alte Planungsblatt-Bundle). INTERNE Ops-Doku: enthält Lieferanten + EK-Preise,
     * NICHT zum Aushändigen an den Kunden gedacht.
     */
    public function dokument(Team $team, int $orderId, bool $mitEinkauf = true): array
    {
        $order = FoodAlchemistProductionOrder::visibleToTeam($team)->with('lines.recipe:id,name')->findOrFail($orderId);
        $status = $order->status instanceof ProductionOrderStatus ? $order->status : ProductionOrderStatus::from((string) $order->status);

        $einkauf = null;
        if ($mitEinkauf) {
            $ziele = collect($order->targets ?? [])
                ->map(fn ($t) => Arr::except($t, ['source_ref', 'label']))
                ->values()->all();
            if ($ziele !== []) {
                $liste = $this->planung->einkaufsliste($team, $ziele);
                $einkauf = [
                    'lieferanten' => $liste['lieferanten'],
                    'ek_gesamt' => collect($liste['lieferanten'])->sum('ek_summe'),
                    'warnungen' => $liste['warnungen'],
                ];
            }
        }

        return [
            'id' => (int) $order->id,
            'name' => $order->name,
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
            'einkauf' => $einkauf,
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
        // P1b: Kapitel-Ziel (i. d. R. schon in Einzel-Ziele aufgelöst; Fallback-Label für Direkt-Speicher).
        if (! empty($ziel['chapter_id'])) {
            $chapter = FoodAlchemistFoodbookKapitel::visibleToTeam($team)->find((int) $ziel['chapter_id']);
            $wert = $ziel['persons'] ?? null;

            return $chapter !== null ? $chapter->title . ($wert !== null ? " ({$wert} P.)" : '') : null;
        }
        if (! empty($ziel['concept_id'])) {
            $name = FoodAlchemistConcept::visibleToTeam($team)->find((int) $ziel['concept_id'])?->name;
            $wert = $ziel['persons'] ?? null;

            return $name !== null ? $name . ($wert !== null ? " ({$wert} P.)" : '') : null;
        }
        if (! empty($ziel['recipe_id'])) {
            $recipe = FoodAlchemistRecipe::visibleToTeam($team)->find((int) $ziel['recipe_id']);
            if ($recipe === null) {
                return null;
            }
            // Basisrezept mit kg-Ziel (P1): in Kilogramm ausgewiesen, nicht in Ansätzen.
            if (! (bool) $recipe->is_sales_recipe && isset($ziel['amount_kg']) && (float) $ziel['amount_kg'] > 0) {
                return $recipe->name . ' (' . $this->zahl((float) $ziel['amount_kg']) . ' kg)';
            }
            $wert = $ziel['portions'] ?? $ziel['persons'] ?? null;
            // Basisrezept solo wird in ganzen Ansätzen gemessen, nicht in Portionen.
            $einheit = (bool) $recipe->is_sales_recipe ? 'Port.' : 'Ansätze';

            return $recipe->name . ($wert !== null ? " ({$this->zahl((float) $wert)} {$einheit})" : '');
        }

        return null;
    }

    /** Zahl fürs Label ohne überflüssige Nachkommastellen (5.0 ⇒ „5", 5.5 ⇒ „5,5"). */
    private function zahl(float $n): string
    {
        return rtrim(rtrim(number_format($n, 2, ',', '.'), '0'), ',');
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
