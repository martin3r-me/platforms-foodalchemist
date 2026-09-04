<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Recipes\IngredientEditor;
use Platform\FoodAlchemist\Models\FoodAlchemistGpForm;
use Platform\FoodAlchemist\Models\FoodAlchemistPrice;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\RecipeRecomputeService;
use Platform\FoodAlchemist\Services\RecipeService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * GL-11-Stk→g-Brücke im ANZEIGE-Pfad.
 *
 * Befund (Dominique, 2026-09-04, TK-Baguette 1,12 €/Stk à 440 g): die Brücke stand nur
 * privat in `RecipeRecomputeService::zutatKosten`. Der Save rechnete darum richtig
 * (Kachel »EK GESAMT 28,06 €«, »MIT PREIS 2/2«), während die Zutaten-Tabelle desselben
 * Gerichts »—«, »Σ 0,06 €« und »nur 1 von 2 Zutaten bepreist« zeigte — zwei Zahlen für
 * ein Rezept auf einem Bildschirm. Ursache war NICHT die Einheit: `zeilenEk()` bricht
 * bei `ek_pro_g === null` ab, bevor die Einheit gelesen wird, also fiel g, kg und stk
 * gleichermaßen aus.
 *
 * Diese Tests behaupten deshalb keine Beispielzahlen, sondern die INVARIANTE, die der
 * Bug gebrochen hat: was der Editor live anzeigt, muss das sein, was der Save bucht —
 * in beide Richtungen, auch für »unbepreist«. Sie spiegeln die Client-Formel aus
 * ingredient-editor.blade.php (`mengeAvg × gFaktor × ek_pro_g`) gegen die Server-
 * Aggregation. Bewegt sich eine der beiden Seiten allein, kippt der Test.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    // render() liest currentTeamRelation; der Nachzieh-Befehl zusätzlich die Mitglieder-
    // Tabelle (D1-Gate) — makeUser setzt nur current_team_id, die Mitgliedschaft muss dazu.
    $nutzer = $this->makeUser($this->rootTeam, 'Root User');
    $this->rootTeam->users()->attach($nutzer->id, ['role' => 'owner']);
    $this->actingAs($nutzer);
    $this->svc = app(RecipeService::class);
    $this->g = FoodAlchemistVocabEinheit::firstOrCreate(
        ['team_id' => $this->rootTeam->id, 'slug' => 'g'],
        ['display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1],
    );
    // Zähl-Einheit OHNE generisches Gramm-Gewicht — das Gewicht hängt am Produkt.
    $this->stk = FoodAlchemistVocabEinheit::firstOrCreate(
        ['team_id' => $this->rootTeam->id, 'slug' => 'stk'],
        ['display_de' => 'Stück', 'dimension' => 'count'],
    );
    $this->scheibe = FoodAlchemistVocabEinheit::firstOrCreate(
        ['team_id' => $this->rootTeam->id, 'slug' => 'scheibe'],
        ['display_de' => 'Scheibe', 'dimension' => 'count'],
    );
    // Wie im echten Vokabular: „bund" trägt ein GLOBALES Gewicht — genau die Stufe,
    // die vor A das produktspezifische verdeckt hat.
    $this->bund = FoodAlchemistVocabEinheit::firstOrCreate(
        ['team_id' => $this->rootTeam->id, 'slug' => 'bund'],
        ['display_de' => 'Bund', 'dimension' => 'count', 'default_in_g' => 30],
    );
    $supplier = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Chefs Culinar']);
    $this->supplier = $supplier;

    /**
     * GP mit frei wählbaren Preis- und Gewichtsquellen — damit der Wächter unten JEDE
     * Kombination stellen kann, ohne pro Fall eine eigene Fixture zu schreiben.
     * $las = Liste [preis, unit_code, qty]; $formen = ['scheibe' => 30.0, …].
     */
    $this->mkGpFlex = function (string $name, array $las, ?float $stueckG = null, array $formen = []) use ($supplier) {
        $gp = $this->makeGp($this->rootTeam, $name);
        $lead = null;
        foreach ($las as [$preis, $unit, $qty]) {
            $la = FoodAlchemistSupplierItem::create([
                'team_id' => $this->rootTeam->id, 'supplier_id' => $supplier->id,
                'designation' => $name.' '.$unit, 'qty' => $qty, 'unit_code' => $unit,
            ]);
            FoodAlchemistSupplierItemStructure::create([
                'team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'gp_id' => $gp->id,
            ]);
            FoodAlchemistPrice::create([
                'team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id,
                'price' => $preis, 'status' => '0',
            ]);
            $lead ??= $la->id;
        }
        foreach ($formen as $slug => $gramm) {
            FoodAlchemistGpForm::create([
                'gp_id' => $gp->id, 'form_slug' => $slug, 'gramm' => $gramm, 'source' => 'manual',
            ]);
        }
        $gp->update(['lead_la_supplier_item_id' => $lead, 'piece_default_g' => $stueckG]);

        return $gp->refresh();
    };

    /** GP, dessen EINZIGER Artikel per Stück bepreist ist (Gebinde 20 Stk) — der reale Fall. */
    $this->mkStkGp = function (string $name, float $gebindePreis, float $stkJeGebinde, ?float $stueckG) use ($supplier) {
        $gp = $this->makeGp($this->rootTeam, $name);
        $la = FoodAlchemistSupplierItem::create([
            'team_id' => $this->rootTeam->id, 'supplier_id' => $supplier->id,
            'designation' => $name, 'qty' => $stkJeGebinde, 'unit_code' => 'Stk',
        ]);
        FoodAlchemistSupplierItemStructure::create([
            'team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'gp_id' => $gp->id,
        ]);
        FoodAlchemistPrice::create([
            'team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id,
            'price' => $gebindePreis, 'status' => '0',
        ]);
        $gp->update(['lead_la_supplier_item_id' => $la->id, 'piece_default_g' => $stueckG]);

        return $gp->refresh();
    };

    /** Die Zeilen, die der Editor an den Client gibt. */
    $this->zeilen = fn (int $recipeId) => collect(
        Livewire::test(IngredientEditor::class, ['recipeId' => $recipeId])->viewData('zeilenJson')
    );

    /**
     * Client-Formel 1:1 aus ingredient-editor.blade.php:
     *   gFaktor(z) = einheiten[unit].default_in_g ?? z.g_pro_stueck
     *   zeilenEk(z) = mengeAvg × gFaktor × ek_pro_g   (null ⇒ Zeile trägt nichts)
     * Rückgabe: [Σ €, Anzahl bepreister Zeilen].
     */
    // gFaktor(z) — EINE Stelle, von Summe UND Faktor-Assertion benutzt (sonst prüft der
    // Test zwei verschiedene Formeln und die Deckungsgleichheit ist Zufall).
    // Server-Pendant ohne Umrechnung ist 0.0, nicht null → für Vergleiche 0.0 zurückgeben.
    $this->clientFaktor = function ($z) {
        $e = FoodAlchemistVocabEinheit::find($z['unit_vocab_id']);
        if ($e?->dimension === 'count' && ($z['g_pro_stueck'] ?? null) !== null) {
            return (float) $z['g_pro_stueck'];                     // 1 Sub-Stück-Ertrag
        }
        if (($z['formen_g'][$z['unit_vocab_id']] ?? null) !== null) {
            return (float) $z['formen_g'][$z['unit_vocab_id']];    // 3 produktspezifisch
        }
        if ($e?->default_in_g !== null) {
            return (float) $e->default_in_g;                       // 4a global
        }
        if ($e?->slug === 'stk' && ($z['stueck_gewicht'] ?? null) !== null) {
            return (float) $z['stueck_gewicht'];                   // 4b Stückgewicht
        }

        return 0.0;
    };

    $this->clientSumme = function ($zeilen) {
        $summe = 0.0;
        $bepreist = 0;
        foreach ($zeilen as $z) {
            if ($z['is_optional']) {
                continue;
            }
            // Stück-Zeile: €/Stück direkt, ohne Gewicht (Spiegel des count-Zweigs in zutatKosten)
            $slug = FoodAlchemistVocabEinheit::find($z['unit_vocab_id'])?->slug;
            if ($slug === 'stk' && ($z['ek_pro_stk'] ?? null) !== null) {
                $summe += (float) $z['quantity'] * (float) $z['ek_pro_stk'];
                $bepreist++;

                continue;
            }
            $faktor = ($this->clientFaktor)($z);
            if ($z['ek_pro_g'] === null || $faktor == 0.0) {
                continue;
            }
            $summe += (float) $z['quantity'] * $faktor * (float) $z['ek_pro_g'];
            $bepreist++;
        }

        return [$summe, $bepreist];
    };
});

