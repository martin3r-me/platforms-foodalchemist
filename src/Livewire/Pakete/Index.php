<?php

namespace Platform\FoodAlchemist\Livewire\Pakete;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Platform\FoodAlchemist\Services\PaketService;

/**
 * M10-02 / Doc 15 §M10: Paket-Browser — Pakete (bepreiste Bündel mehrerer
 * Gerichte für eine Rolle) anlegen, Gerichte zusammenstellen, Per-Person-Preis
 * pflegen (manuell oder auto aus den Gerichten). Liste links, Edit-/Gerichte-
 * Panel rechts (P-1, Kontext in der URL).
 */
class Index extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'role')]
    public string $rolleFilter = '';

    #[Url(as: 'b')]
    public ?int $selectedId = null;

    /** Editierbare Felder des gewählten Pakets. */
    public array $form = [
        'name' => '', 'role' => '', 'niveau' => '', 'preis_modus' => 'manuell',
        'preis_pro_person' => null, 'ek_pro_person' => null, 'wareneinsatz_prozent' => null,
        'description' => '',
    ];

    public string $gerichtSuche = '';

    /** Menge/Person je Paket-Gericht (keyed by paket_gericht-id). */
    public array $mengeForm = [];

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function neu(PaketService $svc): void
    {
        $team = $this->team();
        $b = $svc->create($team, ['name' => 'Neuer Paket', 'role' => $this->rolleFilter ?: null]);
        $this->waehle($b->id, $svc);
    }

    public function waehle(int $id, PaketService $svc): void
    {
        $b = $svc->detail($this->team(), $id);
        if ($b === null) {
            return;
        }
        $this->selectedId = $id;
        $this->form = [
            'name' => $b->name, 'role' => $b->role ?? '', 'niveau' => $b->niveau ?? '',
            'preis_modus' => $b->preis_modus, 'preis_pro_person' => $b->preis_pro_person,
            'ek_pro_person' => $b->ek_pro_person, 'wareneinsatz_prozent' => $b->wareneinsatz_prozent,
            'description' => $b->description ?? '',
        ];
        $this->mengeForm = $b->gerichte->mapWithKeys(fn ($g) => [$g->id => $g->quantity !== null ? (float) $g->quantity : null])->all();
        $this->gerichtSuche = '';
    }

    public function gerichtMengeSpeichern(int $rowId, PaketService $svc): void
    {
        if ($this->selectedId === null) {
            return;
        }
        $quantity = $this->mengeForm[$rowId] ?? null;
        $svc->setGerichtMenge($this->team(), $this->selectedId, $rowId, $quantity !== null && $quantity !== '' ? (float) $quantity : null);
    }

    public function gerichtHoch(int $rowId, PaketService $svc): void
    {
        $this->verschiebeGericht($rowId, -1, $svc);
    }

    public function gerichtRunter(int $rowId, PaketService $svc): void
    {
        $this->verschiebeGericht($rowId, 1, $svc);
    }

    private function verschiebeGericht(int $rowId, int $richtung, PaketService $svc): void
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

    public function speichern(PaketService $svc): void
    {
        if ($this->selectedId === null) {
            return;
        }
        $svc->update($this->team(), $this->selectedId, $this->form);
        $this->dispatch('paket-gespeichert');
        $this->waehle($this->selectedId, $svc);
    }

    public function loeschen(int $id, PaketService $svc): void
    {
        $svc->delete($this->team(), $id);
        if ($this->selectedId === $id) {
            $this->selectedId = null;
        }
    }

    public function gerichtHinzu(int $vkRecipeId, PaketService $svc): void
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

    public function gerichtRaus(int $vkRecipeId, PaketService $svc): void
    {
        if ($this->selectedId === null) {
            return;
        }
        $b = $svc->detail($this->team(), $this->selectedId);
        $ids = $b->gerichte->pluck('vk_recipe_id')->reject(fn ($id) => (int) $id === $vkRecipeId)->values();
        $svc->syncGerichte($this->team(), $this->selectedId, $ids->map(fn ($id) => ['vk_recipe_id' => (int) $id])->all());
        $this->waehle($this->selectedId, $svc);
    }

    public function neuBerechnen(PaketService $svc): void
    {
        if ($this->selectedId === null) {
            return;
        }
        $b = $svc->detail($this->team(), $this->selectedId);
        $svc->recomputePrice($b);
        $this->waehle($this->selectedId, $svc);
    }

    public function render(PaketService $svc)
    {
        $team = $this->team();
        $selected = $this->selectedId !== null ? $svc->detail($team, $this->selectedId) : null;

        return view('foodalchemist::livewire.pakete.index', [
            'pakete' => $svc->paginateBrowser(['search' => $this->search, 'role' => $this->rolleFilter], $team),
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
