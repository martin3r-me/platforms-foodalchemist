<?php

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Models\Team;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Exceptions\WissenGesperrtException;
use Platform\FoodAlchemist\Services\FaRechte;
use Platform\FoodAlchemist\Services\Knowledge\KnowledgeCanonService;
use Platform\FoodAlchemist\Services\KnowledgeService;
use Platform\FoodAlchemist\Support\FaBereiche;
use Platform\FoodAlchemist\Support\MasterTeam;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Dominique 07.11.2026: Mandanten legen kein eigenes Wissen an und sehen das Wissens-Modul nicht. Das kuratierte Plattform-Wissen
 * des Master-Teams wirkt bei ihnen nur über den Prompt. Master und seine Unter-Teams pflegen weiter. Testdaten „TEST-WNM“.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->master = $this->rootTeam;   // Master-Familie: rootTeam + childA/childB
    config(['foodalchemist.master_team_id' => $this->master->id]);
    MasterTeam::flush();
    $this->mandant = Team::create(['name' => 'Caterer TEST-WNM', 'user_id' => 1, 'personal_team' => false]);
    $this->mandantUser = $this->makeUser($this->mandant, 'Admin TEST-WNM');
    $this->kurator = $this->makeUser($this->master, 'Kurator TEST-WNM');
});

it('Service: Mandant darf kein Wissen, keine Kategorie, keinen Kanon anlegen; Master und Unter-Team schon', function () {
    $svc = app(KnowledgeService::class);
    expect(fn () => $svc->create($this->mandant, ['title' => 'Eigene Regel TEST-WNM', 'category' => 'regelwerk', 'content_md' => 'x']))
        ->toThrow(WissenGesperrtException::class, 'Master-Team zentral')
        ->and(fn () => $svc->import($this->mandant, [['title' => 'Import TEST-WNM', 'category' => 'regelwerk', 'content_md' => 'x']]))->toThrow(WissenGesperrtException::class)
        ->and(fn () => $svc->createCategory($this->mandant, 'Hauswissen TEST-WNM'))->toThrow(WissenGesperrtException::class);

    $global = $svc->create($this->master, ['title' => 'Plattform-Regel TEST-WNM', 'category' => 'regelwerk', 'content_md' => 'x']);
    expect(DB::table('foodalchemist_knowledge_documents')->where('id', $global->id)->value('team_id'))->toBeNull()
        ->and(fn () => app(KnowledgeCanonService::class)->set($this->mandant, ['scope' => 'feature', 'scope_key' => 'recipe.generator', 'slug' => $global->slug, 'mode' => 'wenn_platz']))
        ->toThrow(\RuntimeException::class, 'Master-Team zentral');
    $kind = $svc->create($this->childA, ['title' => 'Standort-Notiz TEST-WNM', 'category' => 'regelwerk', 'content_md' => 'x']);
    expect((int) DB::table('foodalchemist_knowledge_documents')->where('id', $kind->id)->value('team_id'))->toBe($this->childA->id)
        ->and(DB::table('foodalchemist_knowledge_documents')->where('title', 'like', '%TEST-WNM%')->whereNotIn('id', [$global->id, $kind->id])->count())->toBe(0);
});

it('Bereich: Wissens-Modul nur im Master (auch nicht für Plattform-Admin im Mandanten); Observatorium bleibt für Mandanten', function () {
    $rechte = app(FaRechte::class);
    expect(FaBereiche::fuerRoute('foodalchemist.knowledge.index'))->toBe('wissensbasis')
        ->and(FaBereiche::fuerTool('foodalchemist.knowledge.POST'))->toBe('wissensbasis')
        ->and(FaBereiche::fuerTool('foodalchemist.knowledge_canon.PUT'))->toBe('wissensbasis')
        ->and(FaBereiche::fuerTool('foodalchemist.rules.PUT'))->toBe('wissensbasis')
        // Lese-Tools bleiben beim Mandanten (Vorgangs-Anleitungen, Regelwerks-Wirkung, Anker-Wissen)
        ->and(FaBereiche::fuerTool('foodalchemist.knowledge.GET'))->toBe('wissen')
        ->and(FaBereiche::fuerTool('foodalchemist.knowledge.SEARCH'))->toBe('wissen')
        ->and(FaBereiche::fuerTool('foodalchemist.regelwerk.GET'))->toBe('wissen')
        ->and(FaBereiche::fuerRoute('foodalchemist.observatorium.index'))->toBe('wissen')
        ->and(FaBereiche::fuerTool('foodalchemist.pairings.GET'))->toBe('wissen')
        ->and($rechte->darfBereich($this->mandantUser, $this->mandant, 'wissensbasis'))->toBeFalse()
        ->and($rechte->darfBereich($this->mandantUser, $this->mandant, 'wissen'))->toBeTrue()
        ->and($rechte->darfBereich($this->kurator, $this->master, 'wissensbasis'))->toBeTrue();

    $padmin = $this->makeUser($this->mandant, 'Plattform TEST-WNM');
    config(['platform-shell.admins' => [strtolower((string) $padmin->email)]]);
    expect($rechte->darfBereich($padmin, $this->mandant, 'wissensbasis'))->toBeFalse();

    // MCP: knowledge.POST im Mandanten FORBIDDEN, im Master erlaubt
    $post = app(ToolRegistry::class)->get('foodalchemist.knowledge.POST');
    $r = $post->execute(['title' => 'MCP TEST-WNM', 'category' => 'regelwerk', 'content_md' => 'x'], new ToolContext($this->mandantUser, $this->mandant));
    expect($r->success)->toBeFalse()->and($r->errorCode)->toBe('FORBIDDEN');

    // Einstellungen: Wissenskategorien für den Mandanten weg
    $this->actingAs($this->mandantUser);
    $this->mandantUser->forceFill(['current_team_id' => $this->mandant->id])->save();
    expect(Livewire::test(\Platform\FoodAlchemist\Livewire\Settings\Index::class)->html())->not->toContain('data-settings-link="wissenskategorien"');
});

it('Ohne Master-Team (autarke Installation) pflegt jedes Team sein Wissen wie bisher', function () {
    config(['foodalchemist.master_team_id' => null]);
    MasterTeam::flush();
    $doc = app(KnowledgeService::class)->create($this->mandant, ['title' => 'Autark TEST-WNM', 'category' => 'regelwerk', 'content_md' => 'x']);
    expect($doc->id)->toBeGreaterThan(0)
        ->and(app(FaRechte::class)->darfBereich($this->mandantUser, $this->mandant, 'wissensbasis'))->toBeTrue();
});
