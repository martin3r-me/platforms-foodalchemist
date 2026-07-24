<?php

use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistConceptSlot;
use Platform\FoodAlchemist\Models\FoodAlchemistDishClass;
use Platform\FoodAlchemist\Models\FoodAlchemistDishIdea;
use Platform\FoodAlchemist\Models\FoodAlchemistDishIdeaGroup;
use Platform\FoodAlchemist\Models\FoodAlchemistDishMainGroup;
use Platform\FoodAlchemist\Models\FoodAlchemistEinsatzmoment;
use Platform\FoodAlchemist\Models\FoodAlchemistEventtyp;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistServierform;
use Platform\FoodAlchemist\Models\FoodAlchemistTargetGroup;
use Platform\FoodAlchemist\Services\FoodbookService;
use Platform\FoodAlchemist\Services\IdeenService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 19 E7.3 — kapitelFreigeben (Kapitel-Go „Anlegen"): Routing Paket→Konzept /
 * Einzel→recipe_ref, Dimensions-Stempel via Pivots, Idempotenz, released_* + Protokoll.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->fbSvc = app(FoodbookService::class);
    $this->ideen = app(IdeenService::class);

    $hg = FoodAlchemistDishMainGroup::create(['team_id' => $this->rootTeam->id, 'code' => 'HG', 'label' => 'Hauptgericht']);
    $klasse = FoodAlchemistDishClass::create(['team_id' => $this->rootTeam->id, 'dish_main_group_id' => $hg->id, 'code' => 'HG_N', 'label' => 'Neutral', 'diet_form' => 'neutral']);
    $mk = fn (string $key, string $name) => FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => $key, 'name' => $name, 'status' => 'approved',
        'is_sales_recipe' => true, 'sales_net' => 12.00, 'dish_class_id' => $klasse->id,
    ]);
    $this->dishA = $mk('rA', 'HG: Tomaten-Teller');
    $this->dishB = $mk('rB', 'VS: Bete-Carpaccio');

    // Dimensions-Vokabular für den Stempel.
    $this->sf = FoodAlchemistServierform::create(['team_id' => $this->rootTeam->id, 'label' => 'Buffet', 'code' => 'buffet']);
    $this->et = FoodAlchemistEventtyp::create(['team_id' => $this->rootTeam->id, 'name' => 'Gala']);
    $this->em = FoodAlchemistEinsatzmoment::create(['team_id' => $this->rootTeam->id, 'name' => 'Apéro']);
    $this->zg = FoodAlchemistTargetGroup::create(['team_id' => $this->rootTeam->id, 'name' => 'Bankett-Gast', 'sort_order' => 10]);

    $this->fb = $this->fbSvc->create($this->rootTeam, ['label' => 'Anlage-FB']);
    $this->fbSvc->update($this->rootTeam, $this->fb->id, [
        'default_niveau' => 'haute_cuisine',
        'default_serving_form_id' => $this->sf->id,
        'default_event_type_id' => $this->et->id,
    ]);
    $this->fb->serviceMoments()->sync([$this->em->id]);
    $this->fb->targetGroups()->sync([$this->zg->id]);

    $this->kapitel = $this->fbSvc->addKapitel($this->rootTeam, $this->fb->id, ['title' => 'Buffet-Kapitel']);
});

