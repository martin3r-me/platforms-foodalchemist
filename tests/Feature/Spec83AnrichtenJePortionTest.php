<?php

use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\ProductionTimeService;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 83 B3 (L7): Anrichten am Gericht pro Portion. sales_unit_count leer → 1 Ansatz = 1 Portion; ohne gepflegte Zeit pro
 * Menge leitet sich die Anrichte-Zeit aus den Teller-Komponenten ab (Komponenten × 5 s, Startwert, ungeprüft).
 *
 * Fixture = Nachbau „[HG] Weiderind-Senfrostbraten“ (lokal 3868): 7 Komponenten (Fleisch, Jus, Püree, Beilage, Pickle, Chip,
 * Microgreens), Portion 477 g, sales_unit_count leer, Arbeitszeit 5 min (Pass einrichten). NICHT gegen die lokale DB.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    app(TeamSettingsService::class)->update($this->rootTeam, ['default_batch_max_kg' => 20]);
    $this->times = app(ProductionTimeService::class);

    $this->gericht = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'senfrostbraten', 'name' => '[HG] Weiderind-Senfrostbraten',
        'status' => 'approved', 'is_sales_recipe' => true, 'yield_kg' => 0.477, 'sales_unit_count' => null, 'work_time_min' => 5,
    ]);
    foreach (['Fleisch: Senfrostbraten', 'Jus: Altbier', 'Püree: Petersilienwurzel', 'Beilage: Blumenkohl, geröstet',
        'Pickle: Perlzwiebel', 'Chip: Zwiebelring'] as $i => $name) {
        $sub = $this->makeRecipe($this->rootTeam, $name);
        $this->makeIngredient($this->gericht, $name, null, '60', $i + 1)->update(['referenced_recipe_id' => $sub->id]);
    }
    $this->makeIngredient($this->gericht, 'Microgreens Erbse', FoodAlchemistGp::create(['team_id' => null, 'gp_key' => 'f83|microgreens', 'name' => 'Microgreens Erbse']), '2', 7);
});

it('Abnahme Senfrostbraten: 100 Portionen Anrichten ≈ 50 Personenminuten (±25 %) aus 7 Komponenten × 5 s', function () {
    $r = $this->times->calculateForBatches($this->rootTeam, $this->gericht->fresh(), 100);   // 100 Portionen = 100 Ansätze (1 Ansatz = 1 Portion)

    expect($this->times->tellerKomponenten($this->gericht))->toBe(7)
        ->and($r['variable_time_source'])->toBe('komponenten')
        ->and($r['anrichten_komponenten'])->toBe(7)
        ->and($r['variable_quantity_basis'])->toBe('portion')
        ->and($r['variable_quantity'])->toBe(100.0)
        ->and($r['variable_minutes'])->toBe(58.333)                              // 7 × 5 s × 100 / 60
        ->and($r['variable_minutes'])->toBeGreaterThanOrEqual(37.5)->toBeLessThanOrEqual(62.5)   // Ziel ~50 ±25 %
        ->and($r['warnings'])->toBeEmpty()
        // B3j (14.10.): Pass einrichten entfällt — work_time_min = 5 am Gericht zählt NICHT (Daten bleiben)
        ->and($r['operations'])->toBe(1)->and($r['batch_minutes'])->toBe(0.0)
        ->and($r['batch_limit'])->toBeNull()->and($r['batch_limit_source'])->toBe('gericht')
        ->and($this->gericht->fresh()->work_time_min)->toBe(5)
        // Zeile ohne Regenerier-Behälter: nur Anrichten 58,3
        ->and($r['active_person_minutes'])->toBe(58.333);
});

it('Gepflegte Zeit pro Menge am Gericht gewinnt immer (Portion und auch kg)', function () {
    $this->gericht->update(['variable_work_time_min' => 0.4, 'variable_work_time_basis' => 'portion']);
    $p = $this->times->calculateForBatches($this->rootTeam, $this->gericht->fresh(), 100);
    expect($p['variable_time_source'])->toBe('gepflegt')->and($p['variable_minutes'])->toBe(40.0)
        ->and($p['anrichten_komponenten'])->toBeNull();

    // Sofort-Korrektur 14.10. am Referenzgericht: 0,8 min/kg — bleibt so, keine Doppelzählung mit der Ableitung
    $this->gericht->update(['variable_work_time_min' => 0.8, 'variable_work_time_basis' => 'kg']);
    $k = $this->times->calculateForBatches($this->rootTeam, $this->gericht->fresh(), 100);
    expect($k['variable_time_source'])->toBe('gepflegt')->and($k['variable_quantity_basis'])->toBe('kg')
        ->and($k['variable_minutes'])->toBe(38.16);                               // 0,8 × 47,7 kg
});

