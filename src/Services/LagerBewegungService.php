<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistInventoryLocation;
use Platform\FoodAlchemist\Models\FoodAlchemistInventoryMovement;
use Platform\FoodAlchemist\Models\FoodAlchemistInventoryStock;

/**
 * Spec 67 A · Lagerbewegungen von Hand: Zugang, Abgang (mit Grund), Umlagerung, Storno.
 *
 * Schreibt den Bestand sofort fort (auch ins Negative — periodisches Lager, die Inventur korrigiert)
 * und friert die Bewertung ein (`price_per_base`, `value_eur`). Storno = Gegenbuchung, das Original
 * bleibt als Beleg. Wareneingang und Inventur werden an ihrer Quelle korrigiert, nicht hier.
 *
 * Team-strikt wie Bestand und Inventur.
 */
class LagerBewegungService
{
    public const GRUENDE = [
        'zugang' => ['ohne_bestellung' => 'Ohne Bestellung', 'ruecknahme' => 'Rücknahme aus Ausgabe', 'marktkauf' => 'Marktkauf / Barkauf', 'korrektur' => 'Korrektur'],
        'abgang' => ['verderb' => 'Verderb', 'bruch' => 'Bruch', 'schwund' => 'Schwund', 'personal' => 'Personalessen', 'probe' => 'Probe / Verkostung', 'korrektur' => 'Korrektur'],
    ];

    /** Gründe, die im Controlling als benannter Schwund zählen. */
    public const SCHWUND_GRUENDE = ['verderb', 'bruch', 'schwund'];

    public function __construct(
        private InventurService $inventur,
        private LagerEinrichtungService $einrichtung,
    ) {}

    /**
     * Bewegung buchen.
     *
     * @param  array{art:string, gp_id:int, location_id:int, ziel_location_id?:?int, menge?:mixed, kartons?:mixed, einheiten?:mixed, lose?:mixed,
     *               grund?:?string, notiz?:?string, datum?:?string, preis?:mixed}  $in
     * @return list<FoodAlchemistInventoryMovement>  eine Bewegung, bei Umlagerung zwei
     */
    public function buchen(Team $team, array $in, ?int $userId = null): array
    {
        $art = (string) ($in['art'] ?? '');
        if (! in_array($art, ['zugang', 'abgang', 'umlagerung'], true)) {
            throw new \RuntimeException('Art muss zugang, abgang oder umlagerung sein.');
        }
        $gp = FoodAlchemistGp::visibleToTeam($team)->with('leadLa')->findOrFail((int) ($in['gp_id'] ?? 0));
        $ort = FoodAlchemistInventoryLocation::where('team_id', $team->id)->findOrFail((int) ($in['location_id'] ?? 0));
        $ziel = null;
        if ($art === 'umlagerung') {
            $ziel = FoodAlchemistInventoryLocation::where('team_id', $team->id)->findOrFail((int) ($in['ziel_location_id'] ?? 0));
            if ($ziel->id === $ort->id) {
                throw new \RuntimeException('Quell- und Ziel-Lagerort sind gleich.');
            }
        }
        $grund = $in['grund'] ?? null;
        $grund = $grund !== null && $grund !== '' ? (string) $grund : null;
        if ($art !== 'umlagerung' && ($grund === null || ! array_key_exists($grund, self::GRUENDE[$art]))) {
            throw new \RuntimeException('Bitte einen Grund wählen.');
        }

        $baseUnit = $this->basisEinheit($team, $gp, (int) $ort->id);
        $menge = $this->mengeInBasis($gp, $baseUnit, $in);
        if ($menge <= 0) {
            throw new \RuntimeException('Die Menge muss größer als 0 sein.');
        }
        $preis = $this->preis($team, $gp, $baseUnit, $art === 'zugang' ? ($in['preis'] ?? null) : null);
        $datum = ! empty($in['datum']) ? Carbon::parse((string) $in['datum']) : now();
        if ($datum->isStartOfDay()) {
            $datum = $datum->setTimeFrom(now());
        }
        $notiz = isset($in['notiz']) && trim((string) $in['notiz']) !== '' ? mb_substr(trim((string) $in['notiz']), 0, 500) : null;

        return DB::transaction(function () use ($team, $art, $gp, $ort, $ziel, $grund, $baseUnit, $menge, $preis, $datum, $notiz, $userId) {
            $basis = ['team_id' => $team->id, 'gp_id' => $gp->id, 'base_unit' => $baseUnit, 'qty_base' => round($menge, 4),
                'moved_at' => $datum, 'note' => $notiz, 'reason' => $grund, 'booked_by' => $userId,
                'price_per_base' => $preis, 'value_eur' => $preis !== null ? round($menge * $preis, 2) : null];
            if ($art !== 'umlagerung') {
                return [$this->bewegung($team, $ort->id, $art === 'zugang' ? 'in' : 'out', $art, $basis)];
            }
            $ref = (string) Str::uuid();

            return [
                $this->bewegung($team, $ort->id, 'out', 'umlagerung', $basis + ['transfer_ref' => $ref], 'nach ' . $ziel->name),
                $this->bewegung($team, $ziel->id, 'in', 'umlagerung', $basis + ['transfer_ref' => $ref], 'von ' . $ort->name),
            ];
        });
    }

