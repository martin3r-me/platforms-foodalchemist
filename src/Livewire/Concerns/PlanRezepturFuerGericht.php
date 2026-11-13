<?php

namespace Platform\FoodAlchemist\Livewire\Concerns;

use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\GerichtHuelleService;

/**
 * Leitstelle, Reiter Gericht: Planung mit einem Ziel-Gericht (Dominique 13.11.2026). Kommt die Planung vom
 * Menüpunkt „Rezeptur erstellen" am Gericht ({@see GerichtHuelleService::sessionFuer}), trägt sie `target_recipe_id`.
 * Dann belegt dieser Trait Titel und Briefing des Gericht-Reiters aus der Planung vor (nur leere Felder, einmal je
 * Planung) und liefert das Ziel für den Hinweis im Reiter. Der Go bleibt der normale; der Bau schreibt in das Gericht.
 *
 * Eigener Trait, weil Planung\Index auf seiner Größen-Basis steht (Spec 85 §5).
 */
trait PlanRezepturFuerGericht
{
    /** Planung, deren Ziel-Gericht schon ins Gericht-Briefing übernommen wurde. */
    public ?int $rezepturVorbelegtFuer = null;

    /** Livewire-Hook: nach mount() bzw. hydrate(), vor Aktionen und Render. */
    public function bootedPlanRezepturFuerGericht(): void
    {
        $session = $this->sessionId !== null ? $this->aktiveSession() : null;
        if ($session === null || $session->target_recipe_id === null || $this->rezepturVorbelegtFuer === (int) $session->id
            || ! isset($this->eingabe['gericht'])) {
            return;
        }
        $this->rezepturVorbelegtFuer = (int) $session->id;
        if (trim((string) ($this->eingabe['gericht']['titel'] ?? '')) === '') {
            $this->eingabe['gericht']['titel'] = (string) $session->title;
        }
        if (trim((string) ($this->eingabe['gericht']['brief'] ?? '')) === '') {
            $this->eingabe['gericht']['brief'] = (string) $session->brief;
        }
    }

    /** Das Ziel-Gericht der offenen Planung (für den Hinweis im Gericht-Reiter), sonst null. */
    public function rezepturZiel(): ?FoodAlchemistRecipe
    {
        $team = $this->team();

        return $team !== null && $this->sessionId !== null
            ? app(GerichtHuelleService::class)->zielDerSession($team, $this->aktiveSession()) : null;
    }

    /** Ist das Ziel-Gericht noch eine Hülle (Entwurf ohne Komponenten)? Sonst würde ein weiterer Lauf neu anlegen. */
    public function rezepturOffen(): bool
    {
        $ziel = $this->rezepturZiel();

        return $ziel !== null && GerichtHuelleService::huelle($this->team(), (int) $ziel->id) !== null;
    }
}
