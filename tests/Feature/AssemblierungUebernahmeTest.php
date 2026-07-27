<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Models\FoodAlchemistServierform;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Services\MenuAssemblyService;
use Platform\FoodAlchemist\Services\PlanningFrameService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * 12·S2b (R2.4) — MCP-Fläche + explizite Übernahme.
 *
 * Die tragenden Zusicherungen dieser Etappe sind keine Feature-Häkchen, sondern Riegel:
 *
 * 1. **`assemblierung.POST` schreibt wirklich nichts** — `read_only=true` ist eine
 *    Behauptung im Discovery-Index; hier wird sie an Konzept- und Slot-Zählern gemessen.
 * 2. **Übernommen wird genau die Vorschau** — die geschriebenen Positionen sind ID-gleich
 *    mit den Gerichten aus dem Vorschau-Aufruf, nicht „auch ein gutes Menü".
 * 3. **Der Gegenzeichnungs-Riegel greift VOR dem ersten Write** — eine veraltete Vorschau
 *    hinterlässt kein halbes Konzept.
 * 4. **Nichts wird überschrieben** — ein befülltes oder freigegebenes Konzept wird
 *    abgelehnt, seine Positionen bleiben unverändert (GL 5).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->registry = app(ToolRegistry::class);
    $this->kontext = new ToolContext($this->user, $this->rootTeam);

    $this->frames = app(PlanningFrameService::class);
    $this->concepts = app(ConceptService::class);

    $sf = FoodAlchemistServierform::firstOrCreate(
        ['code' => 'unbestimmt', 'team_id' => $this->rootTeam->id],
        ['label' => 'Unbestimmt']
    );

    $this->mk = function (string $key, string $name, ?float $vk, ?float $ek) use ($sf): FoodAlchemistRecipe {
        $r = FoodAlchemistRecipe::create([
            'team_id' => $this->rootTeam->id, 'recipe_key' => $key, 'name' => $name,
            'status' => 'approved', 'is_sales_recipe' => true,
        ]);
        FoodAlchemistRecipeDarreichung::create([
            'team_id' => $this->rootTeam->id, 'recipe_id' => $r->id, 'serving_form_id' => $sf->id,
            'is_standard' => true, 'sales_net' => $vk, 'ek_portion' => $ek,
        ]);

        return $r;
    };

    // Gerüst am Foodbook mit zwei disjunkten Slots (Namens-No-Gos wie in MenuAssemblyTest),
    // damit die Auswahl hand-nachrechenbar bleibt: A1 (DB 18) + B1 (DB 24) = 42.
    $this->fb = FoodAlchemistFoodbook::create(['team_id' => $this->rootTeam->id, 'label' => 'S2b-FB']);
    $this->frame = $this->frames->frameFor($this->rootTeam, 'foodbook', $this->fb->id);
    $a = $this->frames->addSlot($this->rootTeam, $this->frame, ['label' => 'Vorspeisen', 'target_count' => 1]);
    $b = $this->frames->addSlot($this->rootTeam, $this->frame, ['label' => 'Hauptgänge', 'target_count' => 1]);
    $this->frames->addRule($this->rootTeam, $this->frame, ['rule_type' => 'nogo_ingredient', 'slot_id' => $a->id, 'value_text' => 'hauptgang']);
    $this->frames->addRule($this->rootTeam, $this->frame, ['rule_type' => 'nogo_ingredient', 'slot_id' => $b->id, 'value_text' => 'vorspeise']);

    $this->a1 = ($this->mk)('a1', 'Vorspeise: Tartar', 20.00, 2.00);   // DB 18
    ($this->mk)('a2', 'Vorspeise: Suppe', 10.00, 3.00);                // DB  7
    $this->b1 = ($this->mk)('b1', 'Hauptgang: Rind', 25.00, 1.00);     // DB 24
    ($this->mk)('b2', 'Hauptgang: Fisch', 15.00, 6.00);                // DB  9

    $this->post = fn (array $args) => $this->registry->get('foodalchemist.assemblierung.POST')->execute($args, $this->kontext);
    $this->apply = fn (array $args) => $this->registry->get('foodalchemist.assemblierung.APPLY')->execute($args, $this->kontext);
    $this->owner = ['owner_type' => 'foodbook', 'owner_id' => $this->fb->id];
});

it('Registry-Smoke: POST ist read_only, APPLY nicht — das Verb allein entscheidet es nicht', function () {
    $post = $this->registry->get('foodalchemist.assemblierung.POST');
    $apply = $this->registry->get('foodalchemist.assemblierung.APPLY');

    expect($post)->not->toBeNull()
        ->and($apply)->not->toBeNull()
        ->and($post->getSchema()['type'])->toBe('object')
        ->and($apply->getSchema()['type'])->toBe('object')
        // Der Grund für die Trennung in zwei Tools: POST behauptet read_only und muss es halten.
        ->and($post->getMetadata()['read_only'])->toBeTrue()
        ->and($apply->getMetadata()['read_only'])->toBeFalse()
        ->and($apply->getMetadata()['risk_level'])->toBe('write')
        // Discovery-relevante Keys (Resolver-Whitelist): tags + cost_class müssen sitzen
        ->and($post->getMetadata()['cost_class'])->toBe('local_db')
        ->and($apply->getMetadata()['tags'])->toContain('assemblierung');
});

