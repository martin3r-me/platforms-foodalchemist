<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\Core\Models\User;
use Platform\FoodAlchemist\Enums\FaRolle;
use Platform\FoodAlchemist\Jobs\RecomputeTeamRecipesJob;
use Platform\FoodAlchemist\Models\FoodAlchemistOutlet;
use Platform\FoodAlchemist\Models\FoodAlchemistTeamBetrieb;

/**
 * Spec 77c · Standorte = Unter-Teams. Zwei Brillen:
 *  - Team-Brille (nur im Oberteam): welcher Standort gelesen wird — eigen | alle | ein Unter-Team.
 *    `leseTeamIds()` liefert die Team-IDs für Listen und Auswertungen. Schreiben bleibt immer im
 *    besitzenden Team (Besitz-Prüfungen der Services unverändert). Geschwister sehen einander nicht.
 *  - Betriebs-Brille: im Unter-Team FEST auf den zugeordneten Betrieb des Oberteams
 *    (`zugeordneterBetrieb()`, von ActiveOutletContext genutzt).
 * Bewusst NICHT über `visibleToTeam` (daran hängen Referenz- und Schreibprüfungen von 130 Models).
 */
class StandortService
{
    public function __construct(private FaRechte $rechte) {}

    /** @return list<int> alle Unter-Teams (Nachfahren) ohne das Team selbst */
    public function unterTeamIds(Team $team): array
    {
        return array_values(array_filter(RecomputeTeamRecipesJob::teamUndNachfahren((int) $team->id), fn ($id) => $id !== (int) $team->id));
    }

    /** @return list<array{id:int, name:string, betrieb_id:?int, betrieb:?string}> */
    public function unterTeams(Team $team): array
    {
        $ids = $this->unterTeamIds($team);
        if ($ids === []) {
            return [];
        }
        $zuordnung = FoodAlchemistTeamBetrieb::whereIn('team_id', $ids)->pluck('outlet_id', 'team_id');
        $betriebe = FoodAlchemistOutlet::whereIn('id', $zuordnung->values())->pluck('name', 'id');

        return Team::whereIn('id', $ids)->orderBy('name')->get(['id', 'name'])->map(fn ($t) => [
            'id' => (int) $t->id, 'name' => (string) $t->name,
            'betrieb_id' => isset($zuordnung[$t->id]) ? (int) $zuordnung[$t->id] : null,
            'betrieb' => isset($zuordnung[$t->id]) ? ($betriebe[$zuordnung[$t->id]] ?? null) : null,
        ])->all();
    }

    /** Fester Betrieb eines Unter-Teams (Betrieb eines Vorfahren, aktiv) oder null. */
    public function zugeordneterBetrieb(Team $team): ?FoodAlchemistOutlet
    {
        if ($team->parent_team_id === null) {
            return null;
        }
        $outletId = FoodAlchemistTeamBetrieb::where('team_id', $team->id)->value('outlet_id');
        if ($outletId === null) {
            return null;
        }

        return FoodAlchemistOutlet::whereIn('team_id', $this->rechte->teamKette($team))->where('is_inactive', false)->find($outletId);
    }

    /** Oberteam ordnet einem Unter-Team einen seiner Betriebe zu (null = Standard, erbt komplett). FA-Admin des Oberteams. */
    public function betriebZuordnen(Team $oberteam, int $unterTeamId, ?int $outletId, ?User $actor): void
    {
        $this->rechte->pruefe($actor, $oberteam, FaRolle::Admin, 'Betrieb einem Standort zuordnen');
        if (! in_array($unterTeamId, $this->unterTeamIds($oberteam), true)) {
            throw new \RuntimeException('Dieses Team ist kein Unter-Team (Standort) dieses Teams.');
        }
        if ($outletId === null) {
            FoodAlchemistTeamBetrieb::where('team_id', $unterTeamId)->forceDelete();

            return;
        }
        if (! FoodAlchemistOutlet::where('team_id', $oberteam->id)->where('is_inactive', false)->whereKey($outletId)->exists()) {
            throw new \RuntimeException('Betrieb nicht gefunden — zuordnen lassen sich nur aktive Betriebe des Oberteams.');
        }
        FoodAlchemistTeamBetrieb::withTrashed()->updateOrCreate(['team_id' => $unterTeamId],
            ['outlet_id' => $outletId, 'set_by' => $actor?->id, 'deleted_at' => null]);
    }

