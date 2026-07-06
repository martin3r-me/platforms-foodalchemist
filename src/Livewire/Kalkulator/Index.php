<?php

namespace Platform\FoodAlchemist\Livewire\Kalkulator;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;
use Platform\FoodAlchemist\Models\FoodAlchemistKalkulation;
use Platform\FoodAlchemist\Services\KalkulationDokService;

/**
 * M-K10 / Doc 15 §11: Standalone Kalkulations-Composer. Links die Bibliothek der
 * Kalkulationen, rechts der Editor: Positionen (Gericht/Basisrezept/GP/frei)
 * hinzufügen & entfernen → HK1/HK2/VK live. Sätze aus den Einstellungen, entkoppelt
 * vom Concepter (Prüfung).
 */
class Index extends Component
{
    #[Url(as: 'k')]
    public ?int $selectedId = null;

    /** Kopfdaten der gewählten Kalkulation (Editier-Puffer). */
    public string $titel = '';

    public ?string $margeOverride = null;

    public ?string $note = null;

    /** Positions-Picker. */
    public string $addTyp = 'gericht';   // gericht | basisrezept | gp | frei

    public string $addSuche = '';

    public ?string $meldung = null;

    private function team()
    {
        return Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
    }

    private function svc(): KalkulationDokService
    {
        return app(KalkulationDokService::class);
    }

    public function neueKalkulation(): void
    {
        $k = $this->svc()->create($this->team(), 'Neue Kalkulation');
        $this->waehle($k->id);
        $this->meldung = 'Kalkulation angelegt.';
    }

    public function waehle(int $id): void
    {
        $k = FoodAlchemistKalkulation::where('team_id', $this->team()->id)->find($id);
        if ($k === null) {
            $this->selectedId = null;

            return;
        }
        $this->selectedId = $k->id;
        $this->titel = (string) $k->title;
        $this->margeOverride = $k->marge_override_pct !== null
            ? rtrim(rtrim(number_format((float) $k->marge_override_pct, 2, '.', ''), '0'), '.') : '';
        $this->note = (string) ($k->note ?? '');
        $this->addSuche = '';
        $this->meldung = null;
    }

    public function speichereKopf(): void
    {
        if ($this->selectedId === null) {
            return;
        }
        $this->svc()->update($this->team(), $this->selectedId, [
            'title' => $this->titel,
            'marge_override_pct' => $this->margeOverride,
            'note' => $this->note,
        ]);
        $this->meldung = 'Gespeichert.';
    }

    public function loeschen(int $id): void
    {
        $this->svc()->delete($this->team(), $id);
        if ($this->selectedId === $id) {
            $this->selectedId = null;
        }
        $this->meldung = 'Kalkulation gelöscht.';
    }

    public function addPosition(?int $refId = null): void
    {
        if ($this->selectedId === null) {
            return;
        }
        $this->svc()->addPosition($this->team(), $this->selectedId, $this->addTyp, $refId);
        $this->addSuche = '';
        $this->meldung = null;
    }

    public function updatePos(int $id, string $field, $value): void
    {
        $this->svc()->updatePosition($this->team(), $id, [$field => $value]);
    }

    public function aktualisierePos(int $id): void
    {
        $this->svc()->refreshPosition($this->team(), $id);
        $this->meldung = 'Snapshot neu gezogen.';
    }

    public function entfernePos(int $id): void
    {
        $this->svc()->removePosition($this->team(), $id);
    }

    public function render()
    {
        $team = $this->team();
        $svc = $this->svc();

        $kalkulationen = $svc->liste($team);

        $aktiv = null;
        $berechnung = null;
        $quellen = [];
        if ($this->selectedId !== null) {
            $aktiv = FoodAlchemistKalkulation::where('team_id', $team->id)->find($this->selectedId);
            if ($aktiv === null) {
                $this->selectedId = null;
            } else {
                $berechnung = $svc->berechne($team, $aktiv);
                if ($this->addTyp !== 'frei') {
                    $quellen = $svc->quellen($team, $this->addTyp, $this->addSuche);
                }
            }
        }

        return view('foodalchemist::livewire.kalkulator.index', [
            'kalkulationen' => $kalkulationen,
            'active' => $aktiv,
            'berechnung' => $berechnung,
            'quellen' => $quellen,
        ])->layout('platform::layouts.app');
    }
}
