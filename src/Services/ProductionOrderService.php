<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\OrderStatus;
use Platform\FoodAlchemist\Enums\ProductionLineStatus;
use Platform\FoodAlchemist\Enums\ProductionOrderStatus;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel;
use Platform\FoodAlchemist\Models\FoodAlchemistOrder;
use Platform\FoodAlchemist\Models\FoodAlchemistOrderLine;
use Platform\FoodAlchemist\Models\FoodAlchemistProductionOrder;
use Platform\FoodAlchemist\Models\FoodAlchemistProductionOrderLine;
use Platform\FoodAlchemist\Models\FoodAlchemistProductionStation;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Support\TeamScope;

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

    /**
     * Spec 30 — was an einer BERECHNETEN Zeile dem Menschen gehört und deshalb jeden
     * Recompute überlebt. Alles andere (ansaetze, portionen, zutaten, steps_snapshot,
     * arbeitszeit_min, tiefe, position …) ist Rechen-Wahrheit und wird bedingungslos
     * überschrieben.
     *
     * EIN Ort der Wahrheit: neue Overlay-Felder kommen hier rein, der Restore-Test
     * iteriert über diese Konstante und fällt sonst um.
     */
    public const OVERLAY_FELDER = [
        'note',                                    // gab es schon vor Spec 30
        'manual_ansaetze', 'is_manual_ansaetze',   // Küchen-Override der Ansätze
        'is_struck', 'struck_reason',              // gestrichen
        'station_id', 'assignee', 'vorlauf_tage',  // E3: Zuteilung (Posten · Name · Vorlauf)
    ];

    /** Freie Positionen sortieren in ein eigenes Zahlenband, damit der Recompute sie nie umnummeriert. */
    public const MANUELL_POSITION_BASIS = 10000;

    /**
     * Obergrenze für den Vorproduktions-Offset. Kappt Tippfehler („30" statt „3"), die sonst
     * Arbeit einen Monat nach vorne würfen — und hält die Tagesplan-Fenster überschaubar.
     */
    public const MAX_VORLAUF_TAGE = 14;

    // ── source_ref-Verdrahtung (P4: EIN Ort für Bau + Lesen) ────────────────

    /**
     * Basis-Token der Produktions-Herkunft in `order_lines.source_contributions`.
     * Es gibt bewusst KEINE FK (eine Bestellzeile kann N Quellen haben) — die
     * Verknüpfung Produktion↔Bestellung läuft ausschließlich über diesen Präfix.
     */
    public const SOURCE_REF_BASE = 'produktion:';

    /** Per-Auftrag-Präfix `produktion:{id}:` (trailing-Doppelpunkt disambiguiert #1 gegen #10). */
    public static function sourceRefPrefix(int $productionOrderId): string
    {
        return self::SOURCE_REF_BASE . $productionOrderId . ':';
    }

    /** Voller Quell-Key `produktion:{id}:{ref}` für die Bedarfs-Übergabe. */
    public static function sourceRefFor(int $productionOrderId, string $ref): string
    {
        return self::sourceRefPrefix($productionOrderId) . $ref;
    }

    /** Stabiler Hash der Ziel-Liste (Stale-Marker P4). */
    public static function targetsHash(?array $targets): string
    {
        return sha1(json_encode(array_values($targets ?? []), JSON_UNESCAPED_UNICODE) ?: '');
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

        // Küchen-Manager: Überproduktions-/Puffer-% skaliert die Ziel-Mengen (persons/portions/amount_kg)
        // VOR der Explosion → mehr Ansätze + mehr Einkauf. Original-Ziele bleiben unverändert gespeichert.
        $pct = (float) ($order->buffer_pct ?? 0);
        if ($pct > 0) {
            $faktor = 1 + $pct / 100;
            $ziele = array_map(function (array $z) use ($faktor) {
                foreach (['persons', 'portions', 'amount_kg'] as $k) {
                    if (isset($z[$k])) {
                        $z[$k] = (float) $z[$k] * $faktor;
                    }
                }

                return $z;
            }, $ziele);
        }

        // Spec 30: das Overlay der berechneten Zeilen retten (Notiz, Override, Streichung, …).
        // Schlüssel ist `recipe_id`, weil die Explosion genau EINE Zeile je Rezept erzeugt
        // (`explodiere()` aggregiert keyed by recipe_id) — festgenagelt per Unique-Index.
        $overlay = $order->lines()->where('origin', 'computed')->get()
            ->keyBy('recipe_id')
            ->map(fn ($l) => array_filter(
                Arr::only($l->attributesToArray(), self::OVERLAY_FELDER),
                fn ($v) => $v !== null && $v !== false && $v !== '',
            ))->all();

        // forceDelete statt soft-delete: Zeilen sind ephemere Snapshots, die bei jeder
        // Ziel-Änderung neu erzeugt werden — soft-delete würde sonst unbegrenzt Tombstones
        // ansammeln (und der Unique-Index würde an ihnen hängenbleiben).
        // NUR `computed`: freie Positionen (origin=manual) gehören nicht der Explosion.
        $order->lines()->where('origin', 'computed')->forceDelete();

        if ($ziele === []) {
            // Manuelle Zeilen bleiben stehen — sie hängen an keinem Ziel.
            $order->warnungen = $this->verwaisteOverlays($overlay, []);
            $order->save();

            return;
        }

        $blatt = $this->planung->produktionsblattFuerZiele($team, $ziele);

        foreach ($blatt['rezepte'] as $i => $r) {
            $order->lines()->create(array_merge([
                'team_id' => $order->team_id,
                'origin' => 'computed',
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
                'steps_snapshot' => $r['schritte'] ?? null,   // Spec 27: Schrittfolge mit einfrieren
                'darreichung' => $r['darreichung'],
                'zutaten' => $r['zutaten'],
                'position' => $i,
            ], $overlay[$r['recipe_id']] ?? []));
        }

        $order->warnungen = array_merge(
            $blatt['warnungen'],
            $this->verwaisteOverlays($overlay, array_column($blatt['rezepte'], 'recipe_id')),
        );
        $order->save();
        $this->syncPlanDates($order);
    }

    /**
     * Spec 30 E3 — `plan_date` aus `production_date − vorlauf_tage` neu ableiten.
     *
     * ⚠️ EINZIGER SCHREIBER dieser Spalte. Kein anderer Code darf sie setzen, sonst driftet
     * die abgeleitete Wahrheit still gegen `vorlauf_tage` — und der Tagesplan zeigt Arbeit an
     * einem Tag, an dem niemand hinschaut. Jeder Pfad, der `production_date` ändert, ruft das
     * hier: `recomputeOrder()` und `updateHeader()`.
     *
     * BEWUSST in PHP gerechnet statt per SQL-Datumsarithmetik — die divergiert zwischen
     * SQLite (Testsuite) und MySQL.
     */
    public function syncPlanDates(FoodAlchemistProductionOrder $order): void
    {
        $liefertag = $order->production_date;
        if ($liefertag === null) {
            return;
        }

        foreach ($order->lines()->get(['id', 'vorlauf_tage', 'plan_date']) as $line) {
            $soll = $liefertag->copy()->subDays(max(0, (int) $line->vorlauf_tage))->toDateString();
            if ($line->plan_date?->toDateString() !== $soll) {
                $line->forceFill(['plan_date' => $soll])->save();
            }
        }
    }

    /**
     * Spec 30 E3 — Zuteilung setzen: Posten · Verantwortlicher · Vorlauf.
     *
     * Nur übergebene Keys werden angefasst (`array_key_exists`), damit ein Teil-Update nicht
     * still die anderen Felder leert. Vorlauf über `MAX_VORLAUF_TAGE` hinaus wird gekappt:
     * ein Tippfehler („30" statt „3") soll keine Arbeit einen Monat nach vorn werfen.
     */
    public function assignLine(Team $team, int $lineId, array $input): FoodAlchemistProductionOrderLine
    {
        $line = $this->ownedDisponierbareLine($team, $lineId);

        if (array_key_exists('station_id', $input)) {
            $line->station_id = TeamScope::referenz(
                FoodAlchemistProductionStation::class, $input['station_id'], $team, 'Posten'
            );
        }
        if (array_key_exists('assignee', $input)) {
            $line->assignee = mb_substr(trim((string) $input['assignee']), 0, 120) ?: null;
        }
        if (array_key_exists('vorlauf_tage', $input)) {
            $line->vorlauf_tage = max(0, min(self::MAX_VORLAUF_TAGE, (int) $input['vorlauf_tage']));
        }
        $line->save();

        // Vorlauf geändert ⇒ abgeleitetes plan_date nachziehen (einziger Schreiber, s. o.)
        $this->syncPlanDates($line->productionOrder);

        return $line->refresh();
    }

    /**
     * Arbeitszeit + Ansätze je Posten für EINEN Auftrag (Editor/Panel).
     *
     * Gestrichene Zeilen zählen nicht. Zeilen ohne Posten landen im Bucket `null` —
     * unverplante Arbeit darf nicht unsichtbar sein, nur weil sie an keinem Posten hängt.
     * `ohne_zeit` macht die Datenlücke sichtbar: `work_time_min` ist am Rezept oft leer,
     * eine Summe ohne diesen Hinweis würde eine halbe Datenlage als Wahrheit verkaufen.
     *
     * @return list<array{station_id: ?int, station: string, zeilen: int, ansaetze: float, arbeitszeit_min: int, ohne_zeit: int}>
     */
    public function postenSummen(Team $team, int $orderId): array
    {
        $order = FoodAlchemistProductionOrder::visibleToTeam($team)
            ->with('lines.station:id,name')->findOrFail($orderId);

        return $order->lines
            ->reject(fn ($l) => (bool) $l->is_struck)
            ->groupBy(fn ($l) => $l->station_id ?? 0)
            ->map(fn ($zeilen, $sid) => [
                'station_id' => (int) $sid ?: null,
                'station' => (int) $sid ? ($zeilen->first()->station?->name ?? '—') : 'Nicht zugeteilt',
                'zeilen' => $zeilen->count(),
                'ansaetze' => round((float) $zeilen->sum('ansaetze_effektiv'), 3),
                'arbeitszeit_min' => (int) $zeilen->sum('arbeitszeit_min'),
                'ohne_zeit' => $zeilen->whereNull('arbeitszeit_min')->count(),
            ])
            ->sortBy(fn ($p) => $p['station_id'] === null ? 'zzz' : $p['station'])
            ->values()->all();
    }

    /**
     * Spec 30: Overlays, deren Rezept nach der Neu-Explosion nicht mehr vorkommt, sind
     * verloren — das wird GEMELDET statt still geschluckt.
     *
     * Bewusst kein Auto-Promote in eine manuelle Zeile (das reanimierte Produktion, die
     * gerade gestrichen wurde) und keine Park-Tabelle (unsichtbare Overlays, die Wochen
     * später bei zufällig passendem Ziel wieder andocken). Ehrlicher Verlust mit lauter
     * Meldung schlägt stillen Zombie-Zustand.
     *
     * @param  array<int, array<string, mixed>>  $overlay
     * @param  list<int>  $erzeugteRecipeIds
     * @return list<string>
     */
    private function verwaisteOverlays(array $overlay, array $erzeugteRecipeIds): array
    {
        $warnungen = [];
        foreach ($overlay as $recipeId => $felder) {
            if (! $this->istSubstanziell($felder) || in_array((int) $recipeId, array_map('intval', $erzeugteRecipeIds), true)) {
                continue;
            }
            $name = FoodAlchemistRecipe::withTrashed()->whereKey($recipeId)->value('name') ?? "Rezept #{$recipeId}";
            $warnungen[] = "„{$name}“: manuelle Eingriffe verworfen — das Rezept kommt in den Zielen nicht mehr vor.";
        }

        return $warnungen;
    }

    /**
     * Hat an dieser Zeile überhaupt jemand etwas gepflegt, das eine Meldung wert wäre?
     *
     * Wichtig für Felder mit bedeutungslosem Default: `vorlauf_tage = 0` heißt „kein Vorlauf"
     * und ist KEIN Eingriff. Zählte es mit, würde jede entfernte Zeile eine Warnung auslösen
     * und die Meldung wäre in einer Woche Rauschen, das niemand mehr liest.
     */
    private function istSubstanziell(array $felder): bool
    {
        foreach ($felder as $k => $v) {
            if ($k === 'vorlauf_tage' && (int) $v === 0) {
                continue;
            }
            if ($v !== null && $v !== false && $v !== '' && $v !== 0 && $v !== '0') {
                return true;
            }
        }

        return false;
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
        $datumGeaendert = false;
        if (array_key_exists('production_date', $input) && ! empty($input['production_date'])) {
            $order->production_date = $input['production_date'];
            $datumGeaendert = $order->isDirty('production_date');
        }
        // Puffer-% (0–100): ändert die Explosion → nach dem Speichern neu rechnen.
        $pufferGeaendert = false;
        if (array_key_exists('buffer_pct', $input)) {
            $neu = max(0.0, min(100.0, (float) $input['buffer_pct']));
            if ((float) $order->buffer_pct !== $neu) {
                $order->buffer_pct = $neu;
                $pufferGeaendert = true;
            }
        }
        $order->save();
        if ($pufferGeaendert) {
            $this->recomputeOrder($team, $order->refresh());   // rechnet und synct plan_date mit
        } elseif ($datumGeaendert) {
            // Liefertag verschoben ⇒ der ganze Vorproduktions-Schwanz wandert mit.
            // Genau dafür ist der Vorlauf ein OFFSET und kein absolutes Datum.
            $this->syncPlanDates($order->refresh());
        }

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

    // ── Spec 30: Zeilen-Eingriff ────────────────────────────────────────────
    //
    // Bewusst EIGENE Verben statt `updateLine()` zu einem Grab-Bag zu machen: sonst könnte
    // ein Aufruf gleichzeitig das Soll umschreiben und eine Zeile streichen, und der Guard
    // müsste pro Schlüssel verzweigen. Explizite Verben = explizite Guards.

    /**
     * Küchen-Override der Ansätze. `null` nimmt den Override zurück.
     *
     * Der berechnete Wert in `ansaetze` bleibt stehen — nur so ist „manuell 2 — berechnet
     * wären 3 · zurücksetzen" darstellbar (Muster: `OrderService` mit `is_manual_qty`).
     *
     * ⚠️ Der Override propagiert NICHT nach unten: er ändert weder GP-Bedarf noch
     * Eltern-Rechnung noch die Übergabe an die Bestellung (die liest `targets`, nicht Zeilen).
     * Es ist eine Küchen-Korrektur, kein Bedarfs-Eingriff. Propagation hieße, die Explosion
     * mit gepinntem Knoten neu zu rechnen — ein zweites Rundungs-Regime neben `ceil()`.
     */
    public function setLineAnsaetze(Team $team, int $lineId, ?float $ansaetze): FoodAlchemistProductionOrderLine
    {
        $line = $this->ownedOpenLine($team, $lineId);

        if ($line->istManuell()) {
            // Freie Positionen tragen ihre Zahl direkt — kein Override-Paar nötig.
            $line->ansaetze = $ansaetze !== null ? max(0.0, $ansaetze) : 0.0;
        } elseif ($ansaetze === null) {
            $line->manual_ansaetze = null;
            $line->is_manual_ansaetze = false;
        } else {
            $line->manual_ansaetze = max(0.0, $ansaetze);
            $line->is_manual_ansaetze = true;
        }
        $line->save();

        return $line->refresh();
    }

    /**
     * Zeile streichen bzw. wiederherstellen.
     *
     * Streichen ist KEIN Löschen: die nächste Ziel-Änderung würde die Zeile sofort wieder
     * erzeugen. Als Overlay-Flag klebt der Strich dagegen am Rezept. Die Zeile bleibt im
     * Panel sichtbar (durchgestrichen), fällt aber aus allen Summen und aus dem Druck.
     */
    public function setLineStruck(Team $team, int $lineId, bool $struck, ?string $grund = null): FoodAlchemistProductionOrderLine
    {
        $line = $this->ownedOpenLine($team, $lineId);
        if ($line->istManuell()) {
            throw new \RuntimeException('Freie Positionen werden gelöscht, nicht gestrichen.');
        }
        $line->is_struck = $struck;
        $line->struck_reason = $struck ? (trim((string) $grund) ?: null) : null;
        $line->save();

        return $line->refresh();
    }

    /**
     * Freie Position anlegen („Brot beim Bäcker abholen") — etwas, das kein Rezept ist.
     *
     * `recipe_id` bleibt zwingend NULL: eine freie Position mit Rezept würde die
     * Einkaufs-Übergabe umgehen (die läuft über `targets`). Wer „ein Ansatz obendrauf" will,
     * legt ein ZIEL an. `arbeitszeit_min` ist frei setzbar, sonst lügen die Posten-Summen.
     */
    public function addManualLine(Team $team, int $orderId, array $input): FoodAlchemistProductionOrderLine
    {
        $order = $this->ownedOpenOrder($team, $orderId);

        $titel = trim((string) ($input['titel'] ?? ''));
        if ($titel === '') {
            throw new \RuntimeException('Freie Position braucht einen Titel.');
        }

        $maxManuell = (int) $order->lines()->where('origin', 'manual')->max('position');

        $zeile = $order->lines()->create([
            'team_id' => $order->team_id,
            'origin' => 'manual',
            'recipe_id' => null,
            'titel' => mb_substr($titel, 0, 255),
            'is_basisrezept' => false,
            'tiefe' => 0,
            'ansaetze' => (float) ($input['ansaetze'] ?? 1),
            'benoetigt_ansaetze' => (float) ($input['ansaetze'] ?? 1),
            'arbeitszeit_min' => isset($input['arbeitszeit_min']) && $input['arbeitszeit_min'] !== ''
                ? (int) $input['arbeitszeit_min'] : null,
            'note' => trim((string) ($input['note'] ?? '')) ?: null,
            'position' => max($maxManuell, self::MANUELL_POSITION_BASIS) + 1,
        ]);

        // Auch freie Positionen brauchen ihr abgeleitetes plan_date, sonst fallen sie aus
        // dem Tagesplan und aus jeder Kapazitätsrechnung heraus.
        $this->syncPlanDates($order);

        return $zeile->refresh();
    }

    // ── Spec 30 E6: Küchen-Ausführung ───────────────────────────────────────

    /**
     * Zeile abhaken (bzw. Haken zurücknehmen).
     *
     * Nur im LAUFENDEN Auftrag. Im `planned` verboten, weil dort ein Recompute die Zeile
     * unter der Hand ersetzen kann — ein „erledigt", das das überlebt, hinge an inzwischen
     * geänderten Ansätzen und wäre ein Protokoll, das lügt.
     *
     * Die Übergänge sind bewusst frei (Checkliste, kein Beleg-Lebenszyklus): ein Fehlklick
     * muss ohne Datenbank-Eingriff korrigierbar sein.
     */
    public function setLineStatus(Team $team, int $lineId, ProductionLineStatus $ziel): FoodAlchemistProductionOrderLine
    {
        $line = $this->ownedRunningLine($team, $lineId);

        $line->line_status = $ziel;
        $line->done_at = $ziel === ProductionLineStatus::Done ? now() : null;
        $line->done_by = $ziel === ProductionLineStatus::Done ? Auth::id() : null;
        $line->save();

        return $line->refresh();
    }

    /**
     * Abgeleiteter Fortschritt — NICHT gespeichert.
     *
     * Ein Zähler am Auftrag lädt nur zur Drift ein; `detail()` hat die Zeilen ohnehin geladen.
     * Gestrichene Zeilen zählen weder im Nenner noch im Zähler: sie werden bewusst nicht
     * produziert und dürfen den Fortschritt nicht künstlich drücken.
     *
     * @return array{offen: int, in_arbeit: int, erledigt: int, uebersprungen: int, gesamt: int, prozent: int, alle_erledigt: bool}
     */
    public function fortschritt(FoodAlchemistProductionOrder $order): array
    {
        $aktive = $order->lines->reject(fn ($l) => (bool) $l->is_struck);
        $status = fn (ProductionLineStatus $s) => $aktive->filter(fn ($l) => $l->line_status === $s)->count();

        $gesamt = $aktive->count();
        $fertig = $aktive->filter(fn ($l) => $l->line_status->istAbgearbeitet())->count();

        return [
            'offen' => $status(ProductionLineStatus::Open),
            'in_arbeit' => $status(ProductionLineStatus::InProgress),
            'erledigt' => $status(ProductionLineStatus::Done),
            'uebersprungen' => $status(ProductionLineStatus::Skipped),
            'gesamt' => $gesamt,
            'prozent' => $gesamt > 0 ? (int) round($fertig / $gesamt * 100) : 0,
            'alle_erledigt' => $gesamt > 0 && $fertig === $gesamt,
        ];
    }

    /** Freie Position entfernen (nur solche — berechnete Zeilen werden gestrichen). */
    public function removeManualLine(Team $team, int $lineId): void
    {
        $line = $this->ownedOpenLine($team, $lineId);
        if (! $line->istManuell()) {
            throw new \RuntimeException('Berechnete Zeilen werden gestrichen, nicht gelöscht.');
        }
        $line->delete();
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

    /**
     * Spec 30 E4 — serverseitig gefilterte, paginierte Browser-Liste.
     *
     * Schließt den Audit-Befund MVP-033: bis hierher lud `listForTeam()` die VOLLE Menge in
     * den Speicher und filterte in PHP, ohne Pagination. Bei einem Team mit Jahren an
     * Produktionsgeschichte wächst das unbegrenzt.
     *
     * EIN Filtersatz für Liste UND Zähler — im VK-Browser steht der Grund als Kommentar:
     * kennen die Facetten die aktive Auswahl nicht, liefert eine Filterkombination am Ende
     * null Treffer bei gleichzeitig positiven Zählern.
     *
     * @param  array{status?: string, von?: ?string, bis?: ?string, suche?: string}  $filters
     */
    public function paginateBrowser(Team $team, array $filters, int $perPage = 50): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return $this->browserQuery($team, $filters)
            // Offene zuerst, dann nach Produktionsdatum — dieselbe Leserichtung wie listForTeam().
            ->orderByRaw("CASE WHEN status = 'planned' THEN 0 ELSE 1 END")
            ->orderBy('production_date')->orderBy('name')
            ->paginate(max(10, min(250, $perPage)));
    }

    /** Trefferzahl je Status MIT den übrigen aktiven Filtern (ohne die Status-Achse selbst). */
    public function statusCounts(Team $team, array $filters): array
    {
        return $this->browserQuery($team, Arr::except($filters, ['status']))
            ->selectRaw('status, COUNT(*) as n')->groupBy('status')
            ->pluck('n', 'status')->map(fn ($n) => (int) $n)->all();
    }

    public function browserGesamt(Team $team, array $filters): int
    {
        return $this->browserQuery($team, Arr::except($filters, ['status']))->count();
    }

    /** @param  array{status?: string, von?: ?string, bis?: ?string, suche?: string}  $filters */
    private function browserQuery(Team $team, array $filters): \Illuminate\Database\Eloquent\Builder
    {
        $suche = trim((string) ($filters['suche'] ?? ''));

        return FoodAlchemistProductionOrder::visibleToTeam($team)
            ->when(($filters['status'] ?? '') !== '', fn ($q) => $q->where('status', $filters['status']))
            // whereDate: `production_date` persistiert mit Zeitanteil — ein reiner
            // Datumsvergleich würde den letzten Tag des Fensters verschlucken.
            ->when(! empty($filters['von']), fn ($q) => $q->whereDate('production_date', '>=', $filters['von']))
            ->when(! empty($filters['bis']), fn ($q) => $q->whereDate('production_date', '<=', $filters['bis']))
            ->when($suche !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'like', '%' . $suche . '%')
                ->orWhere('reference', 'like', '%' . $suche . '%')));
    }

    /** Detail-Aggregat für UI/MCP. */
    public function detail(Team $team, int $orderId): array
    {
        $order = FoodAlchemistProductionOrder::visibleToTeam($team)->with(['lines.recipe:id,name', 'lines.station:id,name'])->findOrFail($orderId);
        $status = $order->status instanceof ProductionOrderStatus ? $order->status : ProductionOrderStatus::from((string) $order->status);

        // P4: verknüpfte Bestellschienen (kompakt fürs UI/MCP) + Stale-Marker.
        $verknuepft = $this->verknuepfteOrders($team, $orderId)->map(fn ($o) => [
            'id' => (int) $o->id,
            'supplier' => $o->supplier?->name,
            'status' => $o->status instanceof OrderStatus ? $o->status->value : (string) $o->status,
            'total_net' => (float) $o->total_net,
            'reference' => $o->reference,
        ])->values()->all();

        // Spec 30: gestrichene Zeilen bleiben SICHTBAR (durchgestrichen), zählen aber nirgends mit.
        $aktive = $order->lines->reject(fn ($l) => (bool) $l->is_struck);

        return [
            'id' => (int) $order->id,
            'name' => $order->name,
            'production_date' => $order->production_date?->toDateString(),
            'status' => $status->value,
            'status_label' => $status->label(),
            'reference' => $order->reference,
            'targets' => $order->targets ?? [],
            'note' => $order->note,
            'buffer_pct' => (float) ($order->buffer_pct ?? 0),
            'is_owned' => $order->isOwnedBy($team),
            'editierbar' => $status->istOffen() && $order->isOwnedBy($team),
            'verknuepfte_orders' => $verknuepft,
            'last_handover_at' => $order->last_handover_at?->toIso8601String(),
            'einkauf_veraltet' => $this->einkaufVeraltet($order),
            'warnungen' => $order->warnungen ?? [],
            // Spec 30: Summen zählen NUR das, was wirklich produziert wird — gestrichene Zeilen
            // fallen raus, Overrides zählen mit ihrem effektiven Wert.
            'fortschritt' => $this->fortschritt($order),   // Spec 30 E6 — abgeleitet, nie gespeichert
            'ansaetze_gesamt' => (float) $aktive->sum('ansaetze_effektiv'),
            'portionen_gesamt' => (int) $aktive->sum('portionen'),
            'arbeitszeit_gesamt_min' => (int) $aktive->sum('arbeitszeit_min'),
            'zeilen' => $order->lines->map(fn ($l) => [
                'id' => (int) $l->id,
                'recipe_id' => $l->recipe_id !== null ? (int) $l->recipe_id : null,
                'name' => $l->anzeigeName(),
                'ist_basisrezept' => (bool) $l->is_basisrezept,
                'ansaetze' => (float) $l->ansaetze_effektiv,
                'ansaetze_berechnet' => (float) $l->ansaetze,           // Spec 30: Referenz hinter dem Override
                'ist_manuelle_ansaetze' => (bool) $l->is_manual_ansaetze,
                'override_stale' => (bool) $l->override_stale,
                'ist_freie_position' => $l->istManuell(),
                'ist_gestrichen' => (bool) $l->is_struck,
                'struck_reason' => $l->struck_reason,
                'benoetigt_ansaetze' => (float) $l->benoetigt_ansaetze,
                'portionen' => $l->portionen !== null ? (int) $l->portionen : null,
                'produzierte_menge_kg' => $l->produzierte_menge_kg !== null ? (float) $l->produzierte_menge_kg : null,
                'arbeitszeit_min' => $l->arbeitszeit_min !== null ? (int) $l->arbeitszeit_min : null,
                // Spec 30 E3: Zuteilung
                'station_id' => $l->station_id !== null ? (int) $l->station_id : null,
                'station' => $l->station?->name,
                'assignee' => $l->assignee,
                'vorlauf_tage' => (int) $l->vorlauf_tage,
                'plan_date' => $l->plan_date?->format('d.m.Y'),
                // Spec 30 E6
                'line_status' => $l->line_status->value,
                'line_status_label' => $l->line_status->label(),
                'done_at' => $l->done_at?->format('d.m.Y H:i'),
                'zubereitung' => $l->zubereitung,
                'schritte' => $l->steps_snapshot ?? [],   // Spec 27 (leer = Alt-Auftrag → Text-Fallback)
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
        $prefix = self::sourceRefPrefix($productionOrderId);
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
     * P3 (Browser): Einkaufs-Indikator je Produktionsauftrag in EINER Query (kein N+1).
     * Wertet die `produktion:{id}:`-Präfixe in den `source_contributions`-Keys aller
     * team-sichtbaren Bestellzeilen aus und faltet sie auf den „höchsten" Zustand:
     *   versendet (≥1 verknüpfte Bestellung sent/confirmed/delivered) > offen (nur draft) > keine.
     * Stornierte Bestellungen zählen nicht (kein aktiver Einkauf).
     *
     * @param  list<int>  $prodIds
     * @return array<int, 'keine'|'offen'|'versendet'>
     */
    public function einkaufsIndikatoren(Team $team, array $prodIds): array
    {
        $result = array_fill_keys($prodIds, 'keine');
        if ($prodIds === []) {
            return $result;
        }

        $lines = FoodAlchemistOrderLine::query()
            ->whereHas('order', fn ($q) => $q->visibleToTeam($team))
            ->where('source_contributions', 'like', '%' . self::SOURCE_REF_BASE . '%')
            ->with('order:id,status')
            ->get(['id', 'order_id', 'source_contributions']);

        foreach ($lines as $line) {
            $status = $line->order?->status;
            if ($status === null) {
                continue;
            }
            $ostat = $status instanceof OrderStatus ? $status : OrderStatus::from((string) $status);
            if ($ostat === OrderStatus::Cancelled) {
                continue;
            }
            $versendet = in_array($ostat, [OrderStatus::Sent, OrderStatus::Confirmed, OrderStatus::Delivered], true);
            foreach (array_keys((array) $line->source_contributions) as $key) {
                if (! preg_match('/^' . preg_quote(self::SOURCE_REF_BASE, '/') . '(\d+):/', (string) $key, $m)) {
                    continue;
                }
                $pid = (int) $m[1];
                if (! array_key_exists($pid, $result)) {
                    continue;
                }
                if ($versendet) {
                    $result[$pid] = 'versendet';
                } elseif ($result[$pid] !== 'versendet') {
                    $result[$pid] = 'offen';
                }
            }
        }

        return $result;
    }

    /**
     * P3 (DetailPanel): welche Ziele dieses Auftrags wurden schon an eine Bestellung
     * übergeben? Match über den vollen Quell-Key `produktion:{orderId}:{source_ref}` in
     * den `source_contributions` team-sichtbarer Bestellzeilen. Binär je Ziel — keine
     * Mengen-Deckung (Teil-Mengen-Abgleich ist bewusst Nicht-Ziel von v2, es gibt kein
     * Bestand/Netting). Grundlage für „übergeben ✓/–" + den Deckungsgrad k/N.
     *
     * @return array<string, true>  source_ref (ohne Präfix) => übergeben
     */
    public function zielUebergaben(Team $team, int $orderId): array
    {
        $prefix = self::sourceRefPrefix($orderId);
        $lines = FoodAlchemistOrderLine::query()
            ->whereHas('order', fn ($q) => $q->visibleToTeam($team))
            ->where('source_contributions', 'like', '%' . $prefix . '%')
            ->get(['id', 'source_contributions']);

        $refs = [];
        foreach ($lines as $line) {
            foreach (array_keys((array) $line->source_contributions) as $key) {
                if (str_starts_with((string) $key, $prefix)) {
                    $refs[substr((string) $key, strlen($prefix))] = true;
                }
            }
        }

        return $refs;
    }

    /**
     * P4: Ist der Einkauf gegenüber dem aktuellen Ziel-Stand veraltet? True, wenn schon
     * einmal übergeben wurde (`last_handover_at`) und die Ziele sich seither geändert haben
     * (Hash-Abweichung). Grundlage für den DetailPanel-Hinweis „erneut übergeben".
     */
    public function einkaufVeraltet(FoodAlchemistProductionOrder $order): bool
    {
        return $order->last_handover_at !== null
            && $order->handover_targets_hash !== self::targetsHash($order->targets);
    }

    /**
     * P4: Einbahn-Übergabe des Bedarfs ALLER Ziele dieses Auftrags an die Bestellschienen —
     * der eine Ort, der die Ziel-Herkunft (`source_ref`-Präfix) baut UND den Stale-Marker
     * (`last_handover_at` + Ziel-Hash) setzt. Von DetailPanel und dem MCP-HANDOVER-Tool
     * gemeinsam genutzt (Lockstep). Kein Auto-Sync, kein Rückkanal.
     *
     * @return array{orders:list<int>, skipped_ohne_la:list<string>, warnungen:list<string>}
     */
    public function anBestellungUebergeben(Team $team, int $orderId, OrderService $orders, ?int $userId = null): array
    {
        $order = FoodAlchemistProductionOrder::visibleToTeam($team)->findOrFail($orderId);

        $touched = [];
        $skipped = [];
        $warnungen = [];
        foreach (($order->targets ?? []) as $ziel) {
            $sourceRef = self::sourceRefFor($orderId, (string) ($ziel['source_ref'] ?? ''));
            $res = $orders->addNeedFromTarget($team, Arr::except($ziel, ['source_ref', 'label']), $sourceRef, $userId);
            $touched = array_merge($touched, $res['orders']);
            $skipped = array_merge($skipped, $res['skipped_ohne_la']);
            $warnungen = array_merge($warnungen, $res['warnungen']);
        }

        // Stale-Marker aktualisieren: ab jetzt ist der Einkauf auf dem aktuellen Ziel-Stand.
        $order->last_handover_at = now();
        $order->handover_targets_hash = self::targetsHash($order->targets);
        $order->save();

        return [
            'orders' => array_values(array_unique($touched)),
            'skipped_ohne_la' => array_values(array_unique($skipped)),
            'warnungen' => array_values(array_unique($warnungen)),
        ];
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
            // Spec 30: gestrichene Zeilen kommen NICHT auf den Küchenzettel — genau dafür
            // hat sie jemand gestrichen. Overrides gehen mit ihrem effektiven Wert raus.
            'zeilen' => $order->lines->reject(fn ($l) => (bool) $l->is_struck)->map(fn ($l) => [
                'name' => $l->anzeigeName(),
                'ist_basisrezept' => (bool) $l->is_basisrezept,
                'ansaetze' => (float) $l->ansaetze_effektiv,
                'ist_manuelle_ansaetze' => (bool) $l->is_manual_ansaetze,
                'ist_freie_position' => $l->istManuell(),
                'portionen' => $l->portionen !== null ? (int) $l->portionen : null,
                'produzierte_menge_kg' => $l->produzierte_menge_kg !== null ? (float) $l->produzierte_menge_kg : null,
                'arbeitszeit_min' => $l->arbeitszeit_min !== null ? (int) $l->arbeitszeit_min : null,
                'zubereitung' => $l->zubereitung,
                'schritte' => $l->steps_snapshot ?? [],   // Spec 27 (leer = Alt-Auftrag → Text-Fallback)
                'darreichung' => $l->darreichung,
                'zutaten' => $l->zutaten,
            ])->values()->all(),
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

    /**
     * Spec 30 E3 — Disposition ist bis einschließlich `in_progress` erlaubt.
     *
     * Der Snapshot-Freeze schützt die GERECHNETE Wahrheit (Ansätze, Mengen, Zutaten). Posten,
     * Verantwortlicher und Vorlauf sind dagegen Disposition: die Realität besetzt mitten im
     * Service um, und der Recompute ist in `in_progress` ohnehin ein No-op — es gibt nichts,
     * was desynchronisieren könnte.
     */
    /**
     * Abgehakt wird NUR im laufenden Auftrag: vorher ist nichts produziert und ein Recompute
     * könnte die Zeile ersetzen, nachher ist der Beleg abgeschlossen.
     */
    private function ownedRunningLine(Team $team, int $lineId): FoodAlchemistProductionOrderLine
    {
        $line = FoodAlchemistProductionOrderLine::with('productionOrder')->findOrFail($lineId);
        $order = $line->productionOrder;
        if ($order === null || ! $order->isOwnedBy($team)) {
            throw new \RuntimeException('Produktionszeile nicht im Schreibzugriff (D1).');
        }
        $status = $order->status instanceof ProductionOrderStatus ? $order->status : ProductionOrderStatus::from((string) $order->status);
        if ($status !== ProductionOrderStatus::InProgress) {
            throw new \RuntimeException('Abgehakt wird erst, wenn die Produktion läuft.');
        }

        return $line;
    }

    private function ownedDisponierbareLine(Team $team, int $lineId): FoodAlchemistProductionOrderLine
    {
        $line = FoodAlchemistProductionOrderLine::with('productionOrder')->findOrFail($lineId);
        $order = $line->productionOrder;
        if ($order === null || ! $order->isOwnedBy($team)) {
            throw new \RuntimeException('Produktionszeile nicht im Schreibzugriff (D1).');
        }
        $status = $order->status instanceof ProductionOrderStatus ? $order->status : ProductionOrderStatus::from((string) $order->status);
        if (! in_array($status, [ProductionOrderStatus::Planned, ProductionOrderStatus::InProgress], true)) {
            throw new \RuntimeException('Ein abgeschlossener Auftrag lässt sich nicht mehr umdisponieren.');
        }

        return $line;
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
