<?php

namespace Platform\FoodAlchemist\Livewire\Planung;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Component;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistPlanningSession;
use Platform\FoodAlchemist\Services\IdeenService;
use Platform\FoodAlchemist\Services\PlanningSessionService;
use Platform\FoodAlchemist\Support\TeamScope;

/**
 * Planungs-/Kreativ-Cockpit (Doppel-Diamant, Spec 08). Haus-Layout: links Kategorie→Klasse +
 * Session-Liste, Mitte Dashboard/Vorschau, rechts Detail; „Öffnen" → Fullscreen-Dark-Editor mit
 * Tabs Analyse · Skizzen · Planung + Go-Leiste. Read-mostly Container VOR dem Grounding — erst
 * „Go" erzeugt Basisrezept/Gericht/Concept (Draft), Lineage zurück in die Session.
 */
class Index extends Component
{
    public ?string $fehler = null;

    #[Url(as: 'session')]
    public ?int $sessionId = null;

    /** Neue-Planung-Formular (Mitte-Dashboard). */
    public string $neuTitel = '';

    /** Editor-Felder der aktiven Session. */
    public array $form = ['title' => '', 'brief' => '', 'analysis' => '', 'creative_mode' => 'voll_kreativ'];

    /** Skizzen-Eingaben. */
    public string $ideeTitel = '';

    public string $paketName = '';

    public ?string $meldung = null;

    /** Deep-Link `?session=X&open=1` (z.B. vom Trendradar-Carry-in) öffnet den Editor direkt. */
    public function mount(): void
    {
        if (request()->boolean('open') && $this->sessionId !== null && $this->aktiveSession() !== null) {
            $this->ladeForm();
            $this->dispatch('modal.open', name: 'planung-editor');
        }
    }

    private function team(): ?Team
    {
        return Auth::user()?->currentTeamRelation;
    }

    // ── Session-Lifecycle ──────────────────────────────────────────────

    public function neuePlanung(PlanningSessionService $svc): void
    {
        $team = $this->team();
        if ($team === null || trim($this->neuTitel) === '') {
            return;
        }
        $session = $svc->create($team, ['title' => $this->neuTitel, 'created_via' => 'ui']);
        $this->neuTitel = '';
        $this->oeffne($session->id);
    }

    public function oeffne(int $id): void
    {
        $this->sessionId = $id;
        $this->fehler = null;
        $this->meldung = null;
        $this->ladeForm();
        $this->dispatch('modal.open', name: 'planung-editor');
    }

    public function waehle(int $id): void
    {
        $this->sessionId = $id;
    }

    private function ladeForm(): void
    {
        $session = $this->aktiveSession();
        if ($session === null) {
            return;
        }
        $this->form = [
            'title' => (string) $session->title,
            'brief' => (string) $session->brief,
            'analysis' => (string) $session->analysis,
            'creative_mode' => (string) $session->creative_mode,
        ];
    }

    public function speichern(PlanningSessionService $svc): void
    {
        $team = $this->team();
        $session = $this->aktiveSession();
        if ($team === null || $session === null) {
            return;
        }
        $svc->update($team, $session->id, [
            'title' => $this->form['title'] ?? '',
            'brief' => $this->form['brief'] ?? null,
            'analysis' => $this->form['analysis'] ?? null,
        ]);
        if (in_array($this->form['creative_mode'] ?? null, FoodAlchemistPlanningSession::CREATIVE_MODES, true)) {
            $svc->setCreativeMode($team, $session->id, $this->form['creative_mode']);
        }
        $this->meldung = 'Gespeichert.';
    }

    // ── Skizzen (Divergenz-Board) ──────────────────────────────────────

