<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\Core\Models\User;
use Platform\FoodAlchemist\Enums\FaRolle;
use Platform\FoodAlchemist\Models\FoodAlchemistAbo;
use Platform\FoodAlchemist\Support\AboSicht;
use Platform\FoodAlchemist\Support\MasterTeam;
use Platform\FoodAlchemist\Support\StammPaket;

/**
 * Spec 82 §8 / §12 A · Kurator-Aufsatz, Durchstich Schritt 1: Abo = Sammlung des Master-Teams, live und nur
 * lesend beim Mandanten. Eigene Zugriffsregel (InhaltsFreigabeService::freigeben lässt nur Unter-Teams zu).
 *
 * Regeln:
 * - Abos vergibt nur das Master-Team, mit FA-Rolle „Freigeben" (§13 C2).
 * - Abonnent ist immer ein Kunden-Hauptteam, nie ein Unter-Team (§12 A2), nie der Master selbst.
 * - Ein Abo setzt ein gebuchtes Stamm-Paket voraus (Entscheidung 1 = C, 13.10.).
 * - Die Hülle enthält NUR veröffentlichte Fassungs-Zeilen (ist_fassung = 1) samt Unter-Rezepten — nie die
 *   Arbeitszeile des Masters mit ihren ek_* (Leck-Sperre, Bauplan §3b). Ohne Veröffentlichung (Schritt 2) ist
 *   sie leer.
 */
class AboService
{
    public function __construct(
        private FaRechte $rechte,
        private InhaltsFreigabeService $freigaben,
        private PlattformProtokoll $protokoll,
    ) {}

    public function abonnieren(Team $master, Team $mandant, int $sammlungId, ?User $actor): FoodAlchemistAbo
    {
        if (! MasterTeam::istMaster($master)) {
            throw new \RuntimeException('Abos vergibt nur das Master-Team.');
        }
        $this->rechte->pruefe($actor, $master, FaRolle::Freigeben, 'Abos vergeben');
        if ((int) $mandant->id === (int) $master->id) {
            throw new \RuntimeException('Das Master-Team kann sich nicht selbst abonnieren.');
        }
        if ((int) $this->rechte->kundenHauptTeam($mandant)->id !== (int) $mandant->id) {
            throw new \RuntimeException('Ein Abo hat immer das Oberteam (Kunden-Hauptteam), nie ein Unter-Team (Spec 82 §12 A2).');
        }
        if (! StammPaket::istFreigeschaltet($mandant)) {
            throw new \RuntimeException('Ein Abo setzt ein gebuchtes Stamm-Paket voraus (Spec 82, Entscheidung 1 = C).');
        }
        $sammlung = DB::table('foodalchemist_sammlungen')->where('id', $sammlungId)->whereNull('deleted_at')->first();
        if ($sammlung === null || (int) $sammlung->team_id !== (int) $master->id) {
            throw new \RuntimeException('Abonnieren lassen sich nur Sammlungen des Master-Teams.');
        }

        $abo = DB::transaction(function () use ($master, $mandant, $sammlungId, $actor) {
            $abo = FoodAlchemistAbo::firstOrNew(['mandant_team_id' => $mandant->id, 'sammlung_id' => $sammlungId]);
            $abo->forceFill([
                'master_team_id' => $master->id,
                'status' => FoodAlchemistAbo::AKTIV,
                'seit' => $abo->status === FoodAlchemistAbo::AKTIV && $abo->seit ? $abo->seit : now(),
                'beendet_at' => null, 'beendet_von' => null,
                'angelegt_von' => $abo->exists ? $abo->angelegt_von : $actor?->id,
            ])->save();

            return $abo;
        });
        $this->neuBerechnen((int) $mandant->id);
        $this->protokoll->schreibe('abo_vergeben', ['master_team_id' => $master->id, 'mandant_team_id' => $mandant->id,
            'sammlung_id' => $sammlungId], $actor, $abo);   // §13 C4: ins echte activity-log, am Abo selbst

        return $abo->refresh();
    }

