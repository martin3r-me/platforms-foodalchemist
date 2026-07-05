<?php

use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Services\ConcepterAggregateService;
use Platform\FoodAlchemist\Services\ConcepterBewertungService;
use Platform\FoodAlchemist\Services\PaketService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * M10R-3 / Doc 15 §10.8: deterministische Menü-Bewertung (Struktur · Niveau ·
 * Diät · Preis-Korridor · Allergen-Konfidenz). Keine KI — reine Regeln.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->team = $this->rootTeam;   // öffentliche dyn. Prop für die Helper unten
    $this->pakete = app(PaketService::class);
    $this->concepts = app(ConceptService::class);
    $this->agg = app(ConcepterAggregateService::class);
    $this->bew = app(ConcepterBewertungService::class);

    $veg = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'v', 'name' => 'Veganer Salat',
        'status' => 'approved', 'is_sales_recipe' => true, 'sales_net' => 4.50, 'ek_total_eur' => 1.40,
        'sales_quantity_per_unit_g' => 200, 'nutri_kcal_per_100g' => 120, 'nutri_confidence' => 'high',
        'spec_is_vegan' => true, 'spec_is_vegetarian' => true, 'allergens_confidence' => 'high',
    ]);
    $this->paket = $this->pakete->create($this->rootTeam, ['name' => 'Salad Wall', 'role' => 'Vorspeise', 'level' => 'klassisch']);
    $this->pakete->update($this->rootTeam, $this->paket->id, ['price_per_person' => 4.50, 'level' => 'klassisch']);
    $this->pakete->syncGerichte($this->rootTeam, $this->paket->id, [['sales_recipe_id' => $veg->id]]);

    $this->concept = $this->concepts->create($this->rootTeam, ['name' => 'Mini-Menü', 'level' => 'klassisch']);
    $slot = $this->concepts->addSlot($this->rootTeam, $this->concept->id, ['role' => 'Vorspeise']);
    $this->concepts->fillSlot($this->rootTeam, $slot->id, ['package_id' => $this->paket->id]);
});

function bewertungFuer($self): array
{
    $c = $self->concepts->detail($self->team, $self->concept->id);
    $cockpit = $self->concepts->preisCockpit($c);
    $aggregat = $self->agg->conceptAggregat($c);

    return $self->bew->bewerten($c, $cockpit, $aggregat);
}

function statusVon(array $bew, string $key): string
{
    return collect($bew['checks'])->firstWhere('key', $key)['status'] ?? 'missing';
}

it('befülltes Menü, einheitliches Niveau, hohe Konfidenz → Struktur/Niveau/Allergen ok', function () {
    $b = bewertungFuer($this);

    expect(statusVon($b, 'struktur'))->toBe('ok')
        ->and(statusVon($b, 'level'))->toBe('ok')
        ->and(statusVon($b, 'allergen'))->toBe('ok')
        ->and($b['score'])->toBeGreaterThanOrEqual(80);
});

it('leere Position → Struktur warn', function () {
    $this->concepts->addSlot($this->rootTeam, $this->concept->id, ['role' => 'Dessert']);   // unbefüllt

    expect(statusVon(bewertungFuer($this), 'struktur'))->toBe('warn');
});

it('Diät-Vorgabe vegan wird gegen den Rollup geprüft', function () {
    $this->concepts->update($this->rootTeam, $this->concept->id, ['diet_requirement' => 'vegan']);
    expect(statusVon(bewertungFuer($this), 'diaet'))->toBe('ok');           // einziges Gericht ist vegan
});

it('Preis-Korridor: Ziel weit daneben → fail, Ziel nah → ok', function () {
    $this->concepts->update($this->rootTeam, $this->concept->id, ['target_price_per_person' => 10.00]);
    expect(statusVon(bewertungFuer($this), 'price'))->toBe('fail');         // 4,50 vs 10,00 = 55 % Abw.

    $this->concepts->update($this->rootTeam, $this->concept->id, ['target_price_per_person' => 4.60]);
    expect(statusVon(bewertungFuer($this), 'price'))->toBe('ok');           // ~2 % Abw.
});
