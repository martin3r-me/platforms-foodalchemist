<?php

use Platform\FoodAlchemist\Enums\SignalTyp;
use Platform\FoodAlchemist\Models\FoodAlchemistDishClass;
use Platform\FoodAlchemist\Models\FoodAlchemistDishMainGroup;
use Platform\FoodAlchemist\Models\FoodAlchemistMarkupClass;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistServierform;
use Platform\FoodAlchemist\Models\FoodAlchemistSignal;
use Platform\FoodAlchemist\Services\RecipeOneShotService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 03 L8a — das Wirtschaftlichkeits-Glied der One-Shot-Kaskade.
 *
 * Zu beweisen ist, dass ein per KI erstelltes Gericht die Kaskade BEPREIST verlässt:
 * Portionsgröße, Aufschlagsklasse und Standard-Darreichung werden geschlossen, sofern
 * sie fehlen — und nur dann (GL-07) —, der VK entsteht danach in der bestehenden
 * Preis-Maschine (`price_mode='auto'`, kein Auto-Publish), und der Food-Cost wird
 * gegen das Team-Ziel gehalten. Fehlt eine Vorbedingung, ist das eine benannte
 * Lücke im Ergebnis, kein stiller Null-Preis.
 *
 * Alle vier VK-Anreicherungsfelder sind in den Fixtures gesetzt: die Schrittfolge
 * fällt damit auf [] und kein Provider wird gebraucht — geprüft wird hier das
 * Wirtschaftlichkeits-Glied, nicht der Anreicherungs-Pass (der hat RecipeOneShotTest).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    config(['foodalchemist.ai.provider' => 'fake', 'foodalchemist.ai.backoff' => []]);
    $this->svc = app(RecipeOneShotService::class);

    FoodAlchemistServierform::firstOrCreate(['code' => 'unbestimmt', 'team_id' => $this->rootTeam->id], ['label' => 'Unbestimmt']);

    $this->ak = FoodAlchemistMarkupClass::create([
        'code' => 'ALC', 'label' => 'A la Carte', 'raw_markup_pct' => 300, 'vat_rate' => 19, 'formula_type' => 'aufschlag',
    ]);
    $hg = FoodAlchemistDishMainGroup::create(['code' => 'TEL', 'label' => 'Tellergericht']);
    $this->klasse = FoodAlchemistDishClass::create([
        'dish_main_group_id' => $hg->id, 'code' => 'TEL-OMN', 'label' => 'Teller omnivor',
        'diet_form' => 'omnivor', 'default_markup_class_id' => $this->ak->id,
    ]);

    // So sieht ein Gericht am Ende des Anreicherungs-Passes aus: alle Textfelder stehen,
    // die Klasse ist gesetzt, der EK ist gerechnet — nur wirtschaftlich ist es leer.
    $this->gericht = fn (array $attr = []) => FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'l8-' . bin2hex(random_bytes(4)),
        'name' => 'Rinderrücken mit Jus', 'status' => 'draft', 'is_sales_recipe' => true,
        'description' => 'Kurzgebraten.', 'sales_wording_standard' => 'Zarter Rinderrücken',
        'plating_text' => 'Mittig anrichten.', 'dish_class_id' => $this->klasse->id,
        'yield_kg' => 2.0, 'sales_quantity_per_unit_g' => 200,
        'ek_per_kg_eur' => 12.0, 'ek_total_eur' => 24.0,
        'ek_n_ingredients_total' => 3, 'ek_n_ingredients_priced' => 3,
        ...$attr,
    ]);
});

