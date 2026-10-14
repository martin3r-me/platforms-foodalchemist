<?php

namespace Platform\FoodAlchemist\Livewire\Recipes;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;
use Platform\FoodAlchemist\Enums\FaRolle;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistFormat;
use Platform\FoodAlchemist\Models\FoodAlchemistPaket;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\ConceptFassungService;
use Platform\FoodAlchemist\Services\FaRechte;
use Platform\FoodAlchemist\Services\FassungService;
use Platform\FoodAlchemist\Services\FormatFassungService;
use Platform\FoodAlchemist\Services\PaketFassungService;
use Platform\FoodAlchemist\Support\FassungMenue;
use Platform\FoodAlchemist\Support\MasterTeam;

/**
 * Spec 82 §12 B / §13 B6 — „Veröffentlichen …" im Master: Stand der letzten Fassung, ob der Arbeitsstand abweicht,
 * Allergen-Hinweis vor dem Veröffentlichen, Stamm-GP-Prüfung, Pflicht-Änderungsnotiz. Veröffentlichen selbst läuft
 * unverändert über FassungService::veroeffentlichen (dort hängen auch die Signale an die Abonnenten).
 *
 * PR 8: ein Dialog für ALLE Arten — Rezept/Gericht (`recipeId`, Bestand) sowie Paket, Konzept und Format (`art` + `objektId`). Dieselben Hinweise
 * (Stand, Abweichung, Allergen-Änderung, Blockade mit Liste, Notiz-Pflicht); die Bedingung für den Menüpunkt hält {@see FassungMenue}.
 */
class FassungModal extends Component
{
    public ?int $recipeId = null;

    /** 'recipe' (Rezept/Gericht, `recipeId`) | 'paket' | 'concept' | 'format' (`objektId`) */
    public string $art = 'recipe';

    public ?int $objektId = null;

    public string $notiz = '';

    public ?string $fehler = null;

    public ?string $meldung = null;

    #[On('fassung-modal.oeffnen')]
    public function oeffnen(?int $recipeId = null, string $art = 'recipe', ?int $objektId = null): void
    {
        $this->reset('notiz', 'fehler', 'meldung');
        $this->art = in_array($art, FassungMenue::ARTEN, true) ? $art : 'recipe';
        $this->recipeId = $this->art === 'recipe' ? $recipeId : null;
        $this->objektId = $this->art === 'recipe' ? $recipeId : $objektId;
        $this->dispatch('modal.open', name: 'fassung-modal');
    }

    public function veroeffentlichen(): void
    {
        $this->fehler = $this->meldung = null;
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || $this->objektId === null) {
            return;
        }
        try {
            $user = Auth::user();
            if ($this->art === 'recipe') {
                $r = app(FassungService::class)->veroeffentlichen($team, $this->objektId, $this->notiz, $user);
                $nr = (int) FoodAlchemistRecipe::mitFassungen()->whereKey($r['fassung_id'])->value('fassung_nr');
                $neu = $r['neu'] !== [];
            } else {
                $r = match ($this->art) {
                    'paket' => app(PaketFassungService::class)->veroeffentlichen($team, $this->objektId, $this->notiz, $user),
                    'concept' => app(ConceptFassungService::class)->veroeffentlichen($team, $this->objektId, $this->notiz, $user),
                    default => app(FormatFassungService::class)->veroeffentlichen($team, $this->objektId, $this->notiz, $user),
                };
                $nr = (int) $r['fassung_nr'];
                $neu = (bool) $r['neu'];
            }
            $this->meldung = ! $neu
                ? 'Keine Änderung seit der letzten Fassung. Es wurde nichts veröffentlicht.'
                : 'Fassung '.$nr.' veröffentlicht. Abonnenten sehen sie ab sofort.';
            $this->notiz = '';
            $this->dispatch('fassung-veroeffentlicht', recipeId: $this->recipeId, art: $this->art, objektId: $this->objektId);
        } catch (FaRechtFehltException|\RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    /** Das Objekt des Dialogs (Arbeitszeile des aktiven Teams, nie eine Fassung) oder null. */
    private function objekt(?\Platform\Core\Models\Team $team): ?object
    {
        if ($team === null || $this->objektId === null) {
            return null;
        }
        $modell = match ($this->art) {
            'paket' => FoodAlchemistPaket::class,
            'concept' => FoodAlchemistConcept::class,
            'format' => FoodAlchemistFormat::class,
            default => FoodAlchemistRecipe::class,
        };

        return $modell::where('team_id', $team->id)->where('ist_fassung', false)->find($this->objektId);
    }

    public function render()
    {
        $team = Auth::user()?->currentTeamRelation;
        $objekt = $this->objekt($team);
        $status = null;
        if ($objekt !== null && MasterTeam::istMaster($team)) {
            try {
                $status = match ($this->art) {
                    'paket' => app(PaketFassungService::class)->status($objekt),
                    'concept' => app(ConceptFassungService::class)->status($objekt),
                    'format' => app(FormatFassungService::class)->status($objekt),
                    default => app(FassungService::class)->status($objekt),
                };
                // eine Form für alle Arten: Rezepte kennen Stamm-GP-Verstöße und neue Unter-Rezepte, die übrigen eine Blockade mit Liste und Hinweise
                $status += ['blockiert' => null, 'hinweise' => [], 'neu_im_baum' => [], 'stamm_verstoesse' => [], 'allergen_felder' => []];
            } catch (\RuntimeException $e) {
                $this->fehler = $e->getMessage();
            }
        }

        return view('foodalchemist::livewire.recipes.fassung-modal', [
            'rezept' => $objekt,   // Name der View-Variable bleibt (Bestand); für andere Arten ist es Paket/Konzept/Format
            'art' => $this->art,
            'artLabel' => ['recipe' => 'Rezept', 'paket' => 'Paket', 'concept' => 'Konzept', 'format' => 'Format'][$this->art] ?? 'Rezept',
            'status' => $status,
            'darf' => $team !== null && app(FaRechte::class)->darf(Auth::user(), $team, FaRolle::Freigeben),
        ]);
    }
}
