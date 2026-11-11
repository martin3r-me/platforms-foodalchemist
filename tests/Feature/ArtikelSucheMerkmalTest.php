<?php

use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\SupplierItemService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Artikelsuche 11.11.2026 (Dominique): „Aprikose frisch“ / „Aprikosen ganz“ fand „O+G Aprikosen Kal.A (40-45mm)“ nicht — jedes Wort musste in der
 * Bezeichnung stehen, „frisch“/„ganz“ stehen dort selten. Reine Zustands-/Schnittwörter ranken jetzt (als ganzes Wort) statt zu filtern; „TK“ filtert weiter.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    config(['foodalchemist.semantic_search.enabled' => false]);
    $t = $this->rootTeam->id;
    $sup = FoodAlchemistSupplier::create(['team_id' => $t, 'name' => 'Handelshof', 'rolle' => 'haendler']);
    $this->la = fn (string $name) => FoodAlchemistSupplierItem::create(['team_id' => $t, 'supplier_id' => $sup->id, 'designation' => $name,
        'article_number' => 'A-' . bin2hex(random_bytes(3)), 'qty' => 1, 'unit_code' => 'kg']);
    $this->suche = fn (string $q) => array_map(fn ($i) => $i->designation, app(SupplierItemService::class)->searchGlobal($this->rootTeam, $q, [], 10)->items());
});

it('„Aprikose frisch“ findet frische Aprikosen ohne „frisch“ im Namen; ein frisch benannter Artikel rankt vor, Frischkäse nicht', function () {
    ($this->la)('O+G Aprikosen Kal.A (40-45mm)');
    ($this->la)('Aprikose mit Frischkäse 1300/800g');
    ($this->la)('Aprikosen frisch, Kiste 5 kg');
    ($this->la)('Aprikosen Hälften TK');

    $treffer = ($this->suche)('Aprikose frisch');
    expect($treffer)->toContain('O+G Aprikosen Kal.A (40-45mm)')
        ->and($treffer[0])->toBe('Aprikosen frisch, Kiste 5 kg')
        ->and(array_search('O+G Aprikosen Kal.A (40-45mm)', $treffer))->toBeLessThan(array_search('Aprikose mit Frischkäse 1300/800g', $treffer))
        ->and(($this->suche)('Aprikosen ganz'))->toContain('O+G Aprikosen Kal.A (40-45mm)')
        ->and(($this->suche)('Aprikose TK'))->toBe(['Aprikosen Hälften TK']);
});
