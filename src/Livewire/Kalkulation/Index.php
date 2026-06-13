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

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function setTab(string $tab): void
    {
        $this->tab = $tab === 'concepts' ? 'concepts' : 'gerichte';
        $this->resetPage();
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
                        'vk' => $h['vk_pro_person'], 'db_eur' => $h['db_eur'], 'db_pct' => $h['db_pct']]];
            })->all();
        } else {
            $page = $sales->paginateBrowser(['search' => $this->search], $team, 50);
            $zeilen = $page->getCollection()->map(function ($r) use ($kalk, $team) {
                $hk = $kalk->recipeHk($team, $r);

                return [
                    'id' => $r->id, 'name' => $r->name, 'einheit' => '/Portion',
                    'hk' => ['hk1' => $hk['hk1_pro_portion'], 'hk2' => $hk['hk2_pro_portion'],
                        'vk' => $hk['vk_netto'], 'db_eur' => $hk['db_eur'], 'db_pct' => $hk['db_pct']],
                ];
            })->all();
        }

        return view('foodalchemist::livewire.kalkulation.index', [
            'page' => $page,
            'zeilen' => $zeilen,
            'zuschlag' => $kalk->hk2($team, 100) - 100, // Anzeige: effektiver Zuschlag in % (auf 100 €)
        ])->layout('platform::layouts.app');
    }
}
