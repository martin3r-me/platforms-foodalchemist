<?php

namespace Platform\FoodAlchemist\Livewire\Kalkulation;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Services\KalkulationService;
use Platform\FoodAlchemist\Services\SalesRecipeService;

/**
 * M12-02 / Doc 15 §M12 (K-06): Kalkulations-Übersicht — Gerichte bzw. Concepts mit
 * HK1 (Wareneinsatz) · HK2 (Vollkosten = +Zuschlag/Nebenkosten) · VK · Vollkosten-
 * Deckungsbeitrag. Macht die Food-seitige Kosten-Wahrheit sichtbar (D-HK-1).
 */
class Index extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'tab')]
    public string $tab = 'gerichte';   // gerichte | concepts

    #[Url(as: 'sel')]
    public ?int $selectedId = null;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function setTab(string $tab): void
    {
        $this->tab = $tab === 'concepts' ? 'concepts' : 'gerichte';
        $this->selectedId = null;
        $this->resetPage();
    }

    public function waehle(int $id): void
    {
        $this->selectedId = $this->selectedId === $id ? null : $id;
    }

    public function render(KalkulationService $kalk, SalesRecipeService $sales, ConceptService $concepts)
    {
        $team = Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');

        if ($this->tab === 'concepts') {
            $page = $concepts->paginateBrowser(['search' => $this->search], $team, 50);
            $zeilen = $page->getCollection()->map(function ($c) use ($kalk, $team) {
                $h = $kalk->conceptHk($team, $c);

                return ['id' => $c->id, 'name' => $c->name, 'einheit' => '/Person',
                    'hk' => ['hk1' => $h['hk1_pro_person'], 'hk2' => $h['hk2_pro_person'],
                        'vk' => $h['vk_pro_person'], 'vk_vorschlag' => $h['vk_vorschlag'],
                        'db_eur' => $h['db_eur'], 'db_pct' => $h['db_pct']]];
            })->all();
        } else {
            $page = $sales->paginateBrowser(['search' => $this->search], $team, 50);
            $zeilen = $page->getCollection()->map(function ($r) use ($kalk, $team) {
                $hk = $kalk->recipeHk($team, $r);

                return [
                    'id' => $r->id, 'name' => $r->name, 'einheit' => '/Portion',
                    'hk' => ['hk1' => $hk['hk1_pro_portion'], 'hk2' => $hk['hk2_pro_portion'],
                        'vk' => $hk['vk_netto'], 'vk_vorschlag' => $hk['vk_vorschlag'],
                        'db_eur' => $hk['db_eur'], 'db_pct' => $hk['db_pct']],
                ];
            })->all();
        }

        // M-K4: Wasserfall-Detail für die ausgewählte Zeile (Block-Aufschlüsselung).
        $detail = null;
        if ($this->selectedId !== null) {
            if ($this->tab === 'concepts') {
                $c = $concepts->detail($team, $this->selectedId);
                $detail = $c !== null ? ['name' => $c->name, 'einheit' => '/Person', 'hk' => $kalk->conceptHk($team, $c)] : null;
            } else {
                $r = $sales->detail($team, $this->selectedId);
                $detail = $r !== null ? ['name' => $r->name, 'einheit' => '/Portion', 'hk' => $kalk->recipeHk($team, $r)] : null;
            }
            if ($detail === null) {
                $this->selectedId = null;
            }
        }

        return view('foodalchemist::livewire.kalkulation.index', [
            'page' => $page,
            'zeilen' => $zeilen,
            'detail' => $detail,
            'zuschlag' => $kalk->hk2($team, 100) - 100, // Anzeige: effektiver Zuschlag in % (auf 100 €)
        ])->layout('platform::layouts.app');
    }
}
