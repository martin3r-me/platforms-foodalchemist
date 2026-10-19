<?php

use Platform\FoodAlchemist\Models\FoodAlchemistItemAllergen;
use Platform\FoodAlchemist\Models\FoodAlchemistItemDeclaration;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Services\ReportExportService;
use Platform\FoodAlchemist\Support\AllergenAnnahme;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 85 (Kurator 19.10.): die Deklaration im GP-Bericht (Kunden-Dokument) nimmt dieselbe Auflösung wie die GP-Anzeige —
 * LA-Kette inkl. Annahme bei Positiv-Lieferanten — statt nur der Override-Spalten (die zeigten für jeden LA-belegten GP „ohne Angabe“).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    AllergenAnnahme::vergessen();
    $this->mitLa = function (string $gpName, bool $nurPositiv, array $werte) {
        $gp = $this->makeGp($this->rootTeam, $gpName);
        $l = FoodAlchemistSupplier::create(['team_id' => null, 'name' => 'L ' . $gpName, 'allergene_nur_positiv' => $nurPositiv]);
        $la = FoodAlchemistSupplierItem::create(['team_id' => $this->rootTeam->id, 'supplier_id' => $l->id, 'designation' => $gpName, 'unit_code' => 'kg']);
        FoodAlchemistSupplierItemStructure::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'gp_id' => $gp->id]);
        FoodAlchemistItemAllergen::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id]
            + collect($werte)->mapWithKeys(fn ($v, $k) => ["allergen_{$k}" => $v])->all());
        FoodAlchemistItemDeclaration::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'with_dye' => 3]);
        AllergenAnnahme::vergessen();

        return $gp;
    };
    $this->dek = fn ($gp) => app(ReportExportService::class)->gpDaten($this->rootTeam, $gp->id, ['deklaration' => true])['report']['gp']['deklaration'];
});

it('GP-Bericht: Allergene aus der LA-Kette (nicht nur Override), Sicherheit live, Zusatzstoffe aus den Artikeln', function () {
    $gp = ($this->mitLa)('Sahne', false, ['milk' => 'enthalten', 'gluten' => 'nicht_enthalten']);

    $d = ($this->dek)($gp);
    $wert = collect($d['allergene'])->pluck('wert', 'key');

    expect($wert['milk'])->toBe('enthalten')->and($wert['gluten'])->toBe('nicht_enthalten')->and($wert['eggs'])->toBe('unbekannt')
        ->and($d['allergens_confidence'])->toBe('high')->and($d['allergene_hinweis'])->toBeNull()
        ->and(collect($d['zusatzstoffe'])->firstWhere('key', 'with_dye')['wert'])->toBe(3);
});

it('GP-Bericht bei Positiv-Lieferant: leere Felder angenommen nicht enthalten, Sicherheit Mittel, Hinweis im Dokument', function () {
    $gp = ($this->mitLa)('Butter', true, ['milk' => 'enthalten']);

    $d = ($this->dek)($gp);
    $html = view('foodalchemist::dokumente.partials.report-declaration', ['deklaration' => $d])->render();

    expect(collect($d['allergene'])->pluck('wert', 'key')['gluten'])->toBe('nicht_enthalten')
        ->and($d['allergens_confidence'])->toBe('medium')
        ->and($d['allergene_hinweis'])->toBe('13 Angaben ' . AllergenAnnahme::HINWEIS)
        ->and($html)->toContain('data-allergene-hinweis')->not->toContain('Ohne Angabe');
});
