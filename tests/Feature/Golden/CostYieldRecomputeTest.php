<?php

use Platform\FoodAlchemist\Models\FoodAlchemistPrice;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\RecipeRecomputeService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * M4-03: GL-02 Golden (GT-1…7; GT-8/VK folgt mit M6-Markup-Klassen).
 * GT-5 fixiert auf MULTIPLIKATIV (A-1-Entscheid nach Empfehlung — DB-verifiziert
 * über GT-1/GT-2; in 08_ENTSCHEIDUNGEN nachgetragen, Bestätigung Fachseite offen).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->svc = app(RecipeRecomputeService::class);
    $this->supplier = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Necta']);

    $this->einheiten = [];
    foreach ([
        ['slug' => 'g', 'display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1],
        ['slug' => 'ml', 'display_de' => 'Milliliter', 'dimension' => 'volume', 'default_in_ml' => 1],
        ['slug' => 'el', 'display_de' => 'Esslöffel', 'dimension' => 'volume', 'default_in_ml' => 15],
        ['slug' => 'stk', 'display_de' => 'Stück', 'dimension' => 'count'],
        ['slug' => 'qs', 'display_de' => 'nach Bedarf', 'dimension' => 'qs'],
    ] as $e) {
        $this->einheiten[$e['slug']] = FoodAlchemistVocabEinheit::create(['team_id' => $this->rootTeam->id, ...$e]);
    }

    // GP mit Lead-LA + aktivem Preis anlegen
    $this->mkGpMitPreis = function (string $name, float $preis, float $qty, string $unitCode, ?float $stkDefaultG = null) {
        $gp = $this->makeGp($this->rootTeam, $name);
        $la = FoodAlchemistSupplierItem::create([
            'team_id' => $this->rootTeam->id, 'supplier_id' => $this->supplier->id,
            'designation' => $name . ' LA', 'qty' => $qty, 'unit_code' => $unitCode,
        ]);
        FoodAlchemistSupplierItemStructure::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'gp_id' => $gp->id]);
        FoodAlchemistPrice::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'price' => $preis, 'status' => '0']);
        $gp->update(['lead_la_supplier_item_id' => $la->id, 'stk_default_g' => $stkDefaultG, 'n_las_total' => 1]);

        return $gp->refresh();
    };

    $this->mkRecipe = fn (string $name) => FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => str_replace(' ', '_', mb_strtolower($name)),
        'name' => $name, 'status' => 'draft',
    ]);

    $this->mkZutat = function (FoodAlchemistRecipe $r, array $attrs) {
        static $pos = 0;

        return FoodAlchemistRecipeIngredient::create([
            'team_id' => $this->rootTeam->id, 'recipe_id' => $r->id, 'position' => ++$pos,
            'raw_text' => $attrs['raw_text'] ?? 'Zutat', 'match_method' => 'manual', ...$attrs,
        ]);
    };
});

it('GT-1: Blatt-Rezept mit allen Pfaden — yield 2.08 · ek 1.03 · ek/kg 0.5 · 6/5 priced', function () {
    $fond = ($this->mkRecipe)('ROTE-BETE-FOND');
    $g = $this->einheiten['g']->id;
    $ml = $this->einheiten['ml']->id;
    $stk = $this->einheiten['stk']->id;

    ($this->mkZutat)($fond, ['menge' => 1000, 'einheit_vocab_id' => $g, 'gp_id' => ($this->mkGpMitPreis)('Rote Bete', 0.76, 1.0, 'kg')->id]);
    ($this->mkZutat)($fond, ['menge' => 1000, 'einheit_vocab_id' => $ml, 'gp_id' => ($this->mkGpMitPreis)('Leitungswasser', 0.001, 1.0, 'l')->id]);
    ($this->mkZutat)($fond, ['menge' => 50, 'einheit_vocab_id' => $ml, 'gp_id' => ($this->mkGpMitPreis)('Rotweinessig', 8.14, 2.0, 'l')->id]);
    ($this->mkZutat)($fond, ['menge' => 30, 'einheit_vocab_id' => $g, 'gp_id' => ($this->mkGpMitPreis)('Zucker Bio', 42.00, 25.0, 'kg')->id]);
    // Lorbeer: kein Stk-Preis, aber kg-Lead → count→mass-Brücke über stk_default_g 0.2
    ($this->mkZutat)($fond, ['menge' => 2, 'einheit_vocab_id' => $stk, 'gp_id' => ($this->mkGpMitPreis)('Lorbeerblaetter', 1.84, 0.05, 'kg', stkDefaultG: 0.2)->id]);
    // Pfeffer: stk_default_g NULL + Lead ohne brauchbare Einheit ⇒ unpriced, Yield 0
    $pfeffer = $this->makeGp($this->rootTeam, 'Pfefferkoerner schwarz');
    ($this->mkZutat)($fond, ['menge' => 5, 'einheit_vocab_id' => $stk, 'gp_id' => $pfeffer->id]);

    $this->svc->recomputePipeline($fond->id);
    $fond->refresh();

    expect((float) $fond->yield_kg)->toBe(2.08)
        ->and((float) $fond->ek_total_eur)->toBe(1.03)
        ->and((float) $fond->ek_per_kg_eur)->toBe(0.5)
        ->and($fond->ek_n_ingredients_total)->toBe(6)
        ->and($fond->ek_n_ingredients_priced)->toBe(5)
        ->and($fond->n_zutaten_total)->toBe(6)
        ->and($fond->n_zutaten_ungemappt)->toBe(0);
});