it('zeigt für einen stk-bepreisten GP denselben EK an, den der Save bucht — in g UND in stk', function () {
    // 22,40 € je 20 Stk = 1,12 €/Stk; 1 Stück = 440 g ⇒ 0,002545… €/g
    $gp = ($this->mkStkGp)('Baguettes: TK, 440 g pro Stueck', 22.40, 20.0, 440.0);

    foreach ([['g', $this->g->id, '25'], ['stk', $this->stk->id, '25']] as [$label, $unitId, $menge]) {
        $rezept = $this->svc->create($this->rootTeam, ['name' => 'Brotkorb '.$label]);
        $gerechnet = $this->svc->syncIngredients($this->rootTeam, $rezept->id, [[
            'id' => null, 'gp_id' => $gp->id, 'raw_text' => $menge.' '.$label,
            'quantity' => $menge, 'unit_vocab_id' => $unitId,
        ]]);

        $zeilen = ($this->zeilen)($rezept->id);
        [$clientSumme, $clientBepreist] = ($this->clientSumme)($zeilen);

        expect($zeilen->first()['ek_pro_g'])->not->toBeNull("[{$label}] Zeilen-EK ist blind — die Anzeige zeigt „—\"")
            ->and($zeilen->first()['stueck_gewicht'])->toEqual(440.0, "[{$label}] Stückgewicht fehlt dem Client")
            ->and($gerechnet->ek_n_ingredients_priced)->toBe(1)
            // DIE Invariante: Anzeige == Buchung.
            ->and(round($clientSumme, 2))->toBe((float) $gerechnet->ek_total_eur, "[{$label}] Anzeige und Save weichen ab")
            ->and($clientBepreist)->toBe($gerechnet->ek_n_ingredients_priced, "[{$label}] „bepreist\"-Zähler weicht ab");
    }
});

