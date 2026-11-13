<?php

use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Jobs\MaterializeConceptIdeaJob;
use Platform\FoodAlchemist\Livewire\Concepter\Editor as ConcepterEditor;
use Platform\FoodAlchemist\Livewire\Planung\Index as PlanungIndex;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRun;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRunStep;
use Platform\FoodAlchemist\Models\FoodAlchemistConceptSlot;
use Platform\FoodAlchemist\Models\FoodAlchemistDishIdea;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Services\ConceptVorgabenService;
use Platform\FoodAlchemist\Services\IdeenService;
use Platform\FoodAlchemist\Services\PlanningCascadeService;
use Platform\FoodAlchemist\Services\PlanningSessionService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Gericht-Vorgabe je Concept-Position (Dominique 13.11.2026): „das sind meine Gerichte, baue genau diese".
 * Vorher zählte der Fan-out nur die leeren Positionen und ließ die KI frei erfinden.
 */
const VORGABE_KAROTTE = 'Graved Karotte | Dillcreme | Brot-Crumble';

beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    Queue::fake();
    $this->vorgaben = app(ConceptVorgabenService::class);
    $this->concept = $this->makeConcept($this->rootTeam, 'Green & Tasty', ['status' => 'draft']);
    $this->leer = fn (int $pos, array $attrs = []) => $this->makeConceptSlot($this->concept, $attrs + ['position' => $pos, 'wording' => null]);
    $this->lauf = function () {
        $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'scope' => 'concept', 'status' => 'running']);

        return FoodAlchemistCascadeRunStep::create(['team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'concept',
            'status' => 'running', 'ref_type' => 'concept', 'ref_id' => $this->concept->id]);
    };
});

// ── Vorgabe setzen ──────────────────────────────────────────────────────────

it('setze: Vorgabe und Notiz an einer leeren Position, leerer Titel löscht beides', function () {
    $slot = ($this->leer)(1);

    $slot = $this->vorgaben->setze($this->rootTeam, $slot->id, '  ' . VORGABE_KAROTTE . "\n ", 'Karotte 48 h gebeizt');
    expect($slot->dish_brief)->toBe(VORGABE_KAROTTE)->and($slot->dish_brief_note)->toBe('Karotte 48 h gebeizt');

    $slot = $this->vorgaben->setze($this->rootTeam, $slot->id, '', 'bleibt nicht stehen');
    expect($slot->dish_brief)->toBeNull()->and($slot->dish_brief_note)->toBeNull();
});

it('setze: nicht an einer belegten Position und nicht im geerbten Concept', function () {
    $gericht = $this->makeRecipe($this->rootTeam, '[VOR] Rote Bete', ['is_sales_recipe' => true]);
    $belegt = ($this->leer)(1, ['sales_recipe_id' => $gericht->id]);
    expect(fn () => $this->vorgaben->setze($this->rootTeam, $belegt->id, VORGABE_KAROTTE))
        ->toThrow(RuntimeException::class, 'nur an einer leeren Position');

    $leer = ($this->leer)(2);
    expect(fn () => $this->vorgaben->setze($this->childA, $leer->id, VORGABE_KAROTTE))   // Kind-Team sieht das Concept, darf es aber nicht ändern
        ->toThrow(RuntimeException::class, 'Besitzer-Team');
    expect($leer->refresh()->dish_brief)->toBeNull();
});

// ── Fan-out ─────────────────────────────────────────────────────────────────

