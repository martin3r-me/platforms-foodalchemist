<?php

namespace Platform\FoodAlchemist\Livewire\Projekte;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
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

        return view('foodalchemist::livewire.projekte.detail', [
            'p' => $p, 'firma' => $firma, 'phasen' => FoodAlchemistProjekt::PHASEN,
            'kontakteVerknuepft' => count(array_filter($rollen, fn ($r) => ! empty($r['meta']['crm_contact_id']))), 'kontakteGesamt' => count($rollen),
            'darfSchreiben' => app(FaRechte::class)->darf(Auth::user(), $team, \Platform\FoodAlchemist\Enums\FaRolle::Kuratieren),
        ])->layout(FaShell::layout());
    }
}