it('bleibt ohne hinterlegtes Stückgewicht auf BEIDEN Seiten unbepreist — die Brücke rät nicht', function () {
    $ohneGewicht = ($this->mkStkGp)('Baguettes: TK, ohne Stueckgewicht', 22.40, 20.0, null);
    $rezept = $this->svc->create($this->rootTeam, ['name' => 'Brotkorb ungewogen']);
    $gerechnet = $this->svc->syncIngredients($this->rootTeam, $rezept->id, [[
        'id' => null, 'gp_id' => $ohneGewicht->id, 'raw_text' => '25 g',
        'quantity' => '25', 'unit_vocab_id' => $this->g->id,
    ]]);

    $zeilen = ($this->zeilen)($rezept->id);
    [$clientSumme, $clientBepreist] = ($this->clientSumme)($zeilen);

    expect($zeilen->first()['ek_pro_g'])->toBeNull()
        ->and($clientSumme)->toBe(0.0)
        ->and($clientBepreist)->toBe(0)
        ->and($gerechnet->ek_n_ingredients_priced)->toBe(0)
        ->and($gerechnet->ek_total_eur)->toBeNull();
});

it('lässt kg/l vor der Stk-Brücke gewinnen — die Präzedenz der Kaskade bleibt', function () {
    $gp = ($this->mkStkGp)('Ciabatta: TK, 300 g pro Stueck', 30.00, 10.0, 300.0);   // 3,00 €/Stk = 0,01 €/g
    $supplier = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Handelshof']);
    $kgLa = FoodAlchemistSupplierItem::create([
        'team_id' => $this->rootTeam->id, 'supplier_id' => $supplier->id,
        'designation' => 'Ciabatta 5 kg', 'qty' => 5.0, 'unit_code' => 'kg',
    ]);
    FoodAlchemistSupplierItemStructure::create([
        'team_id' => $this->rootTeam->id, 'supplier_item_id' => $kgLa->id, 'gp_id' => $gp->id,
    ]);
    FoodAlchemistPrice::create([
        'team_id' => $this->rootTeam->id, 'supplier_item_id' => $kgLa->id, 'price' => 20.00, 'status' => '0',
    ]);
    $gp->update(['lead_la_supplier_item_id' => $kgLa->id]);                          // 4,00 €/kg = 0,004 €/g

    $rezept = $this->svc->create($this->rootTeam, ['name' => 'Ciabatta-Test']);
    $this->svc->syncIngredients($this->rootTeam, $rezept->id, [[
        'id' => null, 'gp_id' => $gp->id, 'raw_text' => '1000 g',
        'quantity' => '1000', 'unit_vocab_id' => $this->g->id,
    ]]);

    // Der kg-Lead (0,004 €/g), NICHT die Stk-Brücke (0,01 €/g).
    expect((float) ($this->zeilen)($rezept->id)->first()['ek_pro_g'])->toBe(0.004);
});

