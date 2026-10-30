<?php

use Platform\Core\Contracts\ToolContext;
use Platform\FoodAlchemist\Models\FoodAlchemistAngebot;
use Platform\FoodAlchemist\Models\FoodAlchemistEinsatzmoment;
use Platform\FoodAlchemist\Models\FoodAlchemistEventtyp;
use Platform\FoodAlchemist\Models\FoodAlchemistFormat;
use Platform\FoodAlchemist\Models\FoodAlchemistPaket;
use Platform\FoodAlchemist\Models\FoodAlchemistSaison;
use Platform\FoodAlchemist\Models\FoodAlchemistServierform;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeisekarteRubrik;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplanEintrag;
use Platform\FoodAlchemist\Services\SpeisekarteService;
use Platform\FoodAlchemist\Services\SpeiseplanService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Platform\FoodAlchemist\Tools\AngeboteListTool;
use Platform\FoodAlchemist\Tools\ConceptsListTool;
use Platform\FoodAlchemist\Tools\ConceptsSearchTool;
use Platform\FoodAlchemist\Tools\FoodbooksListTool;
use Platform\FoodAlchemist\Tools\FormatsListTool;
use Platform\FoodAlchemist\Tools\PaketeListTool;
use Platform\FoodAlchemist\Tools\SpeisekartenListTool;
use Platform\FoodAlchemist\Tools\SpeiseplaeneListTool;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 91 PR 5 (C14, B7, D19): concepts.LIST/SEARCH und formats.LIST filtern wie Concepter und Format-Browser (Suche, Klasse,
 * Kategorie, Phase, vier Facetten, kind=paket) und liefern Eventtyp, Servierform, Preis und Wareneinsatz; die übrigen Listen
 * (Foodbooks, Speisekarten, Speisepläne, Pakete, Angebote) blättern mit page und liefern die Spalte ihres Browsers.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    config(['foodalchemist.semantic_search.enabled' => false]);
    $this->ctx = new ToolContext($this->user, $this->rootTeam);
    $t = $this->rootTeam->id;
    $this->buffet = FoodAlchemistServierform::create(['team_id' => $t, 'code' => 'buffet', 'label' => 'Buffet']);
    $this->hochzeit = FoodAlchemistEventtyp::create(['team_id' => $t, 'name' => 'Hochzeit']);
    $this->lunch = FoodAlchemistEinsatzmoment::create(['team_id' => $t, 'name' => 'Lunch']);
    $this->sommer = FoodAlchemistSaison::create(['team_id' => $t, 'name' => 'Sommer']);
});

it('C14 · concepts.LIST filtert wie der Concepter: q, klasse, phase, Facetten, kind=paket', function () {
    $fest = $this->makeConcept($this->rootTeam, 'Sommerfest Buffet', ['class' => 'buffet', 'status' => 'active', 'phase' => 'kalkulation',
        'serving_form_id' => $this->buffet->id, 'event_type_id' => $this->hochzeit->id]);
    $fest->serviceMoments()->sync([$this->lunch->id]);
    $fest->seasons()->sync([$this->sommer->id]);
    $this->makeConcept($this->rootTeam, 'Wintermenü', ['class' => 'menue', 'status' => 'draft']);
    $this->makeConcept($this->rootTeam, 'Fingerfood-Bündel', ['kind' => 'paket', 'class' => 'fingerfood', 'status' => 'active', 'serving_form_id' => $this->buffet->id]);
    $namen = fn (array $a) => array_column(app(ConceptsListTool::class)->execute($a, $this->ctx)->data['concepts'], 'name');

    expect($namen([]))->toBe(['Sommerfest Buffet', 'Wintermenü'])
        ->and($namen(['q' => 'sommerfest']))->toBe(['Sommerfest Buffet'])
        ->and($namen(['klasse' => 'menue']))->toBe(['Wintermenü'])
        ->and($namen(['phase' => 'kalkulation']))->toBe(['Sommerfest Buffet'])
        ->and($namen(['servierform' => $this->buffet->id]))->toBe(['Sommerfest Buffet'])
        ->and($namen(['eventtyp' => $this->hochzeit->id]))->toBe(['Sommerfest Buffet'])
        ->and($namen(['einsatzmoment' => $this->lunch->id]))->toBe(['Sommerfest Buffet'])
        ->and($namen(['saison' => $this->sommer->id]))->toBe(['Sommerfest Buffet'])
        ->and($namen(['kind' => 'paket']))->toBe(['Fingerfood-Bündel'])
        ->and($namen(['kind' => 'paket', 'servierform' => $this->buffet->id, 'q' => 'finger']))->toBe(['Fingerfood-Bündel'])
        ->and(array_column(app(ConceptsSearchTool::class)->execute(['q' => 'finger', 'kind' => 'paket'], $this->ctx)->data['concepts'], 'name'))->toBe(['Fingerfood-Bündel'])
        ->and(app(ConceptsSearchTool::class)->execute(['q' => 'finger'], $this->ctx)->data['concepts'])->toBe([]);
    expect(app(ConceptsListTool::class)->execute(['phase' => 'planung'], $this->ctx)->errorCode)->toBe('VALIDATION_ERROR')
        ->and(app(ConceptsListTool::class)->execute(['kind' => 'pakete'], $this->ctx)->errorCode)->toBe('VALIDATION_ERROR');
});