it('sales_unit_count leer → 1 Portion je Ansatz; gesetzt → Portionen je Ansatz wie bisher', function () {
    $leer = $this->times->calculateForBatches($this->rootTeam, $this->gericht->fresh(), 12);
    expect($leer['variable_quantity'])->toBe(12.0);

    $this->gericht->update(['sales_unit_count' => 10]);
    $zehn = $this->times->calculateForBatches($this->rootTeam, $this->gericht->fresh(), 3);
    expect($zehn['variable_quantity'])->toBe(30.0)->and($zehn['variable_minutes'])->toBe(17.5);   // 30 × 35 s
});

it('Optionale und qs-Zeilen sind keine Teller-Komponenten; Basisrezepte bekommen keine Ableitung', function () {
    $qs = FoodAlchemistVocabEinheit::firstOrCreate(['team_id' => $this->rootTeam->id, 'slug' => 'qs'], ['display_de' => 'nach Geschmack', 'dimension' => 'count']);
    $this->makeIngredient($this->gericht, 'Fleur de Sel', null, '1', 8)->update(['unit_vocab_id' => $qs->id]);
    $this->makeIngredient($this->gericht, 'Kresse', null, '1', 9)->update(['is_optional' => true]);
    expect($this->times->tellerKomponenten($this->gericht->fresh()))->toBe(7);

    $basis = $this->makeRecipe($this->rootTeam, 'Fond: Kalb');
    $this->makeIngredient($basis, 'Knochen', null, '1000');
    $b = $this->times->calculateForBatches($this->rootTeam, $basis->fresh(), 2);
    expect($b['variable_time_source'])->toBeNull()->and($b['variable_minutes'])->toBe(0.0);
});

it('Gericht ohne Komponenten: keine Anrichte-Zeit, keine Warnung (Bestand bleibt unverändert)', function () {
    $leer = FoodAlchemistRecipe::create(['team_id' => $this->rootTeam->id, 'recipe_key' => 'leer', 'name' => 'Leerer Teller',
        'status' => 'approved', 'is_sales_recipe' => true, 'work_time_min' => 10]);
    $r = $this->times->calculateForBatches($this->rootTeam, $leer, 2);

    expect($r['variable_minutes'])->toBe(0.0)->and($r['variable_time_source'])->toBeNull()
        ->and($r['active_person_minutes'])->toBe(0.0)->and($r['warnings'])->toBeEmpty();    // B3j: work_time_min am Gericht zählt nicht
});

// ── B3i/B3l: Anrichten je Darreichungsform (Pauschale + Zeit je Einheit × Menge) ─────────────────────────────

function spec83Formen($team, $gericht): array
{
    $mk = fn (string $code, string $label) => \Platform\FoodAlchemist\Models\FoodAlchemistServierform::firstOrCreate(['code' => $code], ['team_id' => $team->id, 'label' => $label]);
    $svc = app(\Platform\FoodAlchemist\Services\DarreichungService::class);
    $d = [];
    foreach ([['teller', 'Teller', []], ['flying', 'Flying', ['unit_count' => 3]], ['buffet', 'Buffet', []], ['station', 'Live-Station', []], ['unbestimmt', 'Unbestimmt', []]] as [$code, $label, $attrs]) {
        $d[$code] = $svc->anlegen($team, $gericht->id, $mk($code, $label)->id, $attrs);
    }

    return $d;   // teller = Standard (erste Form)
}

