<?php

namespace Platform\FoodAlchemist\Livewire\Abos;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\FaRolle;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Models\FoodAlchemistAbo;
use Platform\FoodAlchemist\Services\AboKuendigungService;
use Platform\FoodAlchemist\Services\AboService;
use Platform\FoodAlchemist\Services\FaRechte;
use Platform\FoodAlchemist\Services\InhaltsFreigabeService;
use Platform\FoodAlchemist\Services\StammPaketService;
use Platform\FoodAlchemist\Support\MasterTeam;

/**
 * Spec 82 §8 / §13 B6 — Abos.
 *   Master-Team: Sammlungen mit Fassungs-Stand, Abo vergeben (nur Mandanten mit Stamm-Paket), Abo kündigen, Rezept
 *   zurückziehen. Schreiben nur mit Rolle „Freigeben"; sonst nur lesen.
 *   Mandant: „Meine Abos" (Sammlung, seit, Rezepte mit Fassung + Änderungsnotiz). Kündigen darf nur ein Admin des
 *   Oberteams; Unter-Teams sehen die Abos ihres Oberteams nur.
 * Kündigen und Zurückziehen immer erst mit Vorschau „genutzt / mitkopiert / verschwindet", dann Bestätigung (§12 D).
 */
class Index extends Component
{
    public string $neuSammlungId = '';

    public string $neuMandantId = '';

    /** Offene Vorschau: ['art' => 'kuendigen', 'abo_id' => int] oder ['art' => 'zurueckziehen', 'sammlung_id' => int, 'recipe_id' => int] */
    public ?array $vorschauFuer = null;

    public bool $bestaetigt = false;

    public ?string $fehler = null;

    public ?string $meldung = null;

    public function vergeben(): void
    {
        $this->aktion(function (Team $team) {
            if ($this->neuSammlungId === '' || $this->neuMandantId === '') {
                throw new \RuntimeException('Bitte Sammlung und Mandant wählen.');
            }
            $mandant = Team::findOrFail((int) $this->neuMandantId);
            app(AboService::class)->abonnieren($team, $mandant, (int) $this->neuSammlungId, Auth::user());
            $this->meldung = 'Abo für „'.$mandant->name.'“ vergeben.';
            $this->neuSammlungId = $this->neuMandantId = '';
        });
    }

    public function kuendigenVorschau(int $aboId): void
    {
        $this->oeffneVorschau(['art' => 'kuendigen', 'abo_id' => $aboId]);
    }

    public function zurueckziehenVorschau(int $sammlungId, int $recipeId): void
    {
        $this->oeffneVorschau(['art' => 'zurueckziehen', 'sammlung_id' => $sammlungId, 'recipe_id' => $recipeId]);
    }

    public function abbrechen(): void
    {
        $this->vorschauFuer = null;
        $this->bestaetigt = false;
    }

    public function ausfuehren(): void
    {
        $v = $this->vorschauFuer;
        if ($v === null) {
            return;
        }
        $this->aktion(function (Team $team) use ($v) {
            $svc = app(AboKuendigungService::class);
            if ($v['art'] === 'kuendigen') {
                $r = $svc->kuendigen($team, (int) $v['abo_id'], Auth::user(), $this->bestaetigt);
                $this->meldung = $r['ausgefuehrt']
                    ? 'Abo gekündigt. '.array_sum(array_map('count', $r['kopien_je_art'] ?? [])).' Objekt(e) (Rezepte, Pakete, Konzepte) als eigene Kopie übernommen.' : null;
            } else {
                $r = $svc->zurueckziehen($team, (int) $v['sammlung_id'], (int) $v['recipe_id'], Auth::user(), $this->bestaetigt);
                $this->meldung = $r['ausgefuehrt'] ? 'Rezept aus der Sammlung zurückgezogen.' : null;
            }
            if ($r['ausgefuehrt']) {
                $this->vorschauFuer = null;
                $this->bestaetigt = false;
            }
        });
    }

    private function oeffneVorschau(array $fuer): void
    {
        $this->fehler = $this->meldung = null;
        $this->bestaetigt = false;
        $this->vorschauFuer = $fuer;
    }

