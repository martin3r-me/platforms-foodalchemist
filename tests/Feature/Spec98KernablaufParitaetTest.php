<?php

use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Livewire\Concepter\Editor as ConcepterEditor;
use Platform\FoodAlchemist\Livewire\Foodbooks\Index as FoodbooksIndex;
use Platform\FoodAlchemist\Livewire\Produktion\Editor as ProduktionEditor;
use Platform\FoodAlchemist\Livewire\Recipes\RecipeModal;
use Platform\FoodAlchemist\Livewire\Verkauf\VkModal;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistEinsatzmoment;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookBlock;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel;
use Platform\FoodAlchemist\Models\FoodAlchemistMarkupClass;
use Platform\FoodAlchemist\Models\FoodAlchemistProductionOrder;
use Platform\FoodAlchemist\Models\FoodAlchemistProductionOrderLine;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeStep;
use Platform\FoodAlchemist\Models\FoodAlchemistSaison;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabKochequipment;
use Platform\FoodAlchemist\Services\Rezept\DarreichungService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * 98 · 2.3d — Paritäts-Tests je Kernablauf: dieselbe Eingabe über die Oberfläche (Livewire) und über das MCP-Tool
 * ergibt dasselbe Ergebnis. Verglichen wird ein Abbild ohne IDs, Zeiten und Herkunft (`created_via`, Name/Schlüssel,
 * wo beide Wege einen eigenen Datensatz brauchen). Abläufe: Rezept anlegen, Gericht kalkulieren, Concept für eine
 * Gästezahl kalkulieren, Foodbook duplizieren, Produktion (Ziel + freie Position).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam, 'Paritaet');
    $this->actingAs($this->user);
    $this->tool = function (string $name, array $args) {
        $r = app(ToolRegistry::class)->get($name)->execute($args, new ToolContext($this->user, $this->rootTeam));
        expect($r->success)->toBeTrue("{$name}: " . ($r->errorCode ?? 'fehlgeschlagen'));

        return $r;
    };
});

/** Vergleichbares Abbild eines Rezepts (ohne ID, Name, Schlüssel und Herkunft). */
function rezeptAbbild(FoodAlchemistRecipe $r): array
{
    $r = $r->fresh();

    return [
        'status' => $r->status->value, 'sales' => (bool) $r->is_sales_recipe, 'description' => $r->description,
        'preparation' => $r->preparation, 'work_time_min' => (int) $r->work_time_min,
        'temperature' => $r->temperature, 'function' => $r->function, 'notes_manual' => $r->notes_manual,
        'yield_pieces' => $r->yield_pieces === null ? null : (float) $r->yield_pieces,
        'geraete' => $r->equipment()->orderBy('slug')->pluck('slug')->all(),
        'schritte' => FoodAlchemistRecipeStep::where('recipe_id', $r->id)->orderBy('position')->pluck('text')->all(),
    ];
}

it('Rezept anlegen: Modal und recipes.POST ergeben dasselbe Rezept samt Fachfeldern und Schritten', function () {
    $kombi = FoodAlchemistVocabKochequipment::create(['team_id' => $this->rootTeam->id, 'slug' => 'kombi', 'name' => 'Kombidämpfer']);
    $eingabe = [
        'description' => 'Kräftiger Rinderfond.', 'preparation' => "1. Knochen rösten\n2. Ansetzen und reduzieren", 'work_time_min' => 45,
        'temperature' => 'warm', 'function' => 'Saucenbasis', 'notes_manual' => 'Am Vortag ansetzen', 'yield_pieces' => 24,
    ];

    $lw = Livewire::test(RecipeModal::class)->call('oeffnen')->set('form.name', 'Fond: Paritaet Oberflaeche');
    foreach ($eingabe as $feld => $wert) {
        $lw->set("form.{$feld}", $wert);
    }
    $lw->set('form.equipment_ids', [(string) $kombi->id])->call('speichern')->assertSet('fehler', null);
    $ui = FoodAlchemistRecipe::where('name', 'Fond: Paritaet Oberflaeche')->firstOrFail();

    $res = ($this->tool)('foodalchemist.recipes.POST', ['name' => 'Fond: Paritaet Tool', 'equipment_ids' => [$kombi->id]] + $eingabe);
    $mcp = FoodAlchemistRecipe::findOrFail($res->data['recipe']['id']);

    expect(rezeptAbbild($mcp))->toBe(rezeptAbbild($ui))
        ->and(rezeptAbbild($ui)['schritte'])->toHaveCount(2)
        ->and(rezeptAbbild($ui)['geraete'])->toBe(['kombi']);
});

