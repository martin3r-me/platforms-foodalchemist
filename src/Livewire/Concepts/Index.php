<?php

namespace Platform\FoodAlchemist\Livewire\Concepts;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Platform\FoodAlchemist\Services\PaketService;
use Platform\FoodAlchemist\Services\ConceptService;

/**
 * M10-03/04/05 / Doc 15 §M10: Concept-Editor — Slot-Gerüst bauen, jeden Slot mit
 * einem Paket (austauschbar) ODER festem Gericht füllen, Live-Preis = Σ der
 * gespeicherten Paket-Preise. „Aus Vorlage starten" (Fork) + „Als Vorlage
 * speichern". Liste links, Editor + Cockpit rechts.
 */
class Index extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'vorlagen')]
    public bool $showVorlagen = false;

    #[Url(as: 'c')]
    public ?int $selectedId = null;

    #[Url(as: 'kat')]
    public string $categoryFilter = '';   // '' = alle · 'none' = ohne Kategorie · sonst Kategorie-ID

    public string $neueKategorie = '';

    public ?int $editKatId = null;

    public string $editKatName = '';

    public array $form = ['name' => '', 'anlass' => '', 'niveau' => '', 'category_id' => null, 'status' => 'draft', 'description' => ''];

    /** Pro Slot editierbare Rolle/Titel (keyed by slot-id). */
    public array $slotForm = [];

    public string $neuerSlotRolle = '';

    /** Slot, für den gerade ein festes Gericht gesucht wird. */
    public ?int $fillSlotId = null;

    public string $gerichtSuche = '';

    /** M13: Zielpreis-Konfigurator (Modus im Editor). */
    public bool $zielModus = false;

    public string $zielPreis = '';

    public ?array $zielVorschlag = null;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedShowVorlagen(): void
    {
        $this->resetPage();
    }

    public function neu(ConceptService $svc): void
    {
        $c = $svc->create($this->team(), ['name' => 'Neues Concept', 'is_vorlage' => $this->showVorlagen]);
        $this->waehle($c->id, $svc);
    }

    public function ausVorlage(int $vorlageId, ConceptService $svc): void
    {
        $vorlage = $svc->detail($this->team(), $vorlageId);
        if ($vorlage === null) {
            return;
        }
        $fork = $svc->forkVonVorlage($this->team(), $vorlageId, $vorlage->name . ' – Kopie');
        $this->showVorlagen = false;
        $this->waehle($fork->id, $svc);
    }

    public function waehle(int $id, ConceptService $svc): void
    {
        $c = $svc->detail($this->team(), $id);
        if ($c === null) {
            return;
        }
        $this->selectedId = $id;
        $this->form = [
            'name' => $c->name, 'anlass' => $c->anlass ?? '', 'niveau' => $c->niveau ?? '',
            'category_id' => $c->category_id, 'status' => $c->status, 'description' => $c->description ?? '',
        ];
        $this->slotForm = $c->slots->mapWithKeys(fn ($s) => [$s->id => ['role' => $s->role ?? '', 'titel' => $s->titel ?? '']])->all();
        $this->fillSlotId = null;
        $this->gerichtSuche = '';
        $this->zielModus = false;
        $this->zielVorschlag = null;
    }

    // ── M13: Zielpreis-Konfigurator ─────────────────────────────────────────

    public function zielpreisToggle(): void
    {
        $this->zielModus = ! $this->zielModus;
        $this->zielVorschlag = null;
    }

    public function zielpreisBerechnen(ConceptService $svc): void
    {
        $ziel = (float) str_replace(',', '.', $this->zielPreis);
        if ($this->selectedId === null || $ziel <= 0) {
            return;
        }
        $this->zielVorschlag = $svc->zielpreisVorschlag($this->team(), $this->selectedId, $ziel);
    }

    public function zielpreisUebernehmen(ConceptService $svc): void
    {
        if ($this->selectedId !== null && $this->zielVorschlag !== null) {
            $svc->zielpreisAnwenden($this->team(), $this->selectedId, $this->zielVorschlag['vorschlag']);
        }
        $this->zielVorschlag = null;
        $this->zielModus = false;
        if ($this->selectedId !== null) {
            $this->waehle($this->selectedId, $svc);
        }
    }

    public function speichern(ConceptService $svc): void
    {
        if ($this->selectedId !== null) {
            $svc->update($this->team(), $this->selectedId, $this->form);
        }
    }

    public function loeschen(int $id, ConceptService $svc): void
    {
        $svc->delete($this->team(), $id);
        if ($this->selectedId === $id) {
            $this->selectedId = null;
        }
    }

    public function slotHinzu(ConceptService $svc): void
    {
        if ($this->selectedId === null) {
            return;
        }
        $svc->addSlot($this->team(), $this->selectedId, ['role' => $this->neuerSlotRolle ?: null, 'titel' => $this->neuerSlotRolle ?: null]);
        $this->neuerSlotRolle = '';
        $this->waehle($this->selectedId, $svc);
    }

    public function slotSpeichern(int $slotId, ConceptService $svc): void
    {
        $svc->updateSlot($this->team(), $slotId, $this->slotForm[$slotId] ?? []);
    }

    public function slotRaus(int $slotId, ConceptService $svc): void
    {
        $svc->removeSlot($this->team(), $slotId);
        $this->waehle($this->selectedId, $svc);
    }

    public function slotHoch(int $slotId, ConceptService $svc): void
    {
        $this->verschiebeSlot($slotId, -1, $svc);
    }

    public function slotRunter(int $slotId, ConceptService $svc): void
    {
        $this->verschiebeSlot($slotId, 1, $svc);
    }

    private function verschiebeSlot(int $slotId, int $richtung, ConceptService $svc): void
    {
        if ($this->selectedId === null) {
            return;
        }
        $ids = $svc->detail($this->team(), $this->selectedId)->slots->pluck('id')->all();
        $pos = array_search($slotId, $ids, true);
        $ziel = $pos + $richtung;
        if ($pos === false || $ziel < 0 || $ziel >= count($ids)) {
            return;
        }
        [$ids[$pos], $ids[$ziel]] = [$ids[$ziel], $ids[$pos]];
        $svc->reorderSlots($this->team(), $this->selectedId, $ids);
        $this->waehle($this->selectedId, $svc);
    }

    public function fuellePaket(int $slotId, int $paketId, ConceptService $svc): void
    {
        $svc->fillSlot($this->team(), $slotId, ['package_id' => $paketId]);
        $this->waehle($this->selectedId, $svc);
    }

    public function fuelleGericht(int $slotId, int $vkRecipeId, ConceptService $svc): void
    {
        $svc->fillSlot($this->team(), $slotId, ['vk_recipe_id' => $vkRecipeId, 'quantity' => 1]);
        $this->fillSlotId = null;
        $this->gerichtSuche = '';
        $this->waehle($this->selectedId, $svc);
    }

    public function slotLeeren(int $slotId, ConceptService $svc): void
    {
        $svc->fillSlot($this->team(), $slotId, []);
        $this->waehle($this->selectedId, $svc);
    }

    public function gerichtPicker(int $slotId): void
    {
        $this->fillSlotId = $this->fillSlotId === $slotId ? null : $slotId;
        $this->gerichtSuche = '';
    }

    // ── M10c-B: Kategorien (Baum) ──────────────────────────────────────────

    public function kategorieWaehlen(string $wert): void
    {
        $this->categoryFilter = $this->categoryFilter === $wert ? '' : $wert;
        $this->resetPage();
    }

    public function kategorieNeu(ConceptService $svc): void
    {
        if (trim($this->neueKategorie) === '') {
            return;
        }
        $parent = is_numeric($this->categoryFilter) ? (int) $this->categoryFilter : null;
        $svc->createCategory($this->team(), $this->neueKategorie, $parent);
        $this->neueKategorie = '';
    }

    public function kategorieEditStart(int $id, string $name): void
    {
        $this->editKatId = $id;
        $this->editKatName = $name;
    }

    public function kategorieRename(ConceptService $svc): void
    {
        if ($this->editKatId !== null) {
            $svc->renameCategory($this->team(), $this->editKatId, $this->editKatName);
        }
        $this->editKatId = null;
        $this->editKatName = '';
    }

    public function kategorieLoeschen(int $id, ConceptService $svc): void
    {
        $svc->deleteCategory($this->team(), $id);
        if ($this->categoryFilter === (string) $id) {
            $this->categoryFilter = '';
        }
    }

    public function alsVorlage(ConceptService $svc): void
    {
        if ($this->selectedId !== null) {
            $svc->alsVorlageSpeichern($this->team(), $this->selectedId);
            $this->dispatch('concept-vorlage-gespeichert');
        }
    }

    public function render(ConceptService $svc)
    {
        $team = $this->team();
        $selected = $this->selectedId !== null ? $svc->detail($team, $this->selectedId) : null;
        $cockpit = $selected !== null ? $svc->preisCockpit($selected) : null;

        $tauschbar = [];
        if ($selected !== null) {
            foreach ($selected->slots as $slot) {
                $tauschbar[$slot->id] = $svc->tauschbarePakete($team, $slot);
            }
        }

        $kandidaten = $this->fillSlotId !== null && $this->gerichtSuche !== ''
            ? app(PaketService::class)->gerichtKandidaten($team, $this->gerichtSuche)
            : collect();

        return view('foodalchemist::livewire.concepts.index', [
            'concepts' => $svc->paginateBrowser([
                'search' => $this->search, 'vorlagen' => $this->showVorlagen,
                'category' => $this->categoryFilter !== '' ? $this->categoryFilter : null,
            ], $team),
            'selected' => $selected,
            'cockpit' => $cockpit,
            'rollup' => $selected !== null ? $svc->allergenRollup($selected) : null,
            'kategorienFlat' => $svc->categoriesFlat($team),
            'tauschbar' => $tauschbar,
            'kandidaten' => $kandidaten,
        ])->layout('platform::layouts.app');
    }

    private function team()
    {
        return Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
    }
}
