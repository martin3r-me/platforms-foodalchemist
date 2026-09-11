<?php

use Illuminate\Support\Facades\DB;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Models\Team;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Services\KnowledgeService;
use Platform\FoodAlchemist\Support\TeamScope;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 52 · Etappe G vorgezogen — der Kurator darf seinen eigenen Korpus pflegen.
 *
 * Vorher galt fuer Wissens-Dokumente: `team_id NULL` = unveraenderlich fuer JEDEN. Das war
 * richtig, solange global „geerbter Seed" hiess. Es ist falsch, sobald der kuratierte Bestand
 * selbst global wird — er waere eingefroren. Genau deshalb stand in TeamScope die Empfehlung,
 * ihn NICHT global zu legen; die Entscheidung vom 2026-09-11 dreht sie um.
 *
 * Die Sperre verschwindet nicht, sie bekommt einen Namen: global gehoert dem MASTER.
 * `KnowledgeLinkService` sagte das schon — hier holt der Rest des Moduls auf.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->kontext = new ToolContext($this->user, $this->rootTeam);
    $this->registry = app(ToolRegistry::class);

    $this->global = function (string $slug = 'regelwerk.global') {
        DB::table('foodalchemist_knowledge_documents')->insert([
            'uuid' => (string) UuidV7::generate(), 'team_id' => null, 'slug' => $slug,
            'title' => 'Global', 'category' => 'regelwerk', 'content_md' => '# Original',
            'version' => 1, 'content_hash' => hash('sha256', 'x'), 'char_count' => 10,
            'active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $slug;
    };
});

it('★ der Master darf globales Wissen aendern — sonst friert sein eigener Korpus ein', function () {
    config(['foodalchemist.master_team_id' => $this->rootTeam->id]);
    $slug = ($this->global)();

    $res = $this->registry->get('foodalchemist.knowledge.PUT')
        ->execute(['slug' => $slug, 'content_md' => '# Gepflegt'], $this->kontext);

    expect($res->success)->toBeTrue();
    expect(DB::table('foodalchemist_knowledge_documents')->where('slug', $slug)->value('content_md'))
        ->toBe('# Gepflegt');
});

it('★ ein Fremd-Team darf globales Wissen weiterhin NICHT aendern', function () {
    config(['foodalchemist.master_team_id' => $this->rootTeam->id]);
    $slug = ($this->global)();
    $fremd = $this->makeUser($this->childA, 'Kind-Nutzer');

    $res = $this->registry->get('foodalchemist.knowledge.PUT')
        ->execute(['slug' => $slug, 'content_md' => 'HACK'], new ToolContext($fremd, $this->childA));

    expect($res->success)->toBeFalse()->and($res->errorCode)->toBe('LOCKED');
    expect(DB::table('foodalchemist_knowledge_documents')->where('slug', $slug)->value('content_md'))
        ->toBe('# Original');
});

it('★ die Config ist die schaerfere Aussage: ein zweites elternloses Team ist NICHT Master', function () {
    // Auf demo tragen historisch ALLE Teams parent_team_id NULL. Ohne Config waere dort jeder
    // Master — die Config macht aus „zufaellig elternlos" eine Entscheidung.
    $zweiterRoot = Team::create(['name' => 'Auch elternlos', 'user_id' => 1, 'personal_team' => false]);
    config(['foodalchemist.master_team_id' => $this->rootTeam->id]);

    expect(TeamScope::isMaster($this->rootTeam))->toBeTrue()
        ->and(TeamScope::isMaster($zweiterRoot))->toBeFalse();
});

it('ungesetzte Config aendert die Master-Antwort nicht', function () {
    config(['foodalchemist.master_team_id' => null]);

    expect(TeamScope::isMaster($this->rootTeam))->toBeTrue()    // parent_team_id === null
        ->and(TeamScope::isMaster($this->childA))->toBeFalse();
});

it('★ der Trockenlauf meldet die Sperre VORHER — er hat sie frueher verschwiegen', function () {
    // Der Anlassfall: `--pruefen` versprach 16 Einordnungen und schrieb 12. Geprueft wurde das
    // Format, nicht das Recht.
    config(['foodalchemist.master_team_id' => $this->rootTeam->id]);
    $slug = ($this->global)();
    $fremd = $this->makeUser($this->childA, 'Kind-Nutzer');

    $res = $this->registry->get('foodalchemist.knowledge.EINORDNEN')->execute([
        'pruefen' => true,
        'eintraege' => [['slug' => $slug, 'art' => 'fachwissen']],
    ], new ToolContext($fremd, $this->childA));

    expect($res->data['eingeordnet'])->toBe(0)
        ->and($res->data['fehlgeschlagen'])->toBe(1);
});

it('★ der Master legt GLOBAL an — alles Neue gehoert dem kuratierten Bestand', function () {
    config(['foodalchemist.master_team_id' => $this->rootTeam->id]);

    $doc = app(KnowledgeService::class)->create($this->rootTeam, [
        'title' => 'Neues Master-Dossier', 'category' => 'regelwerk', 'content_md' => 'Inhalt.',
    ]);

    expect(DB::table('foodalchemist_knowledge_documents')->where('id', $doc->id)->value('team_id'))
        ->toBeNull();
});

it('ein Nicht-Master legt weiterhin team-eigen an', function () {
    config(['foodalchemist.master_team_id' => $this->rootTeam->id]);

    $doc = app(KnowledgeService::class)->create($this->childA, [
        'title' => 'Kind-Dossier', 'category' => 'regelwerk', 'content_md' => 'Inhalt.',
    ]);

    expect((int) DB::table('foodalchemist_knowledge_documents')->where('id', $doc->id)->value('team_id'))
        ->toBe($this->childA->id);
});