it('Rezept bearbeiten: Modal und recipes.PUT ändern die Fachfelder eines Entwurfs gleich', function () {
    $kombi = FoodAlchemistVocabKochequipment::create(['team_id' => $this->rootTeam->id, 'slug' => 'kombi', 'name' => 'Kombidämpfer']);
    $herd = FoodAlchemistVocabKochequipment::create(['team_id' => $this->rootTeam->id, 'slug' => 'herd', 'name' => 'Herd']);
    $anlegen = fn (string $name) => FoodAlchemistRecipe::findOrFail(($this->tool)('foodalchemist.recipes.POST', [
        'name' => $name, 'temperature' => 'warm', 'equipment_ids' => [$kombi->id],
    ])->data['recipe']['id']);
    $ui = $anlegen('Fond: Bearbeiten Oberflaeche');
    $mcp = $anlegen('Fond: Bearbeiten Tool');
    $aenderung = ['temperature' => 'kalt', 'function' => 'Bindung', 'notes_manual' => 'Neu', 'yield_pieces' => 12];

    $lw = Livewire::test(RecipeModal::class)->call('oeffnen', $ui->id);
    foreach ($aenderung as $feld => $wert) {
        $lw->set("form.{$feld}", $wert);
    }
    $lw->set('form.equipment_ids', [(string) $herd->id])->call('speichern')->assertSet('fehler', null);

    ($this->tool)('foodalchemist.recipes.PUT', ['recipe_id' => $mcp->id, 'equipment_ids' => [$herd->id]] + $aenderung);

    expect(rezeptAbbild($mcp))->toBe(rezeptAbbild($ui))
        ->and(rezeptAbbild($ui)['temperature'])->toBe('kalt')
        ->and(rezeptAbbild($ui)['geraete'])->toBe(['herd']);
});

it('recipes.POST/PUT: unbekannte Geräte und negative Stückzahl lehnt das Tool ab, ohne zu schreiben', function () {
    $vorher = FoodAlchemistRecipe::count();
    $r = app(ToolRegistry::class)->get('foodalchemist.recipes.POST')
        ->execute(['name' => 'Fond: Fremdgeraet', 'equipment_ids' => [999999]], new ToolContext($this->user, $this->rootTeam));
    expect($r->errorCode)->toBe('VALIDATION_ERROR')->and(FoodAlchemistRecipe::count())->toBe($vorher);

    $id = ($this->tool)('foodalchemist.recipes.POST', ['name' => 'Fond: Stueck'])->data['recipe']['id'];
    $r = app(ToolRegistry::class)->get('foodalchemist.recipes.PUT')
        ->execute(['recipe_id' => $id, 'yield_pieces' => -3], new ToolContext($this->user, $this->rootTeam));
    expect($r->errorCode)->toBe('VALIDATION_ERROR')->and(FoodAlchemistRecipe::find($id)->yield_pieces)->toBeNull();
});