it('GT-2: 2-Ebenen-Sub-Rezept — I7-Rundung: Nenner = GERUNDETES yield (1.22, nicht 1.23) + Propagation', function () {
    // Sub mit ECHTER Zutat ⇒ Recompute ergibt ek_per_kg 0.5 (wie GT-1-Ergebnis)
    $sub = ($this->mkRecipe)('Fond');
    $fondGp = ($this->mkGpMitPreis)('Fond-Basis', 1.00, 2.0, 'kg');     // 0.0005 €/g
    ($this->mkZutat)($sub, ['menge' => 2000, 'einheit_vocab_id' => $this->einheiten['g']->id, 'gp_id' => $fondGp->id]);
    $this->svc->recomputePipeline($sub->id);
    expect((float) $sub->fresh()->ek_per_kg_eur)->toBe(0.5);

    $gel = ($this->mkRecipe)('ROTE BETE GEL');
    ($this->mkZutat)($gel, ['menge' => 250, 'einheit_vocab_id' => $this->einheiten['ml']->id,
        'referenced_recipe_id' => $sub->id, 'match_method' => 'recipe_ref']);
    ($this->mkZutat)($gel, ['menge' => 2.5, 'einheit_vocab_id' => $this->einheiten['g']->id,
        'gp_id' => ($this->mkGpMitPreis)('Agar', 36.90, 0.5, 'kg')->id,
        'match_method' => 'gemini_proposed', 'match_confidence' => 0.95]);

    $this->svc->recomputePipeline($gel->id);
    $gel->refresh();

    expect((float) $gel->yield_kg)->toBe(0.253)
        ->and((float) $gel->ek_total_eur)->toBe(0.31)
        ->and((float) $gel->ek_per_kg_eur)->toBe(1.22);            // 0.3095/0.253 — NICHT /0.2525 = 1.23!

    // Topologie-Probe: Preis der Sub-Zutat verdoppeln ⇒ Eltern via Propagation aktuell
    \Platform\FoodAlchemist\Models\FoodAlchemistPrice::where('supplier_item_id', $fondGp->lead_la_supplier_item_id)
        ->update(['price' => 2.00]);                               // ⇒ Sub ek/kg 1.0
    $this->svc->recomputeAndPropagate($sub->id);
    expect((float) $sub->fresh()->ek_per_kg_eur)->toBe(1.0)
        ->and((float) $gel->fresh()->ek_total_eur)->toBe(round(250 * 0.001 + 2.5 * 0.0738, 2)); // 0.43
});

it('GT-3 (F6.4): Mengen-Bereich 1–2 EL ⇒ Mittelwert 22.5 g Yield-Beitrag', function () {
    $r = ($this->mkRecipe)('Bereich');
    ($this->mkZutat)($r, ['menge' => 1, 'menge_max' => 2, 'einheit_vocab_id' => $this->einheiten['el']->id,
        'gp_id' => ($this->mkGpMitPreis)('Olivenoel', 10.0, 1.0, 'l')->id]);

    $this->svc->recomputePipeline($r->id);

    expect((float) $r->fresh()->yield_kg)->toBe(0.023);            // ROUND(22.5/1000, 3)
});

it('GT-4: optional + qs — Zähl- und Beitragsregeln (T2)', function () {
    $r = ($this->mkRecipe)('OptQs');
    ($this->mkZutat)($r, ['menge' => 100, 'einheit_vocab_id' => $this->einheiten['g']->id,
        'gp_id' => ($this->mkGpMitPreis)('Mehl', 1.0, 1.0, 'kg')->id]);
    ($this->mkZutat)($r, ['menge' => 50, 'einheit_vocab_id' => $this->einheiten['g']->id, 'is_optional' => true,
        'gp_id' => ($this->mkGpMitPreis)('Butter', 8.0, 1.0, 'kg')->id]);
    ($this->mkZutat)($r, ['menge' => 1, 'einheit_vocab_id' => $this->einheiten['qs']->id,
        'gp_id' => ($this->mkGpMitPreis)('Salz', 0.5, 1.0, 'kg')->id]);

    $this->svc->recomputePipeline($r->id);
    $r->refresh();

    expect($r->n_zutaten_total)->toBe(3)
        ->and((float) $r->yield_kg)->toBe(0.1)                     // nur Mehl
        ->and($r->ek_n_ingredients_total)->toBe(2)                 // Mehl + Salz (optional komplett raus)
        ->and($r->ek_n_ingredients_priced)->toBe(1);               // Salz qs ⇒ Faktor 0 ⇒ unpriced
});

