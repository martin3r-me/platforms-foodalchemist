<?php

use Illuminate\Support\Carbon;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeStep;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplan;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplanEintrag;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplanLinie;
use Platform\FoodAlchemist\Services\AngebotService;
use Platform\FoodAlchemist\Services\HandbuchService;
use Platform\FoodAlchemist\Services\OfferCompositionService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 86 Teil B — die drei weiteren Quellen des Handbuchs (Speisekarte, Speiseplan, Angebot):
 * jeweils Gericht mit Zubereitung im Handbuch, kein Preis im Snapshot, öffentlicher Link.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->hb = app(HandbuchService::class);
    $this->team = $this->rootTeam;
    $this->gericht = function (string $name) {
        $dish = $this->makeRecipe($this->team, $name, ['is_sales_recipe' => true, 'sales_net' => 24.5]);
        FoodAlchemistRecipeStep::create([
            'team_id' => $this->team->id, 'recipe_id' => $dish->id, 'position' => 1, 'ebene' => 'produktion', 'text' => "Schritt für {$name}.",
        ]);

        return $dish;
    };
    $this->pruefe = function (string $type, int $id, string $name) {
        $res = $this->hb->publish($this->team, $type, $id, ['expires_at' => now()->addDays(20)->toDateString()]);
        $json = json_encode(\Platform\FoodAlchemist\Models\FoodAlchemistHandbuch::where('token', $res['token'])->firstOrFail()->snapshot_json);
        expect($json)->not->toContain('sales_net')->not->toContain('24.5');
        $this->get('/p/handbuch/' . $res['token'])->assertOk()->assertSee($name)->assertSee("Schritt für {$name}.")->assertDontSee('24,50');
    };
});

it('Speisekarte: Gericht-Position erscheint im Handbuch', function () {
    $registry = app(ToolRegistry::class);
    $kontext = new ToolContext($this->user, $this->team);
    $dish = ($this->gericht)('Zanderfilet Karte');
    $karteId = $registry->get('foodalchemist.speisekarten.POST')->execute(['name' => 'Abendkarte'], $kontext)->data['speisekarte']['id'];
    $rubrikId = $registry->get('foodalchemist.speisekarte_rubrik.POST')->execute([
        'speisekarte_id' => $karteId, 'title' => 'Fisch', 'consumer_title' => 'Aus dem Wasser', 'art' => 'speisen',
    ], $kontext)->data['rubrik']['id'];
    $registry->get('foodalchemist.speisekarte_positionen.POST')->execute([
        'rubrik_id' => $rubrikId, 'type' => 'gericht_ref', 'sales_recipe_id' => $dish->id,
    ], $kontext);

    ($this->pruefe)('speisekarte', $karteId, 'Zanderfilet Karte');
});

it('Speiseplan: Eintrag erscheint im Handbuch (Tagesabschnitt)', function () {
    $dish = ($this->gericht)('Rindergulasch Plan');
    $mo = Carbon::now()->startOfWeek(Carbon::MONDAY);
    $plan = FoodAlchemistSpeiseplan::create(['team_id' => $this->team->id, 'name' => 'Wochenplan', 'status' => 'draft', 'start_date' => $mo->format('Y-m-d')]);
    $line = FoodAlchemistSpeiseplanLinie::create(['team_id' => $this->team->id, 'menu_plan_id' => $plan->id, 'name' => 'Menü 1', 'sort_order' => 1]);
    FoodAlchemistSpeiseplanEintrag::create([
        'team_id' => $this->team->id, 'menu_plan_id' => $plan->id, 'line_id' => $line->id,
        'week' => 1, 'weekday' => 1, 'meal' => 'mittag', 'entry_date' => $mo->format('Y-m-d'), 'sales_recipe_id' => $dish->id, 'position' => 1,
    ]);

    ($this->pruefe)('speiseplan', $plan->id, 'Rindergulasch Plan');
});

it('Angebot: Gericht aus dem Concept erscheint im Handbuch', function () {
    $dish = ($this->gericht)('Lachs Angebot');
    $angebot = app(AngebotService::class)->create($this->team, ['name' => 'Gala 2027', 'personen' => 40, 'occasion' => 'Hochzeit']);
    $c = FoodAlchemistConcept::create(['team_id' => $this->team->id, 'name' => 'Vorspeise', 'kind' => 'concept', 'status' => 'active']);
    $this->makeConceptSlot($c, ['sales_recipe_id' => $dish->id, 'wording' => 'Lachs Angebot', 'position' => 1]);
    $comp = app(OfferCompositionService::class);
    $kap = $comp->addKapitel($this->team, $angebot->id, ['title' => 'Menü', 'consumer_title' => 'Unser Menü']);
    $comp->addBlock($this->team, $kap->id, ['type' => 'concept_ref', 'concept_id' => $c->id]);

    ($this->pruefe)('angebot', $angebot->id, 'Lachs Angebot');
});
