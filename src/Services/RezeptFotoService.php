<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Http\UploadedFile;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Exceptions\GeerbtNurLesenException;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeStep;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeStepPhoto;

/**
 * Fotos zu Arbeits- und Anrichte-Schritten eines Rezepts — EIN Weg für den Schritt-Editor am Computer (StepEditor) und
 * das Handy-Detail (#1003 Fotos): Bild über FoodAlchemistMediaService speichern, ins Foto-Pool des Rezepts legen und,
 * wenn ein Schritt genannt ist, dort hinten anhängen. Nur am eigenen Rezept (geerbte sind nur lesbar).
 */
class RezeptFotoService
{
    /** Gleiche Grenze überall (Handyfotos 10–12 MB; Livewire-Upload-Grenze 15 MB). */
    public const MAX_KB = 15360;

    public function schrittFoto(Team $team, FoodAlchemistRecipe $rezept, UploadedFile $datei, ?string $caption = null,
        ?int $stepId = null, string $ebene = FoodAlchemistRecipeStep::EBENE_PRODUKTION): FoodAlchemistRecipeStepPhoto
    {
        GeerbtNurLesenException::wennNichtEigen($rezept, $team, 'die Fotos');

        $media = app(FoodAlchemistMediaService::class)->storeImage(
            $datei, $team, 'foodalchemist.recipe', $rezept->id, "foodalchemist/rezepte/{$rezept->id}",
        );
        $foto = FoodAlchemistRecipeStepPhoto::create([
            'team_id' => $rezept->team_id,
            'recipe_id' => $rezept->id,
            'pfad' => $media['path'],
            'context_file_id' => $media['context_file_id'],
            'caption' => trim((string) $caption) ?: null,
        ]);

        // stepId 0/null = schritt-freier Pool („allgemeine" Rezept-Fotos).
        $schritt = ($stepId ?? 0) > 0
            ? FoodAlchemistRecipeStep::where('recipe_id', $rezept->id)->ebene($ebene)->whereKey($stepId)->first()
            : null;
        if ($schritt !== null) {
            $naechste = (int) ($schritt->photos()->max('foodalchemist_recipe_step_photo_links.position') ?? 0) + 1;
            $schritt->photos()->attach($foto->id, ['position' => $naechste]);
        }

        return $foto;
    }
}
