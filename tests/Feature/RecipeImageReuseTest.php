<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Platform\Core\Models\ContextFile;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeStepPhoto;
use Platform\FoodAlchemist\Services\RecipeImageService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Etappe 7 (Roadmap »Mise en Place«) — „Foto-Wiederverwendung / manueller Upload als Alternative",
 * Teil 3a: die COPY-ON-REUSE-Foto-Übernahme (Design-Entscheid #105). Ein vorhandenes Team-Foto wird
 * PHYSISCH auf ein anderes Rezept kopiert — frische ContextFile, kein geteilter context_file_id →
 * kein Waisen-/Doppel-Lösch-Hazard. Wie das manuelle Foto (Teil 1) trägt die Kopie KEINEN Kosten-
 * Call-Log und überlebt damit den KI-Re-Trigger-Purge (loescheKiFotos).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam, 'Reuse-Tester'));
    $this->quellRezept = $this->makeRecipe($this->rootTeam, 'Quell-Rezept');
    $this->zielRezept = $this->makeRecipe($this->rootTeam, 'Ziel-Rezept');
    $this->service = app(RecipeImageService::class);

    // Ein reales Foto (mit Datei auf dem faked-Disk) am Quell-Rezept als wiederverwendbare Vorlage.
    Storage::fake('public');
    $this->quelle = $this->service->uebernimmManuellesFoto(
        $this->rootTeam,
        $this->quellRezept,
        UploadedFile::fake()->image('quelle.jpg'),
        'Original-Caption',
    );
});

it('kopiert ein vorhandenes Foto auf ein anderes Rezept — frische ContextFile, kein Sharing', function () {
    $kopie = $this->service->uebernimmVorhandenesFoto($this->rootTeam, $this->zielRezept, $this->quelle);

    expect($kopie->exists)->toBeTrue()
        ->and((int) $kopie->recipe_id)->toBe((int) $this->zielRezept->id)
        ->and((int) $kopie->team_id)->toBe((int) $this->rootTeam->id)
        // COPY-ON-REUSE: eigene, NEUE ContextFile — NICHT der geteilte Quell-context_file_id.
        ->and((int) $kopie->context_file_id)->toBeGreaterThan(0)
        ->and((int) $kopie->context_file_id)->not->toBe((int) $this->quelle->context_file_id)
        // Caption der Quelle übernommen, wenn keine eigene angegeben.
        ->and($kopie->caption)->toBe('Original-Caption')
        // Quelle unangetastet.
        ->and(FoodAlchemistRecipeStepPhoto::whereKey($this->quelle->id)->exists())->toBeTrue();

    // Datei physisch vorhanden (echte Kopie, kein toter Zeiger) — disk-agnostisch über die ContextFile.
    $cf = ContextFile::find($kopie->context_file_id);
    expect($cf)->not->toBeNull()
        ->and(Storage::disk($cf->disk)->exists($cf->path))->toBeTrue();

    // Kein KI-Call-Log für die Kopie (überlebt loescheKiFotos).
    $callLogged = DB::table('foodalchemist_ai_call_log')
        ->where('target_table', 'foodalchemist_recipe_step_photos')
        ->where('target_id', $kopie->id)
        ->exists();
    expect($callLogged)->toBeFalse();
});

it('übernimmt das kopierte Foto als Ergebnis-/Hero-Foto und wahrt die max.-1-Invariante', function () {
    // Bestehender Hero am Ziel-Rezept — muss beim neuen Hero zurückgesetzt werden.
    $alt = FoodAlchemistRecipeStepPhoto::create([
        'team_id' => $this->rootTeam->id,
        'recipe_id' => $this->zielRezept->id,
        'pfad' => 'foodalchemist/rezepte/' . $this->zielRezept->id . '/alt.jpg',
        'is_result' => true,
    ]);

    $kopie = $this->service->uebernimmVorhandenesFoto(
        $this->rootTeam,
        $this->zielRezept,
        $this->quelle,
        'Als Hero',
        true,   // istErgebnis
    );

    expect((bool) $kopie->is_result)->toBeTrue()
        ->and($kopie->caption)->toBe('Als Hero')
        ->and((bool) $alt->refresh()->is_result)->toBeFalse()
        ->and(FoodAlchemistRecipeStepPhoto::where('recipe_id', $this->zielRezept->id)->where('is_result', true)->count())->toBe(1);
});

it('weist ein Quell-Foto aus einem fremden Team ab', function () {
    // team_id auf ein fremdes Team umbiegen (childA ≠ rootTeam-Kontext des Aufrufs).
    $this->quelle->forceFill(['team_id' => $this->childA->id])->save();

    $this->service->uebernimmVorhandenesFoto($this->rootTeam, $this->zielRezept, $this->quelle);
})->throws(InvalidArgumentException::class);

it('wirft, wenn die Quell-Datei physisch fehlt', function () {
    // Foto-Zeile ohne reale Datei (Legacy-pfad zeigt ins Leere, kein context_file_id).
    $ohneDatei = FoodAlchemistRecipeStepPhoto::create([
        'team_id' => $this->rootTeam->id,
        'recipe_id' => $this->quellRezept->id,
        'pfad' => 'foodalchemist/rezepte/' . $this->quellRezept->id . '/fehlt.jpg',
    ]);

    $this->service->uebernimmVorhandenesFoto($this->rootTeam, $this->zielRezept, $ohneDatei);
})->throws(RuntimeException::class);
