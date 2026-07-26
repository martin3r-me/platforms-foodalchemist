<?php

namespace Platform\FoodAlchemist\Livewire\Concerns;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Platform\FoodAlchemist\Jobs\GenerateRecipeJob;

/**
 * Spec 03 L7b — die EINE Generier-Strecke für beide Generator-Modals
 * (Basisrezept + Gericht) samt One-Shot-Toggle.
 *
 * Anlass ist eine Asymmetrie, die L7b sonst geerbt hätte (V-035): Der
 * 502-Fix von 2026-07-20 (LLM ~25 s + Matching + Aggregation reißen den
 * nginx-fastcgi-Timeout) wurde nur am Basisrezept-Modal umgesetzt; das
 * VK-Modal rief `RecipeGeneratorService::generiere()` weiter synchron in der
 * Livewire-Action — obwohl der VK-Pfad der teurere ist (zusätzliche
 * Taxonomie im Prompt, Kohärenz-Rechnung danach). Der One-Shot kostet 1–4
 * weitere Provider-Calls: am Basisrezept laufen sie in der 300-s-Queue-
 * Ausführung mit, im Web-Request lägen sie obendrauf. Ein Toggle dort wäre
 * also entweder gar nicht baubar oder einer, der garantiert timeoutet.
 *
 * Darum fahren jetzt beide Flächen `GenerateRecipeJob` (der `vkModus`
 * ohnehin schon als Parameter kennt) und pollen dasselbe Cache-Ergebnis.
 * Der Toggle hängt damit an genau EINER Stelle statt zweimal am Rand.
 *
 * Host-Vertrag: `$fehler` ist eine Property der einbindenden Komponente,
 * `auswahlEvent()` liefert das Selektions-Event ihrer Fläche
 * (`recipe-selected` bzw. `vk-recipe-selected`).
 */
trait HatGeneratorLauf
{
    /**
     * L7-DoD: Default AN — „Beschreibung rein, fertiges Rezept raus" ist der
     * Sinn des Knopfes. Aus lässt sich der Toggle für den schnellen Gerüst-Lauf
     * (kein zusätzlicher Provider-Call, Anreicherung später per Sammel-Klick).
     */
    public bool $vollAnreichern = true;

    /** Async: läuft, während der Queue-Job rechnet; UI pollt über die Run-ID. */
    public bool $laeuft = false;

    public ?string $runId = null;

    /** Ergebnis des One-Shot-Passes (`null` = Toggle war aus oder Lauf noch offen). */
    public ?array $anreicherung = null;

    /**
     * Generierung in die Queue geben. Der Web-Request kehrt sofort zurück —
     * die Dauer des Calls ist damit egal (kein 502).
     */
    protected function starteLauf(int $teamId, string $description, array $parameter, bool $vkModus): void
    {
        $this->runId = (string) Str::uuid();
        Cache::put(GenerateRecipeJob::cacheKey($this->runId), ['status' => 'pending'], now()->addMinutes(15));
        GenerateRecipeJob::dispatch(
            $this->runId, $teamId, (int) Auth::id(), $description, $parameter, $vkModus, $this->vollAnreichern
        );
        $this->laeuft = true;
    }

    /** Poll-Ziel (wire:poll während $laeuft): liest den Job-Ausgang aus dem Cache. */
    public function pruefeErgebnis(): void
    {
        if ($this->runId === null) {
            return;
        }
        $stand = Cache::get(GenerateRecipeJob::cacheKey($this->runId));
        if (! is_array($stand) || ($stand['status'] ?? null) === 'pending') {
            return;   // noch am Rechnen → weiter pollen
        }

        $this->laeuft = false;
        if (($stand['status'] ?? null) === 'error') {
            $this->fehler = $stand['fehler'] ?? 'Generierung fehlgeschlagen.';

            return;
        }

        $this->ergebnis = [
            'recipe_id' => $stand['recipe_id'],
            'name' => $stand['name'],
            'statistik' => $stand['statistik'],
            'offene' => $stand['offene'],
        ];
        // Der Pass wirft nie (L7a) — ein Fehlschlag steht als `fehler` im Block
        // und wird als Lücken-Zeile gezeigt, nicht als Generierungs-Fehler.
        $this->anreicherung = is_array($stand['anreicherung'] ?? null) ? $stand['anreicherung'] : null;

        $this->dispatch('recipe-gespeichert');
        $this->dispatch($this->auswahlEvent(), id: $stand['recipe_id']);
    }

    /** Selektions-Event der jeweiligen Fläche (Basisrezept-Browser vs. VK-Browser). */
    abstract protected function auswahlEvent(): string;
}