    // ── Team-Brille ──

    /** @return array{modus:string, team_id:?int} eigen | alle | team (+ team_id) */
    public function brille(Team $viewer, ?int $userId = null): array
    {
        $uid = $userId ?? auth()->id();
        $eigen = ['modus' => 'eigen', 'team_id' => null];
        if ($uid === null || isset($this->nurEigen[(int) $viewer->id]) || $this->unterTeamIds($viewer) === []) {
            return $eigen;
        }
        $skey = 'fa.team_brille.'.$viewer->id;
        $wert = session()->has($skey) ? session($skey) : null;
        if ($wert === null) {
            $row = DB::table('foodalchemist_team_brillen')->where('user_id', $uid)->where('team_id', $viewer->id)->first();
            $wert = $row !== null ? ['modus' => $row->sicht, 'team_id' => $row->sicht_team_id !== null ? (int) $row->sicht_team_id : null] : null;
        }
        if (! is_array($wert) || ! in_array($wert['modus'] ?? '', ['eigen', 'alle', 'team'], true)) {
            return $eigen;
        }
        if ($wert['modus'] === 'team' && ! in_array((int) ($wert['team_id'] ?? 0), $this->unterTeamIds($viewer), true)) {
            return $eigen;
        }

        return ['modus' => $wert['modus'], 'team_id' => $wert['modus'] === 'team' ? (int) $wert['team_id'] : null];
    }

    /** Team-Brille setzen (eigen | alle | team mit Unter-Team-ID). Nur Ansicht — keine Rollenprüfung nötig. */
    public function setzeBrille(Team $viewer, string $modus, ?int $teamId = null, ?int $userId = null): array
    {
        $uid = $userId ?? auth()->id();
        if (! in_array($modus, ['eigen', 'alle', 'team'], true)) {
            throw new \RuntimeException('Team-Brille: eigen, alle oder team.');
        }
        if ($modus === 'team' && ! in_array((int) $teamId, $this->unterTeamIds($viewer), true)) {
            throw new \RuntimeException('Dieser Standort gehört nicht zu diesem Team.');
        }
        $wert = ['modus' => $modus, 'team_id' => $modus === 'team' ? (int) $teamId : null];
        session(['fa.team_brille.'.$viewer->id => $wert]);
        if ($uid !== null) {
            DB::table('foodalchemist_team_brillen')->updateOrInsert(['user_id' => $uid, 'team_id' => $viewer->id],
                ['sicht' => $modus, 'sicht_team_id' => $wert['team_id'], 'updated_at' => now(), 'created_at' => now()]);
        }

        return $wert;
    }

    /**
     * Team-IDs, die Listen und Auswertungen des betrachtenden Teams lesen (Team-Brille).
     * eigen → [Team] · alle → [Team + alle Unter-Teams] · team → [das Unter-Team].
     *
     * @return list<int>
     */
    public function leseTeamIds(Team $viewer, ?int $userId = null): array
    {
        $b = $this->brille($viewer, $userId);

        return match ($b['modus']) {
            'alle' => array_merge([(int) $viewer->id], $this->unterTeamIds($viewer)),
            'team' => [(int) $b['team_id']],
            default => [(int) $viewer->id],
        };
    }

    /**
     * Listen/Auswertungen: auf die Teams der Team-Brille einschränken. Bei Brille „eigen" ohne Unter-Teams
     * identisch zu `where(team_id = Team)`.
     */
    public function leseBereich(\Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder $q, Team $team, string $spalte = 'team_id'): \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder
    {
        return $q->whereIn($spalte, $this->leseTeamIds($team));
    }

