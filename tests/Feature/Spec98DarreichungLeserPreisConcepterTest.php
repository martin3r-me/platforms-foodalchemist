<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\Concepter\ConcepterAggregateService;
use Platform\FoodAlchemist\Services\Concepter\ConceptService;
use Platform\FoodAlchemist\Services\Concepter\SlotEinheitService;
use Platform\FoodAlchemist\Services\Preis\KalkulationService;
use Platform\FoodAlchemist\Services\Rezept\DarreichungResolver;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * 98 · 3.5 Schritt 6a, Gruppe Preis/Marge und Concepter: die Leser nehmen das Portionsgewicht von der Darreichung
 * (Slot-Darreichung, sonst Standard), nicht mehr vom Rezept-Spiegel `sales_quantity_per_unit_g`. Der Spiegel steht in
 * diesen Tests absichtlich auf einem falschen Wert (999 g), damit jeder Rest-Leser auffällt.
 *
 * Der Teiler „Ansatz ÷ Portionen“ ist seit Dominiques Entscheidung (27.11., „Ja, fest 1“) fest 1 und
 * läuft über {@see DarreichungResolver::portionenJeAnsatz()}; der Wächter unten verbietet Spiegel-Reads samt Selects.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->gramm = FoodAlchemistVocabEinheit::create(['team_id' => $this->rootTeam->id, 'slug' => 'g', 'display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1]);

    $this->gericht = $this->makeRecipe($this->rootTeam, 'Rinderroulade', ['is_sales_recipe' => true, 'ek_total_eur' => 8.0, 'nutri_kcal_per_100g' => 100, 'nutri_confidence' => 'high']);
    $this->dar = $this->grammaturAltbestand($this->makeStandardDarreichung($this->gericht, 20.0), 200);
    // 98 · 3.5 6c: die Rezept-Spiegel sind gedroppt — vorher wurden sie hier bewusst abweichend (999 g) gesetzt
    $this->konzepte = app(ConceptService::class);
    $this->konzeptMitGericht = function (?object $einheit = null, ?float $menge = null) {
        $k = $this->konzepte->create($this->rootTeam, ['name' => 'Menü']);
        $slot = $this->konzepte->addSlot($this->rootTeam, $k->id, ['role' => 'Hauptgang']);
        $this->konzepte->fillSlot($this->rootTeam, $slot->id, ['recipe_id' => $this->gericht->id]);
        if ($einheit !== null) {
            DB::table('foodalchemist_concept_slots')->where('id', $slot->id)->update(['unit_vocab_id' => $einheit->id, 'quantity' => $menge]);
        }

        return $k->fresh();
    };
});

it('Resolver: Portionsgewicht von der Darreichung, nie vom Rezept-Spiegel', function () {
    $r = app(DarreichungResolver::class);
    expect($r->portionGramm($this->gericht))->toBe(200.0)
        ->and($r->portionGramm($this->gericht, (object) ['quantity_per_unit_g' => 150]))->toBe(150.0)
        ->and($r->portionGramm((object) ['sales_quantity_per_unit_g' => 999]))->toBeNull();
});

it('portionsAequivalent: ohne übergebene Darreichung gilt das Gewicht der Standard-Darreichung', function () {
    expect(ConcepterAggregateService::portionsAequivalent(500.0, $this->gramm, $this->gericht))->toBe(2.5);
});

it('Concepter-Aggregat: Gewicht und Nährwerte je Person aus der Darreichung', function () {
    $agg = app(ConcepterAggregateService::class)->conceptAggregat(($this->konzeptMitGericht)());
    expect($agg['gewicht_pro_person_g'])->toEqual(200)
        ->and($agg['naehrwerte']['kcal'])->toEqual(200);   // 100 kcal/100 g × 200 g
});

it('Concepter-Aggregat: Gramm-Position rechnet EK und Gewicht über die Darreichung', function () {
    $agg = app(ConcepterAggregateService::class)->conceptAggregat(($this->konzeptMitGericht)($this->gramm, 400.0));
    expect($agg['gewicht_pro_person_g'])->toEqual(400)
        ->and($agg['naehrwerte']['kcal'])->toEqual(400);
});

it('Deklarationsblatt: Portionsgramm und kcal je Gericht aus der Standard-Darreichung', function () {
    $blatt = app(ConcepterAggregateService::class)->conceptDeklaration(($this->konzeptMitGericht)());
    expect($blatt['zeilen'][0]['portion_g'])->toBe(200.0)
        ->and($blatt['zeilen'][0]['kcal'])->toEqual(200);
});

it('Slot-Einheit: Gramm wirkt, wenn nur die Darreichung ein Portionsgewicht hat', function () {
    expect(SlotEinheitService::wirkt($this->gramm, $this->gericht->fresh(), null))->toBeTrue();
});

it('Preis-Cockpit: Gramm-Position je Gericht über das Gewicht der Darreichung', function () {
    $c = $this->konzepte->preisCockpit(($this->konzeptMitGericht)($this->gramm, 400.0));
    $zeile = collect($c['zeilen'])->firstWhere('type', 'gericht');
    expect((float) $zeile['price'])->toBe(40.0);   // 400 g ÷ 200 g = 2 Portionen × 20 €
});

it('Teiler fest 1 (Dominique 27.11.): ein Gericht ist eine Portion je Ansatz', function () {
    $g = FoodAlchemistRecipe::find($this->gericht->id);
    $ohneEkPortion = FoodAlchemistRecipe::query()->select(['id', 'ek_total_eur'])->find($this->gericht->id);   // ohne die Spalte: kein Wurf, kein Nachladen
    $this->dar->forceFill(['ek_portion' => null])->saveQuietly();
    expect(app(DarreichungResolver::class)->portionenJeAnsatz($g))->toBe(1)
        ->and(app(KalkulationService::class)->recipeHk($this->rootTeam, $g)['anzahl_portionen'])->toBe(1)
        ->and(app(DarreichungResolver::class)->ekProPortion($ohneEkPortion))->toBe(8.0);   // ganzer Ansatz = eine Portion
});

it('Wächter: Preis/Marge, Concepter, Position und der Resolver lesen die Spiegelfelder nicht mehr (auch nicht im Select)', function () {
    $modul = dirname(__DIR__, 2);
    $dateien = [...glob($modul . '/src/Services/{Preis,Concepter,Position}/*.php', GLOB_BRACE), ...glob($modul . '/src/Livewire/Concepter/*.php'),
        ...glob($modul . '/resources/views/livewire/concepter/*.blade.php'), $modul . '/src/Services/Rezept/DarreichungResolver.php'];
    $treffer = [];
    foreach ($dateien as $datei) {
        foreach (file($datei) as $nr => $zeile) {
            if (preg_match('#^\s*(\*|//|/\*\*)#', $zeile)) {
                continue;   // Doku-Zeilen dürfen die alten Namen nennen
            }
            if (preg_match('/\b(sales_quantity_per_unit_g|sales_unit_count|sales_unit_vocab_id|container_(warm|cold)_vocab_id|serving_vehicle_vocab_id)\b/', $zeile)) {
                $treffer[] = substr($datei, strlen($modul) + 1) . ':' . ($nr + 1);
            }
        }
    }
    expect($treffer)->toBe([]);
});