it('Paket-Gruppe → EIN Konzept mit Dimensions-Stempel + concept_ref-Block + Slot; Skizzen materialisiert', function () {
    $gruppe = $this->ideen->addGruppe($this->rootTeam, ['chapter_id' => $this->kapitel->id, 'name' => 'Grill-Buffet', 'target_price_pp' => 24.50]);
    $m = $this->ideen->uebernehmeBestand($this->rootTeam, ['chapter_id' => $this->kapitel->id, 'group_id' => $gruppe->id, 'sales_recipe_id' => $this->dishA->id]);

    $res = $this->fbSvc->kapitelFreigeben($this->rootTeam, $this->kapitel->id, 'go');

    expect($res['konzepte'])->toHaveCount(1)
        ->and($res['materialisiert'])->toBe(1)
        ->and($res['queued'])->toBe(0);

    $concept = FoodAlchemistConcept::find($res['konzepte'][0]);
    expect($concept->name)->toBe('Grill-Buffet')
        ->and($concept->created_via)->toBe('kapitel_freigabe')
        ->and((float) $concept->target_price_per_person)->toBe(24.50)
        ->and($concept->level)->toBe('haute')
        ->and((int) $concept->serving_form_id)->toBe($this->sf->id)
        ->and((int) $concept->event_type_id)->toBe($this->et->id)
        ->and($concept->serviceMoments()->pluck('foodalchemist_service_moments.id')->all())->toBe([$this->em->id])
        ->and($concept->targetGroups()->pluck('foodalchemist_target_groups.id')->all())->toBe([$this->zg->id]);

    // concept_ref-Block + genau ein Konzept-Slot mit dem Gericht.
    expect($this->kapitel->blocks()->where('type', 'concept_ref')->where('concept_id', $concept->id)->count())->toBe(1)
        ->and(FoodAlchemistConceptSlot::where('concept_id', $concept->id)->where('sales_recipe_id', $this->dishA->id)->count())->toBe(1);

    // Gruppe + Idee materialisiert.
    expect((int) $gruppe->refresh()->materialized_concept_id)->toBe((int) $concept->id);
    $m->refresh();
    expect($m->status)->toBe('freigegeben')
        ->and($m->materialized_ref['concept_id'])->toBe((int) $concept->id)
        ->and($m->materialized_ref['concept_slot_id'])->toBeGreaterThan(0);
});

it('Einzel-Idee mit Bestand-Ref → recipe_ref-Block; Idee freigegeben + materialized_ref{block_id}', function () {
    $idee = $this->ideen->uebernehmeBestand($this->rootTeam, ['chapter_id' => $this->kapitel->id, 'sales_recipe_id' => $this->dishB->id]);

    $res = $this->fbSvc->kapitelFreigeben($this->rootTeam, $this->kapitel->id);

    expect($res['bloecke_einzel'])->toBe(1)
        ->and($res['materialisiert'])->toBe(1)
        ->and($res['konzepte'])->toHaveCount(0);

    $block = $this->kapitel->blocks()->where('type', 'recipe_ref')->where('sales_recipe_id', $this->dishB->id)->first();
    expect($block)->not->toBeNull();
    $idee->refresh();
    expect($idee->status)->toBe('freigegeben')
        ->and($idee->materialized_ref['block_id'])->toBe((int) $block->id);
});

it('Freitext-Idee (kein sales_recipe_id) → generation_status=queued; kein Block/Konzept', function () {
    $idee = $this->ideen->add($this->rootTeam, ['chapter_id' => $this->kapitel->id, 'title' => 'Wildkräuter-Terrine']);

    $res = $this->fbSvc->kapitelFreigeben($this->rootTeam, $this->kapitel->id);

    expect($res['queued'])->toBe(1)
        ->and($res['materialisiert'])->toBe(0)
        ->and($res['bloecke_einzel'])->toBe(0);
    $idee->refresh();
    expect($idee->generation_status)->toBe('queued')
        ->and($idee->status)->toBe('entwurf');   // Freitext bleibt Entwurf bis E7.4 erdet
    expect($this->kapitel->blocks()->count())->toBe(0)
        ->and(FoodAlchemistConcept::where('team_id', $this->rootTeam->id)->count())->toBe(0);
});