it('Gericht kalkulieren: Darreichung im VK-Modal und recipe_darreichung.PUT ergeben denselben Preis', function () {
    $ak = FoodAlchemistMarkupClass::create([
        'code' => 'PAR', 'label' => 'Paritaet', 'class_factor_pct' => 100,
        'vat_profile_key' => 'regulaer', 'raw_markup_pct' => 300, 'vat_rate' => 19, 'formula_type' => 'aufschlag',
    ]);
    $gericht = fn (string $name) => $this->makeRecipe($this->rootTeam, $name, [
        'is_sales_recipe' => true, 'yield_kg' => 2.0, 'ek_per_kg_eur' => 12.0, 'ek_total_eur' => 24.0,
        'ek_n_ingredients_total' => 3, 'ek_n_ingredients_priced' => 3,
    ]);
    $dar = app(DarreichungService::class);
    $dUi = $dar->ensureStandard($this->rootTeam, $gericht('[HG] Rinderrücken | Oberfläche')->id, 'test');
    $dMcp = $dar->ensureStandard($this->rootTeam, $gericht('[HG] Rinderrücken | Tool')->id, 'test');
    $abbild = function (FoodAlchemistRecipeDarreichung $d) {
        $d = $d->fresh();

        return [
            'price_mode' => $d->price_mode, 'markup_class_id' => (int) $d->markup_class_id,
            'quantity_per_unit_g' => (float) $d->quantity_per_unit_g, 'ek_portion' => round((float) $d->ek_portion, 4),
            'calculated_sales_net' => round((float) $d->calculated_sales_net, 2), 'sales_net' => round((float) $d->sales_net, 2),
            'sales_gross' => round((float) $d->sales_gross, 2),
        ];
    };

    // Die Standard-Darreichung hat keine eigene Grammatur-Eingabe: die Portionsmenge kommt aus der Rezeptur
    // („Standardmenge verwenden"). Beide Wege setzen deshalb nur Aufschlagsklasse und Preismodus.
    Livewire::test(VkModal::class)
        ->call('oeffnen', $dUi->recipe_id)
        ->set("darForm.{$dUi->id}.markup_class_id", $ak->id)
        ->set("darForm.{$dUi->id}.price_mode", 'auto')
        ->call('darreichungSpeichern', $dUi->id)
        ->assertSet('fehler', null);

    ($this->tool)('foodalchemist.recipe_darreichung.PUT', [
        'presentation_id' => $dMcp->id, 'attrs' => ['markup_class_id' => $ak->id, 'price_mode' => 'auto'],
    ]);

    expect($abbild($dMcp))->toBe($abbild($dUi))
        ->and($abbild($dUi)['sales_net'])->toBeGreaterThan(0.0);
});

/*
 * 98 · 2.3d, Unterschied 4 → Plan 98 B (Sperre, Entscheid Dominique 22.11.2026): die Grammatur ist an KEINER Darreichung mehr
 * frei setzbar — Oberfläche und Tool lehnen gleich ab (zentral im DarreichungService). Früher lehnte das Tool nur an der
 * Standard-Darreichung ab, an den übrigen Darreichungen und zusammen mit menge_vom_standard=true ging quantity_per_unit_g durch.
 */
it('recipe_darreichung.PUT: Grammatur an jeder Darreichung abgelehnt, auch neben menge_vom_standard; die Rezeptur-Menge bleibt', function () {
    $gericht = $this->makeRecipe($this->rootTeam, '[HG] Grammatur | Standard', [
        'is_sales_recipe' => true, 'yield_kg' => 2.0, 'ek_per_kg_eur' => 12.0, 'ek_total_eur' => 24.0,
        'ek_n_ingredients_total' => 3, 'ek_n_ingredients_priced' => 3,
    ]);
    $d = app(DarreichungService::class)->ensureStandard($this->rootTeam, $gericht->id, 'test');
    $vorher = $d->fresh()->only(['quantity_per_unit_g', 'menge_vom_standard', 'ek_portion', 'sales_net']);
    $put = fn (int $id, array $attrs) => app(ToolRegistry::class)->get('foodalchemist.recipe_darreichung.PUT')
        ->execute(['presentation_id' => $id, 'attrs' => $attrs], new ToolContext($this->user, $this->rootTeam));

    expect($put($d->id, ['quantity_per_unit_g' => 200])->errorCode)->toBe('VALIDATION_ERROR')
        ->and($put($d->id, ['quantity_per_unit_g' => 200, 'menge_vom_standard' => true])->errorCode)->toBe('VALIDATION_ERROR')
        ->and($d->fresh()->only(['quantity_per_unit_g', 'menge_vom_standard', 'ek_portion', 'sales_net']))->toBe($vorher)
        ->and((float) $d->fresh()->quantity_per_unit_g)->toBe(2000.0);

    // Auch an einer Variante — dort führt der Weg über anteil_prozent bzw. Mengen je Komponente
    $form = \Platform\FoodAlchemist\Models\FoodAlchemistServierform::create(['team_id' => $this->rootTeam->id, 'code' => 'klein', 'label' => 'Klein']);
    $v = app(DarreichungService::class)->anlegen($this->rootTeam, $gericht->id, $form->id);
    $r = $put($v->id, ['quantity_per_unit_g' => 100]);
    expect($r->errorCode)->toBe('VALIDATION_ERROR')->and($r->error)->toContain('anteil_prozent')
        ->and((bool) $v->fresh()->menge_vom_standard)->toBeTrue();
});

