<?php

namespace Platform\FoodAlchemist\Livewire\Settings;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Platform\FoodAlchemist\Livewire\Settings\Concerns\ReordersLists;
use Platform\FoodAlchemist\Services\VocabularyService;
use RuntimeException;

/**
 * M1-03 / Regelwerk GP §3 (v3.4): Warengruppen voll editierbar — die 15 kanonischen
 * sind nur noch Seed/Empfehlung. Anlegen + Umbenennen (Code stabil) + Löschen (hart
 * wenn unbenutzt, sonst per GP-Referenz locked). Plus Sub-Kategorien-Housekeeping.
 */
class Warengruppen extends Component
{
    use ReordersLists;

    public ?int $editId = null;

    public string $editName = '';

    public string $neuWg = '';

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

    /** Eigene Warengruppe anlegen (v3.4 — die 15 kanonischen sind nur Empfehlung). */
    public function wgNeu(): void
    {
        if (trim($this->neuWg) === '') {
            return;
        }
        try {
            $wg = app(VocabularyService::class)->createWarengruppe($this->team(), $this->neuWg);
            $this->reset('neuWg', 'fehler');
            $this->subWg = $wg->code;
            $this->meldung = "Warengruppe „{$wg->name}\" angelegt (Code {$wg->code}).";
        } catch (RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function deleteWg(int $id): void
    {
        try {
            app(VocabularyService::class)->deleteWarengruppe($this->team(), $id);
            $this->meldung = 'Warengruppe gelöscht.';
        } catch (RuntimeException $e) {
            $this->fehler = $e->getMessage(); // genutzte WG werden durch den GP-Referenz-Guard locked
        }
    }

    // ── Warengruppen umsortieren (Pfeile / Drag-and-Drop) ──

    public function wgHoch(int $id): void
    {
        $this->reorderWg($id, -1);
    }

    public function wgRunter(int $id): void
    {
        $this->reorderWg($id, 1);
    }

    private function reorderWg(int $id, int $richtung): void
    {
        $vocab = app(VocabularyService::class);
        $ids = $vocab->listWarengruppen($this->team())->pluck('id')->all();
        if ($neu = $this->reorderNachbar($ids, $id, $richtung)) {
            $vocab->reorderWarengruppen($this->team(), $neu);
        }
    }

    public function wgVerschieben(int $id, int $afterId): void
    {
        $vocab = app(VocabularyService::class);
        $ids = $vocab->listWarengruppen($this->team())->pluck('id')->all();
        if ($neu = $this->reorderHinter($ids, $id, $afterId)) {
            $vocab->reorderWarengruppen($this->team(), $neu);
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

    /** Sub-Kategorie eine Position höher (▲). Index bezieht sich auf die angezeigte Reihenfolge. */
    public function subHoch(int $index): void
    {
        $this->verschiebeSub($index, -1);
    }

    /** Sub-Kategorie eine Position tiefer (▼). */
    public function subRunter(int $index): void
    {
        $this->verschiebeSub($index, 1);
    }

    private function verschiebeSub(int $index, int $richtung): void
    {
        $namen = $this->subNamenInReihenfolge();
        $ziel = $index + $richtung;
        if (! isset($namen[$index], $namen[$ziel])) {
            return;
        }
        [$namen[$index], $namen[$ziel]] = [$namen[$ziel], $namen[$index]];
        app(VocabularyService::class)->reorderSubCategories($this->team(), $this->subWg, $namen);
    }

    /** Drag-and-Drop: gezogene Zeile ($von) hinter die Ziel-Zeile ($nach) einsortieren. */
    public function subVerschieben(int $von, int $nach): void
    {
        $namen = $this->subNamenInReihenfolge();
        if (! isset($namen[$von], $namen[$nach]) || $von === $nach) {
            return;
        }
        $item = $namen[$von];
        $zielName = $namen[$nach];
        unset($namen[$von]);
        $namen = array_values($namen);
        $at = array_search($zielName, $namen, true);
        array_splice($namen, $at + 1, 0, [$item]);
        app(VocabularyService::class)->reorderSubCategories($this->team(), $this->subWg, $namen);
    }

    /** @return array<int, string> angezeigte Sub-Kategorie-Namen der gewählten WG, in Anzeige-Reihenfolge. */
    private function subNamenInReihenfolge(): array
    {
        return app(VocabularyService::class)
            ->listSubCategories($this->team(), $this->subWg)
            ->pluck('sub_category')
            ->values()
            ->all();
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
