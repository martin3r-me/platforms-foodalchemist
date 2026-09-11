<?php

use Platform\FoodAlchemist\Models\FoodAlchemistRecipeStep;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeStepPhoto;
use Platform\FoodAlchemist\Services\ReportExportService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Der Report mit Anrichte-Fotos — der Pfad, der nie funktioniert hat.
 *
 * Gemeldet 2026-09-11 als 500er auf `/rezepte/{id}/dokument?profil=produktion&anrichten=1`:
 *
 *   TypeError: photoDataUri(): Argument #1 ($contextFileId) must be of type ?int, string given
 *
 * Die Methode wurde irgendwann um den ContextFile-Parameter erweitert (damit die Fotos auch
 * von einem nicht-public Disk ins PDF kommen). Mitgezogen wurde nur EINE der zwei
 * Aufrufstellen — die Zubereitungs-Schritte. Die Anrichte-Schritte gaben weiterhin nur den
 * Pfad, und der landete im `?int`-Slot.
 *
 * Warum es niemand sah: es gab keinen Test, der ein Anrichte-Foto in den Report legt. Die
 * Kombination ist speziell (Ebene `anrichten` + Foto + Bilder-Schalter an), und der Fehler
 * ist ein harter 500er, kein stiller Ausfall — es hat ihn nur bis heute niemand ausgeloest.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->report = app(ReportExportService::class);
    $this->rezept = $this->makeRecipe($this->rootTeam, 'Teller mit Anrichte-Schritt');
});

it('★ baut den Report, wenn ein ANRICHTE-Schritt ein Foto traegt', function () {
    $schritt = FoodAlchemistRecipeStep::create([
        'team_id' => $this->rootTeam->id, 'recipe_id' => $this->rezept->id,
        'ebene' => FoodAlchemistRecipeStep::EBENE_ANRICHTEN,
        'position' => 1, 'phase' => 'Anrichten', 'text' => 'Sauce spiegeln, Fisch auflegen.',
    ]);
    $foto = FoodAlchemistRecipeStepPhoto::create([
        'team_id' => $this->rootTeam->id, 'recipe_id' => $this->rezept->id,
        'pfad' => 'foodalchemist/rezepte/1/teller.webp', 'caption' => 'Angerichtet',
    ]);
    $schritt->photos()->attach($foto->id, ['position' => 1]);

    $daten = $this->report->rezeptDaten($this->rootTeam, $this->rezept->id, ['bilder' => true, 'anrichten' => true]);

    expect($daten['recipe']['anrichte_schritte'])->toHaveCount(1)
        ->and($daten['recipe']['anrichte_schritte'][0]['photos'])->toHaveCount(1)
        ->and($daten['recipe']['anrichte_schritte'][0]['photos'][0]['caption'])->toBe('Angerichtet');
});

it('★ auch mit gesetztem context_file_id — beide Aufrufstellen reichen BEIDE Argumente durch', function () {
    // Der eigentliche Vertrag: die Anrichte-Stelle muss dieselben zwei Argumente uebergeben
    // wie die Zubereitungs-Stelle. Ein Foto mit ContextFile ist der Fall, fuer den der
    // Parameter ueberhaupt eingefuehrt wurde.
    $schritt = FoodAlchemistRecipeStep::create([
        'team_id' => $this->rootTeam->id, 'recipe_id' => $this->rezept->id,
        'ebene' => FoodAlchemistRecipeStep::EBENE_ANRICHTEN,
        'position' => 1, 'phase' => 'Anrichten', 'text' => 'Garnieren.',
    ]);
    // Eine ECHTE ContextFile: der Fremdschluessel ist real, und genau fuer diesen Fall
    // wurde der Parameter eingefuehrt (Datei auf einem nicht-public Disk).
    $datei = \Platform\Core\Models\ContextFile::create([
        'token' => 'tst-'.\Illuminate\Support\Str::random(8),
        'team_id' => $this->rootTeam->id, 'user_id' => $this->makeUser($this->rootTeam, 'Foto')->id,
        'context_type' => 'foodalchemist.recipe', 'context_id' => (int) $this->rezept->id,
        'disk' => 'local', 'path' => 'foodalchemist/rezepte/1/garnitur.webp',
        'file_name' => 'garnitur.webp', 'original_name' => 'garnitur.png',
        'mime_type' => 'image/webp', 'file_size' => 123, 'width' => 1024, 'height' => 1024,
    ]);
    $foto = FoodAlchemistRecipeStepPhoto::create([
        'team_id' => $this->rootTeam->id, 'recipe_id' => $this->rezept->id,
        'context_file_id' => $datei->id,
        'pfad' => 'foodalchemist/rezepte/1/garnitur.webp',
    ]);
    $schritt->photos()->attach($foto->id, ['position' => 1]);

    $daten = $this->report->rezeptDaten($this->rootTeam, $this->rezept->id, ['bilder' => true, 'anrichten' => true]);

    // Kein Throw, und die Zeile ist vollstaendig — `src` faellt auf die URL zurueck.
    expect($daten['recipe']['anrichte_schritte'][0]['photos'][0])->toHaveKeys(['id', 'caption', 'url', 'src']);
});

it('ohne Bilder-Schalter bleiben die Fotos leer — der Schalter ist der Schalter', function () {
    $schritt = FoodAlchemistRecipeStep::create([
        'team_id' => $this->rootTeam->id, 'recipe_id' => $this->rezept->id,
        'ebene' => FoodAlchemistRecipeStep::EBENE_ANRICHTEN,
        'position' => 1, 'phase' => 'Anrichten', 'text' => 'Auflegen.',
    ]);
    $foto = FoodAlchemistRecipeStepPhoto::create([
        'team_id' => $this->rootTeam->id, 'recipe_id' => $this->rezept->id,
        'pfad' => 'foodalchemist/rezepte/1/x.webp',
    ]);
    $schritt->photos()->attach($foto->id, ['position' => 1]);

    $daten = $this->report->rezeptDaten($this->rootTeam, $this->rezept->id, ['bilder' => false, 'anrichten' => true]);

    expect($daten['recipe']['anrichte_schritte'][0]['photos'])->toBe([]);
});
