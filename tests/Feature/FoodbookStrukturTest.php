<?php

use Platform\FoodAlchemist\Services\FoodbookService;
use Platform\FoodAlchemist\Services\PlanningFrameService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Phase 3a: „Struktur anwenden" — Planungs-Gerüst-Slots als Kapitel materialisieren
 * (Slot = Kapitel, chapter_id-Kopplung). Idempotenz + Leer-Fall.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->svc = app(FoodbookService::class);
    $this->frames = app(PlanningFrameService::class);
});

it('materialisiert Slots als Kapitel + setzt chapter_id (idempotent)', function () {
    $fb = $this->svc->create($this->rootTeam, ['label' => 'Sommerfest']);
    $frame = $this->frames->frameFor($this->rootTeam, 'foodbook', $fb->id);
    $this->frames->addSlot($this->rootTeam, $frame, ['label' => 'Fingerfood', 'slot_type' => 'gang', 'target_count' => 5]);
    $this->frames->addSlot($this->rootTeam, $frame, ['label' => 'Hauptgang', 'slot_type' => 'gang', 'is_pflicht' => true]);

    $r1 = $this->svc->strukturAusGeruest($this->rootTeam, $fb->id);
    expect($r1['kein_geruest'])->toBeFalse()
        ->and($r1['angelegt'])->toBe(2)
        ->and($r1['uebersprungen'])->toBe(0)
        ->and($fb->chapters()->count())->toBe(2)
        ->and($fb->chapters()->pluck('title')->all())->toContain('Fingerfood', 'Hauptgang');

    // Slots sind jetzt an Kapitel gekoppelt.
    $frame->refresh()->load('slots');
    expect($frame->slots->every(fn ($s) => $s->chapter_id !== null))->toBeTrue();

    // Idempotent: erneut anwenden legt nichts Neues an.
    $r2 = $this->svc->strukturAusGeruest($this->rootTeam, $fb->id);
    expect($r2['angelegt'])->toBe(0)
        ->and($r2['uebersprungen'])->toBe(2)
        ->and($fb->chapters()->count())->toBe(2);
});

it('ohne Gerüst mit Slots: kein_geruest = true, keine Kapitel', function () {
    $fb = $this->svc->create($this->rootTeam, ['label' => 'Leer']);
    $r = $this->svc->strukturAusGeruest($this->rootTeam, $fb->id);
    expect($r['kein_geruest'])->toBeTrue()
        ->and($fb->chapters()->count())->toBe(0);
});
