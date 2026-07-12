<?php

namespace Platform\FoodAlchemist\Livewire\Recipes;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Platform\FoodAlchemist\Services\FeedbackService;

/**
 * R2.6 — Geteilter Feedback-Tab für Gericht- (VkModal) und Basisrezept-Editor
 * (RecipeModal), eingebettet via @livewire. Listet Praxis-Feedback (Küche/Kunde/
 * Event), zeigt das Ø-Aggregat, nimmt neue Einträge auf und bietet die
 * „Weiterentwickeln"-Brücke (Feedback → Draft-Iteration).
 */
class FeedbackPanel extends Component
{
    public int $recipeId;

    /** Formular */
    #[Validate('required|in:kueche,kunde,event')]
    public string $quelle = 'kueche';

    #[Validate('nullable|integer|min:1|max:5')]
    public ?int $score = null;

    #[Validate('nullable|integer|min:1|max:5')]
    public ?int $machbarkeit = null;

    #[Validate('nullable|integer|min:1|max:5')]
    public ?int $aufwand = null;

    #[Validate('nullable|integer|min:1|max:5')]
    public ?int $geschmack = null;

    #[Validate('nullable|integer|min:1|max:5')]
    public ?int $gaeste_reaktion = null;

    #[Validate('nullable|string|max:2000')]
    public ?string $comment = null;

    #[Validate('nullable|string|max:120')]
    public ?string $kontext_label = null;

    #[Validate('nullable|date')]
    public ?string $kontext_datum = null;

    public function mount(int $recipeId): void
    {
        $this->recipeId = $recipeId;
    }

    private function team()
    {
        return Auth::user()?->currentTeamRelation;
    }

    public function speichern(FeedbackService $svc): void
    {
        $this->validate();
        $team = $this->team();
        if ($team === null) {
            $this->addError('quelle', 'Kein Team im Kontext.');

            return;
        }
        try {
            $svc->erstelle($team, $this->recipeId, [
                'quelle' => $this->quelle,
                'score' => $this->score,
                'machbarkeit' => $this->machbarkeit,
                'aufwand' => $this->aufwand,
                'geschmack' => $this->geschmack,
                'gaeste_reaktion' => $this->gaeste_reaktion,
                'comment' => $this->comment,
                'kontext_label' => $this->kontext_label,
                'kontext_datum' => $this->kontext_datum,
                'author_user_id' => Auth::id(),
                'created_via' => 'fa_ui',
            ]);
        } catch (\InvalidArgumentException $e) {
            $this->addError('comment', $e->getMessage());

            return;
        }
        $this->reset(['score', 'machbarkeit', 'aufwand', 'geschmack', 'gaeste_reaktion', 'comment', 'kontext_label', 'kontext_datum']);
        $this->dispatch('feedback-aktualisiert', recipeId: $this->recipeId); // Browser/Editor können Ø nachziehen
    }

    public function loeschen(int $id, FeedbackService $svc): void
    {
        $team = $this->team();
        if ($team === null) {
            return;
        }
        try {
            $svc->loeschen($team, $id);
            $this->dispatch('feedback-aktualisiert', recipeId: $this->recipeId);
        } catch (\Throwable $e) {
            $this->addError('quelle', $e->getMessage());
        }
    }

    public function weiterentwickeln(int $id, FeedbackService $svc): void
    {
        $team = $this->team();
        if ($team === null) {
            return;
        }
        $iteration = $svc->weiterentwickeln($team, $id);
        $this->dispatch('feedback-aktualisiert', recipeId: $this->recipeId);
        // Hinweis für den Nutzer; das neue Draft öffnet man über den Rezept-Browser.
        session()->flash('fa_feedback_hinweis', 'Draft-Iteration „' . $iteration->name . '" (#' . $iteration->id . ') angelegt — im Rezept-Browser als Entwurf sichtbar.');
    }

    public function render(FeedbackService $svc)
    {
        $team = $this->team();
        $aggregat = $team ? $svc->aggregat($team, $this->recipeId) : ['avg' => null, 'count' => 0, 'per_source' => [], 'recent' => []];
        $eintraege = $team ? $svc->fuerRezept($team, $this->recipeId) : collect();

        return view('foodalchemist::livewire.recipes.feedback-panel', [
            'aggregat' => $aggregat,
            'eintraege' => $eintraege,
            'ownTeamId' => $team?->id,
        ]);
    }
}
