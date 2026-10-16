<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierGroup;
use Platform\FoodAlchemist\Support\LieferantenGruppen;

/**
 * Lieferanten-Gruppen: Seed (idempotent, Kommando statt Migration — Spec 82 §6), Vorschlag aus Necta-Branche und Name, Zuordnung von Hand.
 * Ein Lieferant hat genau eine Hauptgruppe. Von Hand gesetzt (`group_source = manuell`) gewinnt gegen jeden Vorschlag.
 */
class SupplierGroupService
{
    /**
     * Die sechs Seed-Gruppen als GLOBALEN Basis-Katalog anlegen (team_id NULL): die Lieferanten (auf io der Stamm) sind global, und eine
     * Gruppe, die ein anderes Team nicht sehen darf, würde dort als „Gruppe fehlt“ erscheinen. Idempotent über den Code (die MySQL-Unique
     * greift bei NULL nicht).
     *
     * @return array{angelegt: list<string>, vorhanden: int}
     */
    public function einrichten(bool $schreiben): array
    {
        $vorhanden = FoodAlchemistSupplierGroup::query()->whereNull('team_id')->pluck('code')->all();
        $out = ['angelegt' => [], 'vorhanden' => count($vorhanden)];
        $i = 0;
        foreach (LieferantenGruppen::seed() as $code => $name) {
            $i++;
            if (in_array($code, $vorhanden, true)) {
                continue;
            }
            $out['angelegt'][] = $name;
            if ($schreiben) {
                FoodAlchemistSupplierGroup::create(['team_id' => null, 'code' => $code, 'name' => $name, 'sort_order' => $i]);
            }
        }

        return $out;
    }

    /** Aktive Gruppen, die das Team sieht (eigene + Basis-Katalog), in Anzeige-Reihenfolge. */
    public function liste(Team $team)
    {
        return FoodAlchemistSupplierGroup::visibleToTeam($team)->where('is_inactive', false)->orderBy('sort_order')->orderBy('name')->get();
    }

    /** Gruppe per Id ODER Code auflösen (MCP/UI); null = nicht gefunden. */
    public function finde(Team $team, int|string|null $idOderCode): ?FoodAlchemistSupplierGroup
    {
        if ($idOderCode === null || $idOderCode === '') {
            return null;
        }
        $q = FoodAlchemistSupplierGroup::visibleToTeam($team);

        return is_numeric($idOderCode) ? $q->find((int) $idOderCode) : $q->where('code', strtoupper(trim((string) $idOderCode)))->first();
    }

    /**
     * Vorschlags-Lauf über die globalen Lieferanten (Stamm) und, mit `$team`, die Lieferanten dieses Teams: Gruppe aus Branche und Name. Von Hand gesetzte Gruppen bleiben (immer), schon vergebene
     * Regel-Gruppen nur mit `$neuBewerten`. Unklare Lieferanten bleiben ohne Gruppe und stehen in der Rückgabe (Prüfliste).
     *
     * @return array{gesamt:int, vorgeschlagen:array<string,int>, unklar:list<array{id:int,name:string,branch:?string}>, uebersprungen_manuell:int, geschrieben:int}
     */
    public function vorschlagen(?Team $team, bool $schreiben, bool $neuBewerten = false): array
    {
        $gruppen = FoodAlchemistSupplierGroup::query()->whereNull('team_id')->pluck('id', 'code')->all();   // der globale Basis-Katalog
        $out = ['gesamt' => 0, 'vorgeschlagen' => [], 'unklar' => [], 'uebersprungen_manuell' => 0, 'geschrieben' => 0];

        FoodAlchemistSupplier::query()->where(fn ($w) => $team === null ? $w->whereNull('team_id') : $w->whereNull('team_id')->orWhere('team_id', $team->id))
            ->orderBy('id')->each(function (FoodAlchemistSupplier $s) use (&$out, $gruppen, $schreiben, $neuBewerten) {
            $out['gesamt']++;
            if ($s->group_source === 'manuell') {
                $out['uebersprungen_manuell']++;

                return;
            }
            if ($s->group_id !== null && ! $neuBewerten) {
                return;
            }
            $code = LieferantenGruppen::vorschlag($s->branch, $s->name);
            if ($code === null || ! isset($gruppen[$code])) {
                $out['unklar'][] = ['id' => (int) $s->id, 'name' => (string) $s->name, 'branch' => $s->branch];

                return;
            }
            $out['vorgeschlagen'][$code] = ($out['vorgeschlagen'][$code] ?? 0) + 1;
            if ($schreiben) {
                DB::table('foodalchemist_suppliers')->where('id', $s->id)->update(['group_id' => $gruppen[$code], 'group_source' => 'regel', 'updated_at' => now()]);
                $out['geschrieben']++;
            }
        });

        return $out;
    }

    /** Anzahl Lieferanten je Gruppe (für Filter/Zähler), sichtbar für das Team. */
    public function zaehler(Team $team): array
    {
        return FoodAlchemistSupplier::visibleToTeam($team)->selectRaw('group_id, COUNT(*) AS n')->groupBy('group_id')->pluck('n', 'group_id')->map(fn ($n) => (int) $n)->all();
    }
}
