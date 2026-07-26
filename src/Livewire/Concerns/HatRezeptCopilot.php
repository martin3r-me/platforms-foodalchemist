<?php

namespace Platform\FoodAlchemist\Livewire\Concerns;

use Illuminate\Support\Facades\Auth;
use Platform\FoodAlchemist\Services\RecipeFindingService;
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
     * Spec 21 · S5b — die Befunde des letzten Batch-Laufs anzeigen, OHNE zu prüfen.
     *
     * Das ist der Landeplatz des Signals `rezept_plausi_ki`: es zählt abgelegte
     * Befunde, also muss die Fläche genau die zeigen. Ein Live-Pass an dieser Stelle
     * wäre gleich doppelt falsch — er kostet Egress bei jedem Sprung aus dem Cockpit,
     * und er könnte etwas anderes liefern als das, worauf das Signal zeigt.
     *
     * Frisch ist trotzdem die Anwendbarkeit: die Zeilen laufen durch `bewerte()`,
     * dieselbe Stelle wie nach jeder Übernahme. Ein Befund vom Vortag, dessen
     * Zielzeile inzwischen weg ist, kommt damit als nicht-anwendbar an statt mit
     * grünem Knopf.
     */
    public function copilotAusAblage(): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || $this->recipeId === null) {
            return;
        }
        $this->fehler = null;
        $this->copilotStatus = null;

        $abgelegt = app(RecipeFindingService::class)->offeneBefundeFuer($team, $this->recipeId);
        if ($abgelegt === []) {
            return;                                                // nichts abgelegt → normale Fläche, kein leerer Kasten
        }

        try {
            $befunde = app(RecipeReviewService::class)->bewerte($team, $this->recipeId, $abgelegt);
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();

            return;
        }

        $this->copilot = ['gesamturteil' => '', 'confidence' => 0.0, 'befunde' => $befunde];
        $this->copilotOffen = true;
        $this->copilotStatus = count($befunde).' offene(r) Befund(e) aus dem letzten Prüflauf — nicht neu geprüft.';
    }

    /**
     * „Lass das so" an EINEM Befund aus der Ablage. Gegenstück zur Übernahme und der
     * eigentliche Ruhigsteller: laut S5a hält `verworfen`, während ein übernommener
     * Befund wiederkommen darf (dann hat der Fix nicht gegriffen). Ohne diesen Knopf
     * bliebe ein bewusst akzeptierter Befund bis in alle Ewigkeit im Signal stehen.
     *
     * Live-Befunde (ohne `finding_id`) verschwinden nur aus der Ansicht — es gibt
     * nichts zu entscheiden, was Bestand hätte.
     */
    public function copilotBefundVerwerfen(int $index): void
    {
        $team = Auth::user()?->currentTeamRelation;
        $befund = $this->copilot['befunde'][$index] ?? null;
        if ($team === null || $befund === null) {
            return;
        }
        $this->fehler = null;

        if (($befund['finding_id'] ?? null) !== null) {
            try {
                app(RecipeFindingService::class)->entscheide($team, (int) $befund['finding_id'], 'verworfen');
            } catch (\RuntimeException $e) {
                $this->fehler = $e->getMessage();

                return;
            }
        }

        $rest = $this->copilot['befunde'];
        unset($rest[$index]);
        $this->copilot['befunde'] = array_values($rest);
        $this->copilotStatus = 'Befund verworfen — er kommt nicht wieder.';
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

        // S5b: kam der Befund aus der Ablage, wird er dort auch abgeschlossen — sonst
        // zählt `rezept_plausi_ki` einen erledigten Befund bis zum nächsten Batch weiter.
        // Bewusst `uebernommen` und nicht `verworfen`: greift der Fix nicht, darf der
        // Befund wiederkommen (S5a, Entscheidung 2).
        if (($befund['finding_id'] ?? null) !== null) {
            app(RecipeFindingService::class)->entscheide($team, (int) $befund['finding_id'], 'uebernommen');
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