it('ist idempotent — zweiter Go legt nichts doppelt an', function () {
    $gruppe = $this->ideen->addGruppe($this->rootTeam, ['chapter_id' => $this->kapitel->id, 'name' => 'Paket', 'target_price_pp' => 20]);
    $this->ideen->uebernehmeBestand($this->rootTeam, ['chapter_id' => $this->kapitel->id, 'group_id' => $gruppe->id, 'sales_recipe_id' => $this->dishA->id]);
    $this->ideen->uebernehmeBestand($this->rootTeam, ['chapter_id' => $this->kapitel->id, 'sales_recipe_id' => $this->dishB->id]);

    $erste = $this->fbSvc->kapitelFreigeben($this->rootTeam, $this->kapitel->id);
    $zweite = $this->fbSvc->kapitelFreigeben($this->rootTeam, $this->kapitel->id);

    // Erster Lauf materialisiert beide; zweiter findet alles freigegeben → 0 Neuanlagen.
    expect($erste['materialisiert'])->toBe(2)
        ->and($zweite['materialisiert'])->toBe(0)
        ->and($zweite['bloecke_einzel'])->toBe(0)
        ->and($zweite['konzepte'])->toHaveCount(1);   // reused, nicht neu

    // Kein Duplikat: 1 Konzept, 1 concept_ref-Block, 1 recipe_ref-Block, 1 Slot.
    expect(FoodAlchemistConcept::where('team_id', $this->rootTeam->id)->count())->toBe(1)
        ->and($this->kapitel->blocks()->where('type', 'concept_ref')->count())->toBe(1)
        ->and($this->kapitel->blocks()->where('type', 'recipe_ref')->count())->toBe(1)
        ->and(FoodAlchemistConceptSlot::where('sales_recipe_id', $this->dishA->id)->count())->toBe(1);
});

it('setzt released_* + release_result am Kapitel', function () {
    $this->ideen->uebernehmeBestand($this->rootTeam, ['chapter_id' => $this->kapitel->id, 'sales_recipe_id' => $this->dishB->id]);
    $user = $this->makeUser($this->rootTeam, 'Freigeber');

    $this->fbSvc->kapitelFreigeben($this->rootTeam, $this->kapitel->id, 'Freigabe-Notiz', $user->id);

    $k = $this->kapitel->refresh();
    expect($k->released_at)->not->toBeNull()
        ->and((int) $k->released_by)->toBe((int) $user->id)
        ->and($k->release_note)->toBe('Freigabe-Notiz')
        ->and($k->release_result['bloecke_einzel'])->toBe(1)
        ->and($k->release_result['materialisiert'])->toBe(1);
});

/**
 * Spec 19 E7.5 — „Anlage zurückziehen": Undo räumt Anlage-Objekte weg, Skizzen kehren in den
 * Entwurf zurück, Kapitel ist wieder anlegbar. Guards: Snapshot/Versand friert ein.
 */
it('Undo räumt Konzept + Blöcke + Slots weg und setzt Ideen + released_* zurück', function () {
    $gruppe = $this->ideen->addGruppe($this->rootTeam, ['chapter_id' => $this->kapitel->id, 'name' => 'Grill-Buffet', 'target_price_pp' => 24.50]);
    $paketIdee = $this->ideen->uebernehmeBestand($this->rootTeam, ['chapter_id' => $this->kapitel->id, 'group_id' => $gruppe->id, 'sales_recipe_id' => $this->dishA->id]);
    $einzelIdee = $this->ideen->uebernehmeBestand($this->rootTeam, ['chapter_id' => $this->kapitel->id, 'sales_recipe_id' => $this->dishB->id]);

    $res = $this->fbSvc->kapitelFreigeben($this->rootTeam, $this->kapitel->id, 'go');
    $conceptId = $res['konzepte'][0];
    expect(FoodAlchemistConcept::find($conceptId))->not->toBeNull()
        ->and($this->kapitel->blocks()->count())->toBe(2);   // concept_ref + recipe_ref

    $undo = $this->fbSvc->anlageZuruckziehen($this->rootTeam, $this->kapitel->id);

    expect($undo['status'])->toBe('zurueckgezogen')
        ->and($undo['konzepte_geloescht'])->toBe(1)
        ->and($undo['bloecke_geloescht'])->toBe(1)          // nur der frische recipe_ref-Block
        ->and($undo['ideen_zurueckgesetzt'])->toBe(2);

    // Konzept + Slots + Blöcke weg (soft-delete → nicht mehr sichtbar).
    expect(FoodAlchemistConcept::find($conceptId))->toBeNull()
        ->and(FoodAlchemistConceptSlot::where('concept_id', $conceptId)->count())->toBe(0)
        ->and($this->kapitel->blocks()->count())->toBe(0);

    // Ideen zurück auf Entwurf, materialized_* geleert.
    $paketIdee->refresh();
    $einzelIdee->refresh();
    expect($paketIdee->status)->toBe('entwurf')->and($paketIdee->materialized_ref)->toBeNull()
        ->and($einzelIdee->status)->toBe('entwurf')->and($einzelIdee->materialized_ref)->toBeNull();

    // Gruppe entkoppelt + Kapitel wieder anlegbar.
    expect($gruppe->refresh()->materialized_concept_id)->toBeNull();
    $k = $this->kapitel->refresh();
    expect($k->released_at)->toBeNull()->and($k->release_result)->toBeNull();

    // Re-Anlage nach Undo funktioniert wieder (Round-Trip idempotent).
    $reAnlage = $this->fbSvc->kapitelFreigeben($this->rootTeam, $this->kapitel->id);
    expect($reAnlage['materialisiert'])->toBe(2)->and($reAnlage['konzepte'])->toHaveCount(1);
});

