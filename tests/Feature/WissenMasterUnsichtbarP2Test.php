<?php

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Models\Team;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Livewire\Knowledge\Browser;
use Platform\FoodAlchemist\Services\Knowledge\KnowledgePreviewService;
use Platform\FoodAlchemist\Support\MasterTeam;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 82 §13 H — P2: MCP-Tools (ablauf.GET, regelwerk.GET, Bindings, PREVIEW) und Wissens-Browser.
 * Mandant: liest Ablauf-Anleitungen (art=ablauf), aber kein Regelwerk, keine Dossiers, keine Master-Bindungen, keine Slugs in der Vorschau.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    config(['foodalchemist.knowledge_master_regel' => true]);
    $this->master = Team::create(['name' => 'BHG.DIGITAL', 'user_id' => 1, 'personal_team' => false]);
    config(['foodalchemist.master_team_id' => $this->master->id]);
    MasterTeam::flush();

    $this->doc = function (?int $teamId, string $slug, string $kategorie, ?string $art = null): int {
        // Die workflow.*-Dossiers liegen per Migration schon in der DB — updateOrInsert auf den Slug.
        DB::table('foodalchemist_knowledge_documents')->updateOrInsert(['slug' => $slug], [
            'uuid' => (string) UuidV7::generate(), 'team_id' => $teamId, 'title' => 'Titel ' . $slug,
            'category' => $kategorie, 'art' => $art, 'content_md' => "---\nrequired_tools: [foodalchemist.recipes.POST]\n---\n## Schritt\nText " . $slug,
            'version' => 1, 'content_hash' => hash('sha256', $slug), 'char_count' => 40, 'active' => 1, 'deleted_at' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return (int) DB::table('foodalchemist_knowledge_documents')->where('slug', $slug)->value('id');
    };
    // Ablauf-Dossiers des Vorgangs basisrezept_anlegen (global bzw. Master) + ein Regelwerk + ein Fachdossier
    ($this->doc)(null, 'workflow.basisrezept_regeln', 'workflow', 'ablauf');
    ($this->doc)($this->master->id, 'workflow.basisrezept_erzeugen', 'workflow', 'ablauf');
    $this->regelId = ($this->doc)(null, 'regelwerk.basisrezepte_p2', 'regelwerk');
    ($this->doc)($this->master->id, 'zutat.fachdossier_p2', 'zutat');

    $this->registry = app(ToolRegistry::class);
    $zaehler = 0;
    $this->als = function (Team $team) use (&$zaehler): ToolContext {
        $user = $this->makeUser($team, 'U ' . $team->id . ' ' . (++$zaehler));
        $this->actingAs($user);

        return new ToolContext($user, $team);
    };
});

it('★ Mandant liest Ablauf-Dossiers (art=ablauf), aber kein Regelwerk und kein Fachdossier', function () {
    $k = ($this->als)($this->childA);
    $get = $this->registry->get('foodalchemist.knowledge.GET');

    expect($get->execute(['slug' => 'workflow.basisrezept_regeln'], $k)->success)->toBeTrue()
        ->and($get->execute(['slug' => 'workflow.basisrezept_erzeugen'], $k)->success)->toBeTrue()
        ->and($get->execute(['slug' => 'regelwerk.basisrezepte_p2'], $k)->success)->toBeFalse()
        ->and($get->execute(['slug' => 'zutat.fachdossier_p2'], $k)->success)->toBeFalse();
});

it('★ ablauf.GET liefert dem Mandanten die Anleitung, aber keine lesbare Regelwerks-Liste', function () {
    $res = $this->registry->get('foodalchemist.ablauf.GET')->execute(['vorgang' => 'basisrezept_anlegen'], ($this->als)($this->childA));

    expect($res->success)->toBeTrue()
        ->and(array_column($res->data['dossiers'], 'slug'))->toContain('workflow.basisrezept_regeln', 'workflow.basisrezept_erzeugen')
        ->and($res->data['regelwerke']['quelle'])->toBe('nicht_lesbar')
        ->and($res->data['regelwerke']['dokumente'])->toBe([]);

    $mitWissen = $this->registry->get('foodalchemist.ablauf.GET')->execute(['vorgang' => 'basisrezept_anlegen', 'mit_wissen' => true], ($this->als)($this->childA));
    expect(json_encode($mitWissen->data))->not->toContain('regelwerk.basisrezepte_p2');
});

