<?php

use Platform\FoodAlchemist\Models\FoodAlchemistRecipeStep;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeStepPhoto;
use Platform\FoodAlchemist\Services\RecipeStepService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 27 Phase 1 — Bestands-Backfill: Markdown-Zubereitung → Schritte, Fotos über
 * ihre alte `schritt_nr` an den Schritt gleicher `position`. Die zu schützenden
 * Invarianten: kein Foto wird geraten (0 / out-of-range bleibt unverlinkt), dry-run
 * schreibt nichts, und ein zweiter Lauf ist ein No-Op.
 */
// Fixture-Helfer als Closures (nicht als globale Funktionen): sie brauchen die
// PROTECTED Trait-Methoden bzw. -Properties, und `--parallel` verträgt globale
// Funktionen in Testdateien ohnehin schlecht (fa_test.sh §Konsequenz).
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->svc = app(RecipeStepService::class);

    /** Rezept mit 3 Schritten in 2 Phasen. */
    $this->dreiSchritte = fn (string $name = 'Schmorbraten') => $this->makeRecipe($this->rootTeam, $name, [
        'preparation' => "## Mise en Place\n1. Zwiebeln schneiden.\n2. Fond erhitzen.\n\n## Finish\n3. Montieren.",
    ]);

    $this->foto = fn (int $recipeId, int $schrittNr, string $datei) => FoodAlchemistRecipeStepPhoto::create([
        'team_id' => $this->rootTeam->id,
        'recipe_id' => $recipeId,
        'schritt_nr' => $schrittNr,
        'pfad' => 'foodalchemist/rezepte/' . $recipeId . '/' . $datei,
    ]);
});

it('parst Phasen + Schritte und nummeriert lückenlos', function () {
    $r = ($this->dreiSchritte)();

    $this->artisan('foodalchemist:steps-backfill', ['--team' => $this->rootTeam->id, '--apply' => true])
        ->assertSuccessful();

    $steps = FoodAlchemistRecipeStep::where('recipe_id', $r->id)->orderBy('position')->get();
    expect($steps)->toHaveCount(3)
        ->and($steps->pluck('position')->all())->toBe([1, 2, 3])
        ->and($steps->pluck('phase')->all())->toBe(['Mise en Place', 'Mise en Place', 'Finish'])
        ->and($steps[0]->text)->toBe('Zwiebeln schneiden.')
        ->and((int) $steps[0]->team_id)->toBe((int) $this->rootTeam->id);
});

it('verlinkt Fotos an den Schritt mit gleicher position', function () {
    $r = ($this->dreiSchritte)();
    $f3 = ($this->foto)($r->id, 3, 'finish.jpg');

    $this->artisan('foodalchemist:steps-backfill', ['--team' => $this->rootTeam->id, '--apply' => true])
        ->assertSuccessful();

    $schritt3 = FoodAlchemistRecipeStep::where('recipe_id', $r->id)->where('position', 3)->firstOrFail();
    expect($schritt3->photos->pluck('id')->all())->toBe([$f3->id])
        // Gegenrichtung: das Foto kennt seinen Schritt.
        ->and($f3->fresh()->steps->pluck('id')->all())->toBe([$schritt3->id]);
});

it('schritt_nr = 0 bleibt unverlinkt (allgemeines Rezept-Foto)', function () {
    $r = ($this->dreiSchritte)();
    $hero = ($this->foto)($r->id, 0, 'hero.jpg');

    $this->artisan('foodalchemist:steps-backfill', ['--team' => $this->rootTeam->id, '--apply' => true])
        ->assertSuccessful();

    expect($hero->fresh()->steps)->toHaveCount(0);
});

it('schritt_nr ausserhalb der Schrittzahl wird NICHT geraten', function () {
    $r = ($this->dreiSchritte)();
    $daneben = ($this->foto)($r->id, 9, 'schritt9.jpg');   // es gibt nur 3 Schritte

    $this->artisan('foodalchemist:steps-backfill', ['--team' => $this->rootTeam->id, '--apply' => true])
        ->assertSuccessful();

    expect($daneben->fresh()->steps)->toHaveCount(0);
});

it('dry-run schreibt nichts', function () {
    $r = ($this->dreiSchritte)();

    $this->artisan('foodalchemist:steps-backfill', ['--team' => $this->rootTeam->id])->assertSuccessful();

    expect(FoodAlchemistRecipeStep::where('recipe_id', $r->id)->count())->toBe(0);
});

it('ist idempotent — der zweite Lauf fasst nichts an', function () {
    $r = ($this->dreiSchritte)();

    $this->artisan('foodalchemist:steps-backfill', ['--team' => $this->rootTeam->id, '--apply' => true])->assertSuccessful();
    $ids = FoodAlchemistRecipeStep::where('recipe_id', $r->id)->orderBy('position')->pluck('id')->all();

    $this->artisan('foodalchemist:steps-backfill', ['--team' => $this->rootTeam->id, '--apply' => true])->assertSuccessful();

    expect(FoodAlchemistRecipeStep::where('recipe_id', $r->id)->orderBy('position')->pluck('id')->all())->toBe($ids);
});

