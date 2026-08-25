<?php

use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistFormatSlot;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Services\FormatService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Format-Umbau F2: format_slots — Aufbau des Formats („Conceptor eine Ebene höher"):
 * Concept-Referenzen (in mehreren Formaten nutzbar) + Struktur-Blöcke (header/text/spacer).
 * Deckt Slot-Management (FormatService) + die Editionen→Slots-Datenmigration ab.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->svc = app(FormatService::class);
    $this->concepts = app(ConceptService::class);

    $mkConcept = fn (string $name, string $status = 'active') => tap(
        $this->concepts->create($this->rootTeam, ['name' => $name]),
        fn ($c) => $c->update(['status' => $status])
    );
    $this->c1 = $mkConcept('Sommer-Menü');
    $this->c2 = $mkConcept('Winter-Menü');
    $this->format = $this->svc->create($this->rootTeam, ['name' => 'CHEFS.CORNER']);
});

it('slotConceptEinfuegen legt eine Concept-Referenz an; ein Concept passt in mehrere Formate', function () {
    $slot = $this->svc->slotConceptEinfuegen($this->rootTeam, $this->format->id, $this->c1->id);
    expect($slot->type)->toBe('concept')->and((int) $slot->concept_id)->toBe($this->c1->id);

    // dasselbe Concept in ein zweites Format → Referenz, kein Besitz-Konflikt
    $format2 = $this->svc->create($this->rootTeam, ['name' => 'STREETFOOD.MARKET']);
    $this->svc->slotConceptEinfuegen($this->rootTeam, $format2->id, $this->c1->id);
    expect(FoodAlchemistFormatSlot::where('concept_id', $this->c1->id)->count())->toBe(2);
});

it('slotBlockEinfuegen legt header/text/spacer an; unbekannter Typ wirft', function () {
    $h = $this->svc->slotBlockEinfuegen($this->rootTeam, $this->format->id, 'header', ['title' => 'Vorspeisen']);
    $t = $this->svc->slotBlockEinfuegen($this->rootTeam, $this->format->id, 'text', ['text_content' => 'Unsere Welt auf dem Teller']);
    $s = $this->svc->slotBlockEinfuegen($this->rootTeam, $this->format->id, 'spacer');
    expect($h->title)->toBe('Vorspeisen')->and($t->text_content)->toBe('Unsere Welt auf dem Teller')->and($s->height)->toBe('mittel');

    expect(fn () => $this->svc->slotBlockEinfuegen($this->rootTeam, $this->format->id, 'quatsch'))
        ->toThrow(RuntimeException::class);
});

it('Einfügen hinter einem Ziel-Slot sortiert korrekt ein', function () {
    $a = $this->svc->slotConceptEinfuegen($this->rootTeam, $this->format->id, $this->c1->id);       // [A]
    $b = $this->svc->slotConceptEinfuegen($this->rootTeam, $this->format->id, $this->c2->id);       // [A, B]
    // Header direkt hinter A → [A, Header, B]
    $h = $this->svc->slotBlockEinfuegen($this->rootTeam, $this->format->id, 'header', ['title' => 'X'], $a->id);

    $reihen = $this->format->slots()->orderBy('position')->pluck('id')->all();
    expect($reihen)->toBe([$a->id, $h->id, $b->id]);
});

it('slotsNeuOrdnen + slotEntfernen', function () {
    $a = $this->svc->slotConceptEinfuegen($this->rootTeam, $this->format->id, $this->c1->id);
    $b = $this->svc->slotConceptEinfuegen($this->rootTeam, $this->format->id, $this->c2->id);
    $this->svc->slotsNeuOrdnen($this->rootTeam, $this->format->id, [$b->id, $a->id]);
    expect($this->format->slots()->orderBy('position')->pluck('id')->all())->toBe([$b->id, $a->id]);

    $this->svc->slotEntfernen($this->rootTeam, $a->id);
    expect($this->format->slots()->count())->toBe(1);
});

it('conceptKandidaten liefert nur aktive Konzepte', function () {
    $this->c2->update(['status' => 'draft']);
    $namen = $this->svc->conceptKandidaten($this->rootTeam)->pluck('name')->all();
    expect($namen)->toContain('Sommer-Menü')->not->toContain('Winter-Menü');
});

it('#2: paketKandidaten liefert nur kind=paket, conceptKandidaten nur Konzepte', function () {
    $paket = $this->concepts->createPaket($this->rootTeam, ['name' => 'Grill-Paket']); // Default-Status active

    $pakete = $this->svc->paketKandidaten($this->rootTeam);
    $konzepte = $this->svc->conceptKandidaten($this->rootTeam);

    expect($pakete->pluck('name')->all())->toContain('Grill-Paket')
        ->and($pakete->every(fn ($p) => $p->kind === 'paket'))->toBeTrue()
        ->and($pakete->pluck('name')->all())->not->toContain('Sommer-Menü')   // Konzepte gehören nicht in den Paket-Reiter
        ->and($konzepte->pluck('name')->all())->toContain('Sommer-Menü')
        ->and($konzepte->pluck('name')->all())->not->toContain('Grill-Paket'); // Pakete gehören nicht in den Concept-Reiter
});

it('#2: paketKandidaten + conceptKandidaten teilen den Klasse-Filter', function () {
    $this->concepts->createPaket($this->rootTeam, ['name' => 'Flying-Paket', 'class' => 'Flying']);
    $this->concepts->createPaket($this->rootTeam, ['name' => 'Buffet-Paket', 'class' => 'Buffet']);

    $namen = $this->svc->paketKandidaten($this->rootTeam, '', ['class' => 'Flying'])->pluck('name')->all();
    expect($namen)->toContain('Flying-Paket')->not->toContain('Buffet-Paket');
});

it('F6: ein Paket ist als Format-Slot buchbar (kind=paket-Concept über concept_id)', function () {
    $paket = $this->concepts->createPaket($this->rootTeam, ['name' => 'Grill-Paket']);
    $slot = $this->svc->slotConceptEinfuegen($this->rootTeam, $this->format->id, $paket->id);
    expect($slot->type)->toBe('concept')->and((int) $slot->concept_id)->toBe($paket->id);
});

// F2e: der Test der Alt-Editionen→Slots-Datenmigration ist entfernt — `concepts.format_id`
// ist gedroppt, die Test-DB kann den Pre-Drop-Zustand nicht mehr herstellen. Das Command
// (foodalchemist:format-editions-to-slots) bleibt als Escape-Hatch für den Migrations-Guard.
