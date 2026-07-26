<?php

namespace Platform\FoodAlchemist\Livewire\Concerns;

use Illuminate\Support\Facades\Auth;
use Platform\FoodAlchemist\Services\RecipeReviewService;

/**
 * Spec 03 L6b — die Copilot-Fläche für beide Editoren (RecipeModal + VkModal).
 *
 * Bewusst ein Trait und keine zweite Kopie: die Ebenen-Wahl (Basis vs. Gericht)
 * fällt schon im `RecipeReviewService` über `is_sales_recipe`, das UI-Verhalten
 * ist danach identisch. Kopiert wäre der Unterschied zwischen den Flächen nur
 * eine Frage der Zeit — genau die L1-Lücke, die diese Etappe nicht wiederholen
 * soll.
 *
 * Host-Vertrag: `$recipeId`, `$fehler`, `$zutatenVersion` (Re-Mount des
 * eingebetteten Zutaten-Editors) sind Properties der einbindenden Komponente.
 */
trait HatRezeptCopilot
{
    public bool $copilotOffen = false;

    /** @var ?array{gesamturteil:string, confidence:float, befunde:array<int, array<string,mixed>>} Read-only-Befunde — NICHTS persistiert */
    public ?array $copilot = null;

    /** Kurzmeldung unter den Karten (übernommen / nichts gefunden) — reine Anzeige. */
    public ?string $copilotStatus = null;

    /** Prüf-Pass: read-only. Persistiert nur den Gateway-Audit (GL-07 I3). */
    public function copilotPruefen(): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || $this->recipeId === null) {
            return;
        }
        $this->fehler = null;
        $this->copilotStatus = null;

        try {
            $this->copilot = app(RecipeReviewService::class)->pruefe($team, $this->recipeId);
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();
            $this->copilot = null;

            return;
        }

        $this->copilotOffen = true;
        if (($this->copilot['befunde'] ?? []) === []) {
            // Kein Befund IST ein Ergebnis — keine Fehlzeile, sondern eine Entwarnung.
            $this->copilotStatus = 'Keine Befunde — der Copilot hat nichts zu beanstanden.';
        }
    }

    /**
     * Übernahme genau EINES Befunds. Der Schreib-Weg liegt im Service; hier
     * bleibt nur die Frage, was danach mit den ÜBRIGEN Befunden passiert:
     * sie werden gegen den frischen Bestand neu bewertet (`bewerte`), weil ein
     * `entfernen` die Zielzeile eines anderen Befunds gerade gelöscht haben kann.
     * Ohne das würde die zweite Karte gegen eine Zeile schreiben, die es nicht
     * mehr gibt — bzw. der Knopf bliebe grün, obwohl der Befund tot ist.
     */
    public function copilotUebernehmen(int $index): void
    {
        $team = Auth::user()?->currentTeamRelation;
        $befund = $this->copilot['befunde'][$index] ?? null;
        if ($team === null || $this->recipeId === null || $befund === null) {
            return;
        }
        $this->fehler = null;

        try {
            app(RecipeReviewService::class)->uebernehmen($team, $this->recipeId, $befund);
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();

            return;
        }

        $rest = $this->copilot['befunde'];
        unset($rest[$index]);
        $this->copilot['befunde'] = app(RecipeReviewService::class)
            ->bewerte($team, $this->recipeId, array_values($rest));
        $this->copilotStatus = 'Befund übernommen — Kalkulation neu gerechnet.';

        // #511-Event-Kette: der eingebettete Zutaten-Editor lebt im Client, seine
        // rows überleben ein reines Re-Render → Version hochzählen erzwingt Re-Mount.
        $this->zutatenVersion++;
        $this->dispatch('recipe-gespeichert');
    }

    /** „Alle übernehmen" = nur `auto_applicable`, einer nach dem anderen, mit Neubewertung dazwischen. */
    public function copilotAlleUebernehmen(): void
    {
        $n = 0;
        while (true) {
            $index = null;
            foreach (($this->copilot['befunde'] ?? []) as $i => $b) {
                if (($b['auto_applicable'] ?? false) === true) {
                    $index = $i;
                    break;
                }
            }
            if ($index === null) {
                break;
            }
            $vorher = count($this->copilot['befunde']);
            $this->copilotUebernehmen((int) $index);
            if ($this->fehler !== null || count($this->copilot['befunde'] ?? []) >= $vorher) {
                break;                                             // Fortschritts-Garantie gegen Endlosschleife
            }
            $n++;
        }

        if ($n > 0) {
            $offen = count($this->copilot['befunde'] ?? []);
            $this->copilotStatus = $n.' Befund(e) übernommen'
                .($offen > 0 ? ' — '.$offen.' bleibt/bleiben zur Ansicht.' : '.');
        }
    }

    public function copilotVerwerfen(): void
    {
        $this->copilot = null;                                     // reject lässt Fachdaten unberührt (GL-07)
        $this->copilotStatus = null;
    }

    /** Beim Rezept-Wechsel aufrufen — Befunde des Vorgängers dürfen nicht ins nächste Rezept lecken. */
    protected function copilotZuruecksetzen(): void
    {
        $this->reset(['copilotOffen', 'copilot', 'copilotStatus']);
    }
}