it('Fan-out: Vorgabe wird 1:1 zur Skizze, erfunden wird nur für die Position ohne Vorgabe', function () {
    $mit = ($this->leer)(1, ['dish_brief' => VORGABE_KAROTTE, 'dish_brief_note' => 'Karotte 48 h gebeizt']);
    $ohne = ($this->leer)(2);
    $conceptStep = ($this->lauf)();

    $erfunden = FoodAlchemistDishIdea::create(['team_id' => $this->rootTeam->id, 'concept_id' => $this->concept->id, 'title' => 'Erfunden', 'status' => 'entwurf', 'target_form' => 'einzel']);
    $this->mock(IdeenService::class, fn ($m) => $m->shouldReceive('kiDivergenzConcept')->once()
        ->withArgs(fn ($team, $conceptId, $anzahl) => $anzahl === 1)   // nur EINE Position zu erfinden
        ->andReturn(['angelegt' => [$erfunden], 'roh' => 1, 'confidence' => 0.8, 'call_log_id' => null]));

    app(PlanningCascadeService::class)->fanoutConceptInvention($this->rootTeam, (int) $conceptStep->id, (int) $this->concept->id, 'voll_kreativ');

    $kinder = FoodAlchemistCascadeRunStep::where('parent_step_id', $conceptStep->id)->where('kind', 'gericht')->orderBy('id')->get();
    expect($kinder)->toHaveCount(2)->and($kinder->pluck('status')->unique()->all())->toBe(['geplant']);

    $vorgabeStep = $kinder->first(fn ($s) => ($s->context_snapshot['vorgabe'] ?? false) === true);
    $idee = FoodAlchemistDishIdea::findOrFail((int) $vorgabeStep->context_snapshot['dish_idea_id']);
    expect($vorgabeStep->label)->toBe(VORGABE_KAROTTE)
        ->and($vorgabeStep->context_snapshot['beschreibung'])->toBe('Karotte 48 h gebeizt')
        ->and(array_column($vorgabeStep->context_snapshot['komponenten'], 'name'))->toBe(['Graved Karotte', 'Dillcreme', 'Brot-Crumble'])
        ->and($idee->title)->toBe(VORGABE_KAROTTE)
        ->and($idee->source_meta['quelle'])->toBe('vorgabe')
        ->and((int) $idee->source_meta['target_concept_slot_id'])->toBe((int) $mit->id)
        ->and($idee->sales_recipe_id)->toBeNull();
    expect((int) $erfunden->refresh()->source_meta['target_concept_slot_id'])->toBe((int) $ohne->id);
    Queue::assertNotPushed(MaterializeConceptIdeaJob::class);   // Vorschlag-Gate: gebaut wird erst nach der Freigabe
});

it('Fan-out: stehen an allen leeren Positionen Vorgaben, wird die KI gar nicht nach Ideen gefragt', function () {
    ($this->leer)(1, ['dish_brief' => VORGABE_KAROTTE]);
    ($this->leer)(2, ['dish_brief' => 'Forellen-Rillette, Forellenhaut-Chips']);   // ohne Trenner „|": keine Komponenten-Vorgabe
    $conceptStep = ($this->lauf)();
    $this->mock(IdeenService::class, fn ($m) => $m->shouldNotReceive('kiDivergenzConcept'));

    app(PlanningCascadeService::class)->fanoutConceptInvention($this->rootTeam, (int) $conceptStep->id, (int) $this->concept->id, 'voll_kreativ');

    $kinder = FoodAlchemistCascadeRunStep::where('parent_step_id', $conceptStep->id)->orderBy('sort')->get();
    expect($kinder->pluck('label')->all())->toBe([VORGABE_KAROTTE, 'Forellen-Rillette, Forellenhaut-Chips'])
        ->and($kinder[1]->context_snapshot['komponenten'])->toBe([]);
});

it('Freigabe des Vorgabe-Schritts startet den Bau wie bei einer erfundenen Skizze', function () {
    ($this->leer)(1, ['dish_brief' => VORGABE_KAROTTE]);
    $conceptStep = ($this->lauf)();
    $this->mock(IdeenService::class, fn ($m) => $m->shouldNotReceive('kiDivergenzConcept'));
    $cascade = app(PlanningCascadeService::class);
    $cascade->fanoutConceptInvention($this->rootTeam, (int) $conceptStep->id, (int) $this->concept->id, 'voll_kreativ');
    $step = FoodAlchemistCascadeRunStep::where('parent_step_id', $conceptStep->id)->firstOrFail();

    $cascade->erzeugeGeplantenStep($this->rootTeam, (int) $step->id);

    Queue::assertPushed(MaterializeConceptIdeaJob::class, 1);
    expect($step->refresh()->status)->toBe('running');
});

// ── Wortlaut bleibt Kundenwording ───────────────────────────────────────────