/**
 * Der Wächter (Dominique, 2026-09-04): NICHT die drei Bugs von heute nachstellen, sondern die
 * Klasse zunageln, aus der sie stammen. Alle drei entstanden dadurch, dass der Anzeige-Pfad die
 * Kaskaden-ENTSCHEIDUNG neu getroffen hat (welche Preisquelle? welches Gewicht?) und anders
 * abbog als `zutatKosten`/`grammFaktor`. Multipliziert hat der Client immer richtig.
 *
 * Darum stellt diese Tabelle jede Kombination aus Einheiten-Art × Preisquelle × Gewichtsquelle
 * und behauptet je Fall dieselben drei Gleichungen — inklusive der Fälle, in denen die richtige
 * Antwort „unbepreist" bzw. „kein Gewicht" ist (sonst könnte man den Test mit Raten grün machen):
 *   1. Gramm-Faktor:  Client-gFaktor × Menge == Server-bruttoMasseG
 *   2. Geld:          Σ Client-Zeilen-EK   == Server-ek_total_eur
 *   3. Vollständigkeit: Client-„bepreist"  == Server-ek_n_ingredients_priced
 * Biegt eine Seite allein ab, kippt der Fall — egal an welcher Stelle der Kaskade.
 */
it('hält Anzeige und Buchung über alle Einheiten-Arten und Preisquellen deckungsgleich', function () {
    $recompute = app(RecipeRecomputeService::class);
    // 10 €/kg = 0,01 €/g · 2,24 € je 2 Stk = 1,12 €/Stk · Stückgewicht 440 g · Scheibe 30 g
    $faelle = [
        'g · kg-LA' => ['einheit' => 'g', 'menge' => '250',
            'gp' => [[10.0, 'kg', 1.0]], 'stueck' => null, 'formen' => []],
        'g · nur Stk-LA (Brücke)' => ['einheit' => 'g', 'menge' => '250',
            'gp' => [[2.24, 'Stk', 2.0]], 'stueck' => 440.0, 'formen' => []],
        'stk · Stk-LA' => ['einheit' => 'stk', 'menge' => '3',
            'gp' => [[2.24, 'Stk', 2.0]], 'stueck' => 440.0, 'formen' => []],
        'stk · kg-LA' => ['einheit' => 'stk', 'menge' => '3',
            'gp' => [[10.0, 'kg', 1.0]], 'stueck' => 440.0, 'formen' => []],
        'scheibe · kg-LA + Form' => ['einheit' => 'scheibe', 'menge' => '8',
            'gp' => [[10.0, 'kg', 1.0]], 'stueck' => 440.0, 'formen' => ['scheibe' => 30.0]],
        'scheibe · Stk-LA + Form' => ['einheit' => 'scheibe', 'menge' => '8',
            'gp' => [[2.24, 'Stk', 2.0]], 'stueck' => 440.0, 'formen' => ['scheibe' => 30.0]],
        // A: OHNE hinterlegte Form ist die Naturalgrösse UNBEKANNT (0 g) — nicht „ein ganzes
        // Stück". Vorher wog diese Zeile 8 × 440 g; das war die Erfindung, die A entfernt.
        'scheibe OHNE Form → unbekannt' => ['einheit' => 'scheibe', 'menge' => '8',
            'gp' => [[10.0, 'kg', 1.0]], 'stueck' => 440.0, 'formen' => []],
        // A: produktspezifisch schlägt das globale Einheiten-Gewicht (bund = 30 g im Vokabular)
        'bund · Formgewicht schlägt global' => ['einheit' => 'bund', 'menge' => '0.5',
            'gp' => [[10.0, 'kg', 1.0]], 'stueck' => 440.0, 'formen' => ['bund' => 60.0]],
        // A: ohne Formgewicht bleibt das globale als generische Naturalgrösse
        'bund · ohne Form → global 30 g' => ['einheit' => 'bund', 'menge' => '2',
            'gp' => [[10.0, 'kg', 1.0]], 'stueck' => 440.0, 'formen' => []],
        'g · gar keine LA' => ['einheit' => 'g', 'menge' => '250',
            'gp' => [], 'stueck' => null, 'formen' => []],
        'stk · Stk-LA ohne Stückgewicht' => ['einheit' => 'stk', 'menge' => '3',
            'gp' => [[2.24, 'Stk', 2.0]], 'stueck' => null, 'formen' => []],
    ];
    $unitIds = ['g' => $this->g->id, 'stk' => $this->stk->id,
        'scheibe' => $this->scheibe->id, 'bund' => $this->bund->id];

    foreach ($faelle as $label => $f) {
        $gp = ($this->mkGpFlex)('Wächter '.$label, $f['gp'], $f['stueck'], $f['formen']);
        $rezept = $this->svc->create($this->rootTeam, ['name' => 'Wächter '.$label]);
        $gerechnet = $this->svc->syncIngredients($this->rootTeam, $rezept->id, [[
            'id' => null, 'gp_id' => $gp->id, 'raw_text' => $f['menge'].' '.$f['einheit'],
            'quantity' => $f['menge'], 'unit_vocab_id' => $unitIds[$f['einheit']],
        ]]);

        $zeile = ($this->zeilen)($rezept->id)->first();
        [$clientSumme, $clientBepreist] = ($this->clientSumme)(collect([$zeile]));
        $zutat = $gerechnet->ingredients()->with(['unit', 'gp', 'referencedRecipe'])->first();

        // 1. Gramm-Faktor — die Stelle, an der „scheibe" mit dem GANZEN Stückgewicht rechnete.
        //    Gilt auch für den gewichtsfreien Stück-Fall: beide Seiten müssen dort 0 g sagen
        //    (der EK kommt da über €/Stk, nicht über Masse) — sonst wäre die Bedarfsmenge im
        //    Planungsblatt erfunden.
        expect(($this->clientFaktor)($zeile) * (float) $f['menge'])
            ->toBe($recompute->bruttoMasseG($zutat), "[{$label}] Gramm-Umrechnung weicht ab");
        // 2. Geld
        expect(round($clientSumme, 2))
            ->toBe((float) ($gerechnet->ek_total_eur ?? 0.0), "[{$label}] Anzeige und Buchung weichen ab");
        // 3. Vollständigkeit
        expect($clientBepreist)
            ->toBe($gerechnet->ek_n_ingredients_priced, "[{$label}] „bepreist\"-Zähler weicht ab");
    }
});