    /**
     * Abos aus Sicht eines Teams: das Master-Team sieht alle seine, jedes andere Team die seines Kunden-Hauptteams.
     *
     * @return Collection<int, array{id:int, master_team_id:int, mandant_team_id:int, sammlung_id:int, sammlung:string, status:string, seit:?string}>
     */
    public function abos(Team $team, bool $nurAktiv = true): Collection
    {
        $q = DB::table('foodalchemist_abos AS a')
            ->leftJoin('foodalchemist_sammlungen AS s', 's.id', '=', 'a.sammlung_id')
            ->when($nurAktiv, fn ($w) => $w->where('a.status', FoodAlchemistAbo::AKTIV))
            ->orderBy('a.mandant_team_id')->orderBy('a.id')
            ->select('a.*', 's.name AS sammlung_name');
        MasterTeam::istMaster($team)
            ? $q->where('a.master_team_id', $team->id)
            : $q->where('a.mandant_team_id', $this->rechte->kundenHauptTeam($team)->id);

        return $q->get()->map(fn ($r) => [
            'id' => (int) $r->id, 'master_team_id' => (int) $r->master_team_id, 'mandant_team_id' => (int) $r->mandant_team_id,
            'sammlung_id' => (int) $r->sammlung_id, 'sammlung' => (string) ($r->sammlung_name ?? '—'),
            'status' => (string) $r->status, 'seit' => $r->seit,
        ]);
    }

    /** Hülle aller Mandanten neu rechnen (z. B. nach Veröffentlichung, Schritt 2). */
    public function neuBerechnenAlle(): void
    {
        $mandanten = DB::table('foodalchemist_abos')->where('status', FoodAlchemistAbo::AKTIV)->distinct()->pluck('mandant_team_id');
        foreach ($mandanten as $id) {
            $this->neuBerechnen((int) $id);
        }
        DB::table('foodalchemist_abo_objekte')->whereNotIn('mandant_team_id', $mandanten->all() ?: [0])->delete();
        AboSicht::vergessen();
    }

    /** @return array<string, list<int>> gespeicherte Hülle des Mandanten */
    public function neuBerechnen(int $mandantTeamId): array
    {
        $huelle = $this->huelle($mandantTeamId);
        DB::transaction(function () use ($mandantTeamId, $huelle) {
            DB::table('foodalchemist_abo_objekte')->where('mandant_team_id', $mandantTeamId)->delete();
            $zeilen = [];
            foreach ($huelle as $typ => $ids) {
                foreach ($ids as $id) {
                    $zeilen[] = ['mandant_team_id' => $mandantTeamId, 'typ' => $typ, 'objekt_id' => $id];
                }
            }
            foreach (array_chunk($zeilen, 500) as $teil) {
                DB::table('foodalchemist_abo_objekte')->insert($teil);
            }
        });
        AboSicht::vergessen();

        return $huelle;
    }

