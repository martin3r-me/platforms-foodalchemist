<?php

use Platform\FoodAlchemist\Enums\SignalTyp;
use Platform\FoodAlchemist\Models\FoodAlchemistDishClass;
use Platform\FoodAlchemist\Models\FoodAlchemistDishMainGroup;
use Platform\FoodAlchemist\Models\FoodAlchemistMarkupClass;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistServierform;
use Platform\FoodAlchemist\Models\FoodAlchemistSignal;
use Platform\FoodAlchemist\Services\RecipeOneShotService;
use Platform\FoodAlchemist\Services\DarreichungService;
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
        'code' => 'ALC', 'label' => 'A la Carte', 'class_factor_pct' => 100,
        'vat_profile_key' => 'regulaer', 'raw_markup_pct' => 300, 'vat_rate' => 19, 'formula_type' => 'aufschlag',
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

    // Ziel-WE-Fallback 30 % = Basissatz 3,333; 2,40 € MEK × Faktor = 8,00 €.
    expect($w['sales_net'])->toBe(8.0)
        ->and($w['calculated_sales_net'])->toBe(8.0)
        ->and($w['price_mode'])->toBe('auto')
        ->and($w['price_source'])->toBe('ziel_we_fallback')
        ->and($w['ek_pro_portion'])->toBe(2.4)
        ->and($w['wareneinsatz_pct'])->toBe(30.0)
        ->and($w['ziel_pct'])->toBe(30.0)
        ->and($w['ampel'])->toBe('gruen')
        ->and($w['signal'])->toBeFalse()
        ->and($w['vorlaeufig'])->toBeFalse()
        ->and($w['fehler'])->toBeNull();

    // Preis-Wahrheit liegt an der Standard-Darreichung, nicht am Rezept-Feld …
    $std = $r->fresh()->standardPresentation()->first();
    expect($std)->not->toBeNull()
        ->and((float) $std->sales_net)->toBe(8.0)
        ->and($std->price_mode)->toBe('auto')
        ->and((float) $r->fresh()->sales_net)->toBe(8.0);
});

it('L8a: eine fehlende Portionsgröße wird im Anreicherungs-Pass geschlossen und danach bepreist', function () {
    $r = ($this->gericht)(['sales_quantity_per_unit_g' => null, 'sales_unit_count' => 10]);

    $w = $this->svc->anreichern($this->rootTeam, $r)['wirtschaftlichkeit'];

    expect($w['portion_g'])->toBe(200.0)
        ->and($w['luecken'])->toBe([])
        ->and($w['sales_net'])->toBe(8.0)
        ->and($w['ampel'])->toBe('gruen')
        ->and($w['aufschlagsklasse'])->toBe('ALC');
});

it('L8a: ohne Preisklasse rechnet Auto neutral weiter und macht den Fallback sichtbar', function () {
    $r = ($this->gericht)(['dish_class_id' => null]);

    $w = $this->svc->anreichern($this->rootTeam, $r)['wirtschaftlichkeit'];

    expect($w['luecken'])->toBe([])
        ->and($w['aufschlagsklasse'])->toBeNull()
        ->and($w['portion_g'])->toBe(200.0)
        ->and($w['sales_net'])->toBe(8.0)
        ->and($w['price_warnings'])->toContain('Keine Preisklasse gesetzt: neutraler Klassenfaktor 100 % verwendet.');
});

it('L8a: Wareneinsatz über Ziel → Ampel + genau EIN Signal aus der bestehenden R2.1-Regel', function () {
    // Auto würde bei 20 % Ziel-WE auf 12,00 € steigen. Ein bewusst fixierter
    // Bestandspreis von 9,60 € bleibt stehen und löst deshalb die Warnung aus.
    app(\Platform\FoodAlchemist\Services\TeamSettingsService::class)
        ->update($this->rootTeam, ['target_food_cost_pct' => 20.0]);

    $r = ($this->gericht)();
    $standard = app(DarreichungService::class)->ensureStandard($this->rootTeam, $r->id, 'test');
    app(DarreichungService::class)->aktualisieren($this->rootTeam, $standard->id, [
        'price_mode' => 'fixed',
        'sales_net' => 9.60,
        'price_override_reason' => 'Bewusster Testpreis unter Auto-Vorschlag',
    ]);

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
        'code' => 'BAN', 'label' => 'Bankett', 'class_factor_pct' => 120,
        'vat_profile_key' => 'regulaer', 'raw_markup_pct' => 200, 'vat_rate' => 19, 'formula_type' => 'aufschlag',
    ]);
    $r = ($this->gericht)(['sales_quantity_per_unit_g' => 350, 'markup_class_id' => $eigene->id]);

    $w = $this->svc->anreichern($this->rootTeam, $r)['wirtschaftlichkeit'];

    // BAN wird nicht durch den Klasse-Default ALC ersetzt, die 350 g bleiben stehen.
    expect($w['portion_g'])->toBe(350.0)
        ->and($w['aufschlagsklasse'])->toBe('BAN')
        // 4,20 € MEK × 3,333 Basissatz × 120 % Klassenfaktor = 16,80 €.
        ->and($w['sales_net'])->toBe(16.8);
});