/**
 * Der Scope des Nachzieh-Befehls (C): wer in einer Zähl-Einheit dosiert wird, für die am
 * Grundprodukt kein Gewicht steht, gehört rein — wer eins hat, fällt raus (Resume).
 * Läuft ausdrücklich als DRY-RUN: `--apply` würde die KI aufrufen, und Tests rufen keine
 * Modelle. Geprüft wird die Auswahl, nicht die Schätzung — plus dass die Abfrage auf
 * SQLite überhaupt läuft (sie lief zuerst mit MySQL-only-GROUP_CONCAT).
 */
it('nimmt Grundprodukte ohne Naturalgewicht in den Nachzieh-Scope und lässt bediente wieder raus', function () {
    $gp = ($this->mkGpFlex)('Petersilie: frisch, glatt', [[10.0, 'kg', 1.0]], null, []);
    $rezept = $this->svc->create($this->rootTeam, ['name' => 'Kräuteröl']);
    $this->svc->syncIngredients($this->rootTeam, $rezept->id, [[
        'id' => null, 'gp_id' => $gp->id, 'raw_text' => '0,5 bund',
        'quantity' => '0.5', 'unit_vocab_id' => $this->bund->id,
    ]]);

    $this->artisan('foodalchemist:gp-forms-estimate', ['--team' => $this->rootTeam->id])
        ->expectsOutputToContain('DRY-RUN')
        ->assertSuccessful();

    // Im Scope, solange kein produktspezifisches Bund-Gewicht steht …
    $scope = fn () => \Platform\FoodAlchemist\Models\FoodAlchemistGpForm::where('gp_id', $gp->id)->count();
    expect($scope())->toBe(0);

    // … und draußen, sobald eines da ist (Resume: kein zweiter KI-Aufruf für denselben Fall).
    app(\Platform\FoodAlchemist\Services\GpFormService::class)
        ->setForm($this->rootTeam, (int) $gp->id, 'bund', 60.0, 'manual');
    expect($scope())->toBe(1);

    // Und das gesetzte Gewicht schlägt jetzt das globale (30 g) — sonst wäre C wirkungslos.
    app(RecipeRecomputeService::class)->recomputeAndPropagate($rezept->id);
    $zutat = \Platform\FoodAlchemist\Models\FoodAlchemistRecipe::find($rezept->id)
        ->ingredients()->with(['unit', 'gp', 'referencedRecipe'])->first();
    expect(app(RecipeRecomputeService::class)->bruttoMasseG($zutat))->toBe(30.0);   // 0,5 × 60 g
});
