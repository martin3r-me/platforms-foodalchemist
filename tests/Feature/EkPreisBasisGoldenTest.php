<?php

use Platform\FoodAlchemist\Models\FoodAlchemistPrice;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Services\RecipeRecomputeService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 22 · H2d — V-014, Money-Path-Hälfte: die Herkunft der Preisbasis wird
 * mitgeführt statt vergessen.
 *
 * Golden-Riegel VOR dem Umbau (Bau-Rahmen: „kein Verhaltenswechsel ohne Golden-Test").
 * Der Umbau greift in die **T3-Kaskade selbst** ein — dieselbe Methode, die jeden
 * Rezept-EK rechnet. Die Fehlerklasse ist die stille Verschiebung: eine umgestellte
 * Kaskade, die weiter plausible Zahlen liefert, nur andere. Darum friert dieser Test
 * zuerst die **Zahlen** ein (`ek_total_eur`, `ek_per_kg_eur`, `ek_n_ingredients_*`)
 * und behauptet erst danach die neue Zusicherung (`ek_price_basis`).
 *
 * Die Tabelle deckt jede Lage ab, in der die Kaskade eine Basis wählt:
 *   · Lead-LA gesetzt und bepreist            ⇒ lead
 *   · kein Lead, mehrere bepreiste LAs        ⇒ avg  (der stille Durchschnitt, um den V-014 geht)
 *   · Lead gesetzt, aber ohne gültigen Preis  ⇒ lead (die Zahl kommt vom Lead — als 0,00 €;
 *                                                    ein eigener, separat gemeldeter Befund)
 *   · Stk-Kaskade (zweiter Preis-Pfad)        ⇒ lead
 *   · Sub-Rezept als Zutat                    ⇒ die Basis des Sub (Vererbung, kein Neu-Erfinden)
 *   · gemischt                                ⇒ mixed
 *   · Sub bepreist, aber Basis unbekannt      ⇒ unknown (schwächstes Glied, wie subKonfidenzRang)
 *   · nichts bepreist                         ⇒ NULL (keine Basis ohne EK)
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->recompute = app(RecipeRecomputeService::class);
    $this->supplier = FoodAlchemistSupplier::create([
        'team_id' => $this->rootTeam->id, 'name' => 'Basis-Lieferant',
    ]);

    /**
     * GP mit n Lieferantenartikeln. $preise = Liste [preis, unit_code]; $leadIndex
     * wählt den Lead-LA (null ⇒ kein Lead, die Kaskade muss mitteln).
     * $preise-Eintrag mit preis === null ⇒ LA ohne Preiszeile.
     */
    $this->mkGp = function (string $name, array $preise, ?int $leadIndex = null, ?float $stueckGewichtG = null) {
        $gp = $this->makeGp($this->rootTeam, $name);
        $las = [];
        foreach ($preise as $i => [$preis, $unit]) {
            $la = FoodAlchemistSupplierItem::create([
                'team_id' => $this->rootTeam->id, 'supplier_id' => $this->supplier->id,
                'designation' => $name.' #'.$i, 'qty' => 1.0, 'unit_code' => $unit,
            ]);
            FoodAlchemistSupplierItemStructure::create([
                'team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'gp_id' => $gp->id,
            ]);
            if ($preis !== null) {
                FoodAlchemistPrice::create([
                    'team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id,
                    'price' => $preis, 'status' => '0',
                ]);
            }
            $las[] = $la;
        }
        $gp->update([
            'status' => 'approved',
            'lead_la_supplier_item_id' => $leadIndex !== null ? $las[$leadIndex]->id : null,
            'piece_default_g' => $stueckGewichtG,
        ]);

        return $gp->fresh();
    };

    /** Rezept aus [GP|Rezept => Menge in g], frisch gerechnet. */
    $this->mkRezept = function (string $name, array $zeilen) {
        $r = $this->makeRecipe($this->rootTeam, $name);
        $pos = 1;
        foreach ($zeilen as [$quelle, $menge]) {
            if ($quelle instanceof \Platform\FoodAlchemist\Models\FoodAlchemistRecipe) {
                $z = $this->makeIngredient($r, $quelle->name, null, (string) $menge, $pos++);
                $z->update(['referenced_recipe_id' => $quelle->id]);
            } else {
                $this->makeIngredient($r, $quelle->name, $quelle, (string) $menge, $pos++);
            }
        }
        $this->recompute->recomputeAndPropagate($r->id);

        return $r->fresh();
    };
});

// ── Der Freeze: die Zahlen dürfen sich nicht bewegen ─────────────────────────

