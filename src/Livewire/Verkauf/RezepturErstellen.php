<?php

namespace Platform\FoodAlchemist\Livewire\Verkauf;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Platform\FoodAlchemist\Services\FaRechte;
use Platform\FoodAlchemist\Services\GerichtHuelleService;

/**
 * Menüpunkt „Rezeptur erstellen" im KI-Assistenten des Gerichts (Dominique 13.11.2026). Ein Gericht im Entwurf
 * ohne Komponenten trägt mit Name und Beschreibung schon alles, was die Planung braucht. Der Punkt legt KEINE
 * Rezeptur an, sondern öffnet die Planung (Reiter Gericht) mit einer vorbefüllten Session
 * ({@see GerichtHuelleService::sessionFuer}); dort startet der Mensch mit dem normalen Go, prüft den Bauplan und
 * gibt frei. Gebaut wird in DIESES Gericht. Spiegelt den Zukauf-Knopf am Grundprodukt (Gps\ZukaufRezept).
 *
 * Eigene Komponente, weil VkModal auf seiner Größen-Basis steht (Spec 85 §9).
 */
class RezepturErstellen extends Component
{
    public int $recipeId;

    public ?string $fehler = null;

    public function mount(int $recipeId): void
    {
        $this->recipeId = $recipeId;
    }

    public function planen(GerichtHuelleService $huelle)
    {
        $this->fehler = null;
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null) {
            return null;
        }
        try {
            $session = $huelle->sessionFuer($team, $this->recipeId);
        } catch (ModelNotFoundException) {
            $this->fehler = 'Gericht nicht gefunden oder nicht sichtbar.';

            return null;
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();

            return null;
        }

        return redirect()->route('foodalchemist.planung.index', ['session' => $session->id, 'open' => 1, 'tab' => 'gericht']);
    }

    public function render(FaRechte $rechte)
    {
        $team = Auth::user()?->currentTeamRelation;
        $angeboten = $team !== null && GerichtHuelleService::huelle($team, $this->recipeId) !== null;

        return view('foodalchemist::livewire.verkauf.rezeptur-erstellen', [
            // Wie „Planung anlegen": wer den Bereich Planung nutzen darf
            'angeboten' => $angeboten && $rechte->darfBereich(Auth::user(), $team, 'planung'),
        ]);
    }
}
