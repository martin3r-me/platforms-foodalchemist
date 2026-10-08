<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistInventoryLocation;
use Platform\FoodAlchemist\Models\FoodAlchemistInventoryStock;
use Platform\FoodAlchemist\Models\FoodAlchemistPurchaseTransaction;
use Platform\FoodAlchemist\Models\FoodAlchemistStorageBin;
use Platform\FoodAlchemist\Models\FoodAlchemistStorageBinItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;

/**
 * Spec 66b · Lager digital einrichten.
 *
 * - Stellplätze je Lagerort mit Zone (Kühlung/TK/Trocken/Getränke) und Laufweg-Reihenfolge.
 * - Stammplatz je Grundprodukt und Lagerort; die Zählliste sortiert danach.
 * - Vorschlag: aus Zustand (§9) und Warengruppe (§3) des Grundprodukts die Zone ableiten und den
 *   ersten Stellplatz dieser Zone vorschlagen — der Mensch korrigiert nur noch.
 * - Gebinde: aus dem Lead-Artikel „1 Karton = n Einheiten, 1 Einheit = x g/ml/Stk".
 *
 * Team-strikt wie Bestand und Inventur.
 */
class LagerEinrichtungService
{
    /** Warengruppen (Regelwerk GP §3), die frisch in die Kühlung gehören. */
    private const WG_KUEHL = ['01', '02', '03', '04', '05', '06', '13', '14'];

    /** Bestellmengen-Einheiten, die schon eine Maßeinheit sind (kein zählbares Gebinde). */
    private const MASS_EINHEITEN = ['kg', 'kgm', 'g', 'grm', 'l', 'ltr', 'lt', 'ml', 'mlt'];

    // ── Stellplätze ─────────────────────────────────────────────────────────

    /** @return Collection<int, FoodAlchemistStorageBin> */
    public function stellplaetze(Team $team, int $locationId): Collection
    {
        return FoodAlchemistStorageBin::where('team_id', $team->id)->where('inventory_location_id', $locationId)
            ->withCount('zuordnungen')->orderBy('sort_order')->orderBy('id')->get();
    }

    public function stellplatzAnlegen(Team $team, int $locationId, string $name, ?string $zone = null): FoodAlchemistStorageBin
    {
        FoodAlchemistInventoryLocation::where('team_id', $team->id)->findOrFail($locationId);
        $name = trim($name);
        if ($name === '') {
            throw new \RuntimeException('Der Stellplatz braucht einen Namen.');
        }
        $zone = $this->zoneGueltig($zone);
        $sort = (int) FoodAlchemistStorageBin::where('team_id', $team->id)->where('inventory_location_id', $locationId)->max('sort_order');

        return FoodAlchemistStorageBin::create([
            'team_id' => $team->id, 'inventory_location_id' => $locationId,
            'name' => mb_substr($name, 0, 120), 'zone' => $zone, 'sort_order' => $sort + 10, 'is_active' => true,
        ]);
    }

    /** @param array{name?:string, zone?:?string} $daten */
    public function stellplatzAendern(Team $team, int $binId, array $daten): FoodAlchemistStorageBin
    {
        $bin = FoodAlchemistStorageBin::where('team_id', $team->id)->findOrFail($binId);
        $upd = [];
        if (array_key_exists('name', $daten)) {
            $name = trim((string) $daten['name']);
            if ($name === '') {
                throw new \RuntimeException('Der Stellplatz braucht einen Namen.');
            }
            $upd['name'] = mb_substr($name, 0, 120);
        }
        if (array_key_exists('zone', $daten)) {
            $upd['zone'] = $this->zoneGueltig($daten['zone']);
        }
        $bin->update($upd);

        return $bin->refresh();
    }

    /** Im Laufweg eine Position nach oben (-1) oder unten (+1). */
    public function stellplatzVerschieben(Team $team, int $binId, int $richtung): void
    {
        $bin = FoodAlchemistStorageBin::where('team_id', $team->id)->findOrFail($binId);
        $liste = $this->stellplaetze($team, (int) $bin->inventory_location_id)->values();
        $i = $liste->search(fn ($b) => $b->id === $bin->id);
        $j = $i + ($richtung < 0 ? -1 : 1);
        if ($i === false || $j < 0 || $j >= $liste->count()) {
            return;
        }
        $ordnung = $liste->pluck('id')->all();
        [$ordnung[$i], $ordnung[$j]] = [$ordnung[$j], $ordnung[$i]];
        DB::transaction(function () use ($ordnung) {
            foreach ($ordnung as $k => $id) {
                FoodAlchemistStorageBin::whereKey($id)->update(['sort_order' => ($k + 1) * 10]);
            }
        });
    }

