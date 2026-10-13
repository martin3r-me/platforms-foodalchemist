<?php

namespace Platform\FoodAlchemist\Livewire\Projekte;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Livewire\Concerns\ManagesCanvas;
use Platform\FoodAlchemist\Models\FoodAlchemistProjekt;
use Platform\FoodAlchemist\Services\CanvasService;
use Platform\FoodAlchemist\Services\FaRechte;
use Platform\FoodAlchemist\Services\ProjektService;
use Platform\FoodAlchemist\Support\CrmKunden;
use Platform\FoodAlchemist\Support\FaShell;

/**
 * Spec 82 §9 · Projekt-Detail (Agentur-Akte): Kopf, Phasen als Reiter, Seitenleiste „Verknüpft“. Die Felder der Phasen sind der
 * Projekt-Canvas (owner_type=projekt); Standorte sind Master-Daten am Projekt. Schreiben ab „Kuratieren“, alles nur im Master-Team.
 */
class Detail extends Component
{
    use ManagesCanvas;

    public int $projektId;

    public string $phase = 'zielbild';

    /** @var array{name: string, ziel: string, status: string, planner_projekt_id: string} */
    public array $kopf = ['name' => '', 'ziel' => '', 'status' => 'aktiv', 'planner_projekt_id' => ''];

    public string $neuerStandort = '';

    /** Suchtext für die Person der Freigabe (CRM-Kontakt). */
    public string $gateKontaktSuche = '';

    /** Lizenz-Übernahme: Suchtext und gewähltes Oberteam (nur Plattform-Admin). */
    public string $oberteamSuche = '';

    public ?int $oberteamId = null;

    /** @var array<string,mixed>|null */
    public ?array $uebernahmeBericht = null;

    public ?string $fehler = null;

    public ?string $meldung = null;

    public function mount(int $projekt): void
    {
        $team = CrmKunden::aktuellesTeam();
        try {
            $p = app(ProjektService::class)->finde($team, $projekt);
        } catch (\RuntimeException) {
            abort(404);
        }
        $this->projektId = (int) $p->id;
        $this->phase = $p->phase;
        $this->kopfLaden($p);
        $this->canvasInit('projekt', 'projekt', (int) $p->id);
        $this->phaseSetzen($p->phase);
    }

    private function projekt(): FoodAlchemistProjekt
    {
        return app(ProjektService::class)->finde(CrmKunden::aktuellesTeam(), $this->projektId);
    }

    private function kopfLaden(FoodAlchemistProjekt $p): void
    {
        $this->kopf = ['name' => (string) $p->name, 'ziel' => (string) ($p->ziel ?? ''), 'status' => (string) $p->status, 'planner_projekt_id' => (string) ($p->planner_projekt_id ?? '')];
    }

    /** Reiter wechseln: Phasen mit Feldern zeigen nur ihre Gruppe des Projekt-Canvas. */
    public function phaseSetzen(string $phase): void
    {
        if (! array_key_exists($phase, FoodAlchemistProjekt::PHASEN)) {
            return;
        }
        $this->phase = $phase;
        $gruppe = FoodAlchemistProjekt::PHASEN[$phase];
        $hat = collect(app(CanvasService::class)->felder('projekt', $this->canvasTeam()))->contains(fn ($f) => ($f['group_name'] ?? null) === $gruppe);
        $this->canvasNurGruppe = $hat ? $gruppe : null;
        $this->canvasGespeichert = false;
    }

    /** Die Phase als aktuellen Stand des Projekts festhalten (Kopf). */
    public function phaseAlsStand(): void
    {
        $this->aktion(fn (ProjektService $s) => $s->setze(CrmKunden::aktuellesTeam(), Auth::user(), $this->projektId, ['phase' => $this->phase]), 'Phase gesetzt.');
    }

    public function kopfSpeichern(): void
    {
        $this->aktion(fn (ProjektService $s) => $s->setze(CrmKunden::aktuellesTeam(), Auth::user(), $this->projektId, [
            'name' => $this->kopf['name'], 'ziel' => $this->kopf['ziel'], 'status' => $this->kopf['status'], 'planner_projekt_id' => $this->kopf['planner_projekt_id'],
        ]), 'Projektkopf gespeichert.');
    }

    public function standortAnlegen(): void
    {
        $this->aktion(function (ProjektService $s) {
            $s->standortAnlegen(CrmKunden::aktuellesTeam(), Auth::user(), $this->projektId, $this->neuerStandort);
            $this->neuerStandort = '';
        });
    }

    public function pilotSetzen(int $standortId): void
    {
        $this->aktion(fn (ProjektService $s) => $s->pilotSetzen(CrmKunden::aktuellesTeam(), Auth::user(), $this->projektId, $standortId));
    }

    public function standortLoeschen(int $standortId): void
    {
        $this->aktion(fn (ProjektService $s) => $s->standortLoeschen(CrmKunden::aktuellesTeam(), Auth::user(), $this->projektId, $standortId));
    }