it('B3i: je Form eigene Einheit — Teller s/Portion, Flying s/Stück, Buffet min/Platte (Ausgabe-Behälter), Station 0, unbestimmt wie Teller', function () {
    $d = spec83Formen($this->rootTeam, $this->gericht);
    // Ausgabe-Behälter (Spec 51) an Jus + Fleisch: je 1 GN 2/1 bei 100 Portionen
    $this->artisan('foodalchemist:behaelter-katalog --apply');
    $gn = (int) \Illuminate\Support\Facades\DB::table('foodalchemist_vocab_containers')->where('slug', 'gn_11_65mm')->value('id');
    foreach (['Jus: Altbier', 'Fleisch: Senfrostbraten'] as $name) {
        \Platform\FoodAlchemist\Models\FoodAlchemistRecipeContainer::create(['team_id' => $this->rootTeam->id, 'zweck' => 'ausgabe',
            'recipe_id' => FoodAlchemistRecipe::where('name', $name)->value('id'), 'container_vocab_id' => $gn, 'referenz_menge_kg' => 3.0]);
    }
    $formen = collect($d)->map(fn ($dar) => ['darreichung_id' => $dar->id, 'portionen' => 100.0])->values()->all();
    $r = $this->times->calculateForBatches($this->rootTeam, $this->gericht->fresh(), 500, null, $formen);
    $f = collect($r['bloecke']['anrichten']['formen'])->keyBy('form');

    expect($f['teller'])->toMatchArray(['einheit' => 'portion', 'masseinheit' => 's', 'je_einheit' => 35.0, 'quelle' => 'komponenten', 'minuten' => 58.333])
        ->and($f['flying'])->toMatchArray(['einheit' => 'stueck', 'menge' => 300.0, 'je_einheit' => 5.0, 'quelle' => 'startwert', 'minuten' => 25.0])
        ->and($f['buffet'])->toMatchArray(['einheit' => 'behaelter', 'masseinheit' => 'min', 'menge' => 2.0, 'je_einheit' => 2.0, 'minuten' => 4.0])
        ->and($f['station'])->toMatchArray(['einheit' => 'vor_ort', 'minuten' => 0.0, 'pauschal_min' => 0.0])
        ->and($f['unbestimmt'])->toMatchArray(['einheit' => 'portion', 'minuten' => 58.333])
        ->and($r['bloecke']['anrichten']['minuten'])->toBe(145.667)                 // 58,333 + 25 + 4 + 0 + 58,333
        ->and(implode(' ', $r['warnings']))->toContain('Servierform „Unbestimmt“ unbestimmt');
});

it('B3l: gepflegter Wert und Pauschale je Darreichung — Pauschale einmal je Zeile', function () {
    $d = spec83Formen($this->rootTeam, $this->gericht);
    app(\Platform\FoodAlchemist\Services\DarreichungService::class)->aktualisieren($this->rootTeam, $d['teller']->id, ['anrichten_wert' => '60', 'anrichten_pauschal_min' => '10,5']);
    $r = $this->times->calculateForBatches($this->rootTeam, $this->gericht->fresh(), 100, null, [['darreichung_id' => $d['teller']->id, 'portionen' => 100.0]]);

    expect($r['bloecke']['anrichten']['formen'][0])->toMatchArray(['je_einheit' => 60.0, 'pauschal_min' => 10.5, 'quelle' => 'darreichung', 'minuten' => 110.5])   // 10,5 + 100 × 60 s
        ->and($r['variable_time_source'])->toBe('darreichung');
});

it('B3i: ohne bekannte Formen (Produktionsauftrag, Schritt 1) gilt die Standard-Darreichung — mit Warnung, wenn es mehrere gibt', function () {
    spec83Formen($this->rootTeam, $this->gericht);
    $r = $this->times->calculateForBatches($this->rootTeam, $this->gericht->fresh(), 100);

    expect($r['bloecke']['anrichten']['formen'])->toHaveCount(1)
        ->and($r['bloecke']['anrichten']['formen'][0])->toMatchArray(['form' => 'teller', 'portionen' => 100.0, 'minuten' => 58.333])
        ->and(implode(' ', $r['warnings']))->toContain('bestellte Darreichungen unbekannt');
});

it('B3i Kalkulation/Planungsblatt: bestellte Darreichungen kommen aus den Zielen (Flying 40 Portionen à 3 Stück)', function () {
    $d = spec83Formen($this->rootTeam, $this->gericht);
    $blatt = app(\Platform\FoodAlchemist\Services\PlanungsblattService::class)->produktionsblattFuerZiele($this->rootTeam, [
        ['recipe_id' => $this->gericht->id, 'portions' => 60, 'presentation_id' => $d['teller']->id],
        ['recipe_id' => $this->gericht->id, 'portions' => 40, 'presentation_id' => $d['flying']->id],
    ]);
    $zeile = collect($blatt['rezepte'])->firstWhere('recipe_id', $this->gericht->id);

    // Teller 60 × 35 s = 35 min + Flying 120 Stück × 5 s = 10 min = 45 min (keine Regenerier-Behälter)
    expect(collect($zeile['formen'])->pluck('darreichung_id')->filter()->sort()->values()->all())->toBe(collect([$d['teller']->id, $d['flying']->id])->sort()->values()->all())
        ->and($zeile['arbeitszeit_min'])->toBe(45.0);
});

