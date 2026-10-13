<?php

namespace Platform\FoodAlchemist\Livewire\Standortprofil;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Models\FoodAlchemistProjekt;
use Platform\FoodAlchemist\Models\FoodAlchemistProjektStandort;
use Platform\FoodAlchemist\Services\FaRechte;
use Platform\FoodAlchemist\Services\TeamProfilService;
use Platform\FoodAlchemist\Support\FaShell;
use Platform\FoodAlchemist\Support\MasterTeam;
use Platform\FoodAlchemist\Support\Profiltraeger;

/**
 * Spec 82 §9 · Standortprofil (Menüpunkt unter Übersicht): „Eigene Standorte“ = das Teamprofil des aktuellen Teams (gepflegt in den
 * Einstellungen, hier als Überblick) und, nur im Master-Team, „Kunden-Standorte · aus Projekten“ — das Profil jedes Kunden-Standorts
 * einer Agentur-Akte, pflegbar mit demselben Editor wie das Teamprofil. Kunden-Standorte haben noch kein Team (Lizenz folgt), ihre Profile
 * liegen deshalb beim Master unter `standort_id`.
 */
class Index extends Component
{
    #[Url(as: 'standort', except: 0)]
    public int $standortId = 0;

    public string $maxPax = '';

    public string $convenience = '';

    public string $notizen = '';

    /** @var array{name: string, max_pax: string, saison_von: string, saison_bis: string} */
    public array $neueFlaeche = ['name' => '', 'max_pax' => '', 'saison_von' => '', 'saison_bis' => ''];

    public ?string $fehler = null;

    public ?string $meldung = null;

    public function mount(): void
    {
        if ($this->standortId > 0) {
            $this->standortOeffnen($this->standortId);
        }
    }

    private function team(): ?\Platform\Core\Models\Team
    {
        return Auth::user()?->currentTeamRelation;
    }

    private function istMaster(): bool
    {
        $team = $this->team();

        return $team !== null && MasterTeam::istMaster($team);
    }

    private function traeger(): ?Profiltraeger
    {
        return $this->standortId > 0 && $this->istMaster() ? Profiltraeger::standort($this->team(), $this->standortId) : null;
    }

    public function standortOeffnen(int $id): void
    {
        $this->fehler = $this->meldung = null;
        $team = $this->team();
        if ($team === null || ! $this->istMaster() || ! FoodAlchemistProjektStandort::where('team_id', $team->id)->whereKey($id)->exists()) {
            $this->standortId = 0;
            $this->fehler = 'Diesen Kunden-Standort gibt es nicht.';

            return;
        }
        $this->standortId = $id;
        $profil = app(TeamProfilService::class)->lies($team, Profiltraeger::standort($team, $id))['profil'];
        $this->maxPax = $profil['max_pax_parallel'] !== null ? (string) $profil['max_pax_parallel'] : '';
        $this->convenience = (string) ($profil['convenience_grad'] ?? '');
        $this->notizen = (string) ($profil['notes'] ?? '');
    }

    public function schliessen(): void
    {
        $this->standortId = 0;
        $this->fehler = $this->meldung = null;
    }

    public function profilSpeichern(): void
    {
        $this->aktion(function ($svc, $t, $user) {
            $svc->setzeProfil($t, $user, [
                'max_pax_parallel' => trim($this->maxPax) === '' ? null : trim($this->maxPax),
                'convenience_grad' => $this->convenience === '' ? null : $this->convenience,
                'notes' => trim($this->notizen) === '' ? null : trim($this->notizen),
            ]);
            $this->meldung = 'Kennzahlen gespeichert.';
        });
    }

    public function equipmentSetzen(int $equipmentId, string $wert): void
    {
        $this->aktion(function ($svc, $t, $user) use ($equipmentId, $wert) {
            $svc->setzeEquipment($t, $user, $equipmentId, in_array($wert, ['vorhanden', 'nicht_vorhanden'], true) ? $wert : null);
            $this->meldung = 'Gespeichert.';
        });
    }

    public function flaecheAnlegen(): void
    {
        $this->aktion(function ($svc, $t, $user) {
            $f = $this->neueFlaeche;
            $svc->setzeFlaeche($t, $user, null, (string) $f['name'], $this->zahl($f['max_pax']), $this->zahl($f['saison_von']), $this->zahl($f['saison_bis']));
            $this->neueFlaeche = ['name' => '', 'max_pax' => '', 'saison_von' => '', 'saison_bis' => ''];
            $this->meldung = 'Fläche angelegt.';
        });
    }