    /**
     * Hand-Buchung stornieren: Gegenbuchung je Bewegung (bei Umlagerung beide Hälften).
     *
     * @return list<FoodAlchemistInventoryMovement>  die Storno-Bewegungen
     */
    public function stornieren(Team $team, int $movementId, ?int $userId = null): array
    {
        $m = FoodAlchemistInventoryMovement::where('team_id', $team->id)->findOrFail($movementId);
        if (! $m->istHandbuchung()) {
            throw new \RuntimeException('Nur Hand-Buchungen lassen sich hier stornieren — Wareneingang und Inventur an ihrer Quelle korrigieren.');
        }
        $gruppe = $m->transfer_ref !== null
            ? FoodAlchemistInventoryMovement::where('team_id', $team->id)->where('transfer_ref', $m->transfer_ref)->where('source', 'umlagerung')->get()
            : collect([$m]);
        if (FoodAlchemistInventoryMovement::where('team_id', $team->id)->whereIn('storno_of_id', $gruppe->pluck('id'))->exists()) {
            throw new \RuntimeException('Diese Buchung ist bereits storniert.');
        }

        return DB::transaction(fn () => $gruppe->map(fn ($o) => $this->bewegung($team, (int) $o->inventory_location_id,
            $o->direction === 'in' ? 'out' : 'in', 'storno', [
                'team_id' => $team->id, 'gp_id' => $o->gp_id, 'supplier_item_id' => $o->supplier_item_id, 'base_unit' => $o->base_unit,
                'qty_base' => (float) $o->qty_base, 'moved_at' => now(), 'reason' => $o->reason, 'booked_by' => $userId,
                'price_per_base' => $o->price_per_base, 'value_eur' => $o->value_eur, 'storno_of_id' => $o->id,
                'transfer_ref' => $o->transfer_ref,
            ], 'Storno ' . ($o->moved_at?->format('d.m.Y') ?? '')))->values()->all());
    }

    /**
     * Wert der Hand-Abgänge je Grund im Zeitraum (stornierte ausgenommen). Für das Controlling.
     *
     * @return array{schwund: float, personal: float, probe: float, korrektur: float, je_grund: array<string, float>}
     */
    public function abgaengeWert(Team $team, ?string $von, ?string $bis): array
    {
        // Stornierte Abgänge zählen nie mit — egal, wann storniert wurde (ein Irrtum im Juli bleibt im Juli korrigiert).
        $teamIds = app(StandortService::class)->leseTeamIds($team);   // Spec 77c: Team-Brille
        $ab = FoodAlchemistInventoryMovement::whereIn('team_id', $teamIds)
            ->where('source', 'abgang')->where('direction', 'out')->whereNotNull('reason')
            ->whereNotIn('id', FoodAlchemistInventoryMovement::whereIn('team_id', $teamIds)->whereNotNull('storno_of_id')->select('storno_of_id'))
            ->when($von !== null, fn ($x) => $x->whereDate('moved_at', '>=', $von))
            ->when($bis !== null, fn ($x) => $x->whereDate('moved_at', '<=', $bis))
            ->selectRaw('reason, SUM(value_eur) AS wert')->groupBy('reason')->pluck('wert', 'reason');
        $je = [];
        foreach (array_keys(self::GRUENDE['abgang']) as $g) {
            $je[$g] = round((float) ($ab[$g] ?? 0), 2);
        }

        return [
            'schwund' => round(array_sum(array_intersect_key($je, array_flip(self::SCHWUND_GRUENDE))), 2),
            'personal' => $je['personal'], 'probe' => $je['probe'], 'korrektur' => $je['korrektur'],
            'je_grund' => $je,
        ];
    }

    /** Gebinde des Lead-Artikels in der Basiseinheit dieses Grundprodukts am Lagerort (für die Eingabe). */
    public function gebindeFuer(Team $team, int $gpId, int $locationId): array
    {
        $gp = FoodAlchemistGp::visibleToTeam($team)->with('leadLa')->findOrFail($gpId);
        $unit = $this->basisEinheit($team, $gp, $locationId);

        return ['base_unit' => $unit, 'einheit' => $this->inventur->anzeigeEinheit($unit), 'gebinde' => $this->einrichtung->gebinde($gp->leadLa, $unit)];
    }

