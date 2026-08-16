<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeStepPhoto;
use Platform\FoodAlchemist\Services\RecipeImageService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Etappe 7 (Roadmap »Mise en Place«) — „Foto-Wiederverwendung / manueller Upload als Alternative",
 * Teil 1: die NICHT-KI-Foto-Übernahme. Ein manuell hochgeladenes Foto wird als Rezept-Foto angelegt,
 * OHNE Kosten-Call-Log — und überlebt daher den KI-Re-Trigger-Purge (loescheKiFotos), dessen
 * Discriminator genau der fehlende BILD_FEATURES-Call-Log-Eintrag ist.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam, 'Foto-Tester'));
    $this->rezept = $this->makeRecipe($this->rootTeam, 'Foto-Rezept');
    $this->service = app(RecipeImageService::class);
});

it('übernimmt ein manuell hochgeladenes Foto ohne KI-Call-Log (Pool-Foto)', function () {
    Storage::fake('public');

    $foto = $this->service->uebernimmManuellesFoto(
        $this->rootTeam,
        $this->rezept,
        UploadedFile::fake()->image('teller.jpg'),
        'Vom Fotografen',
    );

    expect($foto->exists)->toBeTrue()
        ->and((int) $foto->recipe_id)->toBe((int) $this->rezept->id)
        ->and((int) $foto->team_id)->toBe((int) $this->rootTeam->id)
        ->and((int) $foto->context_file_id)->toBeGreaterThan(0)
        ->and($foto->caption)->toBe('Vom Fotografen')
        ->and((bool) $foto->is_result)->toBeFalse();

    // Kein KI-Call: das manuelle Foto darf NICHT im Kosten-Call-Log auftauchen.
    $callLogged = DB::table('foodalchemist_ai_call_log')
        ->where('target_table', 'foodalchemist_recipe_step_photos')
        ->where('target_id', $foto->id)
        ->exists();
    expect($callLogged)->toBeFalse();
});

it('setzt einen Default-Caption, wenn keiner übergeben wird', function () {
    Storage::fake('public');

    $foto = $this->service->uebernimmManuellesFoto(
        $this->rootTeam,
        $this->rezept,
        UploadedFile::fake()->image('ohne.jpg'),
    );

    expect($foto->caption)->toBe('Manuelles Foto');
});

it('manuelles Foto überlebt den KI-Re-Trigger-Purge (loescheKiFotos)', function () {
    Storage::fake('public');

    // Manuell übernommenes Foto (kein Call-Log).
    $manuell = $this->service->uebernimmManuellesFoto(
        $this->rootTeam,
        $this->rezept,
        UploadedFile::fake()->image('manuell.jpg'),
    );

    // Ein „KI"-Foto simulieren: Foto-Zeile + passender BILD_FEATURES-Call-Log-Eintrag
    // (provider-los — kein echter ImageGenerationService-Call nötig).
    $kiFoto = FoodAlchemistRecipeStepPhoto::create([
        'team_id' => $this->rootTeam->id,
        'recipe_id' => $this->rezept->id,
        'pfad' => 'foodalchemist/rezepte/' . $this->rezept->id . '/ki.jpg',
    ]);
    DB::table('foodalchemist_ai_call_log')->insert([
        'uuid' => (string) Str::orderedUuid(),
        'team_id' => $this->rootTeam->id,
        'user_id' => null,
        'feature' => RecipeImageService::FEATURE_PRODUKTFOTO,
        'tier' => 'I',
        'model' => 'gpt-image-1.5',
        'prompt_hash' => hash('sha256', 'x'),
        'response_summary' => RecipeImageService::FEATURE_PRODUKTFOTO,
        'tokens_in' => 0,
        'tokens_out' => 0,
        'target_table' => 'foodalchemist_recipe_step_photos',
        'target_id' => $kiFoto->id,
        'error' => null,
        'elapsed_ms' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $geloescht = $this->service->loescheKiFotos($this->rootTeam, $this->rezept);

    expect($geloescht)->toBe(1)
        ->and(FoodAlchemistRecipeStepPhoto::whereKey($kiFoto->id)->exists())->toBeFalse()
        ->and(FoodAlchemistRecipeStepPhoto::whereKey($manuell->id)->exists())->toBeTrue();
});