it('POST liefert dieselbe Zahl wie der Service und schreibt dabei NICHTS', function () {
    $vorherKonzepte = FoodAlchemistConcept::count();
    $vorherFrames = \Platform\FoodAlchemist\Models\FoodAlchemistPlanningFrame::count();

    $res = ($this->post)($this->owner + ['gaeste' => 100]);

    expect($res->success)->toBeTrue()
        ->and($res->data['zielfunktion']['db_pp'])->toBe(42.0)
        ->and($res->data['db_gesamt_gaeste'])->toBe(4200.0)
        ->and($res->data['frame_id'])->toBe($this->frame->id)
        ->and($res->data['hinweis'])->toContain('erwartetes_db_pp=42.00')
        ->and(collect($res->data['gerichte'])->pluck('id')->all())->toBe([$this->a1->id, $this->b1->id]);

    // Keine zweite Wahrheit: das Tool ist eine Projektion des Service, keine Neurechnung.
    $direkt = app(MenuAssemblyService::class)->assembliere($this->rootTeam, $this->frame->refresh(), 100);
    expect($res->data['zielfunktion'])->toBe($direkt['zielfunktion'])
        ->and($res->data['slots'])->toBe($direkt['slots']);

    // read_only=true ist hier gemessen, nicht behauptet.
    expect(FoodAlchemistConcept::count())->toBe($vorherKonzepte)
        ->and(\Platform\FoodAlchemist\Models\FoodAlchemistPlanningFrame::count())->toBe($vorherFrames);
});

it('POST mit erklaerung=true nennt die bindende Vorgabe — und bleibt read-only', function () {
    $this->frames->setHead($this->rootTeam, $this->frame, ['price_max_pp' => 36.00]);
    $vorher = FoodAlchemistConcept::count();

    $res = ($this->post)($this->owner + ['erklaerung' => true]);

    expect($res->success)->toBeTrue()
        ->and($res->data)->toHaveKey('erklaerung')
        ->and($res->data['erklaerung']['constraints'])->not->toBeEmpty()
        ->and($res->data['erklaerung']['bindend'])->not->toBeEmpty();

    // Die Preis-Obergrenze bindet: ohne sie liegt mehr DB drin (A1+B1 = 42 statt 31).
    $preis = collect($res->data['erklaerung']['constraints'])->firstWhere('schluessel', 'preisband_max');
    expect($preis['bindend'])->toBeTrue()
        ->and($preis['delta_db_pp'])->toBeGreaterThan(0.0)
        ->and(FoodAlchemistConcept::count())->toBe($vorher);
});

it('APPLY übernimmt GENAU die Vorschau als Draft-Konzept — inkl. eigener Gerüst-Kopie', function () {
    $vorschau = ($this->post)($this->owner);
    $erwarteteIds = collect($vorschau->data['gerichte'])->pluck('id')->all();

    $res = ($this->apply)($this->owner + [
        'name' => 'Marge-optimal Adler',
        'gaeste' => 100,
        'erwartetes_db_pp' => $vorschau->data['zielfunktion']['db_pp'],
    ]);

    expect($res->success)->toBeTrue()
        ->and($res->data['status'])->toBe('draft')
        ->and($res->data['created_via'])->toBe('menu_assembly_mcp')
        ->and($res->data['name'])->toBe('Marge-optimal Adler')
        ->and($res->data['zielfunktion']['db_pp'])->toBe(42.0)
        ->and($res->data['db_gesamt_gaeste'])->toBe(4200.0)
        ->and($res->data['hinweis'])->toContain('ENTWURF');

    // Geschrieben ist die Vorschau — Position für Position, nicht „auch ein gutes Menü".
    $concept = FoodAlchemistConcept::with('slots')->findOrFail($res->data['concept_id']);
    expect($concept->slots->pluck('sales_recipe_id')->all())->toBe($erwarteteIds)
        ->and($concept->slots->pluck('role')->all())->toBe(['Vorspeisen', 'Hauptgänge'])
        ->and($concept->slots->pluck('type')->unique()->all())->toBe(['gericht']);

    // Eigene Messlatte am Konzept (Coverage misst am Konzept, nicht am Foodbook-Gerüst)
    $kopie = $this->frames->find('concept', $concept->id);
    expect($kopie)->not->toBeNull()
        ->and($kopie->id)->not->toBe($this->frame->id)
        ->and($kopie->slots()->count())->toBe(2);

    expect($res->data['protokoll'])->toHaveCount(2)
        ->and($res->data['protokoll'][0]['status'])->toBe('befuellt');
});