    public function ideeHinzu(IdeenService $svc): void
    {
        $team = $this->team();
        $session = $this->aktiveSession();
        if ($team === null || $session === null || trim($this->ideeTitel) === '') {
            return;
        }
        try {
            $svc->add($team, ['planning_session_id' => $session->id, 'title' => $this->ideeTitel, 'created_via' => 'ui']);
            $this->ideeTitel = '';
            $this->fehler = null;
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function ideeVerwerfen(int $id, IdeenService $svc): void
    {
        $team = $this->team();
        if ($team === null) {
            return;
        }
        try {
            $svc->setStatus($team, $id, 'verworfen');
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function paketBilden(IdeenService $svc): void
    {
        $team = $this->team();
        $session = $this->aktiveSession();
        if ($team === null || $session === null || trim($this->paketName) === '') {
            return;
        }
        try {
            $svc->addGruppe($team, ['planning_session_id' => $session->id, 'name' => $this->paketName]);
            $this->paketName = '';
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();
        }
    }

    // ── „Go" / Handoff an den bestehenden KI-Generator ─────────────────

    /** Go → Basisrezept (vk=false) / Gericht (vk=true): Handoff an das bestehende KI-Rezept-Modal. */
    public function goRezept(bool $vk)
    {
        return $this->handoff($vk ? 'gericht' : 'basisrezept',
            $vk ? 'foodalchemist.verkauf.index' : 'foodalchemist.recipes.index');
    }

    /** Go → Concept: Handoff an den bestehenden KI-Konzept-Generator (Concepts-Seite). */
    public function goConcept()
    {
        return $this->handoff('concept', 'foodalchemist.concepts.index');
    }

    /**
     * Übergibt Brief + Session (Lineage-Träger) per Session-Flash an den Ziel-Browser und leitet
     * dorthin — dessen mount() öffnet den vorhandenen KI-Generator vorbefüllt. Kein Parallel-Pfad;
     * die Trend-Herkunft schreibt {@see PlanningSessionService::verknuepfeArtefakt} nach der Erzeugung.
     */
    private function handoff(string $target, string $route)
    {
        $session = $this->aktiveSession();
        if ($this->team() === null || $session === null) {
            return null;
        }
        session()->flash('fa_plan_handoff', [
            'target' => $target,
            'brief' => $this->goBrief($session),
            'planning_session_id' => $session->id,
        ]);

        return redirect()->route($route);
    }

    /** Brief für die Erzeugung: Session-Brief + Analyse-Auszug. */
    private function goBrief(FoodAlchemistPlanningSession $session): string
    {
        $brief = trim((string) $session->brief);
        $analyse = trim((string) $session->analysis);
        $text = $brief !== '' ? $brief : (string) $session->title;
        if ($analyse !== '') {
            $text .= "\n\nKontext:\n" . mb_substr($analyse, 0, 800);
        }

        return $text;
    }

    // ── Datenbeschaffung ───────────────────────────────────────────────

    private function aktiveSession(): ?FoodAlchemistPlanningSession
    {
        if ($this->sessionId === null) {
            return null;
        }
        $team = $this->team();

        return FoodAlchemistPlanningSession::visibleToTeam($team)->find($this->sessionId);
    }

    public function render()
    {
        $team = $this->team();

        // Sessions team-sichtbar + Trend-Kategorie/Klasse (loser Join über die Herkunft).
        $sessions = TeamScope::applyVisible(
            DB::table('foodalchemist_planning_sessions as s')
                ->leftJoin('foodalchemist_trend_meta as m', 'm.knowledge_document_id', '=', 's.source_knowledge_document_id')
                ->whereNull('s.deleted_at'),
            's.team_id', $team
        )->orderByDesc('s.updated_at')
            ->get(['s.id', 's.title', 's.status', 's.source_knowledge_document_id', 's.updated_at', 'm.category', 'm.trend_class']);

        // Baum: Kategorie → Sessions (Frei-Bucket für ohne-Trend).
        $baum = $sessions->groupBy(fn ($s) => $s->category ?: '__frei')
            ->map(fn ($grp, $cat) => [
                'category' => $cat === '__frei' ? 'Frei / ohne Kategorie' : $cat,
                'sessions' => $grp->values(),
            ])->values();

        $active = $this->aktiveSession();
        $skizzen = null;
        if ($active !== null) {
            $skizzen = app(IdeenService::class)->liste($team, null, null, false, $active->id);
        }

        return view('foodalchemist::livewire.planung.index', [
            'sessions' => $sessions,
            'baum' => $baum,
            'active' => $active,
            'skizzen' => $skizzen,
        ])->layout('platform::layouts.app');
    }
}