it('setzeGericht: der Wortlaut der Vorgabe wird Kundenwording, ein gepflegtes Wording gewinnt', function () {
    $gericht = $this->makeRecipe($this->rootTeam, '[VOR] Graved Karotte | Dill-Schmand | Roggenbrot-Crumble', ['is_sales_recipe' => true]);
    $idee = fn (FoodAlchemistConceptSlot $s, string $quelle) => FoodAlchemistDishIdea::create(['team_id' => $this->rootTeam->id, 'concept_id' => $this->concept->id,
        'title' => VORGABE_KAROTTE, 'status' => 'entwurf', 'target_form' => 'einzel', 'source_meta' => ['quelle' => $quelle, 'target_concept_slot_id' => $s->id]]);

    $a = ($this->leer)(1, ['dish_brief' => VORGABE_KAROTTE]);
    $this->vorgaben->setzeGericht($this->rootTeam, $a->id, $gericht->id, $idee($a, 'vorgabe'));
    expect($a->refresh()->sales_recipe_id)->toBe($gericht->id)->and($a->wording)->toBe(VORGABE_KAROTTE);

    $b = ($this->leer)(2, ['dish_brief' => VORGABE_KAROTTE, 'wording' => 'Karotte, anders gedacht']);
    $this->vorgaben->setzeGericht($this->rootTeam, $b->id, $gericht->id, $idee($b, 'vorgabe'));
    expect($b->refresh()->wording)->toBe('Karotte, anders gedacht');

    $c = ($this->leer)(3);   // erfundenes Gericht: kein Wording aus einer Vorgabe
    $this->vorgaben->setzeGericht($this->rootTeam, $c->id, $gericht->id, $idee($c, 'ki_divergenz_concept'));
    expect($c->refresh()->sales_recipe_id)->toBe($gericht->id)->and($c->wording)->toBeNull();
});

// ── Bestand zuerst ──────────────────────────────────────────────────────────

it('Bestand zuerst: ein freigegebenes Gericht gleichen Namens wird vorgeschlagen und bei Freigabe übernommen', function () {
    $bestand = $this->makeRecipe($this->rootTeam, '[VOR] Graved Karotte | Dillcreme | Brot-Crumble', ['is_sales_recipe' => true, 'status' => 'approved']);
    $slot = ($this->leer)(1, ['dish_brief' => VORGABE_KAROTTE]);
    $conceptStep = ($this->lauf)();
    $this->mock(IdeenService::class, fn ($m) => $m->shouldNotReceive('kiDivergenzConcept'));

    app(PlanningCascadeService::class)->fanoutConceptInvention($this->rootTeam, (int) $conceptStep->id, (int) $this->concept->id, 'voll_kreativ');
    $step = FoodAlchemistCascadeRunStep::where('parent_step_id', $conceptStep->id)->firstOrFail();
    $ideeId = (int) $step->context_snapshot['dish_idea_id'];
    expect($step->label)->toBe('Aus Bestand: ' . $bestand->name)
        ->and((int) $step->context_snapshot['bestand_recipe_id'])->toBe((int) $bestand->id)
        ->and($slot->refresh()->sales_recipe_id)->toBeNull();   // noch nichts übernommen: erst die Freigabe

    expect($this->vorgaben->uebernimmBestand($this->rootTeam, $ideeId, (int) $step->id))->toBeTrue();

    expect($slot->refresh()->sales_recipe_id)->toBe($bestand->id)
        ->and($slot->wording)->toBe(VORGABE_KAROTTE)
        ->and($step->refresh()->status)->toBe('done')
        ->and((int) $step->ref_id)->toBe((int) $bestand->id)
        ->and(FoodAlchemistDishIdea::findOrFail($ideeId)->source_meta['erdung'])->toBe('bestand');
});

