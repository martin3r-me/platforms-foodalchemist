<?php

use Illuminate\Support\Facades\DB;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Models\Team;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Services\Ai\KnowledgeContextService;
use Platform\FoodAlchemist\Support\MasterTeam;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 82 §13 H — „Master-Wissen unsichtbar" (P1: KnowledgeContextService + MCP-Lese-Tools).
 *
 * Lesen: eigene Kette; globales Wissen nur Master-Team + dessen Unter-Teams; Plattform-Admin als solcher nicht;
 * ohne Master-Konfiguration niemand (fail closed). Prompt: Master-Wissen bleibt für alle drin (Wirkung ja, Text nein).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    config(['foodalchemist.master_team_id' => null, 'foodalchemist.knowledge_master_regel' => true]);
    MasterTeam::flush();

    $this->master = Team::create(['name' => 'BHG.DIGITAL', 'user_id' => 1, 'personal_team' => false]);
    $this->masterKind = Team::create(['name' => 'BHG Kind', 'user_id' => 1, 'personal_team' => false, 'parent_team_id' => $this->master->id]);

    $this->doc = function (?int $teamId, string $slug, string $kategorie = 'domain', string $inhalt = 'Inhalt') {
        DB::table('foodalchemist_knowledge_documents')->insert([
            'uuid' => (string) UuidV7::generate(), 'team_id' => $teamId, 'slug' => $slug, 'title' => $slug,
            'category' => $kategorie, 'content_md' => $inhalt . ' ' . $slug, 'version' => 1,
            'content_hash' => hash('sha256', $slug), 'char_count' => 20, 'active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $slug;
    };
    ($this->doc)(null, 'global-dossier');
    ($this->doc)($this->master->id, 'master-dossier');
    ($this->doc)($this->rootTeam->id, 'mandant-dossier');
    ($this->doc)($this->childA->id, 'kind-a-dossier');
    ($this->doc)($this->childB->id, 'kind-b-dossier');

    $this->registry = app(ToolRegistry::class);
    $this->svc = app(KnowledgeContextService::class);
    $this->ctx = function (Team $team) {
        $user = $this->makeUser($team, 'U ' . $team->id);
        $this->actingAs($user);

        return new ToolContext($user, $team);
    };
    $this->mitMaster = function () {
        config(['foodalchemist.master_team_id' => $this->master->id]);
        MasterTeam::flush();
    };
});

function slugsAusListe(array $ergebnis): array
{
    return collect($ergebnis['documents'])->pluck('slug')->sort()->values()->all();
}

it('★ Mandant liest über Service-Liste/Suche/Einzelabruf 0 Master- und 0 globale Dossiers', function () {
    ($this->mitMaster)();

    $liste = slugsAusListe($this->svc->listDocuments($this->childA, null, 0, 100, false));
    expect($liste)->toBe(['kind-a-dossier', 'mandant-dossier']);

    $such = collect($this->svc->searchDocuments($this->childA, 'dossier', null, 50))->pluck('slug')->sort()->values()->all();
    expect($such)->toBe(['kind-a-dossier', 'mandant-dossier']);

    expect($this->svc->getDocument($this->childA, 'master-dossier'))->toBeNull()
        ->and($this->svc->getDocument($this->childA, 'global-dossier'))->toBeNull()
        ->and($this->svc->getDocument($this->childA, 'kind-b-dossier'))->toBeNull()
        ->and($this->svc->getDocument($this->childA, 'mandant-dossier'))->not->toBeNull();
});

it('★ dasselbe über die MCP-Tools knowledge.GET / LIST / SEARCH', function () {
    ($this->mitMaster)();
    $kontext = ($this->ctx)($this->childA);

    $get = $this->registry->get('foodalchemist.knowledge.GET');
    expect($get->execute(['slug' => 'master-dossier'], $kontext)->success)->toBeFalse()
        ->and($get->execute(['slug' => 'global-dossier'], $kontext)->success)->toBeFalse()
        ->and($get->execute(['slug' => 'kind-a-dossier'], $kontext)->success)->toBeTrue();

    $list = $this->registry->get('foodalchemist.knowledge.LIST')->execute(['limit' => 100], $kontext);
    expect(json_encode($list->data))->not->toContain('master-dossier')->not->toContain('global-dossier')->toContain('kind-a-dossier');

    $search = $this->registry->get('foodalchemist.knowledge.SEARCH')->execute(['q' => 'dossier'], $kontext);
    expect(json_encode($search->data))->not->toContain('master-dossier')->not->toContain('global-dossier')->toContain('mandant-dossier');
});

