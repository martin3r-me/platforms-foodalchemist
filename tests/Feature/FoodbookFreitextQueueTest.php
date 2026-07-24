<?php

use Illuminate\Support\Facades\Queue;
use Platform\FoodAlchemist\Jobs\MaterializeIdeaJob;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistDishIdea;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\FoodbookService;
use Platform\FoodAlchemist\Services\IdeenService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 19 E7.4 — Freitext-Queue (GenerateRecipeJob-Muster → L7/L8) + Graceful ohne
 * Provider („wartet auf KI"). Die KI-Erdung selbst ist nicht Testgegenstand (Sandbox
 * hat keinen LLM-Provider, Spec §KI-Teile = nur Verdrahtung) — geprüft wird: der Go
 * scheitert nie, die Skizze bleibt retrybar, und der Batch reiht die Jobs korrekt ein.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->fbSvc = app(FoodbookService::class);
    $this->ideen = app(IdeenService::class);

    $this->fb = $this->fbSvc->create($this->rootTeam, ['label' => 'Freitext-FB']);
    $this->kapitel = $this->fbSvc->addKapitel($this->rootTeam, $this->fb->id, ['title' => 'Kreativ-Kapitel']);
});

it('Graceful ohne Provider: queued Freitext-Skizze → wartet_ki, bleibt queued, kein Rezept/Block', function () {
    // Kein LLM-Provider gebunden (Sandbox-Default) → propose() wirft KiNichtVerfuegbarException.
    config(['foodalchemist.ai.provider' => 'core']);

    $idee = $this->ideen->add($this->rootTeam, ['chapter_id' => $this->kapitel->id, 'title' => 'Wildkräuter-Terrine']);
    $this->fbSvc->kapitelFreigeben($this->rootTeam, $this->kapitel->id);   // setzt generation_status=queued
    expect($idee->refresh()->generation_status)->toBe('queued');

    $res = $this->fbSvc->materialisiereFreitextIdee($this->rootTeam, $idee->id);

    expect($res['status'])->toBe('wartet_ki');
    $idee->refresh();
    expect($idee->generation_status)->toBe('queued')            // retrybar, NICHT fehlgeschlagen
        ->and($idee->status)->toBe('entwurf')
        ->and($idee->generated_recipe_id)->toBeNull()
        ->and($idee->materialized_at)->toBeNull()
        ->and($idee->source_meta['generation_hinweis'] ?? null)->toBe('wartet auf KI');
    // Nichts materialisiert.
    expect($this->kapitel->blocks()->count())->toBe(0)
        ->and(FoodAlchemistRecipe::where('team_id', $this->rootTeam->id)->count())->toBe(0);
});

it('verarbeiteFreitextQueue reiht je queued Freitext-Skizze genau einen MaterializeIdeaJob ein', function () {
    Queue::fake();

    $a = $this->ideen->add($this->rootTeam, ['chapter_id' => $this->kapitel->id, 'title' => 'Idee A']);
    $b = $this->ideen->add($this->rootTeam, ['chapter_id' => $this->kapitel->id, 'title' => 'Idee B']);
    $this->fbSvc->kapitelFreigeben($this->rootTeam, $this->kapitel->id);   // beide → queued

    $res = $this->fbSvc->verarbeiteFreitextQueue($this->rootTeam, $this->kapitel->id, $this->makeUser($this->rootTeam, 'Ausloeser')->id);

    expect($res['dispatched'])->toBe(2)
        ->and($res['ids'])->toEqualCanonicalizing([(int) $a->id, (int) $b->id]);
    Queue::assertPushed(MaterializeIdeaJob::class, 2);
    Queue::assertPushed(MaterializeIdeaJob::class, fn (MaterializeIdeaJob $j) => $j->ideaId === (int) $a->id && $j->teamId === $this->rootTeam->id);
});

it('Fehlerpfad: unbrauchbares KI-Rezept (Fake-Echo) → fehlgeschlagen, Skizze bleibt erhalten', function () {
    // Fake-Provider ist ein Kontext-Echo → liefert kein name+zutaten → generiere() wirft
    // RuntimeException („kein verwertbares Rezept") → catch-all → fehlgeschlagen.
    config(['foodalchemist.ai.provider' => 'fake', 'foodalchemist.ai.backoff' => []]);

    $idee = $this->ideen->add($this->rootTeam, ['chapter_id' => $this->kapitel->id, 'title' => 'Unmöglich-Gericht']);
    $this->fbSvc->kapitelFreigeben($this->rootTeam, $this->kapitel->id);

    $res = $this->fbSvc->materialisiereFreitextIdee($this->rootTeam, $idee->id);

    expect($res['status'])->toBe('fehlgeschlagen');
    $idee->refresh();
    expect($idee->generation_status)->toBe('fehlgeschlagen')
        ->and($idee->title)->toBe('Unmöglich-Gericht')          // Original bleibt (kein Kreativitätsverlust)
        ->and($idee->generated_recipe_id)->toBeNull()
        ->and($idee->source_meta['generation_fehler'] ?? null)->not->toBeNull();
    expect(FoodAlchemistConcept::where('team_id', $this->rootTeam->id)->count())->toBe(0);
});

it('überspringt bereits materialisierte / Bestands-Skizzen (idempotent)', function () {
    config(['foodalchemist.ai.provider' => 'core']);

    // Skizze, die gar nicht queued ist → uebersprungen (kein KI-Call).
    $idee = $this->ideen->add($this->rootTeam, ['chapter_id' => $this->kapitel->id, 'title' => 'Nicht-in-Queue']);

    $res = $this->fbSvc->materialisiereFreitextIdee($this->rootTeam, $idee->id);

    expect($res['status'])->toBe('uebersprungen');
    expect($idee->refresh()->generation_status)->toBeNull();
});
