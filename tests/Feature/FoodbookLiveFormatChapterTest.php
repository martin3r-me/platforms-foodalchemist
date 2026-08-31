<?php

use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Services\FoodbookService;
use Platform\FoodAlchemist\Services\FormatService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/** B (2026-08-31): LEBENDES Format-Kapitel — ein Foodbook-Kapitel mit format_id rendert Identität +
 *  Editionen LIVE aus dem Format (Zusammensetzung wirkt durch), statt der 2026-08-27-Einmal-Expansion. */

beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->formatSvc = app(FormatService::class);
    $this->foodbookSvc = app(FoodbookService::class);
    $this->concepts = app(ConceptService::class);

    // Ein Concept mit einem Gericht (für die Wording-Kette / Editions-Zeile).
    $this->mkEdition = function (string $name): \Platform\FoodAlchemist\Models\FoodAlchemistConcept {
        $c = $this->concepts->create($this->rootTeam, ['name' => $name]);
        $c->update(['status' => 'active', 'consumer_name' => $name . ' (Kunde)']);
        $recipe = $this->makeRecipe($this->rootTeam, $name . '-Bowl', ['is_sales_recipe' => true, 'status' => 'approved', 'sales_net' => 12.0]);
        $this->makeConceptSlot($c, ['position' => 1, 'sales_recipe_id' => $recipe->id, 'wording' => $name . '-Bowl-Wording']);

        return $c;
    };
});

it('insertFormatAlsKapitel legt EINE Sektion mit format_id an (keine Unterkapitel/Blöcke mehr)', function () {
    $c = ($this->mkEdition)('Sommer');
    $format = $this->formatSvc->create($this->rootTeam, ['name' => 'CHEFS.CORNER', 'consumer_name' => 'Chefs Corner', 'story' => 'World on a Plate.']);
    $this->formatSvc->slotConceptEinfuegen($this->rootTeam, $format->id, $c->id);
    $fb = $this->makeFoodbook($this->rootTeam, 'Kundenbuch');

    $sektion = $this->foodbookSvc->insertFormatAlsKapitel($this->rootTeam, $fb->id, $format->id);

    expect((int) $sektion->format_id)->toBe((int) $format->id)
        ->and((bool) $sektion->is_struktur)->toBeTrue()
        ->and($sektion->consumer_title)->toBe('Chefs Corner');
    // KEINE Unterkapitel (Live-Ref statt Expansion) + KEINE Blöcke auf der Sektion
    expect(\Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel::where('foodbook_id', $fb->id)->count())->toBe(1)
        ->and($sektion->blocks()->count())->toBe(0);
});

it('dokumentDaten rendert das Format-Kapitel LIVE: ist_format + Editionen aus format_slots + Header-Struktur', function () {
    $c = ($this->mkEdition)('Sommer');
    $format = $this->formatSvc->create($this->rootTeam, ['name' => 'CHEFS.CORNER', 'consumer_name' => 'Chefs Corner', 'claim' => 'World on a Plate', 'story' => 'Story hier.']);
    $this->formatSvc->slotBlockEinfuegen($this->rootTeam, $format->id, 'header', ['title' => 'Warme Mitte']);
    $this->formatSvc->slotConceptEinfuegen($this->rootTeam, $format->id, $c->id);
    $fb = $this->makeFoodbook($this->rootTeam, 'Kundenbuch');
    $this->foodbookSvc->insertFormatAlsKapitel($this->rootTeam, $fb->id, $format->id);

    $daten = $this->foodbookSvc->dokumentDaten($this->rootTeam, $fb->fresh());
    $row = collect($daten['kapitel'])->firstWhere('ist_format', true);

    expect($row)->not->toBeNull()
        ->and($row['title'])->toBe('Chefs Corner')
        ->and($row['claim'])->toBe('World on a Plate')
        ->and($row['preis_range'])->toBeArray();
    // Editionen: 1 Header-Struktur + 1 Concept-Edition (mit Gericht-Zeile aus der Wording-Kette)
    $editionen = collect($row['editionen']);
    expect($editionen->firstWhere('typ', 'header')['name'] ?? null)->toBe('Warme Mitte');
    $concEd = $editionen->firstWhere('typ', 'concept');
    expect($concEd)->not->toBeNull()
        ->and($concEd['name'])->toBe('Sommer (Kunde)')
        ->and(count($concEd['gerichte']))->toBeGreaterThan(0);
});

it('Zusammensetzung ist LIVE: eine nach dem Einfügen ergänzte Edition erscheint sofort', function () {
    $c1 = ($this->mkEdition)('Sommer');
    $format = $this->formatSvc->create($this->rootTeam, ['name' => 'CHEFS.CORNER', 'consumer_name' => 'Chefs Corner']);
    $this->formatSvc->slotConceptEinfuegen($this->rootTeam, $format->id, $c1->id);
    $fb = $this->makeFoodbook($this->rootTeam, 'Kundenbuch');
    $this->foodbookSvc->insertFormatAlsKapitel($this->rootTeam, $fb->id, $format->id);

    $vorher = collect($this->foodbookSvc->dokumentDaten($this->rootTeam, $fb->fresh())['kapitel'])->firstWhere('ist_format', true);
    expect(collect($vorher['editionen'])->where('typ', 'concept'))->toHaveCount(1);

    // Edition NACH dem Einfügen zum Format ergänzen → muss ohne erneutes Einfügen durchwirken.
    $c2 = ($this->mkEdition)('Winter');
    $this->formatSvc->slotConceptEinfuegen($this->rootTeam, $format->id, $c2->id);

    $nachher = collect($this->foodbookSvc->dokumentDaten($this->rootTeam, $fb->fresh())['kapitel'])->firstWhere('ist_format', true);
    expect(collect($nachher['editionen'])->where('typ', 'concept'))->toHaveCount(2);
});

it('Reconciliation: gelöschtes Format → Platzhalter (kein Fehler), Editionen leer', function () {
    $c = ($this->mkEdition)('Sommer');
    $format = $this->formatSvc->create($this->rootTeam, ['name' => 'CHEFS.CORNER']);
    $this->formatSvc->slotConceptEinfuegen($this->rootTeam, $format->id, $c->id);
    $fb = $this->makeFoodbook($this->rootTeam, 'Kundenbuch');
    $this->foodbookSvc->insertFormatAlsKapitel($this->rootTeam, $fb->id, $format->id);

    $this->formatSvc->delete($this->rootTeam, $format->id);   // soft-delete

    $row = collect($this->foodbookSvc->dokumentDaten($this->rootTeam, $fb->fresh())['kapitel'])->firstWhere('ist_format', true);
    expect($row)->not->toBeNull()->and($row['editionen'])->toBe([]);
});