    /** Stellplatz löschen; seine Grundprodukte verlieren den Stammplatz. */
    public function stellplatzLoeschen(Team $team, int $binId): void
    {
        $bin = FoodAlchemistStorageBin::where('team_id', $team->id)->findOrFail($binId);
        DB::transaction(function () use ($bin) {
            FoodAlchemistStorageBinItem::where('storage_bin_id', $bin->id)->delete();
            $bin->delete();
        });
    }

    // ── Stammplätze ─────────────────────────────────────────────────────────

    /**
     * Grundprodukte einem Stellplatz zuordnen ($binId null = Stammplatz entfernen).
     *
     * @param list<int> $gpIds
     */
    public function zuordnen(Team $team, int $locationId, array $gpIds, ?int $binId, string $quelle = 'manuell'): int
    {
        FoodAlchemistInventoryLocation::where('team_id', $team->id)->findOrFail($locationId);
        if ($binId !== null) {
            FoodAlchemistStorageBin::where('team_id', $team->id)->where('inventory_location_id', $locationId)->findOrFail($binId);
        }
        $gpIds = array_values(array_unique(array_map('intval', $gpIds)));
        $sichtbar = FoodAlchemistGp::visibleToTeam($team)->whereIn('id', $gpIds ?: [0])->pluck('id')->all();

        return DB::transaction(function () use ($team, $locationId, $sichtbar, $binId, $quelle) {
            if ($binId === null) {
                return FoodAlchemistStorageBinItem::where('team_id', $team->id)->where('inventory_location_id', $locationId)
                    ->whereIn('gp_id', $sichtbar ?: [0])->delete();
            }
            foreach ($sichtbar as $gpId) {
                FoodAlchemistStorageBinItem::updateOrCreate(
                    ['team_id' => $team->id, 'inventory_location_id' => $locationId, 'gp_id' => $gpId],
                    ['storage_bin_id' => $binId, 'source' => $quelle],
                );
            }

            return count($sichtbar);
        });
    }

    /** @return array<int, int> gp_id => storage_bin_id */
    public function stammplaetze(Team $team, int $locationId): array
    {
        return FoodAlchemistStorageBinItem::where('team_id', $team->id)->where('inventory_location_id', $locationId)
            ->pluck('storage_bin_id', 'gp_id')->map(fn ($v) => (int) $v)->all();
    }

    /**
     * Grundprodukte des Lagerorts zum Einrichten: alles mit Bestand, mit Stammplatz oder (am
     * Standardlager) in den letzten 90 Tagen eingekauft. Mit Zone-Vorschlag und Filterfeldern.
     *
     * @return list<array{gp_id:int, name:string, zustand:?string, warengruppe:?string, lieferant_id:?int, bin_id:?int, zone_vorschlag:?string, bin_vorschlag:?int}>
     */
    public function artikel(Team $team, int $locationId): array
    {
        $location = FoodAlchemistInventoryLocation::where('team_id', $team->id)->findOrFail($locationId);
        $stamm = $this->stammplaetze($team, $locationId);
        $ids = array_merge(
            FoodAlchemistInventoryStock::where('team_id', $team->id)->where('inventory_location_id', $locationId)
                ->whereNotNull('gp_id')->pluck('gp_id')->all(),
            array_keys($stamm),
        );
        if ($location->is_default) {
            $ids = array_merge($ids, FoodAlchemistPurchaseTransaction::where('team_id', $team->id)->whereNotNull('gp_id')
                ->whereDate('purchased_at', '>=', now()->subDays(InventurService::VORBELEGUNG_TAGE)->toDateString())
                ->distinct()->pluck('gp_id')->all());
        }
        $gps = FoodAlchemistGp::visibleToTeam($team)->whereIn('id', array_unique($ids) ?: [0])
            ->with('leadLa:id,supplier_id')
            ->get(['id', 'name', 'condition', 'commodity_group_code', 'lead_la_supplier_item_id']);
        $bins = $this->stellplaetze($team, $locationId);

        return $gps->map(function ($gp) use ($stamm, $bins) {
            $zone = $this->zoneFuer($gp);

            return [
                'gp_id' => (int) $gp->id, 'name' => (string) $gp->name,
                'zustand' => $gp->condition ?: null, 'warengruppe' => $gp->commodity_group_code ?: null,
                'lieferant_id' => $gp->leadLa?->supplier_id !== null ? (int) $gp->leadLa->supplier_id : null,
                'bin_id' => $stamm[$gp->id] ?? null,
                'zone_vorschlag' => $zone,
                'bin_vorschlag' => $this->binFuerZone($bins, $zone)?->id,
            ];
        })->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values()->all();
    }

    /**
     * Vorschlag übernehmen: Grundprodukte OHNE Stammplatz bekommen den ersten Stellplatz ihrer
     * Zone. Bestehende Zuordnungen bleiben unangetastet.
     */
    public function vorschlagUebernehmen(Team $team, int $locationId): int
    {
        $n = 0;
        foreach (collect($this->artikel($team, $locationId))->whereNull('bin_id')->whereNotNull('bin_vorschlag')->groupBy('bin_vorschlag') as $binId => $rows) {
            $n += $this->zuordnen($team, $locationId, $rows->pluck('gp_id')->all(), (int) $binId, 'vorschlag');
        }

        return $n;
    }

