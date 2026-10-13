<?php

namespace Platform\FoodAlchemist\Livewire\Recipes;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;
use Platform\FoodAlchemist\Enums\FaRolle;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\FaRechte;
use Platform\FoodAlchemist\Services\FassungService;
use Platform\FoodAlchemist\Support\MasterTeam;

/**
 * Spec 82 §12 B / §13 B6 — „Veröffentlichen …" im Master: Stand der letzten Fassung, ob der Arbeitsstand abweicht,
 * Allergen-Hinweis vor dem Veröffentlichen, Stamm-GP-Prüfung, Pflicht-Änderungsnotiz. Veröffentlichen selbst läuft
 * unverändert über FassungService::veroeffentlichen (dort hängen auch die Signale an die Abonnenten).
 */
class FassungModal extends Component
{
    public ?int $recipeId = null;

    public string $notiz = '';

    public ?string $fehler = null;

    public ?string $meldung = null;

    #[On('fassung-modal.oeffnen')]
    public function oeffnen(int $recipeId): void
    {
        $this->reset('notiz', 'fehler', 'meldung');
        $this->recipeId = $recipeId;
        $this->dispatch('modal.open', name: 'fassung-modal');
    }

    public function veroeffentlichen(): void
    {
        $this->fehler = $this->meldung = null;
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || $this->recipeId === null) {
            return;
        }
        try {
            $r = app(FassungService::class)->veroeffentlichen($team, $this->recipeId, $this->notiz, Auth::user());
            $nr = (int) FoodAlchemistRecipe::mitFassungen()->whereKey($r['fassung_id'])->value('fassung_nr');
            $this->meldung = $r['neu'] === []
                ? 'Keine Änderung seit der letzten Fassung. Es wurde nichts veröffentlicht.'
                : 'Fassung '.$nr.' veröffentlicht. Abonnenten sehen sie ab sofort.';
            $this->notiz = '';
            $this->dispatch('fassung-veroeffentlicht', recipeId: $this->recipeId);
        } catch (FaRechtFehltException|\RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function render()
    {
        $team = Auth::user()?->currentTeamRelation;
        $rezept = $this->recipeId !== null && $team !== null
            ? FoodAlchemistRecipe::where('team_id', $team->id)->where('ist_fassung', false)->find($this->recipeId) : null;
        $status = null;
        if ($rezept !== null && MasterTeam::istMaster($team)) {
            try {
                $status = app(FassungService::class)->status($rezept);
            } catch (\RuntimeException $e) {
                $this->fehler = $e->getMessage();
            }
        }

        return view('foodalchemist::livewire.recipes.fassung-modal', [
            'rezept' => $rezept,
            'status' => $status,
            'darf' => $team !== null && app(FaRechte::class)->darf(Auth::user(), $team, FaRolle::Freigeben),
        ]);
    }
}