/*
 * 98 · 2.3d, benannte Abweichung → Plan 98 B (Sperre): verkaufsrezepte.PUT nahm sales_quantity_per_unit_g an und schrieb es
 * als freie Grammatur in die Standard-Darreichung (menge_vom_standard aus). Jetzt ist der Kopf nur der Spiegel der
 * Standard-Darreichung: ein abweichender Wert ist VALIDATION_ERROR, der unveränderte Wert geht durch (der Editor schickt ihn mit).
 * Die Verkaufsmenge folgt aus Ansatz ÷ Portionen; io Rezept 80 (60 g bei 64 g) stellt die Grammatur-Umstellung um.
 */
it('verkaufsrezepte.PUT: abweichende sales_quantity_per_unit_g ist VALIDATION_ERROR, der wirksame Wert geht durch (98 · 3.5 6b)', function () {
    $gericht = $this->makeRecipe($this->rootTeam, '[HG] Grammatur | Verkaufsmenge', [
        'is_sales_recipe' => true, 'yield_kg' => 2.0, 'ek_per_kg_eur' => 12.0, 'ek_total_eur' => 24.0,
        'ek_n_ingredients_total' => 3, 'ek_n_ingredients_priced' => 3,
    ]);
    $d = app(DarreichungService::class)->ensureStandard($this->rootTeam, $gericht->id, 'test');
    $vorher = $d->fresh()->only(['quantity_per_unit_g', 'menge_vom_standard', 'ek_portion']);
    $tool = fn (array $felder) => app(ToolRegistry::class)->get('foodalchemist.verkaufsrezepte.PUT')
        ->execute(['id' => $gericht->id, 'felder' => $felder], new ToolContext($this->user, $this->rootTeam));

    $r = $tool(['sales_quantity_per_unit_g' => 60]);
    expect($r->errorCode)->toBe('VALIDATION_ERROR')->and($r->error)->toContain('Summe der Bestandteile')
        ->and($d->fresh()->only(['quantity_per_unit_g', 'menge_vom_standard', 'ek_portion']))->toBe($vorher);

    // kein Rezept-Spiegel mehr: der wirksame Wert ist die Grammatur der Standard-Darreichung
    $wirksam = round(app(DarreichungService::class)->grammJeEinheit($d->fresh(), $gericht->fresh()), 1);
    expect($tool(['sales_quantity_per_unit_g' => $wirksam, 'name' => '[HG] Grammatur | Verkaufsmenge neu'])->success)->toBeTrue();
});

it('Concept bearbeiten: Editor und concepts.PUT schreiben Felder, Einsatzmomente und Saisons gleich', function () {
    $mittag = FoodAlchemistEinsatzmoment::create(['team_id' => $this->rootTeam->id, 'name' => 'Mittagstisch', 'is_inactive' => false, 'sort_order' => 1]);
    $abend = FoodAlchemistEinsatzmoment::create(['team_id' => $this->rootTeam->id, 'name' => 'Abendessen', 'is_inactive' => false, 'sort_order' => 2]);
    $sommer = FoodAlchemistSaison::create(['team_id' => $this->rootTeam->id, 'name' => 'Sommer']);
    $abbild = function (FoodAlchemistConcept $c) {
        $c = $c->fresh();

        return [
            'description' => $c->description, 'occasion' => $c->occasion,
            'momente' => $c->serviceMoments()->orderBy('name')->pluck('name')->all(),
            'saisons' => $c->seasons()->orderBy('name')->pluck('name')->all(),
        ];
    };
    $ui = $this->makeConcept($this->rootTeam, 'Grill Oberfläche');
    $mcp = $this->makeConcept($this->rootTeam, 'Grill Tool');

    Livewire::test(ConcepterEditor::class)
        ->call('oeffnen', 'concepts', $ui->id)
        ->set('form.description', 'Grillbuffet im Garten')
        ->set('form.occasion', 'Sommerfest')
        ->set('form.einsatzmoment_ids', [$mittag->id, $abend->id])
        ->set('form.saison_ids', [$sommer->id])
        ->call('speichern')
        ->assertSet('fehler', null);

    ($this->tool)('foodalchemist.concepts.PUT', [
        'id' => $mcp->id, 'felder' => ['description' => 'Grillbuffet im Garten', 'occasion' => 'Sommerfest'],
        'service_moments' => ['Mittagstisch', 'Abendessen'], 'seasons' => ['Sommer'],
    ]);

    expect($abbild($mcp))->toBe($abbild($ui))
        ->and($abbild($ui)['momente'])->toBe(['Abendessen', 'Mittagstisch']);
});