it('L8a: der One-Shot-Roundtrip endet bepreist — Portion + AK + Standard-Darreichung + VK + W%', function () {
    $r = ($this->gericht)();

    $w = $this->svc->anreichern($this->rootTeam, $r)['wirtschaftlichkeit'];

    // Portion war gesetzt, AK kommt über den Klasse-Default (Klasse-vor-HG).
    expect($w['portion_g'])->toBe(200.0)
        ->and($w['aufschlagsklasse'])->toBe('ALC')
        ->and($w['luecken'])->toBe([]);

    // VK = EK/Portion (12 €/kg × 200 g = 2,40 €) × (1 + 300 %) = 9,60 €
    expect($w['sales_net'])->toBe(9.6)
        ->and($w['ek_pro_portion'])->toBe(2.4)
        // W% = 2,40 / 9,60 = 25 % → unter dem 30 %-Default ⇒ grün, kein Signal
        ->and($w['wareneinsatz_pct'])->toBe(25.0)
        ->and($w['ziel_pct'])->toBe(30.0)
        ->and($w['ampel'])->toBe('gruen')
        ->and($w['signal'])->toBeFalse()
        ->and($w['vorlaeufig'])->toBeFalse()
        ->and($w['fehler'])->toBeNull();

    // Preis-Wahrheit liegt an der Standard-Darreichung, nicht am Rezept-Feld …
    $std = $r->fresh()->standardPresentation()->first();
    expect($std)->not->toBeNull()
        ->and((float) $std->sales_net)->toBe(9.6)
        // … und bleibt überschreibbar: kein Auto-Publish, nur ein Auto-Vorschlag.
        ->and($std->price_mode)->toBe('auto')
        ->and((float) $r->fresh()->sales_net)->toBe(9.6);
});

it('L8a: ohne Portionsgröße gibt es keinen Auto-VK — sie wird benannt, nicht aus dem Yield geraten', function () {
    // yield_kg + sales_unit_count wären „ableitbar" (2 kg / 10 = 200 g). Genau das
    // passiert NICHT: die Darreichung multipliziert mit derselben Anzahl wieder hoch
    // (V-041), der VK wäre der Chargenpreis statt des Portionspreises.
    $r = ($this->gericht)(['sales_quantity_per_unit_g' => null, 'sales_unit_count' => 10]);

    $w = $this->svc->anreichern($this->rootTeam, $r)['wirtschaftlichkeit'];

    expect($w['portion_g'])->toBeNull()
        ->and($w['luecken'])->toBe(['portion'])
        ->and($w['sales_net'])->toBeNull()
        ->and($w['ampel'])->toBe('unbekannt')
        // Die Aufschlagsklasse steht trotzdem — die Lücke ist genau eine, nicht zwei.
        ->and($w['aufschlagsklasse'])->toBe('ALC');
});

it('L8a: fehlt auch die Aufschlagsklasse (keine Klasse, keine HG), wird sie benannt statt geraten', function () {
    $r = ($this->gericht)(['dish_class_id' => null]);

    $w = $this->svc->anreichern($this->rootTeam, $r)['wirtschaftlichkeit'];

    expect($w['luecken'])->toBe(['aufschlagsklasse'])
        ->and($w['aufschlagsklasse'])->toBeNull()
        ->and($w['portion_g'])->toBe(200.0)                        // Portion wurde trotzdem gesetzt
        ->and($w['sales_net'])->toBeNull();                        // ohne Aufschlag kein Vorschlag
});

it('L8a: Wareneinsatz über Ziel → Ampel + genau EIN Signal aus der bestehenden R2.1-Regel', function () {
    // Ein teurerer EK verschiebt die Quote NICHT (sie ist bei Cost-plus rein
    // aufschlag-getrieben: 1 / (1 + 300 %) = 25 %). Der Ausreißer entsteht erst
    // gegen ein engeres Team-Ziel — genau der reale Fall „Aufschlagsklasse passt
    // nicht zum Food-Cost-Ziel".
    app(\Platform\FoodAlchemist\Services\TeamSettingsService::class)
        ->update($this->rootTeam, ['target_food_cost_pct' => 20.0]);

    $r = ($this->gericht)();

    $w = $this->svc->anreichern($this->rootTeam, $r)['wirtschaftlichkeit'];

    expect($w['wareneinsatz_pct'])->toBe(25.0)
        ->and($w['ziel_pct'])->toBe(20.0)
        ->and($w['ampel'])->toBe('gelb')                            // ≤ 1,5 × Ziel ⇒ Warnung, nicht kritisch
        ->and($w['signal'])->toBeTrue();

    $signale = FoodAlchemistSignal::where('team_id', $this->rootTeam->id)
        ->where('type', SignalTyp::WareneinsatzUeberZiel->value)->get();
    expect($signale)->toHaveCount(1)
        ->and($signale[0]->ref_id)->toBe($r->id)
        ->and($signale[0]->dedup_key)->toBe('we-quote-recipe-' . $r->id);
});