it('Bestand zuerst gilt nur für freigegebene Gerichte mit demselben Namen', function () {
    $this->makeRecipe($this->rootTeam, '[VOR] Graved Karotte | Dillcreme | Brot-Crumble', ['is_sales_recipe' => true, 'status' => 'draft']);          // Entwurf ist kein Bestand
    $this->makeRecipe($this->rootTeam, '[VOR] Graved Karotte | Dill-Schmand | Roggenbrot-Crumble', ['is_sales_recipe' => true, 'status' => 'approved']);   // ähnlich ist nicht dasselbe
    $this->makeRecipe($this->rootTeam, 'Graved Karotte | Dillcreme | Brot-Crumble', ['is_sales_recipe' => false, 'status' => 'approved']);                 // Basisrezept, kein Gericht

    expect($this->vorgaben->bestandsGericht($this->rootTeam, VORGABE_KAROTTE))->toBeNull();

    $treffer = $this->makeRecipe($this->rootTeam, '[VOR] Brot-Crumble | Graved Karotte | Dillcreme', ['is_sales_recipe' => true, 'status' => 'approved']);   // gleiche Wörter, andere Reihenfolge
    expect($this->vorgaben->bestandsGericht($this->rootTeam, VORGABE_KAROTTE)?->id)->toBe($treffer->id);
});

it('uebernimmBestand lässt erfundene Skizzen und Vorgaben ohne Bestand in Ruhe', function () {
    $slot = ($this->leer)(1, ['dish_brief' => VORGABE_KAROTTE]);
    $step = ($this->lauf)();
    $idee = FoodAlchemistDishIdea::create(['team_id' => $this->rootTeam->id, 'concept_id' => $this->concept->id, 'title' => VORGABE_KAROTTE,
        'status' => 'entwurf', 'target_form' => 'einzel', 'source_meta' => ['quelle' => 'vorgabe', 'target_concept_slot_id' => $slot->id]]);

    expect($this->vorgaben->uebernimmBestand($this->rootTeam, (int) $idee->id, (int) $step->id))->toBeFalse()
        ->and($slot->refresh()->sales_recipe_id)->toBeNull()
        ->and($step->refresh()->status)->toBe('running');
});

// ── Eingabe: Leitstelle, Concepter, MCP ─────────────────────────────────────

it('Leitstelle: Plan-Panel zeigt Positionen, speichert die Vorgabe und hängt eine Position mit Vorgabe an', function () {
    $gericht = $this->makeRecipe($this->rootTeam, '[VOR] Rote Bete', ['is_sales_recipe' => true]);
    ($this->leer)(1, ['role' => 'Vorspeise', 'sales_recipe_id' => $gericht->id]);
    $leer = ($this->leer)(2, ['role' => 'Hauptgang']);
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'Green & Tasty']);

    $c = Livewire::test(PlanungIndex::class)
        ->call('oeffne', $session->id)
        ->call('bestehendesConceptWaehlen', $this->concept->id)
        ->assertSeeHtml('data-plan-positionen')
        ->assertSee('[VOR] Rote Bete')
        ->call('planVorgabeSpeichern', $leer->id, VORGABE_KAROTTE)
        ->assertSet('fehler', null);
    expect($leer->refresh()->dish_brief)->toBe(VORGABE_KAROTTE);

    $c->set('planNeueVorgabe', 'Sellerie im Salzteig')->call('planPositionMitVorgabe')->assertSet('planNeueVorgabe', '');
    $neu = FoodAlchemistConceptSlot::where('concept_id', $this->concept->id)->orderByDesc('id')->first();
    expect($neu->dish_brief)->toBe('Sellerie im Salzteig')->and(FoodAlchemistConceptSlot::where('concept_id', $this->concept->id)->count())->toBe(3);

    $positionen = $c->instance()->planPositionen();
    expect(array_column($positionen, 'leer'))->toBe([false, true, true])->and($positionen[1]['vorgabe'])->toBe(VORGABE_KAROTTE);
});

it('Leitstelle: eine Position aus einem anderen Concept lässt sich nicht über den Plan beschreiben', function () {
    $anderes = $this->makeConcept($this->rootTeam, 'Anderes Concept', ['status' => 'draft']);
    $fremd = $this->makeConceptSlot($anderes, ['wording' => null]);
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'Green & Tasty']);

    Livewire::test(PlanungIndex::class)
        ->call('oeffne', $session->id)
        ->call('bestehendesConceptWaehlen', $this->concept->id)
        ->call('planVorgabeSpeichern', $fremd->id, VORGABE_KAROTTE);

    expect($fremd->refresh()->dish_brief)->toBeNull();
});

