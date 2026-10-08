<?php

namespace Platform\FoodAlchemist\Services;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistGpLagerartikel;
use Platform\FoodAlchemist\Models\FoodAlchemistInventoryStock;

/**
 * Spec 74 · Lagerartikel (Grundvorrat): Gewürze, Öle, Salz … gehen nicht mit jedem Rezept in die Bestellung,
 * sondern werden nachgefüllt, wenn der Bestand unter den Mindestbestand fällt — auf den Sollbestand.
 * Mengen in Basiseinheit (g/ml/Stk), Eingabe/Anzeige in kg/l/Stk.
 */
class LagerartikelService
{
    public function __construct(private InventurService $inventur) {}

    /** Setzen/ändern. $min/$soll in kg/l/Stk (leer = ohne). ist=false entfernt die Markierung. */
    public function setzen(Team $team, int $gpId, bool $ist, mixed $min = null, mixed $soll = null): ?FoodAlchemistGpLagerartikel
    {
        $gp = FoodAlchemistGp::visibleToTeam($team)->with('leadLa:id,unit_code')->findOrFail($gpId);
        $vorhanden = FoodAlchemistGpLagerartikel::where('team_id', $team->id)->where('gp_id', $gp->id)->first();
        if (! $ist) {
            $vorhanden?->delete();

            return null;
        }
        $base = $vorhanden?->base_unit ?: $this->basis($team, $gp);
        $faktor = in_array($base, ['g', 'ml'], true) ? 1000 : 1;
        $min = $this->zahl($min, 'Mindestbestand');
        $soll = $this->zahl($soll, 'Sollbestand');
        if ($min !== null && $soll !== null && $soll < $min) {
            throw new \RuntimeException('Der Sollbestand muss mindestens so hoch sein wie der Mindestbestand.');
        }
        $daten = ['ist_lagerartikel' => true, 'base_unit' => $base,
            'mindestbestand' => $min !== null ? $min * $faktor : null, 'sollbestand' => $soll !== null ? $soll * $faktor : null];

        return $vorhanden !== null
            ? tap($vorhanden)->update($daten)
            : FoodAlchemistGpLagerartikel::create(['team_id' => $team->id, 'gp_id' => $gp->id] + $daten);
    }

    /** Mehrere Grundprodukte auf einmal als Lagerartikel markieren (ohne Mengen). @return int Anzahl neu */
    public function markieren(Team $team, array $gpIds): int
    {
        $neu = 0;
        foreach (array_unique(array_map('intval', $gpIds)) as $id) {
            if (! FoodAlchemistGpLagerartikel::where('team_id', $team->id)->where('gp_id', $id)->exists()) {
                $this->setzen($team, $id, true);
                $neu++;
            }
        }

        return $neu;
    }

    /** @return array<int, true> gp_id-Set der Lagerartikel dieses Betriebs */
    public function ids(Team $team): array
    {
        return FoodAlchemistGpLagerartikel::where('team_id', $team->id)->where('ist_lagerartikel', true)
            ->pluck('gp_id')->mapWithKeys(fn ($id) => [(int) $id => true])->all();
    }

    /**
     * Alle Lagerartikel mit Bestand und Ampel: leer | unter_min | ok | ohne_min.
     *
     * @return list<array{gp_id:int, name:string, base:string, einheit:string, bestand:float, min:?float, soll:?float, status:string, nachfuellen_basis:float}>
     */
    public function liste(Team $team): array
    {
        $alle = FoodAlchemistGpLagerartikel::where('team_id', $team->id)->where('ist_lagerartikel', true)->with('gp:id,name')->get();
        if ($alle->isEmpty()) {
            return [];
        }
        $bestand = FoodAlchemistInventoryStock::query()
            ->where('foodalchemist_inventory_stocks.team_id', $team->id)->whereIn('foodalchemist_inventory_stocks.gp_id', $alle->pluck('gp_id'))
            ->whereNull('foodalchemist_inventory_stocks.supplier_item_id')
            ->join('foodalchemist_inventory_locations as l', 'l.id', '=', 'foodalchemist_inventory_stocks.inventory_location_id')
            ->where('l.is_active', true)->whereNull('l.deleted_at')
            ->selectRaw('foodalchemist_inventory_stocks.gp_id, foodalchemist_inventory_stocks.base_unit, SUM(qty_base) as menge')
            ->groupBy('foodalchemist_inventory_stocks.gp_id', 'foodalchemist_inventory_stocks.base_unit')->get()
            ->groupBy('gp_id');
        $out = [];
        foreach ($alle as $a) {
            $b = (float) (($bestand[$a->gp_id] ?? collect())->firstWhere('base_unit', $a->base_unit)?->menge ?? 0);
            $min = $a->mindestbestand !== null ? (float) $a->mindestbestand : null;
            $soll = $a->sollbestand !== null ? (float) $a->sollbestand : null;
            $status = $min === null ? 'ohne_min' : ($b <= 0 ? 'leer' : ($b < $min ? 'unter_min' : 'ok'));
            $out[] = [
                'gp_id' => (int) $a->gp_id, 'name' => (string) ($a->gp?->name ?? '—'), 'base' => $a->base_unit,
                'einheit' => $this->inventur->anzeigeEinheit($a->base_unit),
                'bestand' => (float) $this->inventur->anzeigeMenge($b, $a->base_unit),
                'min' => $min !== null ? (float) $this->inventur->anzeigeMenge($min, $a->base_unit) : null,
                'soll' => $soll !== null ? (float) $this->inventur->anzeigeMenge($soll, $a->base_unit) : null,
                'status' => $status,
                // Nachfüllen nur unter Mindestbestand: auf Soll (ohne Soll: auf das Doppelte des Mindestbestands)
                'nachfuellen_basis' => in_array($status, ['leer', 'unter_min'], true) ? max(0.0, ($soll ?? 2 * $min) - $b) : 0.0,
            ];
        }
        usort($out, fn ($x, $y) => [array_search($x['status'], ['leer', 'unter_min', 'ohne_min', 'ok']), $x['name']] <=> [array_search($y['status'], ['leer', 'unter_min', 'ohne_min', 'ok']), $y['name']]);

        return $out;
    }