it('--verify schreibt nichts und berichtet die Abdeckung', function () {
    $r = ($this->dreiSchritte)();

    $this->artisan('foodalchemist:steps-backfill', ['--team' => $this->rootTeam->id, '--verify' => true])
        ->assertSuccessful();

    expect(FoodAlchemistRecipeStep::where('recipe_id', $r->id)->count())->toBe(0);

    $c = $this->svc->coverage($this->rootTeam);
    expect($c['recipes_with_prep'])->toBeGreaterThan(0)
        ->and($c['recipes_with_steps'])->toBe(0);
});

it('Spiegel: preparation wird aus den Schritten neu gerendert (EINBAHN)', function () {
    // Unsaubere Quelle (Marker-Mix, Leerzeilen) → nach dem Backfill kanonisch.
    $r = $this->makeRecipe($this->rootTeam, 'Spiegeltest', [
        'preparation' => "## Garen\n\n- Anbraten.\n- Ablöschen.\n",
    ]);

    $this->svc->backfillBulk($this->rootTeam, apply: true, recipeId: $r->id);

    expect($r->fresh()->preparation)->toBe("## Garen\n1. Anbraten.\n2. Ablöschen.");
});

it('Spiegel wirft bestehenden Freitext nicht weg, wenn es keine Schritte gibt', function () {
    $r = $this->makeRecipe($this->rootTeam, 'Ohne Schritte', ['preparation' => 'Nur ein Satz.']);
    FoodAlchemistRecipeStep::where('recipe_id', $r->id)->delete();

    $this->svc->spiegele($r);

    expect($r->fresh()->preparation)->toBe('Nur ein Satz.');
});

it('sync: ein Foto darf an mehreren Schritten hängen (M:N)', function () {
    $r = ($this->dreiSchritte)();
    $this->svc->backfillBulk($this->rootTeam, apply: true, recipeId: $r->id);

    $f = ($this->foto)($r->id, 0, 'mep.jpg');
    $steps = FoodAlchemistRecipeStep::where('recipe_id', $r->id)->orderBy('position')->get();
    $steps[0]->photos()->attach($f->id, ['position' => 1]);
    $steps[2]->photos()->attach($f->id, ['position' => 1]);

    expect($f->fresh()->steps->pluck('id')->all())->toBe([$steps[0]->id, $steps[2]->id]);
});

it('sync behält Schritt-IDs (und damit die Fotos) beim Umformulieren und Umsortieren', function () {
    $r = ($this->dreiSchritte)();
    $this->svc->backfillBulk($this->rootTeam, apply: true, recipeId: $r->id);

    $steps = FoodAlchemistRecipeStep::where('recipe_id', $r->id)->orderBy('position')->get();
    $f = ($this->foto)($r->id, 0, 'am-schritt.jpg');
    $steps[2]->photos()->attach($f->id, ['position' => 1]);

    // Schritt 3 wandert nach vorne, Schritt 2 fällt weg.
    $this->svc->sync($r, [
        ['id' => $steps[2]->id, 'phase' => 'Finish', 'text' => 'Montieren und abschmecken.'],
        ['id' => $steps[0]->id, 'phase' => 'Mise en Place', 'text' => 'Zwiebeln schneiden.'],
    ]);

    $neu = FoodAlchemistRecipeStep::where('recipe_id', $r->id)->orderBy('position')->get();
    expect($neu)->toHaveCount(2)
        ->and($neu[0]->id)->toBe($steps[2]->id)
        ->and($neu[0]->text)->toBe('Montieren und abschmecken.')
        ->and($neu[0]->photos->pluck('id')->all())->toBe([$f->id])   // ⬅ Foto klebt am Schritt, nicht an der Nummer
        ->and(FoodAlchemistRecipeStep::where('recipe_id', $r->id)->whereKey($steps[1]->id)->exists())->toBeFalse();
});

it('ausMarkdown überschreibt vorhandene Schritte nur auf Verlangen', function () {
    $r = ($this->dreiSchritte)();
    $this->svc->backfillBulk($this->rootTeam, apply: true, recipeId: $r->id);

    expect($this->svc->ausMarkdown($r, '1. Ganz anders.'))->toBe(0)
        ->and(FoodAlchemistRecipeStep::where('recipe_id', $r->id)->count())->toBe(3);

    expect($this->svc->ausMarkdown($r, '1. Ganz anders.', ueberschreiben: true))->toBe(1)
        ->and(FoodAlchemistRecipeStep::where('recipe_id', $r->id)->count())->toBe(1);
});
