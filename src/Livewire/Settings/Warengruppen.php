<?php

namespace Platform\FoodAlchemist\Livewire\Settings;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Platform\FoodAlchemist\Services\VocabularyService;
use RuntimeException;

/**
 * M1-03 / Regelwerk GP §3: Warengruppen (read-mostly, Codes fix) +
 * Sub-Kategorien-Housekeeping (Rename propagiert auf GPs, Clear → NULL).
 */
class Warengruppen extends Component
{
    public ?int $editId = null;

    public string $editName = '';

    public string $subWg = '';

    public ?string $renameAlt = null;

    public string $renameNeu = '';

    public string $neuSub = '';

    public ?string $fehler = null;

    public ?string $meldung = null;

    public function waehleWg(string $code): void
    {
        $this->subWg = $code;
        $this->reset('editId', 'editName', 'renameAlt', 'renameNeu', 'fehler', 'meldung');
    }

    public function startEditName(int $id, string $aktuell): void
    {
        $this->editId = $id;
        $this->editName = $aktuell;
        $this->fehler = null;
    }

    public function saveName(): void
    {
        try {
            app(VocabularyService::class)->updateWarengruppeName($this->team(), (int) $this->editId, $this->editName);
            $this->reset('editId', 'editName');
        } catch (RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function deleteWg(int $id): void
    {
        try {
            app(VocabularyService::class)->deleteWarengruppe($this->team(), $id);
        } catch (RuntimeException $e) {
            $this->fehler = $e->getMessage(); // §3-Codes laufen IMMER hier rein
        }
    }

    public function startRename(string $wert): void
    {
        $this->renameAlt = $wert;
        $this->renameNeu = $wert;
        $this->fehler = null;
    }

    public function rename(): void
    {
        try {
            $n = app(VocabularyService::class)->renameSubCategory($this->team(), $this->subWg, (string) $this->renameAlt, $this->renameNeu);
            $this->meldung = "{$n} GP(s) umbenannt.";
            $this->reset('renameAlt', 'renameNeu');
        } catch (RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function clearWert(string $wert): void
    {
        $n = app(VocabularyService::class)->clearSubCategory($this->team(), $this->subWg, $wert);
        $this->meldung = "{$n} GP(s) auf NULL gesetzt.";
    }

    /** #371: verwaltete Sub-Kategorie in der gewählten Warengruppe anlegen. */
    public function addSub(): void
    {
        try {
            app(VocabularyService::class)->createSubCategory($this->team(), $this->subWg, $this->neuSub);
            $this->reset('neuSub', 'fehler');
            $this->meldung = 'Sub-Kategorie angelegt.';
        } catch (RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function render(VocabularyService $vocab)
    {
        $team = $this->team();
        $warengruppen = $vocab->listWarengruppen($team);

        if ($this->subWg === '' && $warengruppen->isNotEmpty()) {
            $this->subWg = $warengruppen->first()->code;
        }

        return view('foodalchemist::livewire.settings.warengruppen', [
            'team' => $team,
            'warengruppen' => $warengruppen,
            'paragraf3' => VocabularyService::PARAGRAF3_CODES,
            'subKategorien' => $this->subWg !== '' ? $vocab->listSubCategories($team, $this->subWg) : collect(),
        ]);
    }

    private function team()
    {
        return Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
    }
}
