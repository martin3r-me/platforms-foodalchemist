<?php

namespace Platform\FoodAlchemist\Livewire\Recipes;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;
use Platform\FoodAlchemist\Services\RecipeGeneratorService;

/**
 * M4-14: ✨ Basisrezept-Generator — Beschreibung + Richtungs-Parameter
 * (Convenience/Frische/Bio/Niveau/Sektor/Diät-hart), Bestand-Hybrid-Resolver.
 * Aus-Foto/PDF blockiert auf die Martin-Vision-Frage (Hinweis im Modal).
 */
class GeneratorModal extends Component
{
    public string $beschreibung = '';

    public array $parameter = [
        'convenience' => 'standard', 'frische' => 'frisch', 'bio' => false,
        'niveau' => '', 'sektor' => '', 'diaet_hart' => '', 'aroma' => '',
    ];

    public ?string $fehler = null;

    public ?array $ergebnis = null;

    #[On('generator-modal.oeffnen')]
    public function oeffnen(): void
    {
        $this->reset('fehler', 'ergebnis', 'beschreibung');
        $this->dispatch('modal.open', name: 'generator-modal');
    }

    public function generieren(RecipeGeneratorService $generator): void
    {
        $this->fehler = null;
        $this->ergebnis = null;
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || trim($this->beschreibung) === '') {
            $this->fehler = 'Beschreibung ist Pflicht.';

            return;
        }

        try {
            $resultat = $generator->generiere($team, trim($this->beschreibung), $this->parameter);
            $this->ergebnis = [
                'recipe_id' => $resultat['recipe']->id,
                'name' => $resultat['recipe']->name,
                'statistik' => $resultat['statistik'],
                'offene' => $resultat['offene'],
            ];
            $this->dispatch('recipe-gespeichert');
            $this->dispatch('recipe-selected', id: $resultat['recipe']->id);
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function render()
    {
        return view('foodalchemist::livewire.recipes.generator-modal');
    }
}