it('Undo leert die Freitext-Queue (generation_status → null)', function () {
    $idee = $this->ideen->add($this->rootTeam, ['chapter_id' => $this->kapitel->id, 'title' => 'Wildkräuter-Terrine']);
    $this->fbSvc->kapitelFreigeben($this->rootTeam, $this->kapitel->id);
    expect($idee->refresh()->generation_status)->toBe('queued');

    $undo = $this->fbSvc->anlageZuruckziehen($this->rootTeam, $this->kapitel->id);

    expect($undo['status'])->toBe('zurueckgezogen');
    expect($idee->refresh()->generation_status)->toBeNull()
        ->and($idee->status)->toBe('entwurf');
});

it('Undo löscht per Dedup nur VERKNÜPFTE, nicht vorbestehende recipe_ref-Blöcke', function () {
    // Gericht liegt bereits als Block (aus früherer Übernahme), Idee dedupt darauf.
    $block = $this->fbSvc->addBlock($this->rootTeam, $this->kapitel->id, ['type' => 'recipe_ref', 'sales_recipe_id' => $this->dishB->id]);
    $idee = $this->ideen->uebernehmeBestand($this->rootTeam, ['chapter_id' => $this->kapitel->id, 'sales_recipe_id' => $this->dishB->id]);

    $res = $this->fbSvc->kapitelFreigeben($this->rootTeam, $this->kapitel->id);
    expect($res['bloecke_einzel'])->toBe(0);   // nichts Neues, Dedup
    expect($idee->refresh()->materialized_ref['created'])->toBeFalse();

    $undo = $this->fbSvc->anlageZuruckziehen($this->rootTeam, $this->kapitel->id);

    // Der vorbestehende Block bleibt stehen, Idee ist wieder Entwurf.
    expect($undo['bloecke_geloescht'])->toBe(0);
    expect($this->kapitel->blocks()->where('id', $block->id)->exists())->toBeTrue()
        ->and($idee->refresh()->status)->toBe('entwurf');
});

it('Undo wirft, sobald das Kapitel versendet/eingefroren ist', function () {
    $this->ideen->uebernehmeBestand($this->rootTeam, ['chapter_id' => $this->kapitel->id, 'sales_recipe_id' => $this->dishB->id]);
    $this->fbSvc->kapitelFreigeben($this->rootTeam, $this->kapitel->id);

    $this->kapitel->update(['snapshot_at' => now(), 'status' => 'sent']);

    expect(fn () => $this->fbSvc->anlageZuruckziehen($this->rootTeam, $this->kapitel->id))
        ->toThrow(RuntimeException::class);
});

it('Undo auf nicht-angelegtem Kapitel ist ein no-op', function () {
    $undo = $this->fbSvc->anlageZuruckziehen($this->rootTeam, $this->kapitel->id);
    expect($undo['status'])->toBe('nichts_anzulegen')
        ->and($undo['konzepte_geloescht'])->toBe(0);
});
