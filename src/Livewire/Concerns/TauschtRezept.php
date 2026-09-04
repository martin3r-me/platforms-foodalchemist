<?php

namespace Platform\FoodAlchemist\Livewire\Concerns;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Platform\FoodAlchemist\Enums\RecipeStatus;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\RecipeService;
use Platform\FoodAlchemist\Support\Suche;

/**
 * „Rezept in allen Verwendungen tauschen" (Spec 44, Dominique 2026-09-04) — das Pendant zum
 * GP-Tausch, gleichzeitig im Detail-Panel UND im Editor. Der GP-Tausch lag anfangs nur im Panel
 * und wurde später in den Editor KOPIERT; die Kopie lief auseinander (roher Status-String,
 * unfindbarer Reiter). Deshalb liegt die Mechanik hier von Anfang an EINMAL im Trait und die
 * Oberfläche in EINEM Blade-Partial.
 *
 * Erwartet an der einbindenden Komponente: `public ?int $recipeId`.
 */
trait TauschtRezept
{
    public string $tauschSuche = '';

    public ?string $fehlerTausch = null;

    public ?string $hinweisTausch = null;

    /** Alle eigenen Eltern-Zeilen dieses Rezepts auf $zielId umhängen + neu rechnen. */
    public function rezeptErsetzen(int $zielId): void
    {
        $this->fehlerTausch = null;
        $this->hinweisTausch = null;
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || $this->recipeId === null) {
            return;
        }
        try {
            $ergebnis = app(RecipeService::class)->ersetzeInVerwendungen($team, $this->recipeId, $zielId);
        } catch (\RuntimeException $e) {
            $this->fehlerTausch = $e->getMessage();

            return;
        }
        $this->tauschSuche = '';
        $this->hinweisTausch = $this->tauschMeldung($zielId, $ergebnis);
        $this->dispatch('recipe-gespeichert');                     // Browser + Panels: frische Aggregate
    }

    /** Ziel-Vorschläge zum Suchtext — Veraltet-Rezepte und das Rezept selbst sind keine Ziele. */
    protected function tauschKandidaten(): Collection
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || $this->recipeId === null || trim($this->tauschSuche) === '') {
            return collect();
        }

        return Suche::like(
            FoodAlchemistRecipe::visibleToTeam($team)
                ->whereKeyNot($this->recipeId)
                ->where('status', '!=', RecipeStatus::Deprecated->value),
            'name',
            $this->tauschSuche,
        )->orderBy('name')->limit(6)->get(['id', 'name', 'status', 'team_id', 'is_sales_recipe']);
    }

    /** @return array{zeilen: int, rezepte: int, fremd_zeilen: int, fremd_rezepte: int} */
    protected function tauschBilanz(): array
    {
        $team = Auth::user()?->currentTeamRelation;

        return $team !== null && $this->recipeId !== null
            ? app(RecipeService::class)->verwendungsBilanz($team, $this->recipeId)
            : ['zeilen' => 0, 'rezepte' => 0, 'fremd_zeilen' => 0, 'fremd_rezepte' => 0];
    }

    /** Ergebnis-Satz — nennt AUCH das Übersprungene (geerbt / Zyklus / Ziel schon drin). */
    private function tauschMeldung(int $zielId, array $e): string
    {
        $ziel = FoodAlchemistRecipe::find($zielId)?->name ?? "#{$zielId}";
        $satz = "{$e['zeilen']} Zeile(n) in {$e['rezepte']} Rezept(en) auf „{$ziel}“ umgehängt — Rezepte neu berechnet.";
        if (($e['fremd_rezepte'] ?? 0) > 0) {
            $satz .= " {$e['fremd_rezepte']} geerbtes/geerbte Rezept(e) unberührt (read-only, D1).";
        }
        if (($e['zyklus'] ?? []) !== []) {
            $satz .= ' Übersprungen (Zyklus): ' . implode(', ', $e['zyklus']) . '.';
        }
        if (($e['doppelt'] ?? []) !== []) {
            $satz .= ' Ziel war schon enthalten in: ' . implode(', ', $e['doppelt'])
                . ' — dort stehen jetzt zwei Zeilen (Mengen prüfen).';
        }

        return $satz;
    }
}