it('B3i/B3l VK-Modal: je Darreichung Pauschale + Wert mit Einheit der Form, Startwert-Platzhalter und Hochrechnung für 100', function () {
    $d = spec83Formen($this->rootTeam, $this->gericht);
    $this->actingAs($this->makeUser($this->rootTeam, 'Koch Anrichten'));
    $lw = \Livewire\Livewire::test(\Platform\FoodAlchemist\Livewire\Verkauf\VkModal::class)->call('oeffnen', $this->gericht->id);
    $html = preg_replace('/<!--.*?-->/s', '', $lw->html());   // Livewire-Blockkommentare zwischen den Textteilen
    expect($html)->toContain('data-dar-anrichten="'.$d['teller']->id.'"')->toContain('je Teller (s)')->toContain('35 Startwert')
        ->toContain('je Stück (s)')->toContain('je Platte (min)')->toContain('vor Ort an der Station')
        ->toMatch('/data-dar-anrichten-hochrechnung="'.$d['teller']->id.'">100 Portionen\s*≈ 58 min · Startwert, ungeprüft/u');

    $lw->call('bearbeitenStarten')->set('darForm.'.$d['teller']->id.'.anrichten_wert', '30')->set('darForm.'.$d['teller']->id.'.anrichten_pauschal_min', '5')
        ->call('darreichungSpeichern', $d['teller']->id);
    expect((float) $d['teller']->fresh()->anrichten_wert)->toBe(30.0)->and((float) $d['teller']->fresh()->anrichten_pauschal_min)->toBe(5.0)
        ->and(preg_replace('/<!--.*?-->/s', '', $lw->html()))->toMatch('/data-dar-anrichten-hochrechnung="'.$d['teller']->id.'">100 Portionen\s*≈ 55 min</u');   // 5 + 100 × 30 s
});

it('B3i MCP im Lockstep: recipe_darreichung.PUT setzt die Anrichte-Felder, verkaufsrezepte.GET zeigt Wert und Einheit', function () {
    $d = spec83Formen($this->rootTeam, $this->gericht);
    $ctx = new \Platform\Core\Contracts\ToolContext(user: $this->makeUser($this->rootTeam, 'MCP Koch'), team: $this->rootTeam);
    $reg = app(\Platform\Core\Tools\ToolRegistry::class);
    $put = $reg->get('foodalchemist.recipe_darreichung.PUT')->execute(['presentation_id' => $d['buffet']->id, 'attrs' => ['anrichten_wert' => 3, 'anrichten_pauschal_min' => 15]], $ctx);
    expect($put->success)->toBeTrue();

    $get = $reg->get('foodalchemist.verkaufsrezepte.GET')->execute(['id' => $this->gericht->id], $ctx);
    $buffet = collect($get->data['presentations'] ?? [])->firstWhere('presentation_id', $d['buffet']->id);
    expect($buffet)->toMatchArray(['anrichten_wert' => 3.0, 'anrichten_pauschal_min' => 15.0])
        ->and($buffet['anrichten_einheit']['einheit'])->toBe('behaelter');
});

it('Planungsblatt: Ansätze und Portionen des Gerichts bleiben unverändert, nur die Arbeitszeit folgt B3', function () {
    $blatt = app(\Platform\FoodAlchemist\Services\PlanungsblattService::class)
        ->produktionsblattFuerZiele($this->rootTeam, [['recipe_id' => $this->gericht->id, 'portions' => 100]]);
    $zeile = collect($blatt['rezepte'])->firstWhere('recipe_id', $this->gericht->id);

    // Gerichts-Ansätze = Portionen ÷ max(1, sales_unit_count) — rechnet das Planungsblatt selbst, nicht ProductionTimeService
    expect($zeile['ansaetze'])->toBe(100.0)->and($zeile['benoetigt_ansaetze'])->toBe(100.0)->and($zeile['portionen'])->toBe(100)
        ->and($zeile['arbeitszeit_min'])->toBe(58.333);                        // nur Anrichten 58,3 (B3j)
});