it('Master-Team und dessen Unter-Teams lesen global + Master-Wissen + eigene Kette', function () {
    ($this->mitMaster)();

    foreach ([$this->master, $this->masterKind] as $team) {
        $liste = slugsAusListe($this->svc->listDocuments($team, null, 0, 100, false));
        expect($liste)->toContain('global-dossier', 'master-dossier')->not->toContain('mandant-dossier')->not->toContain('kind-a-dossier');
        expect($this->svc->getDocument($team, 'global-dossier'))->not->toBeNull();
    }
});

it('Plattform-Admin als solcher liest kein globales Wissen — nur über Mitgliedschaft im Master-Team', function () {
    ($this->mitMaster)();
    $admin = $this->makeUser($this->childA, 'Plattform Admin');
    config(['platform-shell.admins' => [strtolower($admin->email)]]);
    $this->actingAs($admin);

    expect($this->svc->getDocument($this->childA, 'global-dossier'))->toBeNull()
        ->and($this->svc->getDocument($this->childA, 'master-dossier'))->toBeNull();
});

it('Ohne Master-Konfiguration liest niemand global (fail closed), Wurzel-Teams zählen nicht als Master', function () {
    expect(MasterTeam::id())->toBeNull();

    foreach ([$this->rootTeam, $this->master, $this->childA] as $team) {
        expect($this->svc->getDocument($team, 'global-dossier'))->toBeNull();
    }
    expect($this->svc->getDocument($this->rootTeam, 'mandant-dossier'))->not->toBeNull();
});

it('Notbremse knowledge_master_regel=false stellt die alte Regel her (Lesen wie Prompt)', function () {
    ($this->mitMaster)();
    config(['foodalchemist.knowledge_master_regel' => false]);

    expect($this->svc->getDocument($this->childA, 'global-dossier'))->not->toBeNull()
        ->and($this->svc->getDocument($this->childA, 'master-dossier'))->not->toBeNull();
});

it('★ PROMPT-Pfad: der Prompt des Mandanten enthält Master-Wissen weiter (echter Prompt-Bau)', function () {
    ($this->mitMaster)();
    foreach (KnowledgeContextService::ALWAYS_LOAD_CROSS_CUTTING as $slug) {
        ($this->doc)(null, $slug, 'cross_cutting', 'Querschnitt');
    }
    $fisch = ($this->doc)($this->master->id, 'fisch_seafood', 'domain', 'Lachs Wissen');
    DB::table('foodalchemist_knowledge_aliases')->insert([
        'alias_slug' => 'lachs', 'knowledge_document_id' => DB::table('foodalchemist_knowledge_documents')->where('slug', $fisch)->value('id'),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    foreach ([['cross_cutting', 'always'], ['domain', 'discovery']] as [$kat, $modus]) {
        DB::table('foodalchemist_knowledge_routings')->insert([
            'feature' => 'ai_generate_recipe', 'category' => $kat, 'mode' => $modus,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $prompt = $this->svc->contextFor($this->childA, 'ai_generate_recipe', 'Lachs mit Butter');
    $slugs = array_map(fn ($f) => explode('@', $f)[0], $prompt['files_used']);

    expect($slugs)->toContain('fisch_seafood')
        ->and($slugs)->toContain(KnowledgeContextService::ALWAYS_LOAD_CROSS_CUTTING[0]);
    // …während derselbe Mandant dieselben Dossiers nicht lesen kann:
    expect($this->svc->getDocument($this->childA, 'fisch_seafood'))->toBeNull();
});