    /** @param  callable(Team): void  $fn */
    private function aktion(callable $fn): void
    {
        $this->fehler = $this->meldung = null;
        try {
            $fn($this->team());
        } catch (FaRechtFehltException|\RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    private function team(): Team
    {
        return Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
    }

    /** Vorschau der offenen Aktion (rechnet, schreibt nichts). @return ?array{titel:string, mandanten:list<array{name:string, vorschau:array<string,mixed>}>} */
    private function vorschau(Team $team): ?array
    {
        $v = $this->vorschauFuer;
        if ($v === null) {
            return null;
        }
        try {
            $svc = app(AboKuendigungService::class);
            if ($v['art'] === 'kuendigen') {
                $abo = FoodAlchemistAbo::findOrFail((int) $v['abo_id']);
                $r = $svc->kuendigen($team, (int) $abo->id, Auth::user(), false);
                $sammlung = DB::table('foodalchemist_sammlungen')->where('id', $abo->sammlung_id)->value('name');

                return ['titel' => 'Abo kündigen: '.($sammlung ?? '—'), 'knopf' => 'Abo kündigen',
                    'mandanten' => [['name' => (string) Team::find($abo->mandant_team_id)?->name, 'vorschau' => $r['vorschau']]]];
            }
            $r = $svc->zurueckziehen($team, (int) $v['sammlung_id'], (int) $v['recipe_id'], Auth::user(), false);
            $namen = Team::whereIn('id', array_column($r['mandanten'], 'mandant_team_id'))->pluck('name', 'id');
            $rezept = DB::table('foodalchemist_recipes')->where('id', $v['recipe_id'])->value('name');

            return ['titel' => 'Zurückziehen: '.($rezept ?? '—'), 'knopf' => 'Zurückziehen',
                'mandanten' => array_map(fn ($m) => ['name' => (string) ($namen[$m['mandant_team_id']] ?? '—'), 'vorschau' => $m['vorschau']], $r['mandanten'])];
        } catch (FaRechtFehltException|\RuntimeException $e) {
            $this->fehler = $e->getMessage();
            $this->vorschauFuer = null;

            return null;
        }
    }

    /**
     * Neueste Fassung je Familie (Arbeitszeilen-ID des Masters).
     *
     * @param  list<int>  $familien
     * @return array<int, object{id:int, fassung_von_id:int, fassung_nr:int, veroeffentlicht_at:?string, aenderungsnotiz:?string, fassung_allergen_geaendert:?int, name:string}>
     */
    private function neuesteFassungen(array $familien): array
    {
        if ($familien === []) {
            return [];
        }
        $max = DB::table('foodalchemist_recipes')->where('ist_fassung', true)->whereNull('deleted_at')->whereIn('fassung_von_id', $familien)
            ->groupBy('fassung_von_id')->selectRaw('fassung_von_id, MAX(fassung_nr) AS nr');

        return DB::table('foodalchemist_recipes AS r')
            ->joinSub($max, 'm', fn ($j) => $j->on('m.fassung_von_id', '=', 'r.fassung_von_id')->on('m.nr', '=', 'r.fassung_nr'))
            ->where('r.ist_fassung', true)->whereNull('r.deleted_at')
            ->get(['r.id', 'r.fassung_von_id', 'r.fassung_nr', 'r.veroeffentlicht_at', 'r.aenderungsnotiz', 'r.fassung_allergen_geaendert', 'r.name'])
            ->keyBy('fassung_von_id')->all();
    }

    /** @return list<array{id:int, name:string, rezepte:list<array{id:int, name:string, fassung:?object}>, abonnenten:int}> */
    private function masterSammlungen(Team $master): array
    {
        $sammlungen = app(InhaltsFreigabeService::class)->sammlungen($master);
        $familien = collect($sammlungen)->flatMap(fn ($s) => collect($s['objekte'])->where('typ', 'recipe')->pluck('id'))->unique()->values()->all();
        $fassungen = $this->neuesteFassungen($familien);
        $abonnenten = DB::table('foodalchemist_abos')->where('master_team_id', $master->id)->where('status', FoodAlchemistAbo::AKTIV)
            ->groupBy('sammlung_id')->selectRaw('sammlung_id, COUNT(*) AS n')->pluck('n', 'sammlung_id');

        return array_map(fn ($s) => [
            'id' => $s['id'], 'name' => $s['name'],
            'rezepte' => collect($s['objekte'])->where('typ', 'recipe')->map(fn ($o) => ['id' => $o['id'], 'name' => $o['name'], 'fassung' => $fassungen[$o['id']] ?? null])->values()->all(),
            'abonnenten' => (int) ($abonnenten[$s['id']] ?? 0),
        ], $sammlungen);
    }

    /** Rezepte eines Mandanten-Abos: nur Familien mit veröffentlichter Fassung (das ist, was der Mandant sieht). */
    private function aboRezepte(int $sammlungId): array
    {
        $familien = DB::table('foodalchemist_sammlung_objekte')->where('sammlung_id', $sammlungId)->where('typ', 'recipe')
            ->pluck('objekt_id')->map(fn ($v) => (int) $v)->all();

        return collect($this->neuesteFassungen($familien))->sortBy('name')->values()->all();
    }

    public function render()
    {
        $team = $this->team();
        $user = Auth::user();
        $rechte = app(FaRechte::class);
        $istMaster = MasterTeam::istMaster($team);
        $abos = app(AboService::class)->abos($team);
        $teamNamen = Team::whereIn('id', $abos->pluck('mandant_team_id')->all())->pluck('name', 'id');

        if ($istMaster) {
            $daten = [
                'darfSchreiben' => $rechte->darf($user, $team, FaRolle::Freigeben),
                'sammlungen' => $this->masterSammlungen($team),
                'mandanten' => app(StammPaketService::class)->uebersicht(),
            ];
        } else {
            $ober = $rechte->kundenHauptTeam($team);
            $daten = [
                'istOberteam' => (int) $ober->id === (int) $team->id,
                'oberteamName' => (string) $ober->name,
                'darfKuendigen' => (int) $ober->id === (int) $team->id && $rechte->darf($user, $team, FaRolle::Admin),
                'rezepte' => $abos->mapWithKeys(fn ($a) => [$a['id'] => $this->aboRezepte($a['sammlung_id'])])->all(),
            ];
        }

        return view('foodalchemist::livewire.abos.index', $daten + [
            'istMaster' => $istMaster,
            'abos' => $abos->map(fn ($a) => $a + ['mandant' => (string) ($teamNamen[$a['mandant_team_id']] ?? '—')])->all(),
            'vorschau' => $this->vorschau($team),
        ])->layout(\Platform\FoodAlchemist\Support\FaShell::layout());
    }
}