it('L8a: gepflegte Portion und gesetzte AK werden nicht überschrieben (GL-07)', function () {
    $eigene = FoodAlchemistMarkupClass::create([
        'code' => 'BAN', 'label' => 'Bankett', 'raw_markup_pct' => 200, 'vat_rate' => 19, 'formula_type' => 'aufschlag',
    ]);
    $r = ($this->gericht)(['sales_quantity_per_unit_g' => 350, 'markup_class_id' => $eigene->id]);

    $w = $this->svc->anreichern($this->rootTeam, $r)['wirtschaftlichkeit'];

    // BAN wird nicht durch den Klasse-Default ALC ersetzt, die 350 g bleiben stehen.
    expect($w['portion_g'])->toBe(350.0)
        ->and($w['aufschlagsklasse'])->toBe('BAN')
        // 12 €/kg × 350 g = 4,20 € × (1 + 200 %) = 12,60 €
        ->and($w['sales_net'])->toBe(12.6);
});

it('L8a: teil-unbepreiste Zutaten machen den VK vorläufig', function () {
    $r = ($this->gericht)(['ek_n_ingredients_priced' => 1]);       // 1 von 3 bepreist

    $w = $this->svc->anreichern($this->rootTeam, $r)['wirtschaftlichkeit'];

    expect($w['vorlaeufig'])->toBeTrue()
        ->and($w['sales_net'])->toBe(9.6);                          // gerechnet wird trotzdem
});

it('L8a: ein Basisrezept hat kein Wirtschaftlichkeits-Glied', function () {
    $hg = \Platform\FoodAlchemist\Models\FoodAlchemistRecipeMainGroup::create([
        'team_id' => $this->rootTeam->id, 'code' => 'FND', 'label' => 'Fonds & Saucen',
    ]);
    $kat = \Platform\FoodAlchemist\Models\FoodAlchemistRecipeCategory::create([
        'team_id' => $this->rootTeam->id, 'main_group_id' => $hg->id, 'code' => 'JUS', 'label' => 'Jus',
    ]);
    $r = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'l8-basis', 'name' => 'Jus: Kalb',
        'status' => 'draft', 'is_sales_recipe' => false,
        'description' => 'Da.', 'category_id' => $kat->id, 'taste_direction' => 'herzhaft',
        'yield_kg' => 1.0, 'ek_per_kg_eur' => 8.0,
    ]);

    $erg = $this->svc->anreichern($this->rootTeam, $r);

    expect($erg['wirtschaftlichkeit'])->toBeNull()
        ->and($r->fresh()->standardPresentation()->first())->toBeNull();
});

// ── L8b: die dritte Vorbedingung wird auch benannt ──────────────────────────

it('L8b: fehlt die Standard-Darreichung, ist auch DAS eine benannte Lücke (vorher stumm)', function () {
    // `ensureStandard` darf ohne Servierform-Vokabular nichts anlegen → keine
    // Preis-Zeile, kein VK. Ohne die Lücke zeigte die Generator-Fläche in diesem
    // Fall weder Preis noch Grund.
    FoodAlchemistServierform::where('code', 'unbestimmt')->forceDelete();

    $w = $this->svc->anreichern($this->rootTeam, ($this->gericht)())['wirtschaftlichkeit'];

    expect($w['luecken'])->toContain('darreichung')
        ->and($w['sales_net'])->toBeNull()
        ->and($w['fehler'])->toBeNull();          // eine Lücke, kein Fehlschlag
});

