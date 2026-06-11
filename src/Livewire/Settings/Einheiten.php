<?php

namespace Platform\FoodAlchemist\Livewire\Settings;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Platform\FoodAlchemist\Services\VocabularyService;
use RuntimeException;

/**
 * M1-02 / D-1: Einheiten-Verwaltung — CRUD inkl. Stück-Default-Gewichten
 * (default_in_g/_ml). Inline-Edit mit Enter/Escape (D-1 §4), Inaktiv-Toggle
 * statt Löschen (AT-D1-04), Delete nur ohne GP-Referenzen.
 */
class Einheiten extends Component
{
    public bool $includeInactive = false;

    public ?int $editId = null;

    public array $form = [];

    public array $neu = ['slug' => '', 'display_de' => '', 'dimension' => '', 'default_in_g' => '', 'default_in_ml' => '', 'sort_order' => 50];

    public ?string $fehler = null;

    public function edit(int $id): void
    {
        $einheit = app(VocabularyService::class)
            ->listEinheiten($this->team(), true)
            ->firstWhere('id', $id);

        if ($einheit === null) {
            return;
        }

        $this->editId = $id;
        $this->fehler = null;
        $this->form = $einheit->only(['display_de', 'dimension', 'default_in_g', 'default_in_ml', 'is_approximate', 'sort_order']);
    }

    public function cancel(): void
    {
        $this->reset('editId', 'form', 'fehler');
    }

    public function save(): void
    {
        try {
            app(VocabularyService::class)->updateEinheit($this->team(), (int) $this->editId, $this->form);
            $this->cancel();
        } catch (RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function create(): void
    {
        try {
            app(VocabularyService::class)->createEinheit($this->team(), $this->neu);
            $this->reset('neu', 'fehler');
        } catch (RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function toggleInactive(int $id, bool $inactive): void
    {
        try {
            app(VocabularyService::class)->setEinheitInactive($this->team(), $id, $inactive);
        } catch (RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function delete(int $id): void
    {
        try {
            app(VocabularyService::class)->deleteEinheit($this->team(), $id);
        } catch (RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function render(VocabularyService $vocab)
    {
        $team = $this->team();

        return view('foodalchemist::livewire.settings.einheiten', [
            'team' => $team,
            'einheiten' => $vocab->listEinheiten($team, $this->includeInactive),
        ]);
    }

    private function team()
    {
        return Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
    }
}
