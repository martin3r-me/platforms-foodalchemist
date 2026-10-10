<?php

use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Services\LaFirstGpService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Lauf 90 (demo, Step 603 „Jus: Rind"): „Rind: frisch, Parüren" (800 g) → GP 12977 „Rinder-Hamburger-Patties: frisch,
 * 160 g pro Stueck" über den Mint — ein LA ohne die Derivat-Form gewann, weil „rinder" auf „rind" stemmt.
 * Regelwerk §11.2: nennt der Text eine Derivat-Form, zählt nur ein LA mit derselben Form; sonst Derivat (Rohware-Mutter).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $supplier = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Necta']);
    $this->la = fn (string $d) => FoodAlchemistSupplierItem::create([
        'team_id' => $this->rootTeam->id, 'supplier_id' => $supplier->id, 'designation' => $d, 'qty' => 1.0, 'unit_code' => 'kg',
    ]);
    // Der Finder liefert je Suchtext Kandidaten wie auf demo (dort per Embedding) — geprüft wird die AUSWAHL
    // (Grundwort, Form, Rohware), nicht die Suche. Ohne Semantik fände die Lexik den Patty-LA gar nicht.
    $this->vorschlaege = function (array $jeText) {
        $mock = Mockery::mock(\Platform\FoodAlchemist\Services\LaCandidateFinder::class, [
            app(\Platform\FoodAlchemist\Services\Matching\TokenEngine::class),
            app(\Platform\FoodAlchemist\Services\Matching\MatchHeuristics::class),
            app(\Platform\FoodAlchemist\Services\TerminologyService::class),
            app(\Platform\FoodAlchemist\Services\StammLieferantService::class),
            app(\Platform\FoodAlchemist\Services\SupplierItemService::class),
            app(\Platform\FoodAlchemist\Services\LeadLaStrategieResolver::class),
        ])->makePartial();
        $mock->shouldReceive('find')->andReturnUsing(function ($team, string $text) use ($jeText) {
            foreach ($jeText as $muster => $las) {
                if (mb_stripos($text, $muster) !== false) {
                    return collect(array_map(function ($la) { $la->setAttribute('score', 0.8); return $la; }, $las));
                }
            }

            return collect();
        });
        app()->instance(\Platform\FoodAlchemist\Services\LaCandidateFinder::class, $mock);
    };
    $this->mapped = function (string $laName, string $gpName) {
        $gp = $this->makeGp($this->rootTeam, $gpName);
        FoodAlchemistSupplierItemStructure::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => ($this->la)($laName)->id, 'gp_id' => $gp->id]);

        return $gp;
    };
});

it('Rind: frisch, Parüren → nie das Patty-GP, sondern Derivat „Rind: frisch, Parüren" aus Rohware', function () {
    $patty = ($this->mapped)('Rinder-Hamburger-Patties frisch 160 g', 'Rinder-Hamburger-Patties: frisch, 160 g pro Stueck');
    $pattyLa = FoodAlchemistSupplierItem::where('designation', 'like', 'Rinder-Hamburger%')->first();
    ($this->vorschlaege)(['Parüren' => [$pattyLa], 'rind' => [$pattyLa, ($this->la)('Rind frisch')]]);

    $gp = app(LaFirstGpService::class)->mintFromLa($this->rootTeam, 'Rind: frisch, Parüren');

    expect($gp?->id)->not->toBe($patty->id)
        ->and($gp?->name)->toBe('Rind: frisch, Parüren')
        ->and((bool) $gp?->is_derivat)->toBeTrue()
        ->and(FoodAlchemistGp::find($gp->derivat_von_gp_id)->name)->toBe('Rind');
});

it('Rind: frisch, Parüren ohne Rohware → Lücke, nie das Patty-GP', function () {
    ($this->mapped)('Rinder-Hamburger-Patties frisch 160 g', 'Rinder-Hamburger-Patties: frisch, 160 g pro Stueck');
    $pattyLa = FoodAlchemistSupplierItem::where('designation', 'like', 'Rinder-Hamburger%')->first();
    ($this->vorschlaege)(['Parüren' => [$pattyLa], 'rind' => [$pattyLa]]);

    expect(app(LaFirstGpService::class)->mintFromLa($this->rootTeam, 'Rind: frisch, Parüren'))->toBeNull();
});

it('Geflügel: frisch, Karkasse → Derivat aus Rohware, nicht die Grillwurst', function () {
    $wurst = ($this->mapped)('Gefluegel Grillwurst frisch', 'Gefluegel Grillwurst: frisch, angebraten');
    $wurstLa = FoodAlchemistSupplierItem::where('designation', 'Gefluegel Grillwurst frisch')->first();
    ($this->vorschlaege)(['Karkasse' => [$wurstLa], 'gefluegel' => [$wurstLa, ($this->la)('Gefluegel ganz frisch')]]);

    $gp = app(LaFirstGpService::class)->mintFromLa($this->rootTeam, 'Geflügel: frisch, Karkasse');

    expect($gp?->id)->not->toBe($wurst->id)
        ->and($gp?->name)->toBe('Geflügel: frisch, Karkasse');
});

it('Kalbsknochen mit LA, der die Form trägt → dieser LA (Zukaufware), kein Derivat', function () {
    $knochen = ($this->mapped)('Kalbsknochen frisch gesaegt', 'Kalbsknochen: frisch');
    ($this->mapped)('Kalb Hamburger Patties', 'Kalb Hamburger: frisch');
    ($this->vorschlaege)(['Kalbsknochen' => [FoodAlchemistSupplierItem::where('designation', 'Kalb Hamburger Patties')->first(),
        FoodAlchemistSupplierItem::where('designation', 'Kalbsknochen frisch gesaegt')->first()]]);

    $gp = app(LaFirstGpService::class)->mintFromLa($this->rootTeam, 'Kalbsknochen');

    expect($gp?->id)->toBe($knochen->id);
});

it('Toast (Rohzutat im Crunch) trifft das Toastbrot-GP verdrahtbar', function () {
    // Lauf 90, Rezept 3821 „Crunch: Kürbiskern-Toast": „Toast" blieb ohne GP (0,4). Demo-Namen (WG 09.1).
    $toast = $this->makeGp($this->rootTeam, 'Toastbrote / Sandwich-Toasts: trocken');
    $this->makeGp($this->rootTeam, 'Toastbrote: trocken, geschnitten');
    $this->makeGp($this->rootTeam, 'Toastscheiben: frisch');

    $m = app(\Platform\FoodAlchemist\Services\IngredientMatchService::class)->matchIngredient($this->rootTeam, 'Toast');

    expect(str_starts_with((string) $m['gp_name'], 'Toastbrote'))->toBeTrue()
        ->and(\Platform\FoodAlchemist\Services\IngredientMatchService::istAutomatischVerdrahtbar($m))->toBeTrue();
});
