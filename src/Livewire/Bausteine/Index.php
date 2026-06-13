<?php

namespace Platform\FoodAlchemist\Livewire\Bausteine;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Platform\FoodAlchemist\Services\BausteinService;

/**
 * M10-02 / Doc 15 §M10: Baustein-Browser — Bausteine (bepreiste Bündel mehrerer
 * Gerichte für eine Rolle) anlegen, Gerichte zusammenstellen, Per-Person-Preis
 * pflegen (manuell oder auto aus den Gerichten). Liste links, Edit-/Gerichte-
 * Panel rechts (P-1, Kontext in der URL).
 */
class Index extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'rolle')]
    public string $rolleFilter = '';

    #[Url(as: 'b')]
    public ?int $selectedId = null;

    /** Editierbare Felder des gewählten Bausteins. */
    public array $form = [
        'name' => '', 'rolle' => '', 'niveau' => '', 'preis_modus' => 'manuell',
        'preis_pro_person' => null, 'ek_pro_person' => null, 'wareneinsatz_prozent' => null,
        'beschreibung' => '',
    ];

    public string $gerichtSuche = '';

    /** Menge/Person je Baustein-Gericht (keyed by baustein_gericht-id). */
    public array $mengeForm = [];

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function neu(BausteinService $svc): void
    {
        $team = $this->team();
        $b = $svc->create($team, ['name' => 'Neuer Baustein', 'rolle' => $this->rolleFilter ?: null]);
        $this->waehle($b->id, $svc);
    }

    public function waehle(int $id, BausteinService $svc): void
    {
        $b = $svc->detail($this->team(), $id);
        if ($b === null) {
            return;
        }
        $this->selectedId = $id;
        $this->form = [
            'name' => $b->name, 'rolle' => $b->rolle ?? '', 'niveau' => $b->niveau ?? '',
            'preis_modus' => $b->preis_modus, 'preis_pro_person' => $b->preis_pro_person,
            'ek_pro_person' => $b->ek_pro_person, 'wareneinsatz_prozent' => $b->wareneinsatz_prozent,
            'beschreibung' => $b->beschreibung ?? '',
        ];
        $this->mengeForm = $b->gerichte->mapWithKeys(fn ($g) => [$g->id => $g->menge !== null ? (float) $g->menge : null])->all();
        $this->gerichtSuche = '';
    }

    public function gerichtMengeSpeichern(int $rowId, BausteinService $svc): void
    {
        if ($this->selectedId === null) {
            return;
        }
        $menge = $this->mengeForm[$rowId] ?? null;
        $svc->setGerichtMenge($this->team(), $this->selectedId, $rowId, $menge !== null && $menge !== '' ? (float) $menge : null);
    }

    public function gerichtHoch(int $rowId, BausteinService $svc): void
    {
        $this->verschiebeGericht($rowId, -1, $svc);
    }

    public function gerichtRunter(int $rowId, BausteinService $svc): void
    {
        $this->verschiebeGericht($rowId, 1, $svc);
    }

    private function verschiebeGericht(int $rowId, int $richtung, BausteinService $svc): void
    {
        if ($this->selectedId === null) {
            return;
        }
        $ids = $svc->detail($this->team(), $this->selectedId)->gerichte->pluck('id')->all();
        $pos = array_search($rowId, $ids, true);
        $ziel = $pos + $richtung;
        if ($pos === false || $ziel < 0 || $ziel >= count($ids)) {
            return;
        }
        [$ids[$pos], $ids[$ziel]] = [$ids[$ziel], $ids[$pos]];
        $svc->reorderGerichte($this->team(), $this->selectedId, $ids);
        $this->waehle($this->selectedId, $svc);
    }

    public function speichern(BausteinService $svc): void
    {
        if ($this->selectedId === null) {
            return;
        }
        $svc->update($this->team(), $this->selectedId, $this->form);
        $this->dispatch('baustein-gespeichert');
        $this->waehle($this->selectedId, $svc);
    }

    public function loeschen(int $id, BausteinService $svc): void
    {
        $svc->delete($this->team(), $id);
        if ($this->selectedId === $id) {
            $this->selectedId = null;
        }
    }

    public function gerichtHinzu(int $vkRecipeId, BausteinService $svc): void
    {
        if ($this->selectedId === null) {
            return;
        }
        $b = $svc->detail($this->team(), $this->selectedId);
        $ids = $b->gerichte->pluck('vk_recipe_id')->push($vkRecipeId)->unique()->values();
        $svc->syncGerichte($this->team(), $this->selectedId, $ids->map(fn ($id) => ['vk_recipe_id' => (int) $id])->all());
        $this->gerichtSuche = '';
        $this->waehle($this->selectedId, $svc);
    }

    public function gerichtRaus(int $vkRecipeId, BausteinService $svc): void
    {
        if ($this->selectedId === null) {
            return;
        }
        $b = $svc->detail($this->team(), $this->selectedId);
        $ids = $b->gerichte->pluck('vk_recipe_id')->reject(fn ($id) => (int) $id === $vkRecipeId)->values();
        $svc->syncGerichte($this->team(), $this->selectedId, $ids->map(fn ($id) => ['vk_recipe_id' => (int) $id])->all());
        $this->waehle($this->selectedId, $svc);
    }

    public function neuBerechnen(BausteinService $svc): void
    {
        if ($this->selectedId === null) {
            return;
        }
        $b = $svc->detail($this->team(), $this->selectedId);
        $svc->recomputePrice($b);
        $this->waehle($this->selectedId, $svc);
    }

    public function render(BausteinService $svc)
    {
        $team = $this->team();
        $selected = $this->selectedId !== null ? $svc->detail($team, $this->selectedId) : null;

        return view('foodalchemist::livewire.bausteine.index', [
            'bausteine' => $svc->paginateBrowser(['search' => $this->search, 'rolle' => $this->rolleFilter], $team),
            'rollen' => $svc->rollen($team),
            'selected' => $selected,
            'kandidaten' => $selected !== null
                ? $svc->gerichtKandidaten($team, $this->gerichtSuche)->reject(
                    fn ($r) => $selected->gerichte->pluck('vk_recipe_id')->contains($r->id))
                : collect(),
        ])->layout('platform::layouts.app');
    }

    private function team()
    {
        return Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
    }
}
