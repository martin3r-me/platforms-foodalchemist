<?php

use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Models\FoodAlchemistServierform;
use Platform\FoodAlchemist\Services\DarreichungResolver;
use Platform\FoodAlchemist\Services\DarreichungService;
use Platform\FoodAlchemist\Services\FoodbookService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * R0.5 — DarreichungService/DarreichungResolver-Testbasis (SQLite-tragfähiger Teil).
 * ⚠️ Der Zwei-Darreichungen-Fall (Buffet gewinnt über Standard, „2,32 statt 25 €")
 * ist auf der In-Memory-SQLite NICHT abbildbar — dort wirkt der partielle
 * Ein-Standard-Unique-Index wie volles unique(recipe_id) und verbietet eine zweite
 * Darreichung. Dieser Money-Path ist auf MySQL per Smoke verifiziert (R0.2). Hier:
 * Ein-Standard-Invariante (Idempotenz-Richtung), Resolver-Standard + -Fallback.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->svc = app(DarreichungService::class);
    $this->resolver = app(DarreichungResolver::class);
    FoodAlchemistServierform::firstOrCreate(['code' => 'unbestimmt', 'team_id' => $this->rootTeam->id], ['label' => 'Unbestimmt']);

    $this->gericht = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'g-dar', 'name' => 'Testgericht',
        'status' => 'approved', 'is_sales_recipe' => true, 'sales_net' => 25.00,
    ]);
});

it('ensureStandard legt genau EINE Standard-Darreichung an und ist idempotent', function () {
    $erste = $this->svc->ensureStandard($this->rootTeam, $this->gericht->id);
    expect($erste)->not->toBeNull()
        ->and($erste->is_standard)->toBeTrue();

    // zweiter Aufruf darf KEINE zweite Standard-Zeile erzeugen (Ein-Standard-Invariante)
    $zweite = $this->svc->ensureStandard($this->rootTeam, $this->gericht->id);
    expect($zweite->id)->toBe($erste->id);

    expect(FoodAlchemistRecipeDarreichung::where('recipe_id', $this->gericht->id)->where('is_standard', true)->count())
        ->toBe(1);
    expect(FoodAlchemistRecipeDarreichung::where('recipe_id', $this->gericht->id)->count())
        ->toBe(1);
});

it('Resolver: standardFuer liefert die Standard-Darreichung des Gerichts', function () {
    $standard = $this->svc->ensureStandard($this->rootTeam, $this->gericht->id);

    $gefunden = $this->resolver->standardFuer($this->gericht->fresh());
    expect($gefunden)->not->toBeNull()
        ->and($gefunden->id)->toBe($standard->id)
        ->and($gefunden->is_standard)->toBeTrue();
});

it('Resolver-Fallback: Gericht ohne Darreichung → standardFuer ist null (kein stiller Preis)', function () {
    expect($this->resolver->standardFuer($this->gericht->fresh()))->toBeNull();
});

it('Money-Path: die Standard-Darreichung ist die Preis-Wahrheit; recipes.sales_net spiegelt sie', function () {
    // Preis-Wahrheit liegt an der Darreichung; das Legacy-Feld recipes.sales_net ist die
    // Anzeige-/Kompat-Schicht und wird AUS dem Standard gespiegelt (nicht umgekehrt).
    $standard = $this->svc->ensureStandard($this->rootTeam, $this->gericht->id);
    $this->svc->aktualisieren($this->rootTeam, $standard->id, ['sales_net' => 2.32, 'price_mode' => 'manuell']);

    expect((float) $this->resolver->standardFuer($this->gericht->fresh())->sales_net)->toBe(2.32)
        ->and((float) $this->gericht->fresh()->sales_net)->toBe(2.32); // Legacy-Feld folgt dem Standard
});

// ── Spec 19 E7.1 · DarreichungResolver::fuerBlock (recipe_ref-Einzel-Gericht) ──
// Der distinguierende Zwei-Darreichungen-Fall (Servierform-Match / expliziter Override
// SCHLAGEN den Standard, „2,32 statt 25 €") ist auf In-Memory-SQLite nicht abbildbar
// (partieller Ein-Standard-Unique-Index verbietet die 2. Darreichung) → MySQL-Smoke.
// Hier: die SQLite-tragfähigen Zweige (Standard-Fallback, expliziter Zeiger, kein Gericht).

/** Minimaler recipe_ref-Block am Kapitel eines frischen Foodbooks. */
function macheRecipeRefBlock($team, int $recipeId): \Platform\FoodAlchemist\Models\FoodAlchemistFoodbookBlock
{
    $fbSvc = app(FoodbookService::class);
    $fb = $fbSvc->create($team, ['label' => 'Dar-FB']);
    $kapitel = $fbSvc->addKapitel($team, $fb->id, ['title' => 'Kap']);

    return $fbSvc->addBlock($team, $kapitel->id, ['type' => 'recipe_ref', 'sales_recipe_id' => $recipeId]);
}

