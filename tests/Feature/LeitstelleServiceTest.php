<?php

use Platform\FoodAlchemist\Models\FoodAlchemistDishClass;
use Platform\FoodAlchemist\Models\FoodAlchemistDishMainGroup;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistTargetGroup;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\FoodbookService;
use Platform\FoodAlchemist\Services\LeitstelleService;
use Platform\FoodAlchemist\Services\PlanningFrameService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 19 E5.1 — LeitstelleService: checkliste (7 abgeleitete Schritte),
 * kapitelStand (Rail-Sicht), speisenBaum (heterogener Baum).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->fbSvc = app(FoodbookService::class);
    $this->frames = app(PlanningFrameService::class);
    $this->svc = app(LeitstelleService::class);

    $g = FoodAlchemistVocabEinheit::create(['team_id' => $this->rootTeam->id, 'slug' => 'g', 'display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1]);
    $hg = FoodAlchemistDishMainGroup::create(['team_id' => $this->rootTeam->id, 'code' => 'HG', 'label' => 'Hauptgericht']);
    $klasse = FoodAlchemistDishClass::create(['team_id' => $this->rootTeam->id, 'dish_main_group_id' => $hg->id, 'code' => 'HG_N', 'label' => 'Neutral', 'diet_form' => 'neutral']);
    $gp = $this->makeGp($this->rootTeam, 'Tomate');
    $this->dish = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'r1', 'name' => 'HG: Tomaten-Teller', 'status' => 'approved',
        'is_sales_recipe' => true, 'sales_net' => 12.00, 'dish_class_id' => $klasse->id,
    ]);
    $this->dish->ingredients()->create(['team_id' => $this->rootTeam->id, 'position' => 0, 'gp_id' => $gp->id, 'raw_text' => 'Tomate', 'quantity' => 100, 'unit_vocab_id' => $g->id]);

    $this->fb = $this->fbSvc->create($this->rootTeam, ['label' => 'Leitstelle-FB']);
});

it('checkliste eines leeren Foodbooks liefert 7 Schritte in Reihenfolge, alle offen', function () {
    $steps = $this->svc->checkliste($this->rootTeam, $this->fb);

    expect($steps)->toHaveCount(7)
        ->and(array_column($steps, 'key'))->toBe(['bedarf', 'struktur', 'tiefe', 'kapitel_aufbau', 'kreativ', 'anlegen', 'preise'])
        ->and(array_column($steps, 'nr'))->toBe([1, 2, 3, 4, 5, 6, 7]);

    // Alle Schritte offen; jeder trägt ein Sprungziel (tab + anker).
    foreach ($steps as $s) {
        expect($s['status'])->toBe('offen')
            ->and($s['tab'])->toBeString()->not->toBe('')
            ->and($s['anker'])->toBeString()->not->toBe('');
    }
    // Freigabe/Versand ist NICHT in der Checkliste (UX 1).
    expect(array_column($steps, 'key'))->not->toContain('freigabe');
});

it('Bedarf wird teil bei nur Gästezahl und erledigt mit zusätzlicher Default-Dimension', function () {
    $this->fbSvc->update($this->rootTeam, $this->fb->id, ['personen' => 100]);
    $bedarf = collect($this->svc->checkliste($this->rootTeam, $this->fb))->firstWhere('key', 'bedarf');
    expect($bedarf['status'])->toBe('teil');

    $zg = FoodAlchemistTargetGroup::create(['team_id' => $this->rootTeam->id, 'name' => 'Bankett', 'sort_order' => 1]);
    $this->fbSvc->toggleZielgruppe($this->rootTeam, $this->fb->id, $zg->id);
    $bedarf = collect($this->svc->checkliste($this->rootTeam, $this->fb))->firstWhere('key', 'bedarf');
    expect($bedarf['status'])->toBe('erledigt');
});

it('Struktur/Tiefe/Kapitel-Aufbau/Anlegen/Preise schalten mit dem Bestand hoch', function () {
    // Struktur: ein Top-Kapitel → erledigt; Tiefe flach → teil.
    $top = $this->fbSvc->addKapitel($this->rootTeam, $this->fb->id, ['title' => 'Vorspeisen']);
    $steps = collect($this->svc->checkliste($this->rootTeam, $this->fb))->keyBy('key');
    expect($steps['struktur']['status'])->toBe('erledigt')
        ->and($steps['tiefe']['status'])->toBe('teil')                 // flach
        ->and($steps['kapitel_aufbau']['status'])->toBe('offen')       // keine Ziele
        ->and($steps['anlegen']['status'])->toBe('offen');             // kein Inhalt

    // Tiefe erledigt mit Unterkapitel (n-tief).
    $this->fbSvc->addKapitel($this->rootTeam, $this->fb->id, ['title' => 'Kalte VS'], $top->id);
    // Kapitel-Aufbau: beide Kapitel bekommen ein Ziel → erledigt.
    $this->fbSvc->updateKapitel($this->rootTeam, $top->id, ['target_count' => 3]);
    $kind = $this->fb->refresh()->chapters->firstWhere('parent_id', $top->id);
    $this->fbSvc->updateKapitel($this->rootTeam, $kind->id, ['niveau' => 'gehoben']);

    $steps = collect($this->svc->checkliste($this->rootTeam, $this->fb))->keyBy('key');
    expect($steps['tiefe']['status'])->toBe('erledigt')
        ->and($steps['kapitel_aufbau']['status'])->toBe('erledigt');

    // Anlegen + Preise: recipe_ref-Block (bepreist, sales_net 12) an EIN Kapitel.
    $this->fbSvc->addBlock($this->rootTeam, $top->id, ['type' => 'recipe_ref', 'sales_recipe_id' => $this->dish->id]);
    $steps = collect($this->svc->checkliste($this->rootTeam, $this->fb))->keyBy('key');
    // 2 Kapitel, 1 mit Inhalt → anlegen teil; das befüllte ist bepreist → preise erledigt (Bezug: befüllte Kapitel).
    expect($steps['anlegen']['status'])->toBe('teil')
        ->and($steps['preise']['status'])->toBe('erledigt');
});