it('Master-Team bekommt in ablauf.GET weiter die Regelwerks-Auskunft', function () {
    $res = $this->registry->get('foodalchemist.ablauf.GET')->execute(['vorgang' => 'basisrezept_anlegen'], ($this->als)($this->master));

    expect($res->success)->toBeTrue()->and($res->data['regelwerke']['quelle'])->not->toBe('nicht_lesbar');
});

it('regelwerk.GET: Mandant bekommt „nicht lesbar" (Wirkung bleibt), Master die Auskunft', function () {
    $tool = $this->registry->get('foodalchemist.regelwerk.GET');

    $mandant = $tool->execute(['prompt_key' => 'recipe.generator'], ($this->als)($this->childA));
    expect($mandant->success)->toBeTrue()->and($mandant->data['quelle'])->toBe('nicht_lesbar')->and($mandant->data['dokumente'])->toBe([]);

    $master = $tool->execute(['prompt_key' => 'recipe.generator'], ($this->als)($this->master));
    expect($master->data['quelle'] ?? null)->not->toBe('nicht_lesbar');
});

it('Bindungen: Mandant sieht keine Master-Bindungen, der Master schon', function () {
    $docId = DB::table('foodalchemist_knowledge_documents')->where('slug', 'zutat.fachdossier_p2')->value('id');
    DB::table('foodalchemist_knowledge_bindings')->insert([
        'uuid' => (string) UuidV7::generate(), 'team_id' => $this->master->id, 'knowledge_document_id' => $docId,
        'binding_type' => 'layer', 'target_key' => 'recipe.generator', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $tool = $this->registry->get('foodalchemist.knowledge_bindings.GET');

    expect(json_encode($tool->execute([], ($this->als)($this->childA))->data))->not->toContain('zutat.fachdossier_p2');
    expect(json_encode($tool->execute([], ($this->als)($this->master))->data))->toContain('zutat.fachdossier_p2');
})->skip(fn () => ! app(ToolRegistry::class)->has('foodalchemist.knowledge_bindings.GET'), 'Bindings-Tool unter anderem Namen registriert');

it('★ PREVIEW: Mandant sieht Mengen („Plattform-Wissen"), keine Master-Slugs; der Master sieht sie', function () {
    foreach (\Platform\FoodAlchemist\Services\Ai\KnowledgeContextService::ALWAYS_LOAD_CROSS_CUTTING as $slug) {
        ($this->doc)(null, $slug, 'cross_cutting');
    }
    DB::table('foodalchemist_knowledge_routings')->insert([
        'feature' => 'recipe.generator', 'category' => 'cross_cutting', 'mode' => 'always', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $svc = app(KnowledgePreviewService::class);

    $mandant = $svc->preview($this->childA, 'recipe.generator', 'Lachs mit Butter');
    expect($mandant['plattform_wissen']['verdeckt'])->toBeGreaterThan(0)
        ->and($mandant['retrieval'])->toBe([])
        ->and($mandant['datenwerk'])->toBeNull();

    $master = $svc->preview($this->master, 'recipe.generator', 'Lachs mit Butter');
    expect($master['plattform_wissen'])->toBeNull()
        ->and(count($master['retrieval']))->toBeGreaterThan(0);
});

it('Wissens-Browser: Mandant listet keine globalen/Master-Dossiers, der Master schon', function () {
    ($this->als)($this->childA);
    Livewire::test(Browser::class)->assertViewHas('docs', function ($docs) {
        $slugs = collect($docs)->pluck('slug')->all();

        return ! in_array('regelwerk.basisrezepte_p2', $slugs, true) && ! in_array('zutat.fachdossier_p2', $slugs, true);
    });

    ($this->als)($this->master);
    Livewire::test(Browser::class)->assertViewHas('docs', function ($docs) {
        $slugs = collect($docs)->pluck('slug')->all();

        return in_array('regelwerk.basisrezepte_p2', $slugs, true) && in_array('zutat.fachdossier_p2', $slugs, true);
    });
});
