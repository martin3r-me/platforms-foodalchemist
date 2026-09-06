<?php

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Planung\Index as PlanungIndex;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRun;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRunStep;
use Platform\FoodAlchemist\Services\Knowledge\KnowledgeCanonService;
use Platform\FoodAlchemist\Services\PlanningSessionService;
use Platform\FoodAlchemist\Services\RecipeGenerationContextService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Wissens-Sicht in der Planung (2026-09-06): „In der UI sieht man das Wissen nie komplett."
 *
 * Zwei Ursachen, zwei Beweise:
 *  1. Der Kanon (verbindlicher Prompt-Teil) stand nur im Inspektor-Kanal `kanon`, nie im
 *     persistierten Step-Snapshot → die Step-Zeile kannte ihn nicht. Jetzt: eigenes Feld
 *     `snapshot.kanon_files` — getrennt von `knowledge_files`, das Dedup-Eingang (W0-3b) und
 *     `_knowledge_scope` der Kind-Rezepte bleibt und deshalb NICHT wachsen darf.
 *  2. Die Step-Zeile kappte bei 14 Chips („+N"). Jetzt: komplett, in zwei Gruppen.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    config(['foodalchemist.ai.provider' => 'fake', 'foodalchemist.ai.backoff' => []]);

    $this->mkDoc = function (string $slug, int $chars = 400): void {
        DB::table('foodalchemist_knowledge_documents')->insert([
            'uuid' => (string) UuidV7::generate(), 'team_id' => (int) $this->rootTeam->id, 'slug' => $slug,
            'title' => 'Titel '.$slug, 'category' => 'regelwerk', 'content_md' => str_repeat('Regel ', (int) ($chars / 6)),
            'version' => 1, 'content_hash' => hash('sha256', $slug), 'char_count' => $chars,
            'active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    };
});

it('Snapshot: Kanon-Dossiers stehen in kanon_files — und NICHT in knowledge_files (Dedup-Eingang)', function () {
    ($this->mkDoc)('sicht-kanon-a');
    ($this->mkDoc)('sicht.kanon_b');   // Punkt im Slug — Chip-Pretty darf ihn nicht kürzen
    foreach (['sicht-kanon-a' => 10, 'sicht.kanon_b' => 20] as $slug => $ord) {
        app(KnowledgeCanonService::class)->set($this->rootTeam, [
            'scope' => 'prompt_key', 'scope_key' => 'recipe.generator', 'slug' => $slug, 'ord' => $ord, 'mode' => 'pflicht',
        ]);
    }

    $ctx = app(RecipeGenerationContextService::class)->build($this->rootTeam, 'Rotwein-Schalotten-Reduktion', [], false);

    expect($ctx['snapshot'])->toHaveKeys(['knowledge_files', 'kanon_files'])
        ->and($ctx['snapshot']['kanon_files'])->toBe(['sicht-kanon-a@v1', 'sicht.kanon_b@v1']);
    foreach (['sicht-kanon-a', 'sicht.kanon_b'] as $slug) {
        expect(implode(' ', $ctx['snapshot']['knowledge_files']))->not->toContain($slug);
    }
    // Inspektor-Kanal bleibt identisch befüllt (eine Wahrheit, zwei Sichten).
    expect($ctx['kontext']['wissen']['kanon'] ?? null)->toBe($ctx['snapshot']['kanon_files']);
});

it('Step-Zeile: zeigt Kanon + Recherche komplett — 20 Recherche-Chips ohne „+N"-Kappung', function () {
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'X', 'brief' => 'y']);
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'planning_session_id' => $session->id, 'scope' => 'rezept', 'status' => 'review']);
    $recherche = array_map(fn (int $i) => sprintf('recherche-dossier-%02d@v3', $i), range(1, 20));
    FoodAlchemistCascadeRunStep::create([
        'team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'rezept', 'status' => 'done',
        'label' => 'Reduktion', 'context_snapshot' => [
            'knowledge_files' => $recherche,
            'kanon_files' => ['regelwerk-basisrezepte-6-mengen-einheiten-yield@v2', 'workflow.basisrezept_erstellungs_dossier@v1'],
        ],
    ]);

    $t = Livewire::test(PlanungIndex::class)->set('sessionId', $session->id)->set('laufId', $run->id)
        ->assertSee('Verwendetes Wissen (22)')
        ->assertSee('Kanon (2)')
        ->assertSee('Recherche (20)')
        ->assertSee('regelwerk-basisrezepte-6-mengen-einheiten-yield')
        ->assertSee('workflow.basisrezept_erstellungs_dossier')   // Punkt-Slug ungekürzt
        ->assertDontSee('+6');                                    // 20 − 14: die alte Kappung
    foreach ($recherche as $f) {
        $t->assertSee(explode('@', $f, 2)[0]);
    }
});

it('Step-Zeile: alter Snapshot ohne kanon_files rendert weiter (nur Recherche-Gruppe)', function () {
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'X', 'brief' => 'y']);
    $run = FoodAlchemistCascadeRun::create(['team_id' => $this->rootTeam->id, 'planning_session_id' => $session->id, 'scope' => 'rezept', 'status' => 'review']);
    FoodAlchemistCascadeRunStep::create([
        'team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'rezept', 'status' => 'done',
        'label' => 'Alt', 'context_snapshot' => ['knowledge_files' => ['pairings/tomate.md', 'domains/suppen.md']],
    ]);

    Livewire::test(PlanungIndex::class)->set('sessionId', $session->id)->set('laufId', $run->id)
        ->assertSee('Verwendetes Wissen (2)')
        ->assertSee('Kanon (0)')
        ->assertSee('tomate')->assertSee('suppen')
        ->assertDontSee('Kanon (verbindlich)');
});
