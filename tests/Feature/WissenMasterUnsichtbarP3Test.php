<?php

use Illuminate\Support\Facades\DB;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Models\Team;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Support\MasterTeam;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 82 §13 H — P3: Steuer-/Diagnose-Sicht (Kanon, Routings, Profil, Versorgung, Embedding-Kontrolle) und Verbindungen.
 * Mandant: ACCESS_DENIED bzw. keine Master-Kanten; Master + dessen Unter-Teams unverändert; Notbremse = alte Sicht.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    config(['foodalchemist.knowledge_master_regel' => true]);
    $this->master = Team::create(['name' => 'BHG.DIGITAL', 'user_id' => 1, 'personal_team' => false]);
    $this->masterKind = Team::create(['name' => 'BHG Kind', 'user_id' => 1, 'personal_team' => false, 'parent_team_id' => $this->master->id]);
    config(['foodalchemist.master_team_id' => $this->master->id]);
    MasterTeam::flush();

    $this->registry = app(ToolRegistry::class);
    $zaehler = 0;
    $this->als = function (Team $team) use (&$zaehler): ToolContext {
        $user = $this->makeUser($team, 'U ' . $team->id . ' ' . (++$zaehler));
        $this->actingAs($user);

        return new ToolContext($user, $team);
    };
    $this->doc = function (int $teamId, string $slug): void {
        DB::table('foodalchemist_knowledge_documents')->insert([
            'uuid' => (string) UuidV7::generate(), 'team_id' => $teamId, 'slug' => $slug, 'title' => $slug,
            'category' => 'regelwerk', 'content_md' => "## {$slug}\nInhalt.", 'version' => 1,
            'content_hash' => hash('sha256', $slug), 'char_count' => 20, 'active' => 1, 'created_via' => 'ui',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    };
});

dataset('steuerSichten', [
    'Kanon' => [['foodalchemist.knowledge_canon.GET', []]],
    'Routings' => [['foodalchemist.knowledge_routings.GET', []]],
    'Profil' => [['foodalchemist.knowledge_profil.GET', ['prompt_key' => 'recipe.generator']]],
    'Versorgung' => [['foodalchemist.knowledge_versorgung.GET', ['prompt_key' => 'recipe.generator']]],
    'Embedding-Kontrolle' => [['foodalchemist.knowledge.EMBED_STATUS', []]],
]);

it('★ Mandant bekommt die Master-Steuer-Sicht nicht (ACCESS_DENIED)', function (array $fall) {
    [$tool, $args] = $fall;
    $res = $this->registry->get($tool)->execute($args, ($this->als)($this->childA));

    expect($res->success)->toBeFalse()->and($res->errorCode)->toBe('ACCESS_DENIED');
})->with('steuerSichten');

it('Master-Team und dessen Unter-Team bekommen die Steuer-Sicht weiter', function (array $fall) {
    [$tool, $args] = $fall;
    foreach ([$this->master, $this->masterKind] as $team) {
        $res = $this->registry->get($tool)->execute($args, ($this->als)($team));

        expect($res->errorCode ?? null)->not->toBe('ACCESS_DENIED');
    }
})->with('steuerSichten');

it('Notbremse knowledge_master_regel=false: Steuer-Sicht wie früher offen', function () {
    config(['foodalchemist.knowledge_master_regel' => false]);
    $res = $this->registry->get('foodalchemist.knowledge_canon.GET')->execute([], ($this->als)($this->childA));

    expect($res->errorCode ?? null)->not->toBe('ACCESS_DENIED');
});

it('★ Verbindungen: Mandant sieht Master-Kanten nicht, der Master schon', function () {
    ($this->doc)($this->master->id, 'master-regel-alt');
    ($this->doc)($this->master->id, 'master-regel-neu');
    $setzen = $this->registry->get('foodalchemist.knowledge_links.SET')->execute(
        ['action' => 'set', 'von' => 'master-regel-neu', 'nach' => 'master-regel-alt', 'art' => 'ersetzt'], ($this->als)($this->master));
    expect($setzen->success)->toBeTrue('set: ' . ($setzen->error ?? ''));

    $mandant = $this->registry->get('foodalchemist.knowledge_links.SET')->execute(
        ['action' => 'get', 'slug' => 'master-regel-neu'], ($this->als)($this->childA));
    // Unlesbares Dossier = leere Auskunft, kein Fehler (sonst verriete der Fehler, dass es das Dossier gibt).
    expect($mandant->data['raus'])->toBe([])->and($mandant->data['rein'])->toBe([]);

    $master = $this->registry->get('foodalchemist.knowledge_links.SET')->execute(
        ['action' => 'get', 'slug' => 'master-regel-neu'], ($this->als)($this->masterKind));
    expect($master->success)->toBeTrue()->and(json_encode($master->data))->toContain('master-regel-alt');
});

it('Gegenprobe Ablauf-Ausnahme: art=ablauf in anderer Kategorie (z. B. wissensmodul_intern) bleibt für Mandanten unlesbar', function () {
    // Die 5 internen Wissensmodul-Dokumente liegen seit 13.10. in der Team-6-Kategorie wissensmodul_intern (art/Inhalt unverändert).
    foreach (['workflow' => 'anleitung-vorgang', 'wissensmodul_intern' => 'wissensmodul-intern-doc'] as $kategorie => $slug) {
        DB::table('foodalchemist_knowledge_documents')->insert([
            'uuid' => (string) UuidV7::generate(), 'team_id' => null, 'slug' => $slug, 'title' => $slug, 'category' => $kategorie, 'art' => 'ablauf',
            'content_md' => "---\ngilt_fuer_vorgang: basisrezept_anlegen\n---\n## x", 'version' => 1, 'content_hash' => hash('sha256', $slug),
            'char_count' => 20, 'active' => 1, 'created_via' => 'mcp', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    $get = $this->registry->get('foodalchemist.knowledge.GET');
    $kontext = ($this->als)($this->childA);

    expect($get->execute(['slug' => 'anleitung-vorgang'], $kontext)->success)->toBeTrue()
        ->and($get->execute(['slug' => 'wissensmodul-intern-doc'], $kontext)->success)->toBeFalse();
});