it('concepts.PUT: ohne Facetten bleibt der Bestand, [] leert, unbekannte Namen werden abgelehnt', function () {
    $mittag = FoodAlchemistEinsatzmoment::create(['team_id' => $this->rootTeam->id, 'name' => 'Mittagstisch', 'is_inactive' => false, 'sort_order' => 1]);
    $c = $this->makeConcept($this->rootTeam, 'Bestand');
    ($this->tool)('foodalchemist.concepts.PUT', ['id' => $c->id, 'service_moments' => ['Mittagstisch']]);
    $momente = fn () => $c->fresh()->serviceMoments()->pluck('name')->all();

    // Bewusste Abweichung vom Editor: der liest fehlende Facetten als leer, das Tool lässt sie stehen.
    ($this->tool)('foodalchemist.concepts.PUT', ['id' => $c->id, 'felder' => ['description' => 'nur Text']]);
    expect($momente())->toBe(['Mittagstisch']);

    $r = app(ToolRegistry::class)->get('foodalchemist.concepts.PUT')
        ->execute(['id' => $c->id, 'service_moments' => ['Mittagstisch', 'Gibtsnicht']], new ToolContext($this->user, $this->rootTeam));
    expect($r->errorCode)->toBe('VALIDATION_ERROR')->and($momente())->toBe(['Mittagstisch']);

    ($this->tool)('foodalchemist.concepts.PUT', ['id' => $c->id, 'service_moments' => []]);
    expect($momente())->toBe([])->and($mittag->fresh())->not->toBeNull();
});

it('Concept kalkulieren: Kalkulations-Tab und concepts.KALKULATION rechnen für dieselbe Gästezahl dasselbe', function () {
    $concept = $this->makeConcept($this->rootTeam, 'Sommerbuffet');
    $gericht = $this->makeRecipe($this->rootTeam, '[HG] Lachs | Paritaet', ['is_sales_recipe' => true]);
    $this->makeStandardDarreichung($gericht, 12.0);
    $this->makeConceptSlot($concept, ['recipe_id' => $gericht->id]);

    $ui = Livewire::test(ConcepterEditor::class)
        ->call('oeffnen', 'concepts', $concept->id, 'kalkulation')
        ->set('simulationPax', 40)
        ->viewData('auftragsSimulation');

    $mcp = ($this->tool)('foodalchemist.concepts.KALKULATION', ['concept_id' => $concept->id, 'pax' => 40])->data['kalkulation'];

    expect($ui)->not->toBeNull()
        ->and($mcp)->toEqual($ui);
});

/*
 * Benannte Abweichung (Kurator-Entscheid 2026-11-21, 98 · 2.3d): foodbook_kapitel.POST legt Kapitel nur in Foodbooks im
 * Entwurf an (sonst ACCESS_DENIED), die Oberfläche (Foodbooks\Index::kapitelNeu) hat diese Sperre nicht. Gewollt: ein
 * Mensch darf ein freigegebenes Foodbook bewusst ändern, ein Agent nicht ungefragt. Darum vergleicht die Foodbook-Parität
 * das Duplizieren, nicht das Kapitel-Anlegen.
 */
