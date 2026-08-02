<?php

use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\SpeisekarteLeitstelleService;
use Platform\FoodAlchemist\Services\SpeisekarteService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/** Speisekarte Stufe E — abgeleitete Leitstelle-Checkliste (Rubriken/Positionen/Preise/Allergene/Branding). */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->karten = app(SpeisekarteService::class);
    $this->ls = app(SpeisekarteLeitstelleService::class);
});

function skStatus(array $stand, string $key): string
{
    return collect($stand['punkte'])->firstWhere('key', $key)['status'];
}

it('Stufe E: leere Karte — alles offen, nicht bereit', function () {
    $karte = $this->karten->create($this->rootTeam, ['name' => 'K']);
    $stand = $this->ls->checkliste($this->rootTeam, $karte->id);
    expect(skStatus($stand, 'rubriken'))->toBe('offen')
        ->and(skStatus($stand, 'positionen'))->toBe('offen')
        ->and($stand['bereit'])->toBeFalse();
});

it('Stufe E: vollständige Karte (Preis + Allergene) → harte Punkte erledigt, bereit', function () {
    $g = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'le1', 'name' => 'Filet', 'status' => 'approved',
        'is_sales_recipe' => true, 'sales_net' => 24.00, 'ek_total_eur' => 8.00,
    ]);
    $g->forceFill(['allergens_confidence' => 'high'])->save();

    $karte = $this->karten->create($this->rootTeam, ['name' => 'K']);
    $rubrik = $this->karten->addRubrik($this->rootTeam, $karte->id, ['title' => 'Hauptgänge']);
    $this->karten->addPosition($this->rootTeam, $rubrik->id, ['type' => 'gericht_ref', 'sales_recipe_id' => $g->id]);

    $stand = $this->ls->checkliste($this->rootTeam, $karte->id);
    expect(skStatus($stand, 'rubriken'))->toBe('erledigt')
        ->and(skStatus($stand, 'positionen'))->toBe('erledigt')
        ->and(skStatus($stand, 'preise'))->toBe('erledigt')
        ->and(skStatus($stand, 'allergene'))->toBe('erledigt')
        ->and($stand['bereit'])->toBeTrue();
});

it('Stufe E: unbekannte Allergen-Konfidenz → allergene offen, nicht bereit', function () {
    $g = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'le2', 'name' => 'Suppe', 'status' => 'approved',
        'is_sales_recipe' => true, 'sales_net' => 6.00,
    ]);
    $g->forceFill(['allergens_confidence' => 'low'])->save();   // schwache Konfidenz → „unbekannt"
    $karte = $this->karten->create($this->rootTeam, ['name' => 'K']);
    $rubrik = $this->karten->addRubrik($this->rootTeam, $karte->id);
    $this->karten->addPosition($this->rootTeam, $rubrik->id, ['type' => 'gericht_ref', 'sales_recipe_id' => $g->id]);

    $stand = $this->ls->checkliste($this->rootTeam, $karte->id);
    expect(skStatus($stand, 'allergene'))->toBe('offen')
        ->and($stand['bereit'])->toBeFalse();
});
