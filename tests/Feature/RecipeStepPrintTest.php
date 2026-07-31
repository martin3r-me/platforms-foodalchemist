<?php

use Platform\FoodAlchemist\Models\FoodAlchemistRecipeStep;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeStepPhoto;
use Platform\FoodAlchemist\Services\PlanungsblattService;
use Platform\FoodAlchemist\Services\RecipeStepService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 27 Phase 4 — Produktionsdruck. Zu schützen: die Anleitung erscheint als
 * Schritt-Karten, `?fotos=0` druckt garantiert KEIN Bild, und Rezepte ohne Schritte
 * fallen sauber auf den Freitext zurück (Bestand vor dem Backfill).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam, 'Druck User');
    $this->actingAs($this->user);
});

it('Produktionsblatt liefert die Schritte samt Fotos im Array', function () {
    $r = $this->makeRecipe($this->rootTeam, 'Fond: Druck', ['preparation' => null, 'yield_kg' => 2.0]);
    app(RecipeStepService::class)->sync($r, [
        ['phase' => 'Garen', 'text' => 'Karkassen bei 200 °C rösten.'],
        ['phase' => 'Garen', 'text' => '4 Stunden ziehen lassen.'],
    ]);
    $step = FoodAlchemistRecipeStep::where('recipe_id', $r->id)->orderBy('position')->firstOrFail();
    $foto = FoodAlchemistRecipeStepPhoto::create([
        'team_id' => $this->rootTeam->id, 'recipe_id' => $r->id, 'pfad' => 'a/b.jpg', 'caption' => 'Röstung',
    ]);
    $step->photos()->attach($foto->id, ['position' => 1]);

    $blatt = app(PlanungsblattService::class)->produktionsblatt($this->rootTeam, ['recipe_id' => $r->id, 'portions' => 1]);
    $zeile = collect($blatt['rezepte'])->firstWhere('recipe_id', $r->id);

    expect($zeile['schritte'])->toHaveCount(2)
        ->and($zeile['schritte'][0]['nr'])->toBe(1)
        ->and($zeile['schritte'][0]['phase'])->toBe('Garen')
        ->and($zeile['schritte'][0]['fotos'][0]['caption'])->toBe('Röstung')
        // Freitext bleibt als Fallback im Array (Spiegel)
        ->and($zeile['zubereitung'])->toContain('Karkassen');
});

it('Produktionsblatt-Druck zeigt Schritt-Karten, ?fotos=0 druckt kein Bild', function () {
    $r = $this->makeRecipe($this->rootTeam, 'Fond: Karten', ['preparation' => null, 'yield_kg' => 2.0]);
    app(RecipeStepService::class)->sync($r, [['phase' => 'Garen', 'text' => 'Karkassen rösten.']]);
    $step = FoodAlchemistRecipeStep::where('recipe_id', $r->id)->firstOrFail();
    // Datei muss existieren, sonst liefert der Service pfad_abs = null (bewusst).
    \Illuminate\Support\Facades\Storage::disk('public')->put('spec27/foto.jpg', 'x');
    $foto = FoodAlchemistRecipeStepPhoto::create([
        'team_id' => $this->rootTeam->id, 'recipe_id' => $r->id, 'pfad' => 'spec27/foto.jpg', 'caption' => 'Röstung',
    ]);
    $step->photos()->attach($foto->id, ['position' => 1]);

    $url = route('foodalchemist.blaetter.dokument', ['typ' => 'produktion', 'recipe_id' => $r->id, 'portions' => 1]);

    $mit = $this->get($url);
    $mit->assertOk()->assertSee('Karkassen rösten.')->assertSee('anleitung-phase', false)->assertSee('<img', false);

    $ohne = $this->get($url . '&fotos=0');
    $ohne->assertOk()->assertSee('Karkassen rösten.')->assertDontSee('<img', false);

    \Illuminate\Support\Facades\Storage::disk('public')->delete('spec27/foto.jpg');
});

it('Rezept ohne Schritte fällt im Druck auf den Freitext zurück', function () {
    $r = $this->makeRecipe($this->rootTeam, 'Fond: Alt-Bestand', [
        'preparation' => 'Alles zusammenwerfen und kochen.', 'yield_kg' => 2.0,
    ]);
    FoodAlchemistRecipeStep::where('recipe_id', $r->id)->delete();

    $this->get(route('foodalchemist.blaetter.dokument', ['typ' => 'produktion', 'recipe_id' => $r->id, 'portions' => 1]))
        ->assertOk()
        ->assertSee('Alles zusammenwerfen und kochen.')
        ->assertSee('zubereitung-fallback', false);
});

it('Postenzettel „Anleitung" druckt nur die Schritte und schaltet Fotos um', function () {
    $r = $this->makeRecipe($this->rootTeam, 'Fond: Posten', ['preparation' => null, 'yield_kg' => 2.0]);
    app(RecipeStepService::class)->sync($r, [
        ['phase' => 'Mise en Place', 'text' => 'Gemüse würfeln.'],
        ['phase' => 'Garen', 'text' => 'Bei 160 °C schmoren.'],
    ]);

    $url = route('foodalchemist.rezepte.anleitung', ['recipe' => $r->id]);

    $this->get($url)->assertOk()
        ->assertSee('Fond: Posten')
        ->assertSee('Gemüse würfeln.')
        ->assertSee('Bei 160 °C schmoren.')
        ->assertSee('Anleitung mit Fotos')
        ->assertDontSee('Bestellvorschlag');          // kein Einkauf auf dem Postenzettel

    $this->get($url . '?fotos=0')->assertOk()
        ->assertSee('Textfassung')
        ->assertDontSee('<img', false);
});

it('Postenzettel ist team-gescoped', function () {
    $fremd = $this->makeRecipe($this->childB, 'Fremd: Posten', ['yield_kg' => 1.0]);
    $this->actingAs($this->makeUser($this->childA, 'Kind A'));

    $this->get(route('foodalchemist.rezepte.anleitung', ['recipe' => $fremd->id]))->assertNotFound();
});

it('Produktionsauftrag friert die Schrittfolge mit ein (steps_snapshot)', function () {
    $r = $this->makeRecipe($this->rootTeam, 'Fond: Auftrag', ['preparation' => null, 'yield_kg' => 2.0]);
    app(RecipeStepService::class)->sync($r, [['phase' => 'Garen', 'text' => 'Ansetzen und ziehen lassen.']]);

    $order = app(\Platform\FoodAlchemist\Services\ProductionOrderService::class)->saveNew(
        $this->rootTeam, '2026-08-05', 'Testauftrag',
        [['source_ref' => 'test:1', 'recipe_id' => $r->id, 'portions' => 4]],
        userId: $this->user->id,
    );

    $zeile = $order->lines()->where('recipe_id', $r->id)->firstOrFail();
    expect($zeile->steps_snapshot)->toBeArray()
        ->and($zeile->steps_snapshot[0]['text'])->toBe('Ansetzen und ziehen lassen.');

    // Ändert sich das Rezept danach, bleibt der Auftrag beim eingefrorenen Stand.
    app(RecipeStepService::class)->sync($r, [['text' => 'ganz anders']]);
    expect($zeile->fresh()->steps_snapshot[0]['text'])->toBe('Ansetzen und ziehen lassen.');
});
