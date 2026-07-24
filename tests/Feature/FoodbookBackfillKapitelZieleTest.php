<?php

use Platform\FoodAlchemist\Services\FoodbookService;
use Platform\FoodAlchemist\Services\PlanningFrameService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 19 E4.5: Backfill Slot-Ziele → Kapitel. `strukturAusGeruest` stempelt nur bei Neu-Anlage;
 * dieser Backfill holt es für VOR-E4.1-Kopplungen nach (Slot hatte Ziele, Kapitel blieb NULL).
 * Idempotent, nur NULL-Felder werden gefüllt, --apply=false = Dry-Run.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->svc = app(FoodbookService::class);
    $this->frames = app(PlanningFrameService::class);

    // Legacy-Szenario: Slot ohne Ziele → Kapitel (kein Stempel), dann Slot-Ziele nachtragen
    // (simuliert einen Slot, der vor E4.1 schon Ziele hatte). Bindet an die Test-Instanz.
    $this->legacyKapitelOhneZiele = function (): array {
        $fb = $this->svc->create($this->rootTeam, ['label' => 'Backfill']);
        $frame = $this->frames->frameFor($this->rootTeam, 'foodbook', $fb->id);
        $slot = $this->frames->addSlot($this->rootTeam, $frame, ['label' => 'Vorspeisen', 'slot_type' => 'gang']);
        $this->svc->strukturAusGeruest($this->rootTeam, $fb->id); // legt Kapitel an, stempelt NICHTS (Slot leer)
        $slot->refresh();
        $this->frames->updateSlot($this->rootTeam, $slot->id, [
            'target_count' => 4, 'price_anchor' => 6.50, 'price_min' => 5.00, 'price_max' => 9.00,
        ]);
        $kapitel = $fb->chapters()->where('title', 'Vorspeisen')->first();

        return [$fb, $kapitel];
    };
});

it('Dry-Run meldet Kapitel, schreibt aber nichts', function () {
    [$fb, $kapitel] = ($this->legacyKapitelOhneZiele)();
    expect($kapitel->target_count)->toBeNull();

    $r = $this->svc->backfillSlotZiele($this->rootTeam, $fb->id, false);

    expect($r['slots_geprueft'])->toBe(1)
        ->and($r['kapitel_gestempelt'])->toBe(1)
        ->and($r['felder_gesetzt'])->toBe(4)
        ->and($r['protokoll'][0]['felder'])->toBe(['target_count', 'price_anchor', 'price_min', 'price_max']);

    // Kein Write.
    $kapitel->refresh();
    expect($kapitel->target_count)->toBeNull()
        ->and($kapitel->price_anchor)->toBeNull();
});

it('--apply stempelt Slot-Ziele aufs Kapitel und ist idempotent', function () {
    [$fb, $kapitel] = ($this->legacyKapitelOhneZiele)();

    $r1 = $this->svc->backfillSlotZiele($this->rootTeam, $fb->id, true);
    expect($r1['kapitel_gestempelt'])->toBe(1)
        ->and($r1['felder_gesetzt'])->toBe(4);

    $kapitel->refresh();
    expect((int) $kapitel->target_count)->toBe(4)
        ->and((float) $kapitel->price_anchor)->toBe(6.50)
        ->and((float) $kapitel->price_min)->toBe(5.00)
        ->and((float) $kapitel->price_max)->toBe(9.00);

    // Zweiter Lauf: alles gefüllt → nichts mehr zu stempeln.
    $r2 = $this->svc->backfillSlotZiele($this->rootTeam, $fb->id, true);
    expect($r2['kapitel_gestempelt'])->toBe(0)
        ->and($r2['felder_gesetzt'])->toBe(0)
        ->and($r2['protokoll'])->toBe([]);
});

it('lässt bereits gesetzte Kapitel-Ziele unangetastet (nur NULL-Felder)', function () {
    [$fb, $kapitel] = ($this->legacyKapitelOhneZiele)();
    // Kapitel hat schon einen abweichenden target_count → darf NICHT überschrieben werden.
    $this->svc->updateKapitel($this->rootTeam, $kapitel->id, ['target_count' => 2]);

    $r = $this->svc->backfillSlotZiele($this->rootTeam, $fb->id, true);
    // target_count bleibt, die 3 Preis-Felder werden gestempelt.
    expect($r['felder_gesetzt'])->toBe(3)
        ->and($r['protokoll'][0]['felder'])->toBe(['price_anchor', 'price_min', 'price_max']);

    $kapitel->refresh();
    expect((int) $kapitel->target_count)->toBe(2)
        ->and((float) $kapitel->price_anchor)->toBe(6.50);
});

it('Command-Roundtrip: Dry-Run + --apply', function () {
    [$fb, $kapitel] = ($this->legacyKapitelOhneZiele)();

    $this->artisan('foodalchemist:backfill-kapitel-ziele', ['--team' => $this->rootTeam->id, '--foodbook' => $fb->id])
        ->assertSuccessful();
    $kapitel->refresh();
    expect($kapitel->target_count)->toBeNull(); // Dry-Run schreibt nicht

    $this->artisan('foodalchemist:backfill-kapitel-ziele', ['--team' => $this->rootTeam->id, '--foodbook' => $fb->id, '--apply' => true])
        ->assertSuccessful();
    $kapitel->refresh();
    expect((int) $kapitel->target_count)->toBe(4);
});
