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
        'status' => 'approved', 'ist_verkaufsrezept' => true, 'vk_netto' => 4.50, 'ek_total_eur' => 1.40,
        'vk_menge_pro_einheit_g' => 200, 'nutri_kcal_per_100g' => 120, 'nutri_konfidenz' => 'high',
        'spec_is_vegan' => true, 'spec_is_vegetarian' => true, 'allergene_konfidenz' => 'high',
    ]);
    $this->paket = $this->pakete->create($this->rootTeam, ['name' => 'Salad Wall', 'rolle' => 'Vorspeise', 'niveau' => 'klassisch']);
    $this->pakete->update($this->rootTeam, $this->paket->id, ['preis_pro_person' => 4.50, 'niveau' => 'klassisch']);
    $this->pakete->syncGerichte($this->rootTeam, $this->paket->id, [['vk_recipe_id' => $veg->id]]);

    $this->concept = $this->concepts->create($this->rootTeam, ['name' => 'Mini-Menü', 'niveau' => 'klassisch']);
    $slot = $this->concepts->addSlot($this->rootTeam, $this->concept->id, ['rolle' => 'Vorspeise']);
    $this->concepts->fillSlot($this->rootTeam, $slot->id, ['paket_id' => $this->paket->id]);
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
        ->and(statusVon($b, 'niveau'))->toBe('ok')
        ->and(statusVon($b, 'allergen'))->toBe('ok')
        ->and($b['score'])->toBeGreaterThanOrEqual(80);
});

it('leere Position → Struktur warn', function () {
    $this->concepts->addSlot($this->rootTeam, $this->concept->id, ['rolle' => 'Dessert']);   // unbefüllt

    expect(statusVon(bewertungFuer($this), 'struktur'))->toBe('warn');
});

it('Diät-Vorgabe vegan wird gegen den Rollup geprüft', function () {
    $this->concepts->update($this->rootTeam, $this->concept->id, ['diaet_vorgabe' => 'vegan']);
    expect(statusVon(bewertungFuer($this), 'diaet'))->toBe('ok');           // einziges Gericht ist vegan
});

it('Preis-Korridor: Ziel weit daneben → fail, Ziel nah → ok', function () {
    $this->concepts->update($this->rootTeam, $this->concept->id, ['zielpreis_pro_person' => 10.00]);
    expect(statusVon(bewertungFuer($this), 'preis'))->toBe('fail');         // 4,50 vs 10,00 = 55 % Abw.

    $this->concepts->update($this->rootTeam, $this->concept->id, ['zielpreis_pro_person' => 4.60]);
    expect(statusVon(bewertungFuer($this), 'preis'))->toBe('ok');           // ~2 % Abw.
});