it('D19 · concepts.LIST liefert Eventtyp, Servierform, Preis, Wareneinsatz und auf Wunsch die Facetten-Werte', function () {
    $this->makeConcept($this->rootTeam, 'Sommerfest Buffet', ['status' => 'active', 'serving_form_id' => $this->buffet->id,
        'event_type_id' => $this->hochzeit->id, 'price_per_person_cache' => 40, 'ek_per_person_cache' => 12]);
    $r = app(ConceptsListTool::class)->execute(['facetten_werte' => true, 'gruppe' => 'x'], $this->ctx)->data;

    expect($r['concepts'][0])->toMatchArray(['kind' => 'konzept', 'eventtyp' => ['id' => $this->hochzeit->id, 'name' => 'Hochzeit'],
        'servierform' => ['id' => $this->buffet->id, 'name' => 'Buffet'], 'preis_pro_person' => 40.0, 'ek_pro_person' => 12.0, 'wareneinsatz_pct' => 30.0])
        ->and($r['facetten_werte']['servierform'])->toContain(['id' => $this->buffet->id, 'name' => 'Buffet'])
        ->and($r['facetten_werte']['saison'])->toContain(['id' => $this->sommer->id, 'name' => 'Sommer'])
        ->and($r['warnungen'][0])->toContain("'gruppe'")
        ->and(app(ConceptsListTool::class)->execute([], $this->ctx)->data)->not->toHaveKey('facetten_werte');
});

it('C14 + B7 · formats.LIST filtert nach q und Facetten, blättert und liefert Eventtyp/Servierform', function () {
    $t = $this->rootTeam->id;
    $corner = FoodAlchemistFormat::create(['team_id' => $t, 'name' => 'CHEFS.CORNER', 'consumer_name' => 'Chefs Corner', 'status' => 'active',
        'serving_form_id' => $this->buffet->id, 'event_type_id' => $this->hochzeit->id]);
    $corner->seasons()->sync([$this->sommer->id]);
    FoodAlchemistFormat::create(['team_id' => $t, 'name' => 'STREETFOOD.MARKET', 'status' => 'draft']);
    $namen = fn (array $a) => array_column(app(FormatsListTool::class)->execute($a, $this->ctx)->data['formats'], 'name');

    expect($namen(['q' => 'chefs']))->toBe(['CHEFS.CORNER'])
        ->and($namen(['servierform' => $this->buffet->id]))->toBe(['CHEFS.CORNER'])
        ->and($namen(['saison' => $this->sommer->id]))->toBe(['CHEFS.CORNER'])
        ->and($namen(['per_page' => 1, 'page' => 2]))->toBe(['STREETFOOD.MARKET'])
        ->and(app(FormatsListTool::class)->execute(['per_page' => 1], $this->ctx)->data)->toMatchArray(['total' => 2, 'last_page' => 2])
        ->and(app(FormatsListTool::class)->execute(['q' => 'chefs'], $this->ctx)->data['formats'][0])->toMatchArray([
            'eventtyp' => ['id' => $this->hochzeit->id, 'name' => 'Hochzeit'], 'servierform' => ['id' => $this->buffet->id, 'name' => 'Buffet']])
        ->and(app(FormatsListTool::class)->execute(['origin' => 'fremd'], $this->ctx)->errorCode)->toBe('VALIDATION_ERROR');
});