it('friert die EK-Zahlen der vier Kaskaden-Lagen ein (Lead, Durchschnitt, durchgefallener Lead, Stk)', function () {
    // 10 €/kg = 0,01 €/g · 20 €/kg = 0,02 €/g ⇒ Durchschnitt 0,015 €/g
    $lead = ($this->mkGp)('Lead-GP', [[10.0, 'kg'], [20.0, 'kg']], 0);
    $avg = ($this->mkGp)('Avg-GP', [[10.0, 'kg'], [20.0, 'kg']]);
    // Lead zeigt auf einen LA ohne Preiszeile: die Kaskade läuft weiter in den Durchschnitt
    $leadTaub = ($this->mkGp)('Lead-taub-GP', [[null, 'kg'], [10.0, 'kg'], [20.0, 'kg']], 0);
    // Stk-Pfad: 2,00 €/Stk bei 50 g Stückgewicht ⇒ 0,04 €/g
    $stk = ($this->mkGp)('Stk-GP', [[2.0, 'Stk'], [6.0, 'Stk']], 0, 50.0);

    $rLead = ($this->mkRezept)('EK Lead', [[$lead, 100]]);
    $rAvg = ($this->mkRezept)('EK Avg', [[$avg, 100]]);
    $rTaub = ($this->mkRezept)('EK Lead taub', [[$leadTaub, 100]]);
    $rStk = ($this->mkRezept)('EK Stk', [[$stk, 100]]);

    expect((float) $rLead->ek_total_eur)->toBe(1.0)
        ->and((float) $rAvg->ek_total_eur)->toBe(1.5)
        ->and((float) $rStk->ek_total_eur)->toBe(4.0)
        // Nenner ist das Kalkulations-Yield (I7); die Fixture führt es mit der
        // eingewogenen Masse mit ⇒ 100 g = 0,1 kg.
        ->and((float) $rLead->ek_per_kg_eur)->toBe(10.0)
        ->and((float) $rAvg->ek_per_kg_eur)->toBe(15.0)
        ->and((float) $rStk->ek_per_kg_eur)->toBe(40.0)
        ->and($rLead->ek_n_ingredients_total)->toBe(1)
        ->and($rLead->ek_n_ingredients_priced)->toBe(1);

    /**
     * ⚠️ Eingefrorener Ist-Stand, KEIN gewünschtes Verhalten: beim durchgefallenen
     * Lead erreicht die Kaskade den Durchschnitt gar nicht. `preisKandidaten` gibt den
     * Lead-LA auch ohne aktiven Preis heraus, und `(float) null` macht daraus 0,00 €/kg
     * — ein *gültiger* Preis für `preisProGramm`. Ergebnis: die Zutat gilt als bepreist
     * (1 von 1), kostet aber 0 €, und `ek_total_eur` fällt auf NULL statt auf 1,50 €.
     * Als Bug gemeldet (Office Dev, Board 54); hier nur festgehalten, weil ein Money-Path-
     * Fix nicht in einen unbeaufsichtigten Lauf gehört. Bewegt sich diese Zeile, ist der
     * Bug entweder behoben (dann gehört sie angepasst) oder still verschoben.
     */
    expect($rTaub->ek_total_eur)->toBeNull()
        ->and($rTaub->ek_n_ingredients_priced)->toBe(1)
        ->and($rTaub->ek_n_ingredients_total)->toBe(1);
});

it('friert die EK-Zahlen der Sub-Rezept-Kette und des unbepreisten Rezepts ein', function () {
    $avg = ($this->mkGp)('Avg-Sub-GP', [[10.0, 'kg'], [20.0, 'kg']]);
    $ohne = $this->makeGp($this->rootTeam, 'GP ganz ohne LA');

    $sub = ($this->mkRezept)('Sub Avg', [[$avg, 100]]);                    // 1,50 € auf 0,1 kg ⇒ 15,00 €/kg
    $eltern = ($this->mkRezept)('Eltern mit Sub', [[$sub, 200]]);          // 200 g × 0,015 €/g = 3,00 €
    $leer = ($this->mkRezept)('EK leer', [[$ohne, 100]]);

    expect((float) $sub->ek_per_kg_eur)->toBe(15.0)
        ->and((float) $eltern->ek_total_eur)->toBe(3.0)
        ->and($eltern->ek_n_ingredients_priced)->toBe(1)
        ->and($leer->ek_total_eur)->toBeNull()
        ->and($leer->ek_n_ingredients_total)->toBe(1)
        ->and($leer->ek_n_ingredients_priced)->toBe(0);
});

// ── Die Zusicherungen, die der Umbau NEU gibt ───────────────────────────────