    /** Gate: Struktur + Musterseite freigeben, Person = CRM-Kontakt. */
    public function strukturFreigeben(int $kontaktId): void
    {
        $this->aktion(function (ProjektService $s) use ($kontaktId) {
            $s->strukturFreigeben(CrmKunden::aktuellesTeam(), Auth::user(), $this->projektId, $kontaktId);
            $this->gateKontaktSuche = '';
        }, 'Struktur und Musterseite sind freigegeben.');
    }

    public function strukturZuruecknehmen(): void
    {
        $this->aktion(fn (ProjektService $s) => $s->strukturZuruecknehmen(CrmKunden::aktuellesTeam(), Auth::user(), $this->projektId), 'Freigabe zurückgenommen.');
    }

    public function foodbookAnlegen()
    {
        $this->fehler = $this->meldung = null;
        try {
            $fb = app(ProjektService::class)->foodbookAnlegen(CrmKunden::aktuellesTeam(), Auth::user(), $this->projektId);
        } catch (FaRechtFehltException|\RuntimeException $e) {
            $this->fehler = $e->getMessage();

            return null;
        }
        $this->phase = 'foodbook';
        $this->meldung = 'Foodbook „'.$fb->label.'“ angelegt und aus der Akte vorbefüllt.';

        return null;
    }

    public function oberteamWaehlen(int $teamId): void
    {
        $this->oberteamId = $teamId;
        $this->oberteamSuche = '';
    }

    public function oberteamLoesen(): void
    {
        $this->oberteamId = null;
    }

    /** Lizenz erteilt: Akte in den Mandanten übernehmen (Kopie). */
    public function lizenzUebernehmen(): void
    {
        $this->fehler = $this->meldung = null;
        $oberteam = $this->oberteamId !== null ? Team::find($this->oberteamId) : null;
        if ($oberteam === null) {
            $this->fehler = 'Bitte zuerst das Oberteam des Mandanten wählen.';

            return;
        }
        try {
            $this->uebernahmeBericht = app(\Platform\FoodAlchemist\Services\ProjektUebernahmeService::class)
                ->uebernehmen(CrmKunden::aktuellesTeam(), Auth::user(), $this->projektId, $oberteam);
            $this->meldung = 'Lizenz erteilt: Die Akte ist in den Mandanten übernommen.';
            $this->oberteamId = null;
            $this->kopfLaden($this->projekt());
            $this->phase = 'uebergabe';
        } catch (FaRechtFehltException|\RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    private function aktion(callable $fn, ?string $erfolg = null): void
    {
        $this->fehler = $this->meldung = null;
        try {
            $fn(app(ProjektService::class));
            $this->meldung = $erfolg;
            $this->kopfLaden($this->projekt());
        } catch (FaRechtFehltException|\RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function render()
    {
        $team = CrmKunden::aktuellesTeam();
        $p = $this->projekt()->load('standorte');
        $firma = CrmKunden::verfuegbar() ? CrmKunden::firma($team, (int) $p->crm_company_id) : null;
        $rollen = $this->canvasListen['rollen'] ?? [];
        $svc = app(ProjektService::class);

        return view('foodalchemist::livewire.projekte.detail', [
            'p' => $p, 'firma' => $firma, 'phasen' => FoodAlchemistProjekt::PHASEN,
            'ki' => app(\Platform\FoodAlchemist\Services\KontextResolver::class)->fuer($team, ['crm_company_id' => (int) $p->crm_company_id, 'projekt_id' => (int) $p->id]),
            'istPlattformAdmin' => ($istAdmin = app(FaRechte::class)->istPlattformAdmin(Auth::user())),
            'oberteamTreffer' => $istAdmin && $this->phase === 'uebergabe' && $p->mandant_team_id === null && $this->oberteamId === null && trim($this->oberteamSuche) !== ''
                ? Team::whereNull('parent_team_id')->where('name', 'like', '%'.trim($this->oberteamSuche).'%')->where('id', '!=', $team->id)->orderBy('name')->limit(8)->get() : collect(),
            'oberteam' => $this->oberteamId !== null ? Team::find($this->oberteamId) : null,
            'mandantTeam' => $p->mandant_team_id !== null ? Team::find($p->mandant_team_id) : null,
            'vorschau' => $istAdmin && $this->oberteamId !== null && ($ot = Team::find($this->oberteamId)) !== null && $p->mandant_team_id === null
                ? app(\Platform\FoodAlchemist\Services\ProjektUebernahmeService::class)->vorschau($team, Auth::user(), (int) $p->id, $ot) : null,
            'gateFehlt' => $svc->gateFehlt($p), 'foodbooks' => $svc->foodbooks($team, (int) $p->id),
            'gateKontakte' => $this->phase === 'paketlogik' && ! $p->strukturFreigegeben() ? CrmKunden::kontakte($team, $this->gateKontaktSuche) : collect(),
            'kontakteVerknuepft' => count(array_filter($rollen, fn ($r) => ! empty($r['meta']['crm_contact_id']))), 'kontakteGesamt' => count($rollen),
            'darfSchreiben' => app(FaRechte::class)->darf(Auth::user(), $team, \Platform\FoodAlchemist\Enums\FaRolle::Kuratieren),
        ])->layout(FaShell::layout());
    }
}