// ── B3c/B3d: drei Blöcke am Gericht, Regenerieren aus Behältern, getrennter Lohn ─────────────────────────

function spec83RegenFixture($test, $team, $gericht): array
{
    $test->artisan('foodalchemist:behaelter-katalog --apply');
    $gn = (int) \Illuminate\Support\Facades\DB::table('foodalchemist_vocab_containers')->where('slug', 'gn_11_65mm')->value('id');
    $jus = \Platform\FoodAlchemist\Models\FoodAlchemistRecipe::where('name', 'Jus: Altbier')->firstOrFail();
    $fleisch = \Platform\FoodAlchemist\Models\FoodAlchemistRecipe::where('name', 'Fleisch: Senfrostbraten')->firstOrFail();
    foreach ([[$jus, 3.0], [$fleisch, 3.0]] as [$r, $kgJeGn]) {
        \Platform\FoodAlchemist\Models\FoodAlchemistRecipeContainer::create(['team_id' => $team->id, 'recipe_id' => $r->id,
            'zweck' => 'regenerieren', 'container_vocab_id' => $gn, 'referenz_menge_kg' => $kgJeGn]);
    }
    // Gerätezeit: Gesamt-Programm 25 min, Fleisch-Override 40 min → längstes Programm 40 min (passiv)
    $fleischZeile = $gericht->ingredients()->where('referenced_recipe_id', $fleisch->id)->firstOrFail();
    \Platform\FoodAlchemist\Models\FoodAlchemistRecipeRegeneration::create(['team_id' => $team->id, 'recipe_id' => $gericht->id,
        'component_label' => 'Gesamt', 'duration_min' => 25, 'temp_c' => 80]);
    \Platform\FoodAlchemist\Models\FoodAlchemistRecipeRegeneration::create(['team_id' => $team->id, 'recipe_id' => $gericht->id,
        'component_label' => 'Fleisch', 'ingredient_id' => $fleischZeile->id, 'duration_min' => 40, 'temp_c' => 70]);

    return ['jus' => $jus, 'fleisch' => $fleisch];
}

it('B3c/B3j: Gericht weist zwei Blöcke getrennt aus — Regenerieren (Behälter × 30 s), Anrichten; Gerätezeit passiv', function () {
    spec83RegenFixture($this, $this->rootTeam, $this->gericht);
    $behaelter = app(\Platform\FoodAlchemist\Services\BehaelterBedarfService::class)->regenerierBehaelter($this->rootTeam, $this->gericht->fresh(), 100);
    $r = $this->times->calculateForBatches($this->rootTeam, $this->gericht->fresh(), 100);
    $b = $r['bloecke'];

    // 100 Portionen: Jus und Fleisch je 60 g → je 6 kg. Spec 51 wählt als Basis-Variante GN 2/1 65 mm (6,17 kg passt) → je 1
    // Behälter, so wie das Produktionsblatt ihn druckt (Referenz GN 1/1 à 3 kg wäre die 2-Behälter-Alternative) → 2 Behälter
    expect($behaelter['anzahl'])->toBe(2)->and($behaelter['nicht_berechenbar'])->toBe([])
        ->and($b)->not->toHaveKey('pass')                                                     // B3j
        ->and($b['regenerieren'])->toMatchArray(['minuten' => 1.0, 'behaelter' => 2, 'sekunden_je_behaelter' => 30.0,
            'geraete_min' => 40.0, 'quelle' => 'behaelter'])
        ->and($b['anrichten'])->toMatchArray(['minuten' => 58.333, 'quelle' => 'komponenten', 'komponenten' => 7])
        // Summe der Blöcke = Personenzeit; die 40 Geräte-Minuten sind passiv (Durchlauf), kein Lohn
        // Senfrostbraten nach B3j: Regenerieren 1,0 + Anrichten 58,3 = 59,3 Personenminuten
        ->and($r['active_person_minutes'])->toBe(59.333)
        ->and($r['passive_minutes'])->toBe(40.0)->and($r['elapsed_minutes'])->toBe(99.333);
});

