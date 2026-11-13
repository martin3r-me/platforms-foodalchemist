<?php

namespace Platform\FoodAlchemist\Livewire\Planung;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistPlanningSession;
use Platform\FoodAlchemist\Services\PlanningSessionService;
use Platform\FoodAlchemist\Services\ZukaufBasisrezeptService;
use Platform\FoodAlchemist\Support\RezeptTypVokabular;

/**
 * Reiter Basisrezept der Planung: Zukauf-Ware (Dominique 13.11.). Hat die Session einen Herkunfts-GP, zeigt der Block
 * die Ware und den Typ (vorbelegt aus der Regel basisrezept.zukauf.typ, sonst Auswahl — nie geraten); der normale Go
 * baut dann das Zukauf-Basisrezept ({@see ZukaufBasisrezeptService::starteLauf}). Ohne Herkunfts-GP: „Zukauf-Ware
 * wählen“ (GP-Suche) — derselbe Einstieg wie der Knopf am GP ({@see PlanningSessionService::zukaufAusGp}).
 */
class ZukaufWare extends Component
{
    public ?int $sessionId = null;

    public string $typ = '';

    public bool $suchen = false;

    public string $suche = '';

    public ?string $fehler = null;

    /** Hinweis + Rezept-ID, wenn die gewählte Ware schon ein Zukauf-Basisrezept hat. */
    public ?array $vorhanden = null;

    public function mount(?int $sessionId = null): void
    {
        $this->sessionId = $sessionId;
        $this->typ = (string) ($this->session()?->zukauf_typ ?? '');
    }

    public function updatedTyp(PlanningSessionService $planung): void
    {
        $this->fehler = null;
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || $this->sessionId === null || $this->typ === '') {
            return;
        }
        try {
            $planung->setzeZukaufTyp($team, $this->sessionId, $this->typ);
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function sucheUmschalten(): void
    {
        $this->suchen = ! $this->suchen;
        $this->suche = '';
        $this->fehler = null;
        $this->vorhanden = null;
    }

    public function waehle(int $gpId, PlanningSessionService $planung)
    {
        $this->fehler = null;
        $this->vorhanden = null;
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null) {
            return null;
        }
        try {
            $e = $planung->zukaufAusGp($team, $gpId);
        } catch (\RuntimeException $ex) {
            $this->fehler = $ex->getMessage();

            return null;
        }
        if ($e['session'] === null) {
            $this->vorhanden = ['id' => (int) $e['recipe']->id, 'name' => (string) $e['recipe']->name];

            return null;
        }

        return redirect()->route('foodalchemist.planung.index', ['session' => $e['session']->id, 'open' => 1, 'tab' => 'basisrezept']);
    }

    private function session(): ?FoodAlchemistPlanningSession
    {
        $team = Auth::user()?->currentTeamRelation;

        return $team !== null && $this->sessionId !== null ? FoodAlchemistPlanningSession::visibleToTeam($team)->find($this->sessionId) : null;
    }

    public function render(ZukaufBasisrezeptService $zukauf)
    {
        $team = Auth::user()?->currentTeamRelation;
        $session = $this->session();
        $gp = $team !== null && $session?->source_gp_id !== null ? FoodAlchemistGp::visibleToTeam($team)->find($session->source_gp_id) : null;
        $treffer = [];
        if ($this->suchen && $team !== null && mb_strlen(trim($this->suche)) >= 2) {
            $treffer = FoodAlchemistGp::visibleToTeam($team)
                ->where('name', 'like', '%' . trim($this->suche) . '%')
                ->where(fn ($q) => $q->whereNull('commodity_group_code')->orWhere('commodity_group_code', '!=', '16'))
                ->where('is_platzhalter', false)
                ->orderBy('name')->limit(8)->get(['id', 'name']);
        }

        return view('foodalchemist::livewire.planung.zukauf-ware', [
            'session' => $session,
            'gp' => $gp,
            'rezept' => $gp !== null ? $zukauf->bestehendFuerGp($team, (int) $gp->id) : null,
            'typVorschlag' => $gp !== null ? $zukauf->typFuerGp($gp) : null,
            'typen' => $gp !== null ? RezeptTypVokabular::alle() : [],
            'treffer' => $treffer,
        ]);
    }
}
