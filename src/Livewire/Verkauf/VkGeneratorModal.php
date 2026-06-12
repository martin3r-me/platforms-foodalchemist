<?php

namespace Platform\FoodAlchemist\Livewire\Verkauf;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;
use Platform\FoodAlchemist\Services\RecipeGeneratorService;

/**
 * M6-06 / D-6 §4.3 + N3: ✨ VK-Generator v1 — der Pain-Point: Basisrezept→VK
 * automatisiert. D-5-Achsen + VK-Achsen Anlass/Serviceform/Kompositions-Stil
 * (Stil filtert den GL-13-Pairing-Block, Achse 10); Bestand-Hybrid-Resolver
 * mit Basisrezepten ZUERST; Accept setzt ist_verkaufsrezept=true + Klasse/AK
 * aus dem Vorschlag (validiert, Lineage ki). Aus-Foto/PDF blockiert (Martin-
 * Vision-Frage). Bio-Default konventionell — Bio nie zufällig.
 */
class VkGeneratorModal extends Component
{
    public string $beschreibung = '';

    public array $parameter = [
        'convenience' => 'standard', 'frische' => 'frisch', 'bio' => false,
        'niveau' => '', 'sektor' => '', 'diaet_hart' => '', 'aroma' => '',
        'anlass' => '', 'serviceform' => '', 'kompositions_stil' => '',
    ];

    public ?string $fehler = null;

    public ?array $ergebnis = null;

    #[On('vk-generator-modal.oeffnen')]
    public function oeffnen(): void
    {
        $this->reset('fehler', 'ergebnis', 'beschreibung');
        $this->dispatch('modal.open', name: 'vk-generator-modal');
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
            $resultat = $generator->generiere($team, trim($this->beschreibung), array_filter(
                $this->parameter, fn ($v) => $v !== '' && $v !== null,
            ), null, vkModus: true);
            $this->ergebnis = [
                'recipe_id' => $resultat['recipe']->id,
                'name' => $resultat['recipe']->name,
                'statistik' => $resultat['statistik'],
                'offene' => $resultat['offene'],
            ];
            $this->dispatch('recipe-gespeichert');
            $this->dispatch('vk-recipe-selected', id: $resultat['recipe']->id);
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function render()
    {
        return view('foodalchemist::livewire.verkauf.vk-generator-modal');
    }
}