it('L8a: teil-unbepreiste Zutaten machen den VK vorläufig', function () {
    $r = ($this->gericht)(['ek_n_ingredients_priced' => 1]);       // 1 von 3 bepreist

    $w = $this->svc->anreichern($this->rootTeam, $r)['wirtschaftlichkeit'];

    expect($w['vorlaeufig'])->toBeTrue()
        ->and($w['sales_net'])->toBe(8.0);                          // gerechnet wird trotzdem
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

it('L8b: fehlendes Standard-Servierform-Vokabular heilt sich selbst und erzeugt die Preis-Wahrheit', function () {
    FoodAlchemistServierform::where('code', 'unbestimmt')->forceDelete();

    $recipe = ($this->gericht)();
    $w = $this->svc->anreichern($this->rootTeam, $recipe)['wirtschaftlichkeit'];

    expect($w['luecken'])->toBe([])
        ->and($w['sales_net'])->toBe(8.0)
        ->and($recipe->fresh()->standardPresentation()->exists())->toBeTrue()
        ->and($w['fehler'])->toBeNull();          // eine Lücke, kein Fehlschlag
});

// ── L8b-2: der Ziel-VK wird gehalten, nicht durchgesetzt ────────────────────

it('L8b-2: ohne Vorgabe bleibt der Ziel-Abgleich leer (Bestandspfad unverändert)', function () {
    $w = $this->svc->anreichern($this->rootTeam, ($this->gericht)())['wirtschaftlichkeit'];

    expect($w['ziel_vk'])->toBeNull()
        ->and($w['ziel_delta_eur'])->toBeNull()
        ->and($w['ziel_wareneinsatz_pct'])->toBeNull()
        ->and($w['ziel_ampel'])->toBe('unbekannt')
        ->and($w['sales_net'])->toBe(8.0);
});

it('L8b-2: ein zu niedriger Ziel-VK wird NICHT durchgesetzt — gezeigt wird, was er kosten würde', function () {
    // Kalkuliert: 8,00 € bei 2,40 € MEK je Portion. Vorgabe 6,00 € würde 40 % WE bedeuten.
    $r = ($this->gericht)();

    $w = $this->svc->anreichern($this->rootTeam, $r, 6.0)['wirtschaftlichkeit'];

    expect($w['sales_net'])->toBe(8.0)            // NICHT auf 6,00 gedrückt (kein Solver)
        ->and($w['ziel_vk'])->toBe(6.0)
        ->and($w['ziel_delta_eur'])->toBe(2.0)    // Ist − Ziel, positiv = zu teuer
        ->and($w['ziel_wareneinsatz_pct'])->toBe(40.0)
        ->and($w['ziel_ampel'])->toBe('gelb')     // > 30 % Ziel, ≤ 1,5 × ⇒ Warnung
        ->and($w['ampel'])->toBe('gruen');        // die Ist-Ampel bleibt davon unberührt

    // Und der Preis am Objekt hat sich nicht bewegt — die Vorgabe wird nirgends geschrieben.
    expect((float) $r->fresh()->sales_net)->toBe(8.0);
});

it('L8b-2: liegt der kalkulierte VK unter dem Ziel, ist das Ziel tragfähig (negatives Delta)', function () {
    $w = $this->svc->anreichern($this->rootTeam, ($this->gericht)(), 12.0)['wirtschaftlichkeit'];

    expect($w['ziel_delta_eur'])->toBe(-4.0)
        ->and($w['ziel_wareneinsatz_pct'])->toBe(20.0)   // 2,40 / 12,00
        ->and($w['ziel_ampel'])->toBe('gruen');
});

it('L8b-2: auch ohne Preisklasse bleibt der neutrale Auto-Vorschlag mit Ziel vergleichbar', function () {
    $w = $this->svc->anreichern($this->rootTeam, ($this->gericht)(['dish_class_id' => null]), 8.5)['wirtschaftlichkeit'];

    expect($w['sales_net'])->toBe(8.0)
        ->and($w['ziel_vk'])->toBe(8.5)
        ->and($w['ziel_delta_eur'])->toBe(-0.5)
        ->and($w['luecken'])->toBe([]);
});

it('L8b-2: eine nicht-positive Vorgabe ist keine Vorgabe (Schutz im Nenner)', function () {
    $w = $this->svc->anreichern($this->rootTeam, ($this->gericht)(), 0.0)['wirtschaftlichkeit'];

    expect($w['ziel_vk'])->toBeNull()
        ->and($w['ziel_wareneinsatz_pct'])->toBeNull();
});
