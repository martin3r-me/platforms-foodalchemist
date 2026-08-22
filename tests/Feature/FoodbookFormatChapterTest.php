<?php

use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookBlock;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\FoodbookService;
use Platform\FoodAlchemist\Services\FormatService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Format-Modul (Phase C) — Foodbook Format-Kapitel (Voll-Live):
 * insertFormatChapter + Live-Projektion in dokumentDaten (Identität + Editionen +
 * Preis-Range), NICHT additiv im Total, Kunden-IP-Guard, graceful bei gelöschtem Format.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->svc = app(FoodbookService::class);
    $this->fsvc = app(FormatService::class);
    $this->seq = 0;

    $this->makeFoodbook = function (?string $customer = null) {
        return FoodAlchemistFoodbook::create([
            'team_id' => $this->rootTeam->id, 'code' => 'FB-' . (++$this->seq),
            'label' => 'Test-Foodbook', 'jahr' => 2027, 'customer' => $customer,
            'personen' => 100, 'status' => 'draft',
        ]);
    };

    // Edition = Concept mit einem Gericht-Slot (für die Wording-Zeilen), an ein Format gehängt.
    $this->makeEdition = function (int $formatId, string $name, float $price, string $dishName) {
        $dish = FoodAlchemistRecipe::create([
            'team_id' => $this->rootTeam->id, 'recipe_key' => 'fmt-dish-' . (++$this->seq),
            'name' => $dishName, 'is_sales_recipe' => true, 'status' => 'approved',
        ]);
        $c = FoodAlchemistConcept::create([
            'team_id' => $this->rootTeam->id, 'name' => $name, 'consumer_name' => $name,
            'price_per_person_cache' => $price,
        ]);
        $c->slots()->create(['team_id' => $this->rootTeam->id, 'type' => 'gericht', 'sales_recipe_id' => $dish->id, 'position' => 0]);
        $this->fsvc->attachEdition($this->rootTeam, $formatId, $c->id);

        return $c;
    };
});

it('insertFormatChapter legt ein Format-Kapitel an (format_id + geseedete Identität)', function () {
    $f = $this->fsvc->create($this->rootTeam, ['name' => 'CHEFS.CORNER', 'consumer_name' => 'CHEFS.CORNER']);
    $fb = ($this->makeFoodbook)();

    $k = $this->svc->insertFormatChapter($this->rootTeam, $fb->id, $f->id);

    expect((int) $k->format_id)->toBe($f->id)
        ->and($k->title)->toBe('CHEFS.CORNER')
        ->and($k->consumer_title)->toBe('CHEFS.CORNER');
});

it('dokumentDaten rendert das Format-Kapitel live (Editionen + Preis-Range, nicht additiv)', function () {
    $f = $this->fsvc->create($this->rootTeam, ['name' => 'CHEFS.CORNER', 'claim' => 'WORLD ON A PLATE']);
    ($this->makeEdition)($f->id, 'FUTURE FLAVORS', 47.50, 'Sous-Vide Prime');
    ($this->makeEdition)($f->id, 'FARM TO TABLE', 49.50, 'Forager’s Delight');
    $fb = ($this->makeFoodbook)();
    $this->svc->insertFormatChapter($this->rootTeam, $fb->id, $f->id);

    $doc = $this->svc->dokumentDaten($this->rootTeam, $fb->fresh(), false);
    $row = collect($doc['kapitel'])->firstWhere('ist_format', true);

    expect($row)->not->toBeNull()
        ->and($row['claim'])->toBe('WORLD ON A PLATE')
        ->and($row['preis_range'])->toBe(['min' => 47.50, 'max' => 49.50])
        ->and($row['vk_pro_person'])->toBeNull()          // Showcase → nicht additiv
        ->and($row['editionen'])->toHaveCount(2)
        ->and($row['editionen'][0]['name'])->toBe('FUTURE FLAVORS')
        ->and($row['editionen'][0]['preis_pp'])->toBe(47.50)
        ->and(collect($row['editionen'][0]['gerichte'])->pluck('text'))->toContain('Sous-Vide Prime');
});

it('Format-Kapitel zählt nicht in den Foodbook-Gesamtpreis (Bespoke-Kapitel schon)', function () {
    $fb = ($this->makeFoodbook)();

    // Bespoke-Kapitel mit einem recipe_ref-Block (Gericht sales_net 20 €) → additiv.
    $bespoke = $this->svc->addKapitel($this->rootTeam, $fb->id, ['title' => 'Bespoke']);
    $dish = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'bespoke-dish-' . (++$this->seq),
        'name' => 'Einzelgericht', 'is_sales_recipe' => true, 'status' => 'approved', 'sales_net' => 20.0,
    ]);
    FoodAlchemistFoodbookBlock::create([
        'team_id' => $this->rootTeam->id, 'chapter_id' => $bespoke->id, 'type' => 'recipe_ref',
        'sales_recipe_id' => $dish->id, 'position' => 0, 'visible' => true,
    ]);

    // Format-Kapitel (Showcase) → 0 additiv.
    $f = $this->fsvc->create($this->rootTeam, ['name' => 'CHEFS.CORNER']);
    ($this->makeEdition)($f->id, 'FUTURE FLAVORS', 47.50, 'Sous-Vide Prime');
    $this->svc->insertFormatChapter($this->rootTeam, $fb->id, $f->id);

    $gesamt = $this->svc->gesamt($this->rootTeam, $fb->fresh());
    expect($gesamt['vk_pro_person'])->toBe(20.0);   // nur das Bespoke-Kapitel, NICHT die 47,50 des Formats
});

it('Kunden-IP-Guard: fremdes Kunden-Format ist nicht einfügbar', function () {
    $f = $this->fsvc->create($this->rootTeam, ['name' => 'Kunde-A-Format', 'origin' => 'kunde', 'customer' => 'Kunde A']);
    $fb = ($this->makeFoodbook)('Kunde B');

    expect(fn () => $this->svc->insertFormatChapter($this->rootTeam, $fb->id, $f->id))
        ->toThrow(RuntimeException::class);
});

it('graceful: gelöschtes Format lässt dokumentDaten nicht crashen (Kapitel fällt auf leer zurück)', function () {
    $f = $this->fsvc->create($this->rootTeam, ['name' => 'CHEFS.CORNER']);
    ($this->makeEdition)($f->id, 'FUTURE FLAVORS', 47.50, 'Sous-Vide Prime');
    $fb = ($this->makeFoodbook)();
    $this->svc->insertFormatChapter($this->rootTeam, $fb->id, $f->id);

    $this->fsvc->delete($this->rootTeam, $f->id);   // Soft-Delete

    $doc = $this->svc->dokumentDaten($this->rootTeam, $fb->fresh(), false);
    // Kein Format mehr auflösbar → kein ist_format-Kapitel, aber auch kein Crash.
    expect(collect($doc['kapitel'])->firstWhere('ist_format', true))->toBeNull()
        ->and($doc['kapitel'])->toHaveCount(1);
});
