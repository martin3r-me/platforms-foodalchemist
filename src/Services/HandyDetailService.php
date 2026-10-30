<?php

namespace Platform\FoodAlchemist\Services;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeStepPhoto;

/**
 * #1003 Handy-Detail im Handbuch-Stil (Rezept und Gericht): nur was die Küche am Handy braucht.
 * Schritte, Anrichten, Regeneration, Portion und Behälter kommen aus derselben Karte wie das öffentliche
 * Handbuch (HandbuchService::gerichtKarte, ohne den Report-Lauf für Zutaten); Zutaten liest die View direkt
 * am Rezept. Kein Pairing, keine KI, keine Verwaltung → schneller Aufbau am Handy.
 */
class HandyDetailService
{
    public function __construct(private HandbuchService $handbuch)
    {
    }

    /** @return array<string,mixed> Karte (foto_url, portion, behaelter, zubereitung, anrichten, regeneration) */
    public function karte(Team $team, FoodAlchemistRecipe $rezept): array
    {
        $karte = $this->handbuch->hydrateKarte($this->handbuch->gerichtKarte($team, $rezept, '', false));
        if ($karte['foto_url'] === null) {
            // Ohne Gericht-Foto: das Endprodukt-Bild („so soll es fertig aussehen") steht oben
            $endprodukt = FoodAlchemistRecipeStepPhoto::where('recipe_id', $rezept->id)->where('is_result', true)->first();
            $karte['foto_url'] = $endprodukt?->url();
        }

        return $karte;
    }

    /** Darreichungsformen eines Gerichts, Standard zuerst (VK aus der Darreichung, der Preis-Wahrheit). */
    public function formen(FoodAlchemistRecipe $rezept): \Illuminate\Support\Collection
    {
        return FoodAlchemistRecipeDarreichung::with('servingForm')
            ->where('recipe_id', $rezept->id)->orderByDesc('is_standard')->orderBy('id')->get();
    }
}