it('veraltete Vorschau bricht ab — und hinterlässt kein halbes Konzept', function () {
    $vorher = FoodAlchemistConcept::count();

    $res = ($this->apply)($this->owner + ['erwartetes_db_pp' => 39.25]);

    expect($res->success)->toBeFalse()
        ->and($res->errorCode)->toBe('VALIDATION_ERROR')
        ->and($res->error)->toContain('Vorschau veraltet')
        // Der Riegel liegt VOR dem ersten Write: kein Konzept, kein Gerüst, keine Position.
        ->and(FoodAlchemistConcept::count())->toBe($vorher)
        ->and($this->frames->find('concept', 0))->toBeNull();
});

it('befülltes Zielkonzept wird abgelehnt statt überschrieben', function () {
    $concept = $this->concepts->create($this->rootTeam, ['name' => 'Handarbeit', 'status' => 'draft']);
    $slot = $this->concepts->addSlot($this->rootTeam, $concept->id, ['role' => 'Vorspeisen']);
    $this->concepts->fillSlot($this->rootTeam, $slot->id, ['sales_recipe_id' => $this->b1->id, 'type' => 'gericht']);

    $res = ($this->apply)($this->owner + ['concept_id' => $concept->id]);

    expect($res->success)->toBeFalse()
        ->and($res->error)->toContain('schon 1 Position');

    // Der Bestand ist unangetastet — genau eine Position, und zwar die des Menschen.
    $concept->refresh()->load('slots');
    expect($concept->slots)->toHaveCount(1)
        ->and($concept->slots->first()->sales_recipe_id)->toBe($this->b1->id);
});

it('nicht-Entwurf wird abgelehnt — die Übernahme schreibt nur in Entwürfe', function () {
    $concept = $this->concepts->create($this->rootTeam, ['name' => 'Freigegeben', 'status' => 'active']);

    $res = ($this->apply)($this->owner + ['concept_id' => $concept->id]);

    expect($res->success)->toBeFalse()
        ->and($res->error)->toContain('Status')
        ->and($concept->refresh()->slots()->count())->toBe(0);
});

it('leeres Draft-Konzept wird befüllt — ohne sein vorhandenes Gerüst zu verdoppeln', function () {
    $concept = $this->concepts->create($this->rootTeam, ['name' => 'Leer', 'status' => 'draft']);
    $eigenes = $this->frames->frameFor($this->rootTeam, 'concept', $concept->id);
    $this->frames->addSlot($this->rootTeam, $eigenes, ['label' => 'Eigen', 'target_count' => 1]);

    $res = ($this->apply)($this->owner + ['concept_id' => $concept->id]);

    expect($res->success)->toBeTrue()
        ->and($res->data['concept_id'])->toBe($concept->id)
        ->and($concept->refresh()->slots()->count())->toBe(2);

    // Punkt 4: ein vorhandenes Gerüst bleibt, wie es ist — kein Merge, keine Dubletten.
    expect($this->frames->find('concept', $concept->id)->id)->toBe($eigenes->id)
        ->and($eigenes->refresh()->slots()->count())->toBe(1);
});

it('unbefüllbarer Slot landet als leere Position MIT Begründung — nie ein erfundenes Gericht', function () {
    // Dritter Slot, dessen No-Gos ALLE vier Gerichte ausschließen (beide Namens-Präfixe).
    $c = $this->frames->addSlot($this->rootTeam, $this->frame, ['label' => 'Desserts', 'target_count' => 1]);
    $this->frames->addRule($this->rootTeam, $this->frame, ['rule_type' => 'nogo_ingredient', 'slot_id' => $c->id, 'value_text' => 'vorspeise']);
    $this->frames->addRule($this->rootTeam, $this->frame, ['rule_type' => 'nogo_ingredient', 'slot_id' => $c->id, 'value_text' => 'hauptgang']);

    $res = ($this->apply)($this->owner);

    expect($res->success)->toBeTrue();
    $leer = collect($res->data['protokoll'])->firstWhere('slot', 'Desserts');
    expect($leer['status'])->toBe('leer')
        ->and($leer['gerichte'])->toBe([]);

    $slots = FoodAlchemistConcept::with('slots')->findOrFail($res->data['concept_id'])->slots;
    $leerSlot = $slots->firstWhere('role', 'Desserts');
    expect($leerSlot->sales_recipe_id)->toBeNull()
        ->and($leerSlot->note)->toContain('Slot bleibt leer');
});

it('Tenancy: fremdes Team-Foodbook ist für beide Tools nicht sichtbar (#504-Muster)', function () {
    $fremd = FoodAlchemistFoodbook::create(['team_id' => $this->childB->id, 'label' => 'Fremd-FB']);
    $args = ['owner_type' => 'foodbook', 'owner_id' => $fremd->id];

    $kontextA = new ToolContext($this->makeUser($this->childA), $this->childA);
    foreach (['foodalchemist.assemblierung.POST', 'foodalchemist.assemblierung.APPLY'] as $name) {
        $res = $this->registry->get($name)->execute($args, $kontextA);
        expect($res->success)->toBeFalse($name)
            ->and($res->errorCode)->toBe('NOT_FOUND', $name);
    }
    expect(FoodAlchemistConcept::count())->toBe(0);
});
