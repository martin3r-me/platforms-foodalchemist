<?php

namespace Platform\FoodAlchemist\Livewire\Blaetter;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\PlanungsblattService;

/**
 * R7.1 — Operative Planungs-Blätter: Ziel wählen (Konzept + Personen ODER
 * Gericht + Portionen), Produktionsblatt + Bestellvorschlag live sehen, als
 * PDF/Druck ausgeben. Read-only — reine Kaskaden-Ausgabe, kein Schreibpfad.
 */
class Index extends Component
{
    #[Url(as: 'typ')]
    public string $zielTyp = 'concept';

    #[Url(as: 'c')]
    public ?int $conceptId = null;

    #[Url(as: 'r')]
    public ?int $recipeId = null;

    #[Url(as: 'n')]
    public int $menge = 100;

    /**
     * Welche Blätter erzeugt/angezeigt werden (Filter — Dominique 2026-07-14).
     * „einkauf" ist raus (Spec 17 E8: war Dublette zur Bestellung; die Event-
     * Aggregation über mehrere Ziele kommt mit der Bestellschiene in S2).
     */
    #[Url(as: 'b')]
    public array $blaetter = ['produktion', 'bestellung'];

    public string $suche = '';

    public function updatedZielTyp(): void
    {
        $this->conceptId = null;
        $this->recipeId = null;
    }

    public function waehleGericht(int $id): void
    {
        $this->recipeId = $id;
    }

    public function render(PlanungsblattService $svc)
    {
        $team = Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');

        $konzepte = FoodAlchemistConcept::visibleToTeam($team)->orderBy('name')->get(['id', 'name']);

        $treffer = collect();
        if ($this->zielTyp === 'recipe' && trim($this->suche) !== '') {
            $treffer = FoodAlchemistRecipe::visibleToTeam($team)->verkauf()
                ->where('name', 'like', '%' . trim($this->suche) . '%')
                ->orderBy('name')->limit(20)->get(['id', 'name']);
        }

        $menge = max(1, $this->menge);
        $ziel = $this->aktuellesZiel($menge);

        // Nur die ausgewählten Blätter rechnen (Filter).
        $produktion = null;
        $bestellung = null;
        if ($ziel !== null) {
            if (in_array('produktion', $this->blaetter, true)) {
                $produktion = $svc->produktionsblatt($team, $ziel);
            }
            if (in_array('bestellung', $this->blaetter, true)) {
                $bestellung = $svc->bestellvorschlag($team, $ziel);
            }
        }

        return view('foodalchemist::livewire.blaetter.index', [
            'konzepte' => $konzepte,
            'treffer' => $treffer,
            'gewaehltesGericht' => $this->recipeId ? FoodAlchemistRecipe::visibleToTeam($team)->find($this->recipeId) : null,
            'produktion' => $produktion,
            'bestellung' => $bestellung,
            'dokUrlParams' => $this->urlParams($menge),
            'mengeLabel' => $this->zielTyp === 'concept' ? 'Personen' : 'Portionen',
        ])->layout('platform::layouts.app');
    }

    /** @return array{concept_id?:int, recipe_id?:int, persons?:int, portions?:int}|null */
    private function aktuellesZiel(int $menge): ?array
    {
        if ($this->zielTyp === 'concept' && $this->conceptId) {
            return ['concept_id' => $this->conceptId, 'persons' => $menge];
        }
        if ($this->zielTyp === 'recipe' && $this->recipeId) {
            return ['recipe_id' => $this->recipeId, 'portions' => $menge];
        }

        return null;
    }

    /** Query-Parameter fürs Dokument (Deep-Link zu den PDF-Routen). */
    private function urlParams(int $menge): array
    {
        if ($this->zielTyp === 'concept' && $this->conceptId) {
            return ['concept_id' => $this->conceptId, 'persons' => $menge];
        }
        if ($this->zielTyp === 'recipe' && $this->recipeId) {
            return ['recipe_id' => $this->recipeId, 'portions' => $menge];
        }

        return [];
    }
}