it('B7 + D19 · Foodbooks, Speisekarten, Speisepläne, Pakete und Angebote blättern mit page und liefern die Browser-Spalte', function () {
    $t = $this->rootTeam->id;
    $fb = $this->makeFoodbook($this->rootTeam, 'Foodbook Rathaus');
    $this->makeChapter($fb, ['title' => 'Fingerfood']);
    $this->makeFoodbook($this->rootTeam, 'Foodbook Zoo');
    $karte = app(SpeisekarteService::class)->create($this->rootTeam, ['name' => 'Abendkarte']);
    app(SpeisekarteService::class)->create($this->rootTeam, ['name' => 'Mittagskarte']);
    FoodAlchemistSpeisekarteRubrik::create(['team_id' => $t, 'menu_card_id' => $karte->id, 'title' => 'Hauptgänge', 'art' => 'speisen', 'position' => 1]);
    $plan = app(SpeiseplanService::class)->create($this->rootTeam, ['name' => 'Kantine Nord']);
    app(SpeiseplanService::class)->create($this->rootTeam, ['name' => 'Kantine Süd']);
    FoodAlchemistSpeiseplanEintrag::create(['team_id' => $t, 'menu_plan_id' => $plan->id, 'week' => 1, 'weekday' => 1, 'meal' => 'mittag', 'position' => 1]);
    $paket = FoodAlchemistPaket::create(['team_id' => $t, 'name' => 'Antipasti-Paket']);
    FoodAlchemistPaket::create(['team_id' => $t, 'name' => 'Dessert-Paket']);
    $paket->dishes()->create(['team_id' => $t, 'sales_recipe_id' => $this->makeRecipe($this->rootTeam, 'Vitello tonnato', ['is_sales_recipe' => true])->id, 'position' => 1]);
    FoodAlchemistAngebot::create(['team_id' => $t, 'name' => 'Sommerfest', 'status' => 'anfrage', 'personen' => 80, 'event_date' => '2027-06-12', 'total_price' => 3990]);
    FoodAlchemistAngebot::create(['team_id' => $t, 'name' => 'Weihnachtsfeier', 'status' => 'anfrage', 'personen' => 40]);
    $liste = fn (string $tool, string $schluessel, array $a) => app($tool)->execute($a, $this->ctx)->data[$schluessel];
    $erste = fn (string $tool, string $schluessel, array $a) => $liste($tool, $schluessel, $a)[0];

    expect($erste(FoodbooksListTool::class, 'foodbooks', ['q' => 'rathaus']))->toMatchArray(['label' => 'Foodbook Rathaus', 'kapitel' => 1])
        ->and(array_column($liste(FoodbooksListTool::class, 'foodbooks', ['per_page' => 1, 'page' => 2]), 'label'))->toBe(['Foodbook Zoo'])
        ->and(app(FoodbooksListTool::class)->execute(['per_page' => 1], $this->ctx)->data['last_page'])->toBe(2)
        ->and($erste(SpeisekartenListTool::class, 'speisekarten', ['q' => 'abend']))->toMatchArray(['name' => 'Abendkarte', 'rubriken' => 1])
        ->and(array_column($liste(SpeisekartenListTool::class, 'speisekarten', ['per_page' => 1, 'page' => 2]), 'name'))->toBe(['Mittagskarte'])
        ->and($erste(SpeiseplaeneListTool::class, 'speiseplaene', ['search' => 'nord']))->toMatchArray(['name' => 'Kantine Nord', 'eintraege' => 1])
        ->and(array_column($liste(SpeiseplaeneListTool::class, 'speiseplaene', ['per_page' => 1, 'page' => 2]), 'name'))->toBe(['Kantine Süd'])
        ->and($erste(PaketeListTool::class, 'pakete', ['q' => 'antipasti']))->toMatchArray(['name' => 'Antipasti-Paket', 'gerichte' => 1])
        ->and(array_column($liste(PaketeListTool::class, 'pakete', ['per_page' => 1, 'page' => 2]), 'name'))->toBe(['Dessert-Paket'])
        ->and($erste(AngeboteListTool::class, 'angebote', ['q' => 'sommer']))->toMatchArray(['name' => 'Sommerfest', 'datum' => '2027-06-12', 'summe' => 3990.0])
        ->and($erste(AngeboteListTool::class, 'angebote', ['q' => 'weihnacht']))->toMatchArray(['datum' => null, 'summe' => null]);
});
