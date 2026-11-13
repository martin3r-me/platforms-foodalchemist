<?php

namespace Platform\FoodAlchemist\Livewire\Gps;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Services\FaRechte;
use Platform\FoodAlchemist\Services\PlanningSessionService;
use Platform\FoodAlchemist\Services\ZukaufBasisrezeptService;

/**
 * Knopf „Zukauf-Basisrezept planen“ am Grundprodukt (Dominique 13.11.): gekaufte Ware (Kräutercrumble, Microgreens,
 * fertiges Gulasch) bekommt ein Basisrezept, das genau diese Ware führt und die Handgriffe beschreibt — damit ein
 * Gericht nur aus Basisrezepten mit Zeitwert besteht. Der Knopf legt KEIN Rezept an, sondern öffnet die Planung
 * (Reiter Basisrezept) mit einer vorbefüllten Session ({@see PlanningSessionService::zukaufAusGp}); dort startet der
 * Mensch mit dem normalen Go. Gibt es schon ein Zukauf-Basisrezept, steht hier der Link statt des Knopfs.
 * Jeder Food-GP (der Mensch entscheidet), nur Non-Food (WG 16) nicht. Rezepte mit dem GP bleiben unberührt.
 */
class ZukaufRezept extends Component
{
    public int $gpId;

    public ?string $fehler = null;

    public function mount(int $gpId): void
    {
        $this->gpId = $gpId;
    }

    public function planen(PlanningSessionService $planung)
    {
        $this->fehler = null;
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null) {
            return null;
        }
        try {
            $e = $planung->zukaufAusGp($team, $this->gpId);
        } catch (\RuntimeException $ex) {
            $this->fehler = $ex->getMessage();

            return null;
        }
        if ($e['session'] === null) {
            return null;   // schon ein Zukauf-Basisrezept: render zeigt den Link
        }

        return redirect()->route('foodalchemist.planung.index', ['session' => $e['session']->id, 'open' => 1, 'tab' => 'basisrezept']);
    }

    public function render(ZukaufBasisrezeptService $zukauf, FaRechte $rechte)
    {
        $team = Auth::user()?->currentTeamRelation;
        $gp = $team !== null ? FoodAlchemistGp::visibleToTeam($team)->find($this->gpId) : null;
        $angeboten = $gp !== null && $zukauf->bietetZukaufAn($gp);

        return view('foodalchemist::livewire.gps.zukauf-rezept', [
            'angeboten' => $angeboten,
            'rezept' => $angeboten ? $zukauf->bestehendFuerGp($team, (int) $gp->id) : null,
            // Wie „Planung anlegen“: wer den Bereich Planung nutzen darf
            'darf' => $angeboten && $rechte->darfBereich(Auth::user(), $team, 'planung'),
        ]);
    }
}