    /** Lagerartikel unter Mindestbestand (für Signal und Bestellrunde). */
    public function unterMindest(Team $team): array
    {
        return array_values(array_filter($this->liste($team), fn ($r) => in_array($r['status'], ['leer', 'unter_min'], true)));
    }

    /** Warengruppen, die typischerweise Grundvorrat sind (Name enthält …). */
    public const TYPISCH = ['gewürz', 'gewuerz', 'würz', 'öl', 'oel', 'essig', 'salz', 'fett', 'backzutat', 'zucker', 'mehl', 'trockenware', 'saucen', 'soßen', 'konserven'];

    /**
     * Vorschlag nach Warengruppe: Grundprodukte, die dieser Betrieb nutzt (Bestand oder in den letzten 365 Tagen bestellt)
     * und die noch kein Lagerartikel sind — je Warengruppe gezählt, typische Vorrats-Gruppen zuerst.
     *
     * @return list<array{code:string, name:string, anzahl:int, typisch:bool}>
     */
    public function warengruppenVorschlag(Team $team): array
    {
        $ids = $this->genutzteGpIds($team);
        if ($ids === []) {
            return [];
        }
        $schon = $this->ids($team);
        $zaehl = FoodAlchemistGp::whereIn('id', array_values(array_diff($ids, array_keys($schon))))->whereNotNull('commodity_group_code')
            ->selectRaw('commodity_group_code, COUNT(*) as n')->groupBy('commodity_group_code')->pluck('n', 'commodity_group_code');
        $namen = \Platform\FoodAlchemist\Models\FoodAlchemistLookupWarengruppe::whereIn('code', $zaehl->keys())->pluck('name', 'code');
        $out = [];
        foreach ($zaehl as $code => $n) {
            $name = (string) ($namen[$code] ?? $code);
            $low = mb_strtolower($name);
            $out[] = ['code' => (string) $code, 'name' => $name, 'anzahl' => (int) $n,
                'typisch' => collect(self::TYPISCH)->contains(fn ($t) => str_contains($low, $t))];
        }
        usort($out, fn ($a, $b) => [$b['typisch'], $b['anzahl']] <=> [$a['typisch'], $a['anzahl']]);

        return $out;
    }

    /** Alle genutzten Grundprodukte einer Warengruppe als Lagerartikel markieren. @return int Anzahl neu */
    public function warengruppeMarkieren(Team $team, string $code): int
    {
        $ids = FoodAlchemistGp::whereIn('id', $this->genutzteGpIds($team))->where('commodity_group_code', $code)->pluck('id')->all();

        return $this->markieren($team, $ids);
    }

    /** @return list<int> GPs mit Bestand oder Bestellung (365 Tage) in diesem Betrieb */
    private function genutzteGpIds(Team $team): array
    {
        $bestand = FoodAlchemistInventoryStock::where('team_id', $team->id)->whereNotNull('gp_id')->pluck('gp_id');
        $bestellt = \Platform\FoodAlchemist\Models\FoodAlchemistOrderLine::where('team_id', $team->id)->whereNotNull('gp_id')
            ->where('created_at', '>=', now()->subDays(365))->distinct()->pluck('gp_id');

        return $bestand->concat($bestellt)->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    private function basis(Team $team, FoodAlchemistGp $gp): string
    {
        $vorhanden = FoodAlchemistInventoryStock::where('team_id', $team->id)->where('gp_id', $gp->id)->whereNull('supplier_item_id')
            ->orderByDesc('qty_base')->value('base_unit');

        return $vorhanden ?: ($this->inventur->basisEinheit((string) ($gp->leadLa?->unit_code ?? 'kg')) ?: 'g');
    }

    private function zahl(mixed $v, string $was): ?float
    {
        $roh = trim(str_replace(',', '.', (string) ($v ?? '')));
        if ($roh === '') {
            return null;
        }
        if (! is_numeric($roh) || (float) $roh < 0) {
            throw new \RuntimeException($was . ' braucht eine Zahl ≥ 0.');
        }

        return (float) $roh;
    }
}