    public function flaecheFeld(int $id, string $feld, string $wert): void
    {
        $this->aktion(function ($svc, $t, $user) use ($id, $feld, $wert) {
            $f = collect($svc->lies($t->team, $t)['flaechen'])->firstWhere('id', $id);
            if ($f === null) {
                return;
            }
            $f[$feld] = in_array($feld, ['max_pax', 'saison_von', 'saison_bis'], true) ? $this->zahl($wert) : trim($wert);
            $svc->setzeFlaeche($t, $user, $id, (string) $f['name'], $f['max_pax'], $f['saison_von'], $f['saison_bis']);
            $this->meldung = 'Gespeichert.';
        });
    }

    public function flaecheLoeschen(int $id): void
    {
        $this->aktion(function ($svc, $t, $user) use ($id) {
            $svc->loescheFlaeche($t, $user, $id);
            $this->meldung = 'Fläche gelöscht.';
        });
    }

    private function zahl(mixed $roh): ?int
    {
        $s = trim((string) $roh);

        return $s === '' || ! ctype_digit($s) ? null : (int) $s;
    }

    /** @param  callable(TeamProfilService, Profiltraeger, \Platform\Core\Models\User): void  $fn */
    private function aktion(callable $fn): void
    {
        $this->fehler = $this->meldung = null;
        $t = $this->traeger();
        $user = Auth::user();
        if ($t === null || $user === null) {
            $this->fehler = 'Kein Kunden-Standort geöffnet.';

            return;
        }
        try {
            $fn(app(TeamProfilService::class), $t, $user);
        } catch (FaRechtFehltException|\RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function render()
    {
        $user = Auth::user();
        $team = $this->team();
        $svc = app(TeamProfilService::class);
        $eigenes = $team !== null ? $svc->lies($team, $team) : ['profil' => [], 'flaechen' => [], 'ausstattung' => []];
        $eigeneAusstattung = collect($eigenes['ausstattung']);

        $kunden = collect();
        $t = $this->traeger();
        if ($team !== null && $this->istMaster()) {
            $projekte = FoodAlchemistProjekt::where('team_id', $team->id)->orderBy('name')->get()->keyBy('id');
            $kunden = FoodAlchemistProjektStandort::where('team_id', $team->id)->orderBy('projekt_id')->orderBy('sort_order')->get()
                ->map(function ($s) use ($team, $svc, $projekte) {
                    $d = $svc->lies($team, Profiltraeger::standort($team, (int) $s->id));
                    $a = collect($d['ausstattung']);

                    return ['standort' => $s, 'projekt' => $projekte->get($s->projekt_id), 'flaechen' => count($d['flaechen']),
                        'bekannt' => $a->filter(fn ($x) => $x['status'] !== 'unbekannt')->count(), 'gesamt' => $a->count(), 'pax' => $d['profil']['max_pax_parallel']];
                })->filter(fn ($z) => $z['projekt'] !== null)->values();
        }

        $offen = $t !== null ? $svc->lies($team, $t) : null;
        $ausstattung = collect($offen['ausstattung'] ?? []);

        return view('foodalchemist::livewire.standortprofil.index', [
            'istMaster' => $this->istMaster(),
            'darfSchreiben' => $team !== null && app(FaRechte::class)->darf($user, $team, \Platform\FoodAlchemist\Enums\FaRolle::Kuratieren),
            'eigenes' => $eigenes, 'eigeneBekannt' => $eigeneAusstattung->filter(fn ($x) => $x['status'] !== 'unbekannt')->count(), 'eigeneGesamt' => $eigeneAusstattung->count(),
            'kunden' => $kunden,
            'offen' => $t !== null ? FoodAlchemistProjektStandort::where('team_id', $team->id)->find($this->standortId) : null,
            'flaechen' => $offen['flaechen'] ?? [],
            'gruppen' => $ausstattung->groupBy(fn ($a) => $a['gruppe'] ?: 'Weitere'),
            'unbekannt' => $ausstattung->filter(fn ($a) => $a['wirksam'] === 'unbekannt')->count(),
            'gesamt' => $ausstattung->count(),
            'convenienceWerte' => ['from_scratch' => 'Von Grund auf (from scratch)', 'teil_convenience' => 'Teil-Convenience', 'voll_convenience' => 'Voll-Convenience'],
        ])->layout(FaShell::layout());
    }
}
