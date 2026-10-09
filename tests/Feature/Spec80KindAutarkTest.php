<?php

use Illuminate\Support\Facades\Queue;
use Platform\FoodAlchemist\Jobs\GenerateRecipeJob;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRun;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRunStep;
use Platform\FoodAlchemist\Services\PlanningCascadeService;
use Platform\FoodAlchemist\Services\RecipeDependencyWorkflowService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Ein Basisrezept steht für sich (Dominique 09.10.) — Live-Test demo Lauf 86: die Gericht-Aroma-Leitplanke
 * „Ingwer, Kürbis, Pilz-Umami“ lief in jedes Unterrezept (Jus mit 1,2 kg Kürbis). Das Kind erbt nur harte
 * Bedingungen und Produktionsachsen und sucht sein Wissen selbst (kein `_knowledge_scope` vom Gericht).
 */
beforeEach(function () {
    Queue::fake();
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
});

$gerichtParams = [
    'aroma' => 'herbstlich-würzig mit Ingwer, Kürbis und Pilz-Umami', 'aroma_kueche' => 'thai', 'saison' => 'Herbst',
    'pax' => 80, 'ziel_portion_g' => 350, 'serviceform' => 'tellerservice', 'occasion' => 'gala', 'ziel_vk_eur' => 24,
    'titel_vorgabe' => 'Rinderfilet', 'suchbegriffe' => ['rind'], 'plan_first' => true, 'kompositions_stil' => 'modern',
    'diaet_hart' => ['vegetarisch'], 'allergen_nogo' => ['nuesse'], 'convenience' => 'from_scratch', 'bestand' => 'hybrid',
    'level' => 'gehoben', 'sektor' => 'restaurant', 'complete_coverage' => false, '_voll_anreichern' => true, '_defer_children' => true,
];

it('erbt nur harte Bedingungen und Produktionsachsen, keine Teller- oder Geschmacksachsen', function () use ($gerichtParams) {
    $kind = RecipeDependencyWorkflowService::kindParameter($gerichtParams);

    expect($kind)->toHaveKeys(['diaet_hart', 'allergen_nogo', 'convenience', 'bestand', 'level', 'sektor', 'complete_coverage'])
        ->not->toHaveKeys(['aroma', 'aroma_kueche', 'saison', 'pax', 'ziel_portion_g', 'serviceform', 'occasion',
            'ziel_vk_eur', 'titel_vorgabe', 'suchbegriffe', 'plan_first', 'kompositions_stil', '_voll_anreichern', '_defer_children']);
});

it('„jetzt erzeugen“ startet das Kind ohne Gericht-Aroma und ohne Wissens-Einschränkung', function () use ($gerichtParams) {
    $run = FoodAlchemistCascadeRun::create([
        'team_id' => $this->rootTeam->id, 'scope' => 'gericht', 'status' => 'review', 'staged' => true, 'brief' => 'x',
    ]);
    $eltern = FoodAlchemistCascadeRunStep::create([
        'team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'gericht', 'status' => 'done',
        'label' => 'Rinderfilet', 'sort' => 1,
        'deferred' => ['children' => ['params' => $gerichtParams, 'user_id' => 0]],
        'context_snapshot' => ['knowledge_files' => ['rind--filet@v1', 'kuerbis--sorten@v2']],
    ]);
    $kind = FoodAlchemistCascadeRunStep::create([
        'team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'parent_step_id' => $eltern->id, 'kind' => 'rezept',
        'status' => 'geplant', 'label' => 'Jus: Ginger Beer', 'sort' => 2,
    ]);

    app(PlanningCascadeService::class)->erzeugeGeplantenStep($this->rootTeam, (int) $kind->id);

    Queue::assertPushed(GenerateRecipeJob::class, function ($job) use ($kind) {
        $p = $job->parameter;

        return (int) ($p['cascade_step_id'] ?? 0) === (int) $kind->id
            && ! array_key_exists('aroma', $p) && ! array_key_exists('aroma_kueche', $p) && ! array_key_exists('saison', $p)
            && ! array_key_exists('_knowledge_scope', $p)
            && ($p['diaet_hart'] ?? null) === ['vegetarisch'];
    });
});