    /** Für Listen, die heute `visibleToTeam` (Kette aufwärts) lesen: bei Brille „eigen" unverändert, sonst Brillen-Teams. */
    public function leseBereichOderSichtbar(\Illuminate\Database\Eloquent\Builder $q, Team $team): \Illuminate\Database\Eloquent\Builder
    {
        $ids = $this->leseTeamIds($team);

        return $ids === [(int) $team->id] ? $q->visibleToTeam($team) : $q->whereIn($q->getModel()->getTable().'.team_id', $ids);
    }

    /**
     * Detailansicht: sichtbar (Kette aufwärts) ODER Beleg eines eigenen Unter-Teams — lesend. Schreiben prüft
     * weiter `isOwnedBy` im jeweiligen Service.
     */
    public function lesbarMitUnterTeams(\Illuminate\Database\Eloquent\Builder $q, Team $team): \Illuminate\Database\Eloquent\Builder
    {
        $unter = $this->unterTeamIds($team);
        $spalte = $q->getModel()->getTable().'.team_id';

        return $unter === [] ? $q->visibleToTeam($team)
            : $q->where(fn ($w) => $w->visibleToTeam($team)->orWhereIn($spalte, $unter));
    }

    /** true, wenn die Brille mehr als das eigene Team zeigt (für die Standort-Spalte). */
    public function zeigtStandorte(Team $viewer): bool
    {
        return $this->brille($viewer)['modus'] === 'alle';
    }

    /** @param list<int> $ids @return array<int,string> Team-ID => Name */
    public function teamNamen(array $ids): array
    {
        // Kopf-Zeilen fragen je Beleg einzeln — gemerkt je Request, sonst N+1 bei 500er-Listen
        $fehlt = array_values(array_diff(array_map('intval', $ids), array_keys($this->namen)));
        if ($fehlt !== []) {
            $this->namen += Team::whereIn('id', $fehlt)->pluck('name', 'id')->map(fn ($n) => (string) $n)->all() + array_fill_keys($fehlt, null);
        }

        return array_filter(array_intersect_key($this->namen, array_flip(array_map('intval', $ids))), fn ($n) => $n !== null);
    }

    /** @var array<int, ?string> */
    private array $namen = [];

    /** @var array<int, true> Teams, die für die Dauer von alsEigen() nur sich selbst lesen */
    private array $nurEigen = [];

    /** Führt $fn aus, als stünde die Team-Brille des Teams auf „eigen" (für den Standort-Vergleich im Controlling). */
    public function alsEigen(Team $team, callable $fn): mixed
    {
        $id = (int) $team->id;
        $vorher = isset($this->nurEigen[$id]);
        $this->nurEigen[$id] = true;
        try {
            return $fn();
        } finally {
            if (! $vorher) {
                unset($this->nurEigen[$id]);
            }
        }
    }

    /**
     * Controlling nebeneinander: je Standort (Oberteam selbst + Unter-Teams der Brille) $fn(Team) mit dessen
     * eigenen Daten, Einkaufspreisen und Einstellungen. Leer, wenn die Brille nur ein Team zeigt.
     *
     * @return list<array{team_id:int, standort:string, wert:mixed}>
     */
    public function jeStandort(Team $viewer, callable $fn): array
    {
        $ids = $this->leseTeamIds($viewer);
        if (count($ids) < 2) {
            return [];
        }
        $teams = Team::whereIn('id', $ids)->get()->keyBy('id');
        $out = [];
        foreach ($ids as $id) {
            $t = $teams->get($id);
            if ($t !== null) {
                $out[] = ['team_id' => (int) $id, 'standort' => (string) $t->name, 'wert' => $this->alsEigen($t, fn () => $fn($t))];
            }
        }

        return $out;
    }
}