    /**
     * Hülle eines Mandanten: für jedes Rezept aus seinen aktiven Abo-Sammlungen die NEUESTE veröffentlichte Fassung,
     * dazu deren Unter-Rezepte (Fassungs-Zeilen zeigen auf Fassungs-Zeilen, Schritt 2). Alles, was keine Fassung ist,
     * fällt am Ende heraus — auch wenn ein Unter-Rezept-Verweis versehentlich auf eine Arbeitszeile zeigt.
     *
     * @return array<string, list<int>>
     */
    public function huelle(int $mandantTeamId, ?int $ohneAboId = null, ?array $ohneSammlungsObjekt = null): array
    {
        // „Was bliebe ohne dieses Abo bzw. ohne dieses Sammlungs-Objekt (['sammlung_id','objekt_id'])?" — Kündigung/Zurückziehen, §12 D
        $familienJe = function (string $typ) use ($mandantTeamId, $ohneAboId, $ohneSammlungsObjekt): array {
            return DB::table('foodalchemist_sammlung_objekte AS o')
                ->join('foodalchemist_abos AS a', 'a.sammlung_id', '=', 'o.sammlung_id')
                ->join('foodalchemist_sammlungen AS s', 's.id', '=', 'o.sammlung_id')
                ->where('a.mandant_team_id', $mandantTeamId)->where('a.status', FoodAlchemistAbo::AKTIV)
                ->when($ohneAboId !== null, fn ($q) => $q->where('a.id', '!=', $ohneAboId))
                ->when($ohneSammlungsObjekt !== null && $typ === ($ohneSammlungsObjekt['typ'] ?? 'recipe'), fn ($q) => $q->whereNot(fn ($w) => $w->where('o.sammlung_id', $ohneSammlungsObjekt['sammlung_id'])
                    ->where('o.objekt_id', $ohneSammlungsObjekt['objekt_id'])))
                ->whereNull('s.deleted_at')->where('o.typ', $typ)
                ->distinct()->pluck('o.objekt_id')->map(fn ($v) => (int) $v)->all();
        };
        $familien = $familienJe('recipe');
        $paketFamilien = $familienJe('paket');
        $konzeptFamilien = $familienJe('concept');
        if ($familien === [] && $paketFamilien === [] && $konzeptFamilien === []) {
            return [];
        }

        // Konzepte (PR 4, auch kind=paket): je Familie die jüngste Fassung; ihre Slots zeigen auf Fassungen von Paket, eingebettetem
        // Paket-Konzept (rekursiv) und Rezept — alle ziehen in die Hülle mit
        $konzeptFassungen = $this->neuesteFassungenVon('foodalchemist_concepts', $konzeptFamilien);
        $paketFassungen = $this->neuesteFassungenVon('foodalchemist_packages', $paketFamilien);
        $rezeptSeeds = [];
        $offenK = $konzeptFassungen;
        $gesehenK = [];
        while ($offenK !== []) {
            $offenK = array_values(array_diff(array_unique($offenK), $gesehenK));
            if ($offenK === []) {
                break;
            }
            $gesehenK = array_merge($gesehenK, $offenK);
            $slots = DB::table('foodalchemist_concept_slots')->whereIn('concept_id', $offenK)->whereNull('deleted_at')
                ->get(['package_id', 'embedded_concept_id', 'sales_recipe_id']);
            $paketFassungen = array_merge($paketFassungen, $slots->pluck('package_id')->filter()->map(fn ($v) => (int) $v)->all());
            $rezeptSeeds = array_merge($rezeptSeeds, $slots->pluck('sales_recipe_id')->filter()->map(fn ($v) => (int) $v)->all());
            $offenK = $slots->pluck('embedded_concept_id')->filter()->map(fn ($v) => (int) $v)->all();
        }
        $konzeptFassungen = $this->nurFassungen('foodalchemist_concepts', $gesehenK);
        $paketFassungen = $this->nurFassungen('foodalchemist_packages', $paketFassungen);
        // Pakete: ihre Gerichte zeigen auf Rezept-Fassungen und ziehen in die Rezept-Hülle mit
        $paketRezepte = $paketFassungen === [] ? [] : DB::table('foodalchemist_package_dishes')->whereIn('package_id', $paketFassungen)
            ->whereNull('deleted_at')->pluck('sales_recipe_id')->map(fn ($v) => (int) $v)->all();

        $offen = array_merge($this->neuesteFassungen($familien), $rezeptSeeds, $paketRezepte);
        $fertig = [];
        while ($offen !== []) {
            $offen = array_values(array_diff(array_unique($offen), $fertig));
            if ($offen === []) {
                break;
            }
            $fertig = array_merge($fertig, $offen);
            $offen = $this->freigaben->abhaengigkeiten('recipe', $offen)['recipe'] ?? [];
        }

        $nurFassungen = DB::table('foodalchemist_recipes')->whereIn('id', $fertig ?: [0])
            ->where('ist_fassung', true)->whereNull('deleted_at')->orderBy('id')->pluck('id')->map(fn ($v) => (int) $v)->all();

        $huelle = [];
        if ($nurFassungen !== []) {
            $huelle['recipe'] = $nurFassungen;
        }
        if ($paketFassungen !== []) {
            $huelle['paket'] = $paketFassungen;
        }
        if ($konzeptFassungen !== []) {
            $huelle['concept'] = $konzeptFassungen;
        }

        return $huelle;
    }

    /**
     * Je Familie (Arbeitszeile) die jüngste nicht gelöschte Fassungs-Zeile der Tabelle (concepts | packages).
     *
     * @param  list<int>  $familien
     * @return list<int>
     */
    private function neuesteFassungenVon(string $tabelle, array $familien): array
    {
        if ($familien === []) {
            return [];
        }

        return DB::table($tabelle)->whereIn('fassung_von_id', $familien)
            ->where('ist_fassung', true)->whereNull('deleted_at')
            ->groupBy('fassung_von_id')->selectRaw('MAX(id) AS id')
            ->pluck('id')->map(fn ($v) => (int) $v)->all();
    }

    /**
     * Alles, was keine Fassung ist, fällt heraus — auch wenn ein Verweis versehentlich auf eine Arbeitszeile zeigt (Leck-Sperre).
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function nurFassungen(string $tabelle, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table($tabelle)->whereIn('id', array_unique($ids))->where('ist_fassung', true)->whereNull('deleted_at')
            ->orderBy('id')->pluck('id')->map(fn ($v) => (int) $v)->all();
    }

    /**
     * Je Rezept-Familie (Arbeitszeile) die jüngste nicht gelöschte Fassungs-Zeile.
     *
     * @param  list<int>  $familien
     * @return list<int>
     */
    private function neuesteFassungen(array $familien): array
    {
        return DB::table('foodalchemist_recipes')->whereIn('fassung_von_id', $familien)
            ->where('ist_fassung', true)->whereNull('deleted_at')
            ->groupBy('fassung_von_id')->selectRaw('MAX(id) AS id')
            ->pluck('id')->map(fn ($v) => (int) $v)->all();
    }
}