// ── L8b-2: der Ziel-VK wird gehalten, nicht durchgesetzt ────────────────────

it('L8b-2: ohne Vorgabe bleibt der Ziel-Abgleich leer (Bestandspfad unverändert)', function () {
    $w = $this->svc->anreichern($this->rootTeam, ($this->gericht)())['wirtschaftlichkeit'];

    expect($w['ziel_vk'])->toBeNull()
        ->and($w['ziel_delta_eur'])->toBeNull()
        ->and($w['ziel_wareneinsatz_pct'])->toBeNull()
        ->and($w['ziel_ampel'])->toBe('unbekannt')
        ->and($w['sales_net'])->toBe(9.6);        // der Rest rechnet wie bisher
});

it('L8b-2: ein zu niedriger Ziel-VK wird NICHT durchgesetzt — gezeigt wird, was er kosten würde', function () {
    // Kalkuliert: 9,60 € bei 2,40 € EK je Portion (W 25 %). Vorgabe 6,00 € ⇒ das
    // Gericht ist zum Wunschpreis nicht zu diesem Aufschlag machbar: der Wareneinsatz
    // stiege auf 40 %. Genau das ist die Aussage — der Preis bleibt bei 9,60 €.
    $r = ($this->gericht)();

    $w = $this->svc->anreichern($this->rootTeam, $r, 6.0)['wirtschaftlichkeit'];

    expect($w['sales_net'])->toBe(9.6)            // NICHT auf 6,00 gedrückt (kein Solver)
        ->and($w['ziel_vk'])->toBe(6.0)
        ->and($w['ziel_delta_eur'])->toBe(3.6)    // Ist − Ziel, positiv = zu teuer
        ->and($w['ziel_wareneinsatz_pct'])->toBe(40.0)
        ->and($w['ziel_ampel'])->toBe('gelb')     // > 30 % Ziel, ≤ 1,5 × ⇒ Warnung
        ->and($w['ampel'])->toBe('gruen');        // die Ist-Ampel bleibt davon unberührt

    // Und der Preis am Objekt hat sich nicht bewegt — die Vorgabe wird nirgends geschrieben.
    expect((float) $r->fresh()->sales_net)->toBe(9.6);
});

it('L8b-2: liegt der kalkulierte VK unter dem Ziel, ist das Ziel tragfähig (negatives Delta)', function () {
    $w = $this->svc->anreichern($this->rootTeam, ($this->gericht)(), 12.0)['wirtschaftlichkeit'];

    expect($w['ziel_delta_eur'])->toBe(-2.4)
        ->and($w['ziel_wareneinsatz_pct'])->toBe(20.0)   // 2,40 / 12,00
        ->and($w['ziel_ampel'])->toBe('gruen');
});

it('L8b-2: ohne kalkulierten VK bleibt das Delta leer — die Vorgabe steht trotzdem', function () {
    // Keine Aufschlagsklasse ⇒ kein Ist-Preis. Der Abgleich „was würde 8,50 € bedeuten"
    // hängt nur am EK je Portion und ist deshalb gerade dann nützlich.
    $w = $this->svc->anreichern($this->rootTeam, ($this->gericht)(['dish_class_id' => null]), 8.5)['wirtschaftlichkeit'];

    expect($w['sales_net'])->toBeNull()
        ->and($w['ziel_vk'])->toBe(8.5)
        ->and($w['ziel_delta_eur'])->toBeNull()
        ->and($w['luecken'])->toBe(['aufschlagsklasse']);
});

it('L8b-2: eine nicht-positive Vorgabe ist keine Vorgabe (Schutz im Nenner)', function () {
    $w = $this->svc->anreichern($this->rootTeam, ($this->gericht)(), 0.0)['wirtschaftlichkeit'];

    expect($w['ziel_vk'])->toBeNull()
        ->and($w['ziel_wareneinsatz_pct'])->toBeNull();
});
