<?php

use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRun;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRunStep;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookBlock;
use Platform\FoodAlchemist\Models\FoodAlchemistFormatSlot;
use Platform\FoodAlchemist\Services\FoodbookService;
use Platform\FoodAlchemist\Services\FormatService;
use Platform\FoodAlchemist\Services\PlanningCascadeService;
use Platform\FoodAlchemist\Services\PlanningFrameService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 80 Teil F — Titel, Titel mit Preis, Freitext, Leerzeile im Planungsrahmen. Entscheid Dominique 2026-10-09:
 * bei Foodbook/Speisekarte an den Anfang des folgenden Abschnitts, beim Format an ihre Stelle.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->frames = app(PlanningFrameService::class);
    $team = $this->rootTeam;   // vor dem Binden lesen — in der gebundenen Closure ist die Test-Eigenschaft geschützt
    $this->lege = fn (string $typ, int $id, $frame) => Closure::bind(
        fn () => $this->legeStrukturAn($team, $typ, $id, $frame), app(PlanningCascadeService::class), PlanningCascadeService::class)();
});

it('Struktur-Typen nur in Rahmen von Foodbook, Speisekarte und Format', function () {
    $concept = $this->makeConcept($this->rootTeam, 'Menü');
    $frame = $this->frames->frameFor($this->rootTeam, 'concept', $concept->id);

    expect(fn () => $this->frames->addSlot($this->rootTeam, $frame, ['label' => 'Hinweis', 'slot_type' => 'freitext']))
        ->toThrow(RuntimeException::class, 'nur im Rahmen');
});

it('Foodbook: Titel mit Preis landet am Anfang des folgenden Kapitels, wird kein eigenes Kapitel', function () {
    $fb = $this->makeFoodbook($this->rootTeam, 'Herbst');
    $frame = $this->frames->frameFor($this->rootTeam, 'foodbook', $fb->id);
    $this->frames->addSlot($this->rootTeam, $frame, ['label' => 'Herbstmenü', 'slot_type' => 'titel_preis', 'price_anchor' => 59, 'position' => 1]);
    $this->frames->addSlot($this->rootTeam, $frame, ['label' => 'Hauptgänge', 'slot_type' => 'gang', 'position' => 2]);

    app(FoodbookService::class)->strukturAusGeruest($this->rootTeam, $fb->id);
    $kapitel = $fb->chapters()->get();
    expect($kapitel)->toHaveCount(1)->and($kapitel[0]->title)->toBe('Hauptgänge');

    // Ein vorhandener Block im Kapitel — der Titel muss davor.
    app(FoodbookService::class)->addBlock($this->rootTeam, $kapitel[0]->id, ['type' => 'text', 'customer_text' => 'alt']);
    ($this->lege)('foodbook', $fb->id, $frame->fresh());

    $bloecke = FoodAlchemistFoodbookBlock::where('chapter_id', $kapitel[0]->id)->orderBy('position')->get();
    expect($bloecke->pluck('type')->all())->toBe(['header_frei_preis', 'text'])
        ->and($bloecke[0]->label)->toBe('Herbstmenü')
        ->and((float) $bloecke[0]->price_value)->toBe(59.0);
});

it('Format: Struktur an ihrer Stelle, Konzepte werden nach dem Einhängen in Rahmen-Reihenfolge geordnet', function () {
    $format = app(FormatService::class)->create($this->rootTeam, ['name' => 'Herbst-Format']);
    $frame = $this->frames->frameFor($this->rootTeam, 'format', $format->id);
    $titel = $this->frames->addSlot($this->rootTeam, $frame, ['label' => 'Vorspeisen', 'slot_type' => 'titel', 'position' => 1]);
    $gang1 = $this->frames->addSlot($this->rootTeam, $frame, ['label' => 'Vorspeise', 'slot_type' => 'gang', 'position' => 2]);
    $pause = $this->frames->addSlot($this->rootTeam, $frame, ['label' => 'Pause', 'slot_type' => 'leerzeile', 'position' => 3]);
    $gang2 = $this->frames->addSlot($this->rootTeam, $frame, ['label' => 'Hauptgang', 'slot_type' => 'gang', 'position' => 4]);

    $map = ($this->lege)('format', $format->id, $frame->fresh());
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'vollkaskade', 'status' => 'running',
        'source_owner_type' => 'format', 'source_owner_id' => $format->id, 'params' => ['struktur_map' => $map]]);
    // Hauptgang-Konzept kommt ZUERST fertig zurück, dann die Vorspeise (asynchron).
    foreach ([[$gang2, 'Hauptgang-Menü'], [$gang1, 'Vorspeisen-Menü']] as [$slot, $name]) {
        $c = $this->makeConcept($this->rootTeam, $name);
        FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'concept',
            'status' => 'done', 'ref_type' => 'concept', 'ref_id' => $c->id, 'slot_id' => $slot->id]);
        app(FormatService::class)->slotConceptEinfuegen($this->rootTeam, $format->id, $c->id);
        app(PlanningCascadeService::class)->ordneFormatNachRahmen($this->rootTeam, $run->id);
    }

    $reihe = FoodAlchemistFormatSlot::where('format_id', $format->id)->orderBy('position')->get()
        ->map(fn ($s) => $s->type === 'concept' ? $s->concept?->name : $s->type)->all();
    expect($reihe)->toBe(['header', 'Vorspeisen-Menü', 'spacer', 'Hauptgang-Menü']);
});