it('Foodbook duplizieren: Index-Aktion und foodbooks.DUPLICATE ergeben dieselbe Kopie', function () {
    $quelle = $this->makeFoodbook($this->rootTeam, 'Sommerkatalog', ['personen' => 60, 'description' => 'Fürs Sommerfest']);
    $vor = $this->makeChapter($quelle, ['title' => 'Vorspeisen', 'position' => 1]);
    $haupt = $this->makeChapter($quelle, ['title' => 'Hauptgänge', 'position' => 2]);
    $this->makeChapter($quelle, ['title' => 'Fisch', 'position' => 1, 'parent_id' => $haupt->id]);
    $this->makeFoodbookBlock($vor, ['position' => 1]);
    $this->makeFoodbookBlock($vor, ['type' => 'header', 'position' => 2]);
    $abbild = function (FoodAlchemistFoodbook $fb) {
        $kapitel = FoodAlchemistFoodbookKapitel::where('foodbook_id', $fb->id)->orderBy('position')->orderBy('title')->get();
        $titel = $kapitel->pluck('title', 'id');

        return [
            'label' => $fb->label, 'status' => $fb->statusWert()->value, 'team_id' => (int) $fb->team_id,
            'personen' => $fb->personen, 'description' => $fb->description,
            'kapitel' => $kapitel->map(fn ($k) => [
                'title' => $k->title, 'position' => (int) $k->position, 'status' => (string) ($k->status?->value ?? $k->status),
                'parent' => $k->parent_id ? $titel[$k->parent_id] : null,
                'bloecke' => FoodAlchemistFoodbookBlock::where('chapter_id', $k->id)->orderBy('position')->pluck('type')->all(),
            ])->all(),
        ];
    };

    $lw = Livewire::test(FoodbooksIndex::class)->call('waehle', $quelle->id)->call('duplizieren');
    $ui = FoodAlchemistFoodbook::findOrFail($lw->get('selectedId'));

    $res = ($this->tool)('foodalchemist.foodbooks.DUPLICATE', ['id' => $quelle->id]);
    $mcp = FoodAlchemistFoodbook::findOrFail($res->data['foodbook']['id']);

    expect($ui->id)->not->toBe($quelle->id)->and($mcp->id)->not->toBe($ui->id)
        ->and($abbild($mcp))->toBe($abbild($ui))
        ->and($abbild($ui)['kapitel'])->toHaveCount(3);
});

it('Produktion: Editor und production_orders.ADD_TARGET/LINE_ADD ergeben dieselben Positionen', function () {
    $fond = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'fond-paritaet', 'name' => 'Brauner Fond',
        'status' => 'approved', 'is_sales_recipe' => false, 'yield_kg' => 2.0, 'work_time_min' => 60,
    ]);
    $abbild = fn (int $orderId) => FoodAlchemistProductionOrderLine::where('production_order_id', $orderId)
        ->orderBy('origin')->orderBy('position')->get()
        ->map(fn ($l) => [
            'origin' => $l->origin, 'titel' => $l->titel, 'recipe_id' => $l->recipe_id, 'ansaetze' => (float) $l->ansaetze,
            'benoetigt_ansaetze' => (float) $l->benoetigt_ansaetze, 'arbeitszeit_min' => (int) $l->arbeitszeit_min,
            'position' => (int) $l->position, 'plan_date' => (string) $l->plan_date,
        ])->all();

    $lw = Livewire::test(ProduktionEditor::class)
        ->call('oeffnenNeu')
        ->set('name', 'Paritaet Oberfläche')
        ->set('productionDate', '2026-08-20')
        ->call('zielEinfuegen', 'basisrezept', $fond->id, 6, 'kg')
        ->call('speichern')
        ->assertSet('fehler', null);
    $uiId = (int) $lw->get('orderId');
    $lw->set('freiTitel', 'Brot beim Bäcker abholen')->set('freiZeit', '25')
        ->call('freiePositionAnlegen')
        ->assertSet('fehler', null);

    $add = ($this->tool)('foodalchemist.production_orders.ADD_TARGET', [
        'production_date' => '2026-08-20', 'name' => 'Paritaet Tool', 'recipe_id' => $fond->id, 'amount_kg' => 6, 'source_ref' => 'recipe:' . $fond->id,
    ]);
    $mcpId = (int) $add->data['order_id'];
    ($this->tool)('foodalchemist.production_orders.LINE_ADD', ['order_id' => $mcpId, 'titel' => 'Brot beim Bäcker abholen', 'arbeitszeit_min' => 25]);

    $ziele = fn (int $id) => collect(FoodAlchemistProductionOrder::findOrFail($id)->targets)
        ->map(fn ($t) => array_diff_key((array) $t, array_flip(['label'])))->all();

    expect($mcpId)->not->toBe($uiId)
        ->and($ziele($mcpId))->toEqual($ziele($uiId))
        ->and($abbild($mcpId))->toBe($abbild($uiId))
        ->and(collect($abbild($uiId))->pluck('origin')->sort()->values()->all())->toBe(['computed', 'manual']);
});