it('B3c: ohne Regenerier-Behälter ist der Block 0 (kein Raten), Basisrezepte haben keine Blöcke', function () {
    $r = $this->times->calculateForBatches($this->rootTeam, $this->gericht->fresh(), 100);
    expect($r['bloecke']['regenerieren'])->toMatchArray(['minuten' => 0.0, 'behaelter' => null, 'quelle' => null])
        ->and($r['active_person_minutes'])->toBe(58.333);

    $basis = $this->makeRecipe($this->rootTeam, 'Fond: Huhn');
    expect($this->times->calculateForBatches($this->rootTeam, $basis, 1)['bloecke'])->toBeNull();
});

it('B3c Kalkulation: Lohn je Block getrennt („davon“-Zeilen), Summe = FEK', function () {
    spec83RegenFixture($this, $this->rootTeam, $this->gericht);
    $costing = app(\Platform\FoodAlchemist\Services\OrderCostingService::class);
    app(\Platform\FoodAlchemist\Services\TeamSettingsService::class)->update($this->rootTeam, ['stundensatz_eur' => 60]);
    $concepts = app(\Platform\FoodAlchemist\Services\ConceptService::class);
    $concept = $concepts->create($this->rootTeam, ['name' => 'Gala']);
    $slot = $concepts->addSlot($this->rootTeam, $concept->id, ['role' => 'Hauptgang']);
    $concepts->fillSlot($this->rootTeam, $slot->id, ['sales_recipe_id' => $this->gericht->id]);
    $concepts->recomputeCache($concept->refresh());

    $k = $costing->costConcept($this->rootTeam, $concept->refresh(), 100);
    $teile = $k['fek_teile'];
    $details = collect($k['cost_breakdown'])->where('stage', 'detail')->pluck('amount', 'key')->all();

    expect($teile)->not->toHaveKey('pass')->and($teile['regenerieren'])->toBeGreaterThan(0)->and($teile['anrichten'])->toBeGreaterThan(0)
        ->and(round(array_sum($teile), 2))->toBe(round($k['fek'], 2))
        ->and(array_keys($details))->toContain('fek_regenerieren', 'fek_anrichten')->not->toContain('fek_pass')
        // Verhältnis der Blöcke = Verhältnis der Minuten (gleicher Satz am Zeilen-Posten)
        ->and(round($teile['anrichten'] / $teile['regenerieren'], 2))->toBe(round(58.333 / 1.0, 2));
    $zeile = collect($k['time_breakdown'])->firstWhere('recipe_id', $this->gericht->id);
    expect($zeile['regenerieren_minutes'])->toBeGreaterThan(0)->and($zeile['bloecke']['regenerieren']['behaelter'])->toBeGreaterThan(0);
});

// ── B3g: Pass = Sammelposten; Anrichte-Minuten anteilig auf die beteiligten Posten ────────────────────────

function spec83Posten($test, $team, $gericht, bool $passIstSammel): array
{
    // Peters Merkmal ist_sammelposten (Spec 83 B2, #360)
    $mk = fn (string $slug, string $name, bool $sammel = false) => \Platform\FoodAlchemist\Models\FoodAlchemistProductionStation::create(
        ['team_id' => $team->id, 'slug' => $slug, 'name' => $name, 'ist_sammelposten' => $sammel]);
    $p = ['saucier' => $mk('saucier', 'Saucier'), 'entremetier' => $mk('entremetier', 'Entremetier'), 'pass' => $mk('pass', 'Pass', $passIstSammel)];
    foreach (['Fleisch: Senfrostbraten' => 'saucier', 'Jus: Altbier' => 'saucier', 'Pickle: Perlzwiebel' => 'saucier', 'Chip: Zwiebelring' => 'saucier',
        'Püree: Petersilienwurzel' => 'entremetier', 'Beilage: Blumenkohl, geröstet' => 'entremetier'] as $name => $posten) {
        \Platform\FoodAlchemist\Models\FoodAlchemistRecipe::where('name', $name)->update(['default_station_id' => $p[$posten]->id]);
    }
    $gericht->update(['default_station_id' => $p['pass']->id]);   // Microgreens (GP) hat keinen Posten

    return $p;
}

