<?php

namespace Platform\FoodAlchemist\Livewire\Speiseplan;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistPaket;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplan;
use Platform\FoodAlchemist\Services\SpeiseplanService;

/**
 * M14-02 / Doc 15 §M14: Speiseplan-Raster — Belegung von Woche × Wochentag ×
 * Mahlzeit mit Concept/Paket/Gericht; Wiederholungs-Warnungen + Kosten/Tag.
 */
class Index extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'sp')]
    public ?int $selectedId = null;

    public int $woche = 1;

    public array $form = ['name' => '', 'zyklus_wochen' => 1, 'min_abstand_tage' => 0, 'status' => 'draft'];

    /** Aktive Zelle für den Inhalts-Picker: [wochentag, mahlzeit]. */
    public ?int $cellTag = null;

    public ?string $cellMahlzeit = null;

    public string $pickerTyp = 'concept';   // concept | paket | gericht

    public string $pickerSuche = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function neu(SpeiseplanService $svc): void
    {
        $sp = $svc->create($this->team(), ['name' => 'Neuer Speiseplan']);
        $this->waehle($sp->id, $svc);
    }

    public function waehle(int $id, SpeiseplanService $svc): void
    {
        $sp = $svc->detail($this->team(), $id);
        if ($sp === null) {
            return;
        }
        $this->selectedId = $id;
        $this->form = ['name' => $sp->name, 'zyklus_wochen' => $sp->zyklus_wochen, 'min_abstand_tage' => $sp->min_abstand_tage, 'status' => $sp->status];
        $this->woche = 1;
        $this->cellSchliessen();
    }

    public function speichern(SpeiseplanService $svc): void
    {
        if ($this->selectedId !== null) {
            $svc->update($this->team(), $this->selectedId, $this->form);
        }
    }

    public function loeschen(int $id, SpeiseplanService $svc): void
    {
        $svc->delete($this->team(), $id);
        if ($this->selectedId === $id) {
            $this->selectedId = null;
        }
    }

    public function wocheSetzen(int $w): void
    {
        $this->woche = max(1, $w);
        $this->cellSchliessen();
    }

    public function zelleOeffnen(int $wochentag, string $mahlzeit): void
    {
        $this->cellTag = $wochentag;
        $this->cellMahlzeit = $mahlzeit;
        $this->pickerSuche = '';
    }

    public function cellSchliessen(): void
    {
        $this->cellTag = null;
        $this->cellMahlzeit = null;
        $this->pickerSuche = '';
    }

    public function inhaltHinzu(string $typ, int $id, SpeiseplanService $svc): void
    {
        if ($this->selectedId === null || $this->cellTag === null || $this->cellMahlzeit === null) {
            return;
        }
        $feld = ['concept' => 'concept_id', 'paket' => 'paket_id', 'gericht' => 'vk_recipe_id'][$typ] ?? 'vk_recipe_id';
        $svc->addEintrag($this->team(), $this->selectedId, [
            'woche' => $this->woche, 'wochentag' => $this->cellTag, 'mahlzeit' => $this->cellMahlzeit, $feld => $id,
        ]);
        $this->pickerSuche = '';
    }

    public function eintragRaus(int $id, SpeiseplanService $svc): void
    {
        $svc->removeEintrag($this->team(), $id);
    }

    public function render(SpeiseplanService $svc)
    {
        $team = $this->team();
        $sp = $this->selectedId !== null ? $svc->detail($team, $this->selectedId) : null;

        $kandidaten = collect();
        if ($sp !== null && $this->cellTag !== null && $this->pickerSuche !== '') {
            $s = '%' . mb_strtolower($this->pickerSuche) . '%';
            $kandidaten = match ($this->pickerTyp) {
                'paket' => FoodAlchemistPaket::visibleToTeam($team)->whereRaw('LOWER(name) LIKE ?', [$s])->orderBy('name')->limit(15)->get(['id', 'name', 'preis_pro_person']),
                'gericht' => FoodAlchemistRecipe::visibleToTeam($team)->verkauf()->whereRaw('LOWER(name) LIKE ?', [$s])->orderBy('name')->limit(15)->get(['id', 'name', 'vk_netto']),
                default => FoodAlchemistConcept::visibleToTeam($team)->echte()->whereRaw('LOWER(name) LIKE ?', [$s])->orderBy('name')->limit(15)->get(['id', 'name', 'preis_pro_person_cache']),
            };
        }

        return view('foodalchemist::livewire.speiseplan.index', [
            'plaene' => $svc->paginateBrowser(['search' => $this->search], $team),
            'sp' => $sp,
            'raster' => $sp !== null ? $svc->raster($sp) : [],
            'kosten' => $sp !== null ? $svc->kosten($sp) : null,
            'wiederholungen' => $sp !== null ? collect($svc->wiederholungen($sp))->where('konflikt', true)->values()->all() : [],
            'mahlzeiten' => SpeiseplanService::MAHLZEITEN,
            'wochentage' => SpeiseplanService::WOCHENTAGE,
            'kandidaten' => $kandidaten,
        ])->layout('platform::layouts.app');
    }

    private function team()
    {
        return Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
    }
}