it('kapitelStand liefert Ziele, Aggregat, WE-Ampel, Inhalts-Zähler und Anlage-Stand', function () {
    $k = $this->fbSvc->addKapitel($this->rootTeam, $this->fb->id, ['title' => 'Hauptgänge']);
    $this->fbSvc->updateKapitel($this->rootTeam, $k->id, ['target_count' => 2, 'price_anchor' => 25.0]);
    $this->fbSvc->addBlock($this->rootTeam, $k->id, ['type' => 'recipe_ref', 'sales_recipe_id' => $this->dish->id]);

    $stand = $this->svc->kapitelStand($this->rootTeam, $k->refresh());

    expect($stand['kapitel_id'])->toBe($k->id)
        ->and($stand['ziele']['target_count'])->toBe(2)
        ->and($stand['ziele']['price_anchor'])->toBe(25.0)
        ->and($stand['aggregat'])->toHaveKey('vk_pro_person')
        ->and($stand['wareneinsatz'])->toHaveKey('status')
        ->and($stand['inhalt']['einzel'])->toBe(1)
        ->and($stand['inhalt']['pakete'])->toBe(0)
        ->and($stand['inhalt']['ideen'])->toBe(0)        // dish_ideas (M4) noch nicht da → schema-guarded 0
        ->and($stand['released'])->toBeFalse();
});

it('speisenBaum trennt Paket (concept_ref, €/Gast) und Einzelgericht (recipe_ref, €/Pos)', function () {
    // Kapitel mit Einzelgericht.
    $k = $this->fbSvc->addKapitel($this->rootTeam, $this->fb->id, ['title' => 'Menü']);
    $this->fbSvc->addBlock($this->rootTeam, $k->id, ['type' => 'recipe_ref', 'sales_recipe_id' => $this->dish->id]);

    // Zweites Kapitel mit Paket über den Gerüst-/Übernahme-Weg (legt Konzept + concept_ref an).
    $frame = $this->frames->frameFor($this->rootTeam, 'foodbook', $this->fb->id);
    $this->frames->addSlot($this->rootTeam, $frame, ['label' => 'Paket-Kapitel', 'slot_type' => 'gang', 'target_count' => 1]);
    $this->fbSvc->strukturAusGeruest($this->rootTeam, $this->fb->id);
    $slot = $frame->refresh()->slots->firstWhere('label', 'Paket-Kapitel');
    $this->fbSvc->uebernehmeVorschlag($this->rootTeam, $this->fb->id, $slot->id, $this->dish->id);

    $baum = collect($this->svc->speisenBaum($this->rootTeam, $this->fb))->keyBy('titel');

    $einzel = collect($baum['Menü']['positionen'])->firstWhere('art', 'einzel');
    expect($einzel)->not->toBeNull()
        ->and($einzel['label'])->toBe('HG: Tomaten-Teller')
        ->and($einzel['preis_einheit'])->toBe('position')
        ->and($einzel['preis'])->toBe(12.0)
        ->and($einzel['status'])->toBe('bepreist');

    $paket = collect($baum['Paket-Kapitel']['positionen'])->firstWhere('art', 'paket');
    expect($paket)->not->toBeNull()
        ->and($paket['preis_einheit'])->toBe('gast')
        ->and($paket['kinder'])->toHaveCount(1);   // ein Gericht im Konzept-Slot
});

// E5.3: kapitelMatrix speist die Fortschritt-Matrix + Kalkulation-WE-Panel der Rail.
it('kapitelMatrix liefert je Kapitel Ziele-/Inhalts-/Preis-Status + WE-Ampel', function () {
    $mit = $this->fbSvc->addKapitel($this->rootTeam, $this->fb->id, ['title' => 'Hauptgänge']);
    $this->fbSvc->updateKapitel($this->rootTeam, $mit->id, ['target_count' => 2]);
    $this->fbSvc->addBlock($this->rootTeam, $mit->id, ['type' => 'recipe_ref', 'sales_recipe_id' => $this->dish->id]);
    $leer = $this->fbSvc->addKapitel($this->rootTeam, $this->fb->id, ['title' => 'Leer']);

    $matrix = collect($this->svc->kapitelMatrix($this->rootTeam, $this->fb->refresh()))->keyBy('titel');

    expect($matrix)->toHaveCount(2);

    $m = $matrix['Hauptgänge'];
    expect($m['kapitel_id'])->toBe($mit->id)
        ->and($m['depth'])->toBe(1)
        ->and($m['hat_ziele'])->toBeTrue()
        ->and($m['positionen'])->toBe(1)
        ->and($m['hat_inhalt'])->toBeTrue()
        ->and($m['bepreist'])->toBeTrue()             // sales_net 12 → Per-Person-VK > 0
        ->and($m['released'])->toBeFalse()
        ->and($m['wareneinsatz'])->toHaveKey('status');

    $l = $matrix['Leer'];
    expect($l['hat_ziele'])->toBeFalse()
        ->and($l['positionen'])->toBe(0)
        ->and($l['hat_inhalt'])->toBeFalse()
        ->and($l['bepreist'])->toBeFalse()
        ->and($l['wareneinsatz']['status'])->toBe('unbekannt');   // kein VK → unbekannt
});