it('B3g/B3h: Blöcke 4/7 Saucier, 2/7 Entremetier, Garnitur ohne Posten am Sammel-Pass = „nicht zugeteilt“', function () {
    $p = spec83Posten($this, $this->rootTeam, $this->gericht, true);
    $bl = $this->times->calculateForBatches($this->rootTeam, $this->gericht->fresh(), 100)['bloecke'];
    $a = collect($bl['anrichten']['aufteilung'])->keyBy(fn ($t) => $t['station_id'] ?? 0);
    expect($bl)->not->toHaveKey('pass');                                                         // B3j

    expect($a)->toHaveCount(3)
        ->and($a[$p['saucier']->id])->toMatchArray(['komponenten' => 4, 'minuten' => 33.333])
        ->and($a[$p['entremetier']->id])->toMatchArray(['komponenten' => 2, 'minuten' => 16.667])
        ->and($a[0])->toMatchArray(['station_id' => null, 'komponenten' => 1, 'minuten' => 8.333])   // eigene Position, nicht geraten
        ->and($a->has($p['pass']->id))->toBeFalse();                                               // Sammelposten trägt keine Anrichte-Minuten
});

it('B3g: Pass ohne Sammelposten-Merkmal nimmt die Komponente ohne Posten', function () {
    $p = spec83Posten($this, $this->rootTeam, $this->gericht, false);
    $a = collect($this->times->calculateForBatches($this->rootTeam, $this->gericht->fresh(), 100)['bloecke']['anrichten']['aufteilung'])
        ->keyBy(fn ($t) => $t['station_id'] ?? 0);

    expect($a[$p['pass']->id])->toMatchArray(['komponenten' => 1, 'minuten' => 8.333])->and($a->has(0))->toBeFalse();
});

it('B3h Kalkulation: Posten-Last nur bei den beteiligten Posten, Sammel-Pass trägt nichts', function () {
    $p = spec83Posten($this, $this->rootTeam, $this->gericht, true);
    $concepts = app(\Platform\FoodAlchemist\Services\ConceptService::class);
    $concept = $concepts->create($this->rootTeam, ['name' => 'Gala']);
    $slot = $concepts->addSlot($this->rootTeam, $concept->id, ['role' => 'Hauptgang']);
    $concepts->fillSlot($this->rootTeam, $slot->id, ['sales_recipe_id' => $this->gericht->id]);
    $concepts->recomputeCache($concept->refresh());

    $k = app(\Platform\FoodAlchemist\Services\OrderCostingService::class)->costConcept($this->rootTeam, $concept->refresh(), 100);
    $last = collect($k['station_load'])->keyBy(fn ($l) => $l['station_id'] ?? 0);

    expect($last[$p['saucier']->id]['active_person_minutes'])->toBeGreaterThanOrEqual(33.33)
        ->and($last->has($p['pass']->id))->toBeFalse()                                      // Sammel-Pass: keine Last
        ->and(round($last[0]['active_person_minutes'], 2))->toBe(8.33)                     // 1/7 von Anrichten 58,33
        ->and(implode(' ', $k['warnings']))->toContain('1 Komponente(n) ohne Posten')
        ->and(round(array_sum($k['fek_teile']), 2))->toBe(round($k['fek'], 2));
});

it('B3g mit B2-Routing: Gericht ohne eigenen Posten nimmt die Team-Vorgabe „Posten für Gerichte“; fremder Posten zählt nicht', function () {
    $p = spec83Posten($this, $this->rootTeam, $this->gericht, true);
    $this->gericht->update(['default_station_id' => null]);
    app(TeamSettingsService::class)->update($this->rootTeam, ['gerichte_station_id' => $p['entremetier']->id]);
    // Pickle trägt einen Posten eines FREMDEN Teams → wie ohne Posten (Regel aus ProductionOrderService::standardPosten)
    $fremd = \Platform\FoodAlchemist\Models\FoodAlchemistProductionStation::create(['team_id' => $this->rootTeam->id + 999, 'slug' => 'fremd', 'name' => 'Fremd']);
    FoodAlchemistRecipe::where('name', 'Pickle: Perlzwiebel')->update(['default_station_id' => $fremd->id]);

    $a = collect($this->times->calculateForBatches($this->rootTeam, $this->gericht->fresh(), 100)['bloecke']['anrichten']['aufteilung'])
        ->keyBy(fn ($t) => $t['station_id'] ?? 0);

    // Saucier: Fleisch, Jus, Chip (3) · Entremetier: Püree, Blumenkohl + Pickle (fremd) + Microgreens (GP) über die Team-Vorgabe (4)
    expect($a[$p['saucier']->id]['komponenten'])->toBe(3)->and($a[$p['entremetier']->id]['komponenten'])->toBe(4)
        ->and($a->has(0))->toBeFalse()->and($a->has($fremd->id))->toBeFalse();
});
