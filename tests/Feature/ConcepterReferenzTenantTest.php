<?php

use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistEinsatzmoment;
use Platform\FoodAlchemist\Models\FoodAlchemistServierform;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * MVP-052 (Audit 23, P0): ConceptService::update() übernahm die Facetten-Fremdschlüssel
 * (serving_form_id, event_type_id, category_id, writing_style_id) roh aus der client-
 * kontrollierten FELDER-Whitelist; syncEinsatzmomente/syncSaisons synchronisierten beliebige
 * IDs. Der Owner-Guard schützte das Konzept, nicht die daran gehängten Referenzen — ein Team
 * konnte fremde Dimensionen an sein eigenes Konzept hängen.
 *
 * Wie bei den Gerichten (MVP-050): geprüft wird SICHTBARKEIT, nicht Eigentum — geerbte/globale
 * Vokabeln bleiben am eigenen Konzept verwendbar.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->concept = FoodAlchemistConcept::create([
        'team_id' => $this->childA->id, 'name' => 'Eigenes Konzept', 'status' => 'draft',
    ]);
});

it('weist eine fremde Servierform am Konzept-Save ab (MVP-052)', function () {
    $svc = app(ConceptService::class);
    $fremd = FoodAlchemistServierform::create([
        'team_id' => $this->childB->id, 'code' => 'B-BUF', 'label' => 'Buffet B', 'is_inactive' => false,
    ]);

    expect(fn () => $svc->update($this->childA, $this->concept->id, ['serving_form_id' => $fremd->id]))
        ->toThrow(RuntimeException::class);

    expect($this->concept->fresh()->serving_form_id)->toBeNull();
});

it('akzeptiert eine geerbte (Master-)Servierform am Konzept-Save (Vererbung bleibt nutzbar)', function () {
    $svc = app(ConceptService::class);
    // serving_forms.team_id ist NOT NULL — Vererbung läuft über die Master-Kette (Root), nicht null.
    $geerbt = FoodAlchemistServierform::create([
        'team_id' => $this->rootTeam->id, 'code' => 'ROOT-BUF', 'label' => 'Buffet Master', 'is_inactive' => false,
    ]);

    $svc->update($this->childA, $this->concept->id, ['serving_form_id' => $geerbt->id]);

    expect($this->concept->fresh()->serving_form_id)->toBe($geerbt->id);
});

it('weist einen fremden Einsatzmoment beim Pivot-Sync ab (MVP-052)', function () {
    $svc = app(ConceptService::class);
    $fremd = FoodAlchemistEinsatzmoment::create([
        'team_id' => $this->childB->id, 'name' => 'Fremd-Moment', 'is_inactive' => false, 'sort_order' => 1,
    ]);

    expect(fn () => $svc->syncEinsatzmomente($this->childA, $this->concept->id, [$fremd->id]))
        ->toThrow(RuntimeException::class);

    expect($this->concept->fresh()->serviceMoments)->toHaveCount(0);
});

it('synct einen eigenen Einsatzmoment weiterhin', function () {
    $svc = app(ConceptService::class);
    $eigen = FoodAlchemistEinsatzmoment::create([
        'team_id' => $this->childA->id, 'name' => 'Eigen-Moment', 'is_inactive' => false, 'sort_order' => 1,
    ]);

    $svc->syncEinsatzmomente($this->childA, $this->concept->id, [$eigen->id]);

    expect($this->concept->fresh()->serviceMoments->pluck('id')->all())->toBe([$eigen->id]);
});