it('nennt die Preisbasis je Kaskaden-Lage — lead, Durchschnitt, und den zweiten Preis-Pfad', function () {
    $lead = ($this->mkGp)('B Lead-GP', [[10.0, 'kg'], [20.0, 'kg']], 0);
    $avg = ($this->mkGp)('B Avg-GP', [[10.0, 'kg'], [20.0, 'kg']]);
    $leadTaub = ($this->mkGp)('B Lead-taub-GP', [[null, 'kg'], [10.0, 'kg'], [20.0, 'kg']], 0);
    $stk = ($this->mkGp)('B Stk-GP', [[2.0, 'Stk'], [6.0, 'Stk']], 0, 50.0);

    expect(($this->mkRezept)('B EK Lead', [[$lead, 100]])->ek_price_basis?->value)->toBe('lead')
        // Der Kern von V-014: dieses Rezept steht auf einem Mittelwert aus 10 und 20 €/kg,
        // und bis hierher sah es aus wie das erste.
        ->and(($this->mkRezept)('B EK Avg', [[$avg, 100]])->ek_price_basis?->value)->toBe('avg')
        // Der durchgefallene Lead bleibt `lead` — die Basis sagt, WOHER die Zahl kommt,
        // und sie kommt (als 0,00 €) vom Lead-LA. Dass sie das tut, ist der separat
        // gemeldete Bug, nicht eine Basis-Frage: die Basis lügt hier nicht.
        ->and(($this->mkRezept)('B EK Lead taub', [[$leadTaub, 100]])->ek_price_basis?->value)->toBe('lead')
        // Zweiter Preis-Pfad (Stk-Kaskade) meldet dieselbe Basis-Sprache
        ->and(($this->mkRezept)('B EK Stk', [[$stk, 100]])->ek_price_basis?->value)->toBe('lead');
});

it('meldet mixed, sobald zwei Zutaten aus verschiedenen Basen bepreist sind', function () {
    $lead = ($this->mkGp)('M Lead-GP', [[10.0, 'kg']], 0);
    $avg = ($this->mkGp)('M Avg-GP', [[10.0, 'kg'], [20.0, 'kg']]);
    $ohne = $this->makeGp($this->rootTeam, 'M GP ohne LA');

    // Zwei bepreiste Zutaten mit verschiedener Basis ⇒ mixed …
    expect(($this->mkRezept)('M gemischt', [[$lead, 100], [$avg, 100]])->ek_price_basis?->value)->toBe('mixed')
        // … eine unbepreiste Zutat ist dagegen KEINE zweite Basis: sie trägt keinen Cent
        // zum EK bei und darf die Aussage über die bepreiste Hälfte nicht verwässern.
        ->and(($this->mkRezept)('M Lead plus Luecke', [[$lead, 100], [$ohne, 100]])->ek_price_basis?->value)->toBe('lead');
});

it('erbt die Basis des Sub-Rezepts und deckelt auf unknown, wenn das Sub keine nennt', function () {
    $avg = ($this->mkGp)('S Avg-GP', [[10.0, 'kg'], [20.0, 'kg']]);
    $lead = ($this->mkGp)('S Lead-GP', [[10.0, 'kg']], 0);

    $sub = ($this->mkRezept)('S Sub Avg', [[$avg, 100]]);
    expect($sub->ek_price_basis?->value)->toBe('avg')
        // Vererbung statt Neu-Erfinden: das Eltern-Rezept kennt nur den €/kg des Sub
        ->and(($this->mkRezept)('S Eltern erbt', [[$sub, 200]])->ek_price_basis?->value)->toBe('avg')
        ->and(($this->mkRezept)('S Eltern gemischt', [[$sub, 200], [$lead, 100]])->ek_price_basis?->value)->toBe('mixed');

    // Altbestand: ein Sub, das bepreist ist, aber (noch) keine Basis trägt. Per
    // Query-Builder, damit `updated_at` unberührt bleibt — genau die Lage jeder
    // Zeile, die vor dieser Migration gerechnet wurde.
    \Illuminate\Support\Facades\DB::table('foodalchemist_recipes')
        ->where('id', $sub->id)->update(['ek_price_basis' => null]);

    // Schwächstes Glied (wie subKonfidenzRang, §7 „kein false-confident"): eine
    // unbekannte Teil-Basis darf nicht als `avg` oder `lead` durchgehen …
    expect(($this->mkRezept)('S Eltern unbekannt', [[$sub->fresh(), 200]])->ek_price_basis?->value)->toBe('unknown')
        // … und auch nicht als `mixed`, wenn daneben eine bekannte Basis steht: „teils
        // geschätzt" und „woher weiß ich nicht" sind verschiedene Aussagen, und nur die
        // zweite verbietet jede Interpretation. Diese Zeile fehlte zuerst — die
        // Mutations-Gegenprobe (Unknown-Vorrang entfernt) blieb dadurch grün.
        ->and(($this->mkRezept)('S Eltern unbekannt plus Lead', [[$sub->fresh(), 200], [$lead, 100]])->ek_price_basis?->value)
        ->toBe('unknown');
});

it('lässt die Basis leer, wenn kein Cent bepreist ist (keine Basis ohne EK)', function () {
    $ohne = $this->makeGp($this->rootTeam, 'N GP ohne LA');

    $leer = ($this->mkRezept)('N EK leer', [[$ohne, 100]]);

    expect($leer->ek_total_eur)->toBeNull()
        ->and($leer->ek_price_basis)->toBeNull();
});