    /** Zone aus Zustand + Warengruppe. null = keine Aussage möglich. */
    public function zoneFuer(FoodAlchemistGp $gp): ?string
    {
        $zustand = mb_strtolower(trim((string) $gp->condition));
        $wg = (string) $gp->commodity_group_code;

        return match (true) {
            $zustand === 'tk' => 'tk',
            $wg === '15' => 'getraenke',
            $zustand === 'frisch' && in_array($wg, self::WG_KUEHL, true) => 'kuehl',
            $zustand === 'frisch' => 'trocken',          // frische Backwaren, Eier-Ersatz, …
            in_array($zustand, ['trocken', 'konserviert'], true) => 'trocken',
            default => null,
        };
    }

    // ── Gebinde ─────────────────────────────────────────────────────────────

    /**
     * Gebinde eines Artikels in der Basiseinheit der Zeile: 1 Karton = pack_units Einheiten,
     * 1 Einheit = unit_base g/ml/Stk. null, wenn der Artikel kein zählbares Gebinde hat oder seine
     * Einheit nicht zur Zeile passt.
     *
     * @return array{pack_label:?string, pack_units:?float, unit_label:string, unit_base:float}|null
     */
    public function gebinde(?FoodAlchemistSupplierItem $la, string $baseUnit): ?array
    {
        if ($la === null || (float) $la->qty <= 0) {
            return null;
        }
        $ordering = mb_strtolower(trim((string) $la->ordering_unit));
        if ($ordering === '' || in_array($ordering, self::MASS_EINHEITEN, true)) {
            return null;
        }
        [$base, $faktor] = match (mb_strtolower(trim((string) $la->unit_code))) {
            'kg' => ['g', 1000.0], 'g' => ['g', 1.0],
            'l' => ['ml', 1000.0], 'ml' => ['ml', 1.0],
            'stk', 'stück', 'st' => ['Stk', 1.0],
            default => ['', 0.0],
        };
        if ($base !== $baseUnit) {
            return null;
        }
        $units = (float) $la->qty_ordering_per_packaging;

        return [
            'pack_label' => $units > 1 ? $this->label((string) $la->packaging_unit, 'Karton') : null,
            'pack_units' => $units > 1 ? round($units, 3) : null,
            'unit_label' => $this->label((string) $la->ordering_unit, 'Einheit'),
            'unit_base' => round((float) $la->qty * $faktor, 4),
        ];
    }

    /** Lesbare Gebinde-Bezeichnung aus Necta-Codes. */
    public function label(string $code, string $fallback): string
    {
        $c = mb_strtolower(trim($code));

        return match (true) {
            $c === '' => $fallback,
            in_array($c, ['kt', 'kart', 'ct', 'karton', 'krt', 'car'], true) => 'Karton',
            in_array($c, ['ki', 'kiste', 'cr'], true) => 'Kiste',
            in_array($c, ['fl', 'fla', 'flasche', 'bo', 'btl.'], true) => 'Flasche',
            in_array($c, ['ds', 'dose', 'can', 'tin'], true) => 'Dose',
            in_array($c, ['pce', 'st', 'stk', 'stück', 'stueck', 'pc'], true) => 'Stück',
            in_array($c, ['pk', 'pck', 'packung', 'pa', 'pak'], true) => 'Packung',
            in_array($c, ['bt', 'btl', 'beutel', 'bag', 'po'], true) => 'Beutel',
            in_array($c, ['ei', 'eimer', 'bu'], true) => 'Eimer',
            in_array($c, ['gl', 'glas', 'jar'], true) => 'Glas',
            in_array($c, ['sch', 'schale', 'tray', 'ta'], true) => 'Schale',
            in_array($c, ['ve', 'gebinde'], true) => 'Gebinde',
            default => mb_strlen($code) <= 12 ? $code : $fallback,
        };
    }

    // ── intern ──────────────────────────────────────────────────────────────

    private function binFuerZone(Collection $bins, ?string $zone): ?FoodAlchemistStorageBin
    {
        if ($zone === null) {
            return null;
        }
        $aktive = $bins->where('is_active', true);

        return $aktive->firstWhere('zone', $zone)
            ?? ($zone === 'getraenke' ? $aktive->firstWhere('zone', 'trocken') : null);
    }

    private function zoneGueltig(?string $zone): ?string
    {
        $zone = $zone !== null ? trim($zone) : null;
        if ($zone === null || $zone === '') {
            return null;
        }
        if (! array_key_exists($zone, FoodAlchemistStorageBin::ZONEN)) {
            throw new \RuntimeException('Unbekannte Zone: ' . $zone);
        }

        return $zone;
    }
}
