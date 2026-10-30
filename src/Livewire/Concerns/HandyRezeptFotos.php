<?php

namespace Platform\FoodAlchemist\Livewire\Concerns;

use Illuminate\Support\Facades\Auth;
use Livewire\WithFileUploads;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeStep;
use Platform\FoodAlchemist\Services\BearbeitungssperreService;
use Platform\FoodAlchemist\Services\RecipeService;
use Platform\FoodAlchemist\Services\RezeptFotoService;
use Platform\FoodAlchemist\Support\HandyLesemodus;

/**
 * #1003 Fotos am Handy (Dominique 30.10., bewusste Ausnahme vom Lesemodus wie die Fundstücke): im Handbuch-Detail von
 * Rezept und Gericht ein Foto aufnehmen — als Hauptbild, zu einem Arbeitsschritt oder zu einem Anrichte-Schritt.
 * Dieselben Wege wie am Computer: Hauptbild über RecipeService::storeDishImage (wie RecipeModal/VkModal), Schritt- und
 * Anrichte-Fotos über RezeptFotoService::schrittFoto (wie StepEditor). Nur am eigenen Rezept; die Bearbeitungssperre
 * (Spec 65) holt handyFotoSpeichern kurz selbst und gibt sie danach frei — hält sie jemand anderes, wird abgelehnt.
 * Zentral abschaltbar über config('foodalchemist.handy.aendern_frei') (Schlüssel handyFotoSpeichern).
 * Die Komponente nimmt handyFotoMethoden() in handyLeseMethoden() und sperrFreiExtra() auf.
 */
trait HandyRezeptFotos
{
    use WithFileUploads;

    public $handyFoto = null;

    /** hauptbild | schritt:<id> | anrichten:<id> */
    public string $handyFotoZiel = 'hauptbild';

    public string $handyFotoCaption = '';

    public ?string $handyFotoMeldung = null;

    /** Upload-Aufrufe nur, solange das Foto-Aufnehmen freigeschaltet ist; handyFotoSpeichern selbst prüft frei(). */
    public static function handyFotoMethoden(): array
    {
        return HandyLesemodus::frei('handyFotoSpeichern')
            ? ['handyFotoSpeichern', 'handyFotoZielWaehlen', '_startUpload', '_finishUpload', '_uploadErrored', '_removeUpload']
            : [];
    }

    public function handyFotoZielWaehlen(string $ziel): void
    {
        $this->handyFotoZiel = preg_match('/^(hauptbild|schritt:\d+|anrichten:\d+)$/', $ziel) ? $ziel : 'hauptbild';
        $this->handyFotoMeldung = null;
    }

    public function handyFotoSpeichern(): void
    {
        $this->handyFotoMeldung = null;
        if (! HandyLesemodus::frei('handyFotoSpeichern')) {
            return;
        }
        $this->validate(['handyFoto' => 'required|image|max:' . RezeptFotoService::MAX_KB], [], ['handyFoto' => 'Foto']);

        $team = Auth::user()?->currentTeamRelation;
        $rezept = $team !== null && $this->recipeId !== null ? FoodAlchemistRecipe::visibleToTeam($team)->find($this->recipeId) : null;
        if ($rezept === null || ! $rezept->isOwnedBy($team)) {
            $this->handyFotoMeldung = 'Geerbtes Rezept: Fotos nur durchs Besitzer-Team.';

            return;
        }

        // Bearbeitungssperre wie am Computer: kurz selbst holen (oder die eigene nutzen), danach wieder freigeben.
        $sperre = app(BearbeitungssperreService::class);
        $uid = (int) Auth::id();
        $selbstGeholt = false;
        if (self::sperreAktiv() && ! $sperre->haelt('recipe', $rezept->id, $uid)) {
            $r = $sperre->sperren('recipe', $rezept->id, $uid, Auth::user()?->name, $team->id);
            if (! $r['ok']) {
                $this->handyFotoMeldung = 'Wird gerade von ' . ($r['inhaber']['name'] ?? 'jemand anderem') . ' bearbeitet. Bitte später noch einmal.';

                return;
            }
            $selbstGeholt = true;
        }

        try {
            [$art, $stepId] = array_pad(explode(':', $this->handyFotoZiel, 2), 2, null);
            if ($art === 'hauptbild') {
                app(RecipeService::class)->storeDishImage($team, $rezept->id, $this->handyFoto);
            } else {
                app(RezeptFotoService::class)->schrittFoto($team, $rezept, $this->handyFoto, $this->handyFotoCaption, (int) $stepId,
                    $art === 'anrichten' ? FoodAlchemistRecipeStep::EBENE_ANRICHTEN : FoodAlchemistRecipeStep::EBENE_PRODUKTION);
            }
        } finally {
            if ($selbstGeholt) {
                $sperre->freigeben('recipe', $rezept->id, $uid);
            }
        }

        $this->reset('handyFoto', 'handyFotoCaption');
        $this->handyFotoMeldung = 'Foto gespeichert.';
    }
}