    // ── intern ──────────────────────────────────────────────────────────────

    private function bewegung(Team $team, int $locationId, string $direction, string $source, array $daten, ?string $zusatz = null): FoodAlchemistInventoryMovement
    {
        $stock = FoodAlchemistInventoryStock::where('team_id', $team->id)->where('inventory_location_id', $locationId)
            ->where('base_unit', $daten['base_unit'])
            ->when($daten['gp_id'] !== null,
                fn ($q) => $q->where('gp_id', $daten['gp_id'])->whereNull('supplier_item_id'),
                fn ($q) => $q->whereNull('gp_id')->where('supplier_item_id', $daten['supplier_item_id'] ?? 0))
            ->lockForUpdate()->first()
            ?? FoodAlchemistInventoryStock::create([
                'team_id' => $team->id, 'inventory_location_id' => $locationId,
                'gp_id' => $daten['gp_id'], 'supplier_item_id' => $daten['gp_id'] === null ? ($daten['supplier_item_id'] ?? null) : null,
                'qty_base' => 0, 'base_unit' => $daten['base_unit'],
            ]);
        $delta = $direction === 'in' ? (float) $daten['qty_base'] : -(float) $daten['qty_base'];
        $stock->update(['qty_base' => round((float) $stock->qty_base + $delta, 4)]);
        $note = trim(implode(' · ', array_filter([$zusatz, $daten['note'] ?? null])));

        return FoodAlchemistInventoryMovement::create(array_merge($daten, [
            'stock_id' => $stock->id, 'inventory_location_id' => $locationId, 'direction' => $direction, 'source' => $source,
            'source_hash' => sha1('fa_hand:' . Str::uuid()), 'note' => $note !== '' ? $note : null,
        ]));
    }

    /** Basiseinheit: vorhandener Bestand am Lagerort, sonst Lead-Artikel, sonst g. */
    private function basisEinheit(Team $team, FoodAlchemistGp $gp, int $locationId): string
    {
        $vorhanden = FoodAlchemistInventoryStock::where('team_id', $team->id)->where('inventory_location_id', $locationId)
            ->where('gp_id', $gp->id)->whereNull('supplier_item_id')->orderByDesc('updated_at')->value('base_unit');

        return $vorhanden ?: ($this->inventur->basisEinheit((string) ($gp->leadLa?->unit_code ?? 'kg')) ?: 'g');
    }

    /** Menge aus kg/l/Stk oder Karton + Einheit + lose. */
    private function mengeInBasis(FoodAlchemistGp $gp, string $baseUnit, array $in): float
    {
        $zahl = function (mixed $v, string $was): ?float {
            $roh = trim(str_replace(',', '.', (string) ($v ?? '')));
            if ($roh === '') {
                return null;
            }
            if (! is_numeric($roh) || (float) $roh < 0) {
                throw new \RuntimeException($was . ' braucht eine Zahl ≥ 0.');
            }

            return (float) $roh;
        };
        $faktor = in_array($baseUnit, ['g', 'ml'], true) ? 1000.0 : 1.0;
        $k = $zahl($in['kartons'] ?? null, 'Kartons');
        $e = $zahl($in['einheiten'] ?? null, 'Einheiten');
        if ($k !== null || $e !== null) {
            $g = $this->einrichtung->gebinde($gp->leadLa, $baseUnit);
            if ($g === null) {
                throw new \RuntimeException('Für dieses Grundprodukt ist kein Gebinde bekannt — Menge in ' . $this->inventur->anzeigeEinheit($baseUnit) . ' angeben.');
            }

            return ($k ?? 0) * (float) ($g['pack_units'] ?? 0) * $g['unit_base'] + ($e ?? 0) * $g['unit_base']
                + ($zahl($in['lose'] ?? null, 'Lose Menge') ?? 0) * $faktor;
        }

        return ($zahl($in['menge'] ?? ($in['lose'] ?? null), 'Menge') ?? 0) * $faktor;
    }

    /** € je Basiseinheit: von Hand (€ je kg/l/Stk) oder aktueller EK. */
    private function preis(Team $team, FoodAlchemistGp $gp, string $baseUnit, mixed $hand): ?float
    {
        $roh = trim(str_replace(',', '.', (string) ($hand ?? '')));
        if ($roh !== '') {
            if (! is_numeric($roh) || (float) $roh < 0) {
                throw new \RuntimeException('Preis braucht eine Zahl ≥ 0.');
            }

            return round((float) $roh / (in_array($baseUnit, ['g', 'ml'], true) ? 1000 : 1), 6);
        }

        return $this->inventur->preisJeBasis($team, $gp, null, $baseUnit);
    }
}