it('fuerBlock: ohne presentation_id + ohne Servierform → standardFuer (bit-identisch heute)', function () {
    $standard = $this->svc->ensureStandard($this->rootTeam, $this->gericht->id);
    $block = macheRecipeRefBlock($this->rootTeam, $this->gericht->id);

    $gefunden = $this->resolver->fuerBlock($block->fresh(), null);
    expect($gefunden)->not->toBeNull()
        ->and($gefunden->id)->toBe($standard->id)
        ->and($gefunden->is_standard)->toBeTrue();
});

it('fuerBlock: expliziter presentation_id-Override wird zurückgegeben', function () {
    $standard = $this->svc->ensureStandard($this->rootTeam, $this->gericht->id);
    $block = macheRecipeRefBlock($this->rootTeam, $this->gericht->id);
    $block->update(['presentation_id' => $standard->id]); // Zeiger gesetzt → Zweig 1

    $gefunden = $this->resolver->fuerBlock($block->fresh(), null);
    expect($gefunden)->not->toBeNull()
        ->and($gefunden->id)->toBe($standard->id);
});

it('fuerBlock: recipe_ref ohne Gericht → null (kein stiller Preis)', function () {
    $block = macheRecipeRefBlock($this->rootTeam, $this->gericht->id);
    $block->update(['sales_recipe_id' => null]); // dish löst zu null auf

    expect($this->resolver->fuerBlock($block->fresh(), null))->toBeNull();
});

it('fuerBlock: Servierform ohne passende Darreichung → Standard-Fallback', function () {
    $standard = $this->svc->ensureStandard($this->rootTeam, $this->gericht->id);
    $form = FoodAlchemistServierform::firstOrCreate(
        ['code' => 'buffet', 'team_id' => $this->rootTeam->id],
        ['label' => 'Buffet']
    );
    $block = macheRecipeRefBlock($this->rootTeam, $this->gericht->id);

    // Servierform gesetzt, aber das Gericht hat keine passende Darreichung → fällt auf Standard.
    $gefunden = $this->resolver->fuerBlock($block->fresh(), $form->id);
    expect($gefunden)->not->toBeNull()->and($gefunden->id)->toBe($standard->id);
});

/**
 * §4 (2026-09-04): `unbestimmt` ist ein Review-ZUSTAND, kein Label. Der Weg heraus ist
 * das Setzen der Servierform an der Zeile — das Vokabular-Label wird nie umbenannt
 * (es ist WaWi-Master und gilt für alle Gerichte).
 */
it('setzeServierform holt die Standard-Zeile aus dem Review-Zustand', function () {
    $dar = $this->svc->ensureStandard($this->rootTeam, $this->gericht->id);
    $unbestimmt = FoodAlchemistServierform::where('code', 'unbestimmt')->firstOrFail();
    expect((int) $dar->serving_form_id)->toBe((int) $unbestimmt->id);

    $teller = FoodAlchemistServierform::firstOrCreate(
        ['code' => 'teller', 'team_id' => $this->rootTeam->id],
        ['label' => 'Teller / serviertes Menü', 'sort_order' => 1]
    );

    $frisch = $this->svc->setzeServierform($this->rootTeam, $dar->id, $teller->id);

    expect((int) $frisch->serving_form_id)->toBe((int) $teller->id)
        ->and($frisch->is_standard)->toBeTrue()                  // bleibt Standard
        ->and((int) $frisch->id)->toBe((int) $dar->id);          // dieselbe Zeile, kein Neuanlegen
});

it('setzeServierform lehnt eine deaktivierte Form ab', function () {
    $dar = $this->svc->ensureStandard($this->rootTeam, $this->gericht->id);
    $aus = FoodAlchemistServierform::create([
        'team_id' => $this->rootTeam->id, 'code' => 'stillgelegt',
        'label' => 'Stillgelegt', 'is_inactive' => true,
    ]);

    expect(fn () => $this->svc->setzeServierform($this->rootTeam, $dar->id, $aus->id))
        ->toThrow(RuntimeException::class);
});

it('setzeServierform auf die eigene Form ist ein No-Op', function () {
    $dar = $this->svc->ensureStandard($this->rootTeam, $this->gericht->id);

    $frisch = $this->svc->setzeServierform($this->rootTeam, $dar->id, (int) $dar->serving_form_id);

    expect((int) $frisch->id)->toBe((int) $dar->id);
});

/**
 * Guard-Lücke bis 2026-09-04: `loeschen` sperrte die Standard-Zeile nur, wenn es MEHR
 * als eine Darreichung gab. Die einzige Zeile war über den MCP-Pfad löschbar — die UI
 * versteckt den Knopf unabhängig von der Anzahl, deshalb fiel es nie auf. Ohne
 * Standard-Darreichung verliert das Gericht Preis-Wahrheit und Slot-Auflösung.
 */
it('die einzige Standard-Darreichung kann nicht geloescht werden', function () {
    $dar = $this->svc->ensureStandard($this->rootTeam, $this->gericht->id);

    expect(FoodAlchemistRecipeDarreichung::where('recipe_id', $this->gericht->id)->count())->toBe(1);
    expect(fn () => $this->svc->loeschen($this->rootTeam, $dar->id))
        ->toThrow(RuntimeException::class);
    expect(FoodAlchemistRecipeDarreichung::where('recipe_id', $this->gericht->id)->count())->toBe(1);
});