it('Concepter: die leere Position trägt das Vorgabe-Feld und speichert Titel und Notiz', function () {
    $slot = ($this->leer)(1);

    $c = Livewire::test(ConcepterEditor::class)->call('oeffnen', 'concepts', $this->concept->id)->call('bearbeitenStarten')
        ->assertSeeHtml('data-slot-vorgabe')
        ->assertDontSeeHtml('data-slot-vorgabe-notiz')
        ->call('vorgabeSpeichern', $slot->id, 'titel', VORGABE_KAROTTE)
        ->assertSeeHtml('data-slot-vorgabe-notiz')
        ->call('vorgabeSpeichern', $slot->id, 'notiz', 'Karotte 48 h gebeizt');
    expect($slot->refresh()->dish_brief)->toBe(VORGABE_KAROTTE)->and($slot->dish_brief_note)->toBe('Karotte 48 h gebeizt');

    $c->call('vorgabeSpeichern', $slot->id, 'titel', '');
    expect($slot->refresh()->dish_brief)->toBeNull()->and($slot->dish_brief_note)->toBeNull();
});

it('MCP: concept_slots.POST und PUT setzen die Vorgabe, concepts.GET liefert sie', function () {
    $registry = app(ToolRegistry::class);
    $kontext = new ToolContext($this->user, $this->rootTeam);
    $run = fn (string $n, array $a) => $registry->get($n)->execute($a, $kontext);

    $r = $run('foodalchemist.concept_slots.POST', ['concept_id' => $this->concept->id, 'role' => 'Vorspeise', 'dish_brief' => VORGABE_KAROTTE, 'dish_brief_note' => 'Karotte 48 h gebeizt']);
    expect($r->success)->toBeTrue()->and($r->data['slot']['dish_brief'])->toBe(VORGABE_KAROTTE);
    $slotId = (int) $r->data['slot']['id'];

    $gericht = $this->makeRecipe($this->rootTeam, '[VOR] Rote Bete', ['is_sales_recipe' => true]);
    $doppelt = $run('foodalchemist.concept_slots.POST', ['concept_id' => $this->concept->id, 'sales_recipe_id' => $gericht->id, 'dish_brief' => VORGABE_KAROTTE]);
    expect($doppelt->success)->toBeFalse()->and($doppelt->errorCode)->toBe('VALIDATION_ERROR');

    expect($run('foodalchemist.concept_slots.PUT', ['slot_id' => $slotId, 'felder' => ['dish_brief_note' => 'ohne Kümmel']])->success)->toBeTrue();
    $slot = FoodAlchemistConceptSlot::findOrFail($slotId);
    expect($slot->dish_brief)->toBe(VORGABE_KAROTTE)->and($slot->dish_brief_note)->toBe('ohne Kümmel');

    $get = $run('foodalchemist.concepts.GET', ['concept_id' => $this->concept->id]);
    expect(collect($get->data['concept']['slots'] ?? $get->data['slots'] ?? [])->firstWhere('id', $slotId)['dish_brief'] ?? null)->toBe(VORGABE_KAROTTE);

    expect($run('foodalchemist.concept_slots.PUT', ['slot_id' => $slotId, 'felder' => ['dish_brief' => '']])->success)->toBeTrue();
    expect($slot->refresh()->dish_brief)->toBeNull()->and($slot->dish_brief_note)->toBeNull();
});

it('vorgabenTitel: die Vorgaben eines Concepts in Reihenfolge, als Kontext für die Erfindung der übrigen Positionen', function () {
    ($this->leer)(2, ['dish_brief' => 'Sellerie im Salzteig']);
    ($this->leer)(1, ['dish_brief' => VORGABE_KAROTTE]);
    ($this->leer)(3);

    expect($this->vorgaben->vorgabenTitel((int) $this->concept->id))->toBe([VORGABE_KAROTTE, 'Sellerie im Salzteig']);
});