it('GT-5 (A-1-Entscheid): Verluste MULTIPLIKATIV — 1000 g · putz 20 % · gar 10 % ⇒ 720 g', function () {
    $r = ($this->mkRecipe)('Verlust');
    ($this->mkZutat)($r, ['menge' => 1000, 'einheit_vocab_id' => $this->einheiten['g']->id,
        'putzverlust_pct' => 20, 'garverlust_pct' => 10,
        'gp_id' => ($this->mkGpMitPreis)('Sellerie', 2.0, 1.0, 'kg')->id]);

    $this->svc->recomputePipeline($r->id);

    expect((float) $r->fresh()->yield_kg)->toBe(0.72);             // NICHT additiv 0.70 (Regelwerk-Wortlaut)
});

it('GT-6: Zyklen-Schutz — Bulk bricht mit beteiligten IDs ab, Inspector lehnt Link ab', function () {
    $a = ($this->mkRecipe)('A');
    $b = ($this->mkRecipe)('B');
    // Zyklus per Direkt-Insert (Service-Guards umgangen)
    ($this->mkZutat)($a, ['menge' => 1, 'einheit_vocab_id' => $this->einheiten['g']->id, 'referenced_recipe_id' => $b->id, 'match_method' => 'recipe_ref']);
    ($this->mkZutat)($b, ['menge' => 1, 'einheit_vocab_id' => $this->einheiten['g']->id, 'referenced_recipe_id' => $a->id, 'match_method' => 'recipe_ref']);

    expect(fn () => $this->svc->recomputeAll())
        ->toThrow(RuntimeException::class, 'Zyklus');

    expect($this->svc->pruefeVerknuepfung($a->id, $b->id))
        ->toMatchArray(['erlaubt' => false, 'grund' => 'Zyklus']);
});

it('GT-7 (A-5-Ziel): Kette A→B→C erlaubt (Tiefe 3); Link C→D blockt (projiziert 4)', function () {
    [$a, $b, $c, $d] = [($this->mkRecipe)('A'), ($this->mkRecipe)('B'), ($this->mkRecipe)('C'), ($this->mkRecipe)('D')];
    $g = $this->einheiten['g']->id;
    ($this->mkZutat)($a, ['menge' => 1, 'einheit_vocab_id' => $g, 'referenced_recipe_id' => $b->id, 'match_method' => 'recipe_ref']);
    ($this->mkZutat)($b, ['menge' => 1, 'einheit_vocab_id' => $g, 'referenced_recipe_id' => $c->id, 'match_method' => 'recipe_ref']);

    expect($this->svc->pruefeVerknuepfung($b->id, $c->id)['projizierte_tiefe'])->toBeLessThanOrEqual(3);

    $pruefung = $this->svc->pruefeVerknuepfung($c->id, $d->id);
    expect($pruefung['erlaubt'])->toBeFalse()
        ->and($pruefung['projizierte_tiefe'])->toBe(4)
        ->and($pruefung['grund'])->toContain('Tiefe');
});

it('I5-Gate: gemini_proposed < 0.85 zählt als ungemappt ⇒ F7.1-Reset + Kosten ohne die Zutat', function () {
    $r = ($this->mkRecipe)('Gate');
    ($this->mkZutat)($r, ['menge' => 100, 'einheit_vocab_id' => $this->einheiten['g']->id,
        'gp_id' => ($this->mkGpMitPreis)('Mehl 2', 1.0, 1.0, 'kg')->id]);
    ($this->mkZutat)($r, ['menge' => 500, 'einheit_vocab_id' => $this->einheiten['g']->id,
        'gp_id' => ($this->mkGpMitPreis)('Fruchtpueree', 5.0, 1.0, 'kg')->id,
        'match_method' => 'gemini_proposed', 'match_confidence' => 0.8]);

    $this->svc->recomputePipeline($r->id);
    $r->refresh();

    expect($r->n_zutaten_ungemappt)->toBe(1)
        ->and($r->allergene_konfidenz)->toBe('low')
        ->and($r->allergen_glutenhaltiges_getreide)->toBe('unbekannt')   // F7.1-Totalreset
        ->and((float) $r->yield_kg)->toBe(0.6)                           // Masse zählt trotzdem (§3.1!)
        ->and($r->ek_n_ingredients_total)->toBe(1);                      // Kosten ohne die ungemappte
});

it('A-3: yield_kg_manual hat Vorrang im ek/kg-Nenner (COALESCE)', function () {
    $r = ($this->mkRecipe)('Manuell');
    ($this->mkZutat)($r, ['menge' => 1000, 'einheit_vocab_id' => $this->einheiten['g']->id,
        'gp_id' => ($this->mkGpMitPreis)('Karotte', 2.0, 1.0, 'kg')->id]);
    $r->update(['yield_kg_manual' => 0.5]);                              // Reduktion: Koch weiß es besser

    $this->svc->recomputePipeline($r->id);
    $r->refresh();

    expect((float) $r->yield_kg)->toBe(1.0)                              // Auto-Wert bleibt sichtbar
        ->and((float) $r->ek_per_kg_eur)->toBe(4.0);                     // 2.00 € / 0.5 kg manual
});
