<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeContainer as RC;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient;
use Platform\FoodAlchemist\Services\PlanungsblattService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 51 Etappe E — die Naht schliessen: produzierte Menge trifft Behälter-Katalog.
 *
 * Der Kern-Entscheid steckt in der Aufteilung: ABFÜLLEN gehört an jede Zeile (was produziert
 * wird, muss irgendwo hinein), REGENERIEREN nur dorthin, wo auch serviert wird — und dort je
 * Komponente, weil eine Sauce in drei Gerichten an drei Pässen je anteilig gewärmt wird.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->blatt = app(PlanungsblattService::class);

    $this->gn65 = DB::table('foodalchemist_vocab_containers')->insertGetId([
        'uuid' => (string) Str::uuid7(), 'team_id' => $this->rootTeam->id,
        'slug' => 'gn_11_65mm', 'name' => 'GN 1/1 65mm', 'sort_order' => 1, 'familie' => 'GN',
        'format_code' => '1/1', 'laenge_mm' => 530, 'breite_mm' => 325, 'tiefe_mm' => 65,
        'volumen_l' => 8.8, 'nutzfaktor' => 0.85, 'max_fuellgewicht_kg' => 15,
        'eignung' => json_encode(['abfuellen', 'regenerieren', 'ausgabe']),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->eimer = DB::table('foodalchemist_vocab_containers')->insertGetId([
        'uuid' => (string) Str::uuid7(), 'team_id' => $this->rootTeam->id,
        'slug' => 'eimer_10_l', 'name' => 'Eimer 10 l', 'sort_order' => 2, 'familie' => 'Eimer',
        'volumen_l' => 10, 'nutzfaktor' => 0.9, 'max_fuellgewicht_kg' => 10,
        'eignung' => json_encode(['abfuellen', 'transport']),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->behaelterAn = fn ($recipe, string $zweck, int $containerId, ?float $ref) => RC::create([
        'team_id' => $recipe->team_id, 'recipe_id' => $recipe->id, 'zweck' => $zweck,
        'container_vocab_id' => $containerId, 'referenz_menge_kg' => $ref,
        'skalierung' => RC::SKALIERUNG_FLAECHE,
    ]);

    $this->alsKomponente = fn ($ziel, $sub, string $mengeG) => FoodAlchemistRecipeIngredient::create([
        'team_id' => $ziel->team_id, 'recipe_id' => $ziel->id, 'referenced_recipe_id' => $sub->id,
        'raw_text' => $sub->name, 'quantity' => $mengeG,
        'unit_vocab_id' => $this->unitG($this->rootTeam)->id, 'position' => 1,
    ]);
});

it('rechnet den Abfüll-Bedarf aus der produzierten Menge — an jeder Zeile', function () {
    $sauce = $this->makeRecipe($this->rootTeam, 'Sauce: Pfeffer', ['yield_kg' => 10]);
    $this->makeIngredient($sauce, 'Sahne', $this->makeGp($this->rootTeam, 'Sahne'), '10000');
    $sauce->forceFill(['yield_kg' => 10])->save();

    ($this->behaelterAn)($sauce, RC::ZWECK_ABFUELLEN, $this->eimer, 9.0);

    $blatt = $this->blatt->produktionsblattFuerZiele($this->rootTeam, [
        ['recipe_id' => $sauce->id, 'amount_kg' => 40],
    ]);

    $zeile = collect($blatt['rezepte'])->firstWhere('recipe_id', $sauce->id);
    $ab = $zeile['behaelter']['abfuellen'];

    // 4 Ansätze à 10 kg = 40 kg, in Eimer à 9 kg ⇒ fünf. Vier wären 36 kg.
    expect($zeile['produzierte_menge_kg'])->toBe(40.0)
        ->and($ab['berechenbar'])->toBeTrue()
        ->and($ab['varianten'][0]['behaelter'])->toBe('Eimer 10 l')
        ->and($ab['varianten'][0]['anzahl'])->toBe(5)
        ->and($ab['varianten'][0]['konfidenz'])->toBe('hoch');
});

it('ein Fond bekommt KEINEN Regenerations-Bedarf, auch wenn er Auftrags-Top ist', function () {
    $fond = $this->makeRecipe($this->rootTeam, 'Fond: Braun', ['yield_kg' => 6]);
    $this->makeIngredient($fond, 'Knochen', $this->makeGp($this->rootTeam, 'Knochen'), '6000');
    $fond->forceFill(['yield_kg' => 6])->save();

    ($this->behaelterAn)($fond, RC::ZWECK_ABFUELLEN, $this->eimer, 9.0);
    ($this->behaelterAn)($fond, RC::ZWECK_REGENERIEREN, $this->gn65, 8.0);

    $blatt = $this->blatt->produktionsblattFuerZiele($this->rootTeam, [
        ['recipe_id' => $fond->id, 'amount_kg' => 6],
    ]);

    $zeile = collect($blatt['rezepte'])->firstWhere('recipe_id', $fond->id);

    // tiefe == 0, aber kein Gericht: er wird produziert und gelagert, nicht am Pass gewärmt.
    expect($zeile['behaelter']['abfuellen'])->not->toBeNull()
        ->and($zeile['behaelter']['je_komponente'])->toBe([]);
});

it('die Komponenten eines Gerichts bringen ihren ANTEIL mit, nicht die Gesamtmenge', function () {
    $sauce = $this->makeRecipe($this->rootTeam, 'Sauce: Pfeffer', ['yield_kg' => 10]);
    $this->makeIngredient($sauce, 'Sahne', $this->makeGp($this->rootTeam, 'Sahne'), '10000');
    $sauce->forceFill(['yield_kg' => 10])->save();
    ($this->behaelterAn)($sauce, RC::ZWECK_REGENERIEREN, $this->gn65, 8.0);

    $teller = $this->makeRecipe($this->rootTeam, 'Teller: Steak', [
        'is_sales_recipe' => true, 'sales_unit_count' => 1, 'yield_kg' => 0.3,
    ]);
    ($this->alsKomponente)($teller, $sauce, '80');           // 80 g Sauce je Teller
    $teller->forceFill(['yield_kg' => 0.3])->save();

    $blatt = $this->blatt->produktionsblattFuerZiele($this->rootTeam, [
        ['recipe_id' => $teller->id, 'portions' => 100],
    ]);

    $tellerZeile = collect($blatt['rezepte'])->firstWhere('recipe_id', $teller->id);
    $regen = collect($tellerZeile['behaelter']['je_komponente'])->firstWhere('zweck', 'regenerieren');

    // 100 Portionen × 80 g = 8 kg Sauce am Pass — nicht die 10 kg, die produziert werden.
    expect($regen)->not->toBeNull()
        ->and($regen['menge_kg'])->toBe(8.0)
        ->and($regen['varianten'][0]['anzahl'])->toBe(1);

    // Die Sauce-Zeile selbst traegt nur das Abfuellen — ihre Menge ist die produzierte.
    $sauceZeile = collect($blatt['rezepte'])->firstWhere('recipe_id', $sauce->id);
    expect($sauceZeile['behaelter']['je_komponente'] ?? [])->toBe([]);
});

it('dieselbe Sauce in drei Gerichten: ein Abfüll-Bedarf, drei Regenerier-Anteile', function () {
    $sauce = $this->makeRecipe($this->rootTeam, 'Sauce: Pfeffer', ['yield_kg' => 10]);
    $this->makeIngredient($sauce, 'Sahne', $this->makeGp($this->rootTeam, 'Sahne'), '10000');
    $sauce->forceFill(['yield_kg' => 10])->save();
    ($this->behaelterAn)($sauce, RC::ZWECK_ABFUELLEN, $this->eimer, 9.0);
    ($this->behaelterAn)($sauce, RC::ZWECK_REGENERIEREN, $this->gn65, 8.0);

    $ziele = [];
    foreach (['Steak', 'Schnitzel', 'Braten'] as $i => $name) {
        $teller = $this->makeRecipe($this->rootTeam, "Teller: {$name}", [
            'is_sales_recipe' => true, 'sales_unit_count' => 1, 'yield_kg' => 0.3,
        ]);
        ($this->alsKomponente)($teller, $sauce, '50');
        $teller->forceFill(['yield_kg' => 0.3])->save();
        $ziele[] = ['recipe_id' => $teller->id, 'portions' => 40];
    }

    $blatt = $this->blatt->produktionsblattFuerZiele($this->rootTeam, $ziele);

    $sauceZeilen = collect($blatt['rezepte'])->where('recipe_id', $sauce->id);
    $regenZeilen = collect($blatt['rezepte'])
        ->flatMap(fn ($r) => collect($r['behaelter']['je_komponente'] ?? [])->where('zweck', 'regenerieren'));

    // Die Explosion fasst die Sauce zu EINER Zeile zusammen (UNIQUE production_order_id+recipe_id) —
    // also genau ein Abfüll-Bedarf. Gewärmt wird sie aber an drei Pässen.
    expect($sauceZeilen)->toHaveCount(1)
        ->and($regenZeilen)->toHaveCount(3)
        ->and($regenZeilen->pluck('menge_kg')->all())->toBe([2.0, 2.0, 2.0]);
});

it('ohne Ausbeute nennt der Bedarf einen Grund statt einer Zahl', function () {
    $sauce = $this->makeRecipe($this->rootTeam, 'Sauce: Ohne Yield', ['yield_kg' => null]);
    ($this->behaelterAn)($sauce, RC::ZWECK_ABFUELLEN, $this->eimer, 9.0);

    $blatt = $this->blatt->produktionsblattFuerZiele($this->rootTeam, [
        ['recipe_id' => $sauce->id, 'amount_kg' => 40],
    ]);

    $zeile = collect($blatt['rezepte'])->firstWhere('recipe_id', $sauce->id);

    expect($zeile['behaelter']['abfuellen']['berechenbar'])->toBeFalse()
        ->and($zeile['behaelter']['abfuellen']['grund'])->toContain('yield_kg');
});

it('durchgängig gilt JE REZEPT — eigenes Abfüllen == eigenes Regenerieren', function () {
    $ragout = $this->makeRecipe($this->rootTeam, 'Ragout: Rind', ['yield_kg' => 10]);
    $this->makeIngredient($ragout, 'Rind', $this->makeGp($this->rootTeam, 'Rind'), '10000');
    $ragout->forceFill(['yield_kg' => 10])->save();

    // Ragout im GN mit Deckel: aus dem Kühlhaus direkt in den Ofen — beide Zeilen, ein Behälter.
    ($this->behaelterAn)($ragout, RC::ZWECK_ABFUELLEN, $this->gn65, 8.0);
    ($this->behaelterAn)($ragout, RC::ZWECK_REGENERIEREN, $this->gn65, 8.0);

    $blatt = $this->blatt->produktionsblattFuerZiele($this->rootTeam, [
        ['recipe_id' => $ragout->id, 'amount_kg' => 10],
    ]);

    $zeile = collect($blatt['rezepte'])->firstWhere('recipe_id', $ragout->id);

    expect($zeile['behaelter']['zusammen']['durchgaengig'])->toBeTrue()
        ->and($zeile['behaelter']['zusammen']['behaelter'])->toBe('GN 1/1 65mm')
        ->and($zeile['behaelter']['zusammen']['hinweis'])->toContain('kein Umfüllen');
});

it('verschiedene Behälter am selben Rezept benennen den Umfüll-Schritt', function () {
    $sauce = $this->makeRecipe($this->rootTeam, 'Sauce: Pfeffer', ['yield_kg' => 10]);
    $this->makeIngredient($sauce, 'Sahne', $this->makeGp($this->rootTeam, 'Sahne'), '10000');
    $sauce->forceFill(['yield_kg' => 10])->save();

    ($this->behaelterAn)($sauce, RC::ZWECK_ABFUELLEN, $this->eimer, 9.0);
    ($this->behaelterAn)($sauce, RC::ZWECK_REGENERIEREN, $this->gn65, 8.0);

    $blatt = $this->blatt->produktionsblattFuerZiele($this->rootTeam, [
        ['recipe_id' => $sauce->id, 'amount_kg' => 10],
    ]);

    $zeile = collect($blatt['rezepte'])->firstWhere('recipe_id', $sauce->id);

    expect($zeile['behaelter']['zusammen']['durchgaengig'])->toBeFalse()
        ->and($zeile['behaelter']['zusammen']['hinweis'])->toContain('Umfüllen am Einsatztag');
});

it('eine durchgängige Komponente wird am Gericht NICHT noch einmal gezählt', function () {
    // BEFUND: die erste Fassung verglich das Abfüllen des GERICHTS mit dem Regenerieren der
    // ERSTEN Komponente — zwei verschiedene Rezepte, zwei verschiedene Behälter-Reisen. Bei
    // einem Gericht mit mehreren Komponenten war das Ergebnis willkürlich, und die durchgängigen
    // Behälter landeten doppelt im Rollup.
    $ragout = $this->makeRecipe($this->rootTeam, 'Ragout: Rind', ['yield_kg' => 10]);
    $this->makeIngredient($ragout, 'Rind', $this->makeGp($this->rootTeam, 'Rind'), '10000');
    $ragout->forceFill(['yield_kg' => 10])->save();
    ($this->behaelterAn)($ragout, RC::ZWECK_ABFUELLEN, $this->gn65, 8.0);
    ($this->behaelterAn)($ragout, RC::ZWECK_REGENERIEREN, $this->gn65, 8.0);

    $teller = $this->makeRecipe($this->rootTeam, 'Teller: Ragout', [
        'is_sales_recipe' => true, 'sales_unit_count' => 1, 'yield_kg' => 0.3,
    ]);
    ($this->alsKomponente)($teller, $ragout, '200');
    $teller->forceFill(['yield_kg' => 0.3])->save();

    $blatt = $this->blatt->produktionsblattFuerZiele($this->rootTeam, [
        ['recipe_id' => $teller->id, 'portions' => 40],
    ]);

    $regen = collect(collect($blatt['rezepte'])->firstWhere('recipe_id', $teller->id)['behaelter']['je_komponente'])
        ->firstWhere('zweck', 'regenerieren');

    // Die GNs reisen mit — an der Ragout-Zeile sind sie fuers Abfuellen schon gezaehlt.
    expect($regen['bereits_gezaehlt'])->toBeTrue();

    $ragoutZeile = collect($blatt['rezepte'])->firstWhere('recipe_id', $ragout->id);
    expect($ragoutZeile['behaelter']['abfuellen']['berechenbar'])->toBeTrue();
});

it('eine NICHT durchgängige Komponente zählt am Gericht sehr wohl', function () {
    $sauce = $this->makeRecipe($this->rootTeam, 'Sauce: Pfeffer', ['yield_kg' => 10]);
    $this->makeIngredient($sauce, 'Sahne', $this->makeGp($this->rootTeam, 'Sahne'), '10000');
    $sauce->forceFill(['yield_kg' => 10])->save();
    ($this->behaelterAn)($sauce, RC::ZWECK_ABFUELLEN, $this->eimer, 9.0);
    ($this->behaelterAn)($sauce, RC::ZWECK_REGENERIEREN, $this->gn65, 8.0);

    $teller = $this->makeRecipe($this->rootTeam, 'Teller: Steak', [
        'is_sales_recipe' => true, 'sales_unit_count' => 1, 'yield_kg' => 0.3,
    ]);
    ($this->alsKomponente)($teller, $sauce, '200');
    $teller->forceFill(['yield_kg' => 0.3])->save();

    $blatt = $this->blatt->produktionsblattFuerZiele($this->rootTeam, [
        ['recipe_id' => $teller->id, 'portions' => 40],
    ]);

    $regen = collect(collect($blatt['rezepte'])->firstWhere('recipe_id', $teller->id)['behaelter']['je_komponente'])
        ->firstWhere('zweck', 'regenerieren');

    // Aus dem Eimer ins GN: die GNs kommen zusaetzlich, sie muessen gezaehlt werden.
    expect($regen['bereits_gezaehlt'])->toBeFalse()
        ->and($regen['varianten'][0]['behaelter'])->toBe('GN 1/1 65mm');
});

it('am Konzept skaliert der Bedarf mit der Personenzahl — aus dem Produktionspfad', function () {
    // BEFUND: im Concept-Scope treibt `pax` NUR die Kosten-Simulation; `faktor` wird dort nie
    // gesetzt. Der Bericht haette die Ansatzgroesse gezeigt — bei 50 wie bei 500 Personen
    // dieselbe Zahl. Der Bedarf kommt deshalb aus dem echten Produktionsblatt.
    $sauce = $this->makeRecipe($this->rootTeam, 'Sauce: Pfeffer', ['yield_kg' => 10]);
    $this->makeIngredient($sauce, 'Sahne', $this->makeGp($this->rootTeam, 'Sahne'), '10000');
    $sauce->forceFill(['yield_kg' => 10])->save();
    ($this->behaelterAn)($sauce, RC::ZWECK_ABFUELLEN, $this->eimer, 9.0);

    $teller = $this->makeRecipe($this->rootTeam, 'Teller: Steak', [
        'is_sales_recipe' => true, 'sales_unit_count' => 1, 'yield_kg' => 0.3,
    ]);
    ($this->alsKomponente)($teller, $sauce, '100');
    // Portionsgewicht: ohne das ergibt eine Gramm-Menge am Slot kein Portions-Aequivalent.
    $teller->forceFill(['yield_kg' => 0.3, 'sales_quantity_per_unit_g' => 300])->save();

    $concept = \Platform\FoodAlchemist\Models\FoodAlchemistConcept::create([
        'team_id' => $this->rootTeam->id, 'name' => 'Buffet Herbst', 'status' => 'draft',
    ]);
    \Platform\FoodAlchemist\Models\FoodAlchemistConceptSlot::create([
        'team_id' => $this->rootTeam->id, 'concept_id' => $concept->id, 'position' => 1,
        'sales_recipe_id' => $teller->id, 'quantity' => 300,
        'unit_vocab_id' => $this->unitG($this->rootTeam)->id,
    ]);

    $svc = app(\Platform\FoodAlchemist\Services\ReportExportService::class);
    $anzahl = function (int $pax) use ($svc, $concept, $sauce) {
        $daten = $svc->conceptDaten($this->rootTeam, (int) $concept->id, [
            'behaelter' => true, 'kaskade' => true, 'pax' => $pax, 'simulation' => false,
        ]);

        // Der Bedarf der Sauce haengt an IHREM Knoten — im Concept steht sie als Komponente
        // unter dem Gericht.
        $suche = function (array $node) use (&$suche, $sauce) {
            if ((int) ($node['id'] ?? 0) === (int) $sauce->id) {
                return $node['behaelter'] ?? null;
            }
            foreach ($node['ingredients'] ?? [] as $kind) {
                $treffer = is_array($kind['subrecipe'] ?? null) ? $suche($kind['subrecipe']) : null;
                if ($treffer !== null) {
                    return $treffer;
                }
            }

            return null;
        };

        foreach ($daten['concept']['slots'] ?? [] as $slot) {
            foreach ($slot['gerichte'] ?? [] as $g) {
                $treffer = $suche($g['recipe'] ?? []);
                if ($treffer !== null) {
                    return $treffer;
                }
            }
        }

        return null;
    };

    $klein = $anzahl(50);
    $gross = $anzahl(500);

    //  50 Pax × 100 g =  5 kg Bedarf → aber ein Basisrezept wird in GANZEN Ansaetzen gekocht:
    //                     1 Ansatz = 10 kg produziert → 2 Eimer à 9 kg.
    // 500 Pax × 100 g = 50 kg Bedarf → 5 Ansaetze = 50 kg → 6 Eimer.
    // Vorher stand hier zweimal dieselbe Zahl, weil am Concept ueberhaupt nichts skalierte.
    expect($klein)->not->toBeNull()
        ->and($gross)->not->toBeNull()
        ->and($klein[0]['kurz'])->not->toBe($gross[0]['kurz'])
        ->and($klein[0]['kurz'])->toContain('2× Eimer 10 l')
        ->and($gross[0]['kurz'])->toContain('6× Eimer 10 l');
});
