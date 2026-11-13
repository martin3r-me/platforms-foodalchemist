<?php

namespace Platform\FoodAlchemist\Livewire\Gps;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Platform\FoodAlchemist\Enums\FaRolle;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Services\FaRechte;
use Platform\FoodAlchemist\Services\ZukaufBasisrezeptService;
use Platform\FoodAlchemist\Support\RezeptTypVokabular;

/**
 * Knopf „Als Zukauf-Basisrezept anlegen“ am Grundprodukt (Dominique 13.11.): gekaufte Fertigware (Kräutercrumble,
 * Microgreens, fertiges Gulasch) bekommt ein Basisrezept, das GENAU diese Ware führt und die Handgriffe beschreibt —
 * damit ein Gericht nur aus Basisrezepten mit Zeitwert besteht. Gleicher Bau wie die Kaskade
 * ({@see ZukaufBasisrezeptService::ausGp}); gibt es schon eins, wird es verlinkt statt doppelt angelegt.
 * Rezepte, die den GP nutzen, bleiben unberührt. Eingebettet im GP-Dialog und im Detail-Panel der GP-Liste.
 */
class ZukaufRezept extends Component
{
    public int $gpId;

    /** Gewählter Basisrezept-Typ (vorbelegt aus der Regel basisrezept.zukauf.typ, sonst leer = Mensch wählt). */
    public string $typ = '';

    /** Bezeichnung im Rezeptnamen „<Typ>: <Bezeichnung> (Zukauf)“ — vorbelegt mit dem Produktnamen des GP. */
    public string $bezeichnung = '';

    public bool $formOffen = false;

    public ?string $fehler = null;

    public function mount(int $gpId): void
    {
        $this->gpId = $gpId;
        $gp = $this->gp();
        if ($gp !== null) {
            $this->typ = (string) (app(ZukaufBasisrezeptService::class)->typFuerGp($gp) ?? '');
            $this->bezeichnung = ZukaufBasisrezeptService::bezeichnungFuerGp($gp);
        }
    }

    public function formUmschalten(): void
    {
        $this->formOffen = ! $this->formOffen;
        $this->fehler = null;
    }

    public function anlegen(ZukaufBasisrezeptService $zukauf): void
    {
        $this->fehler = null;
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null) {
            return;
        }
        try {
            $ergebnis = $zukauf->ausGp($team, $this->gpId, $this->typ !== '' ? $this->typ : null, $this->bezeichnung);
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();

            return;
        }
        $this->formOffen = false;
        // Danach den Basisrezept-Editor öffnen: Rüst- und Regenerationszeit werden dort gepflegt.
        $this->dispatch('recipe-modal.oeffnen', id: (int) $ergebnis['recipe']->id);
    }

    private function gp(): ?FoodAlchemistGp
    {
        $team = Auth::user()?->currentTeamRelation;

        return $team !== null ? FoodAlchemistGp::visibleToTeam($team)->find($this->gpId) : null;
    }

    public function render(ZukaufBasisrezeptService $zukauf, FaRechte $rechte)
    {
        $team = Auth::user()?->currentTeamRelation;
        $gp = $this->gp();
        $angeboten = $gp !== null && $zukauf->bietetZukaufAn($gp);

        return view('foodalchemist::livewire.gps.zukauf-rezept', [
            'angeboten' => $angeboten,
            'rezept' => $angeboten ? $zukauf->bestehendFuerGp($team, (int) $gp->id) : null,
            'darf' => $angeboten && $rechte->darf(Auth::user(), $team, FaRolle::Kuratieren),
            'typen' => $angeboten ? RezeptTypVokabular::alle() : [],
            'typAbgeleitet' => $angeboten ? $zukauf->typFuerGp($gp) : null,
        ]);
    }
}
