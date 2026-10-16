<?php

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Models\Team;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Livewire\Settings\Wissenssteuerung;
use Platform\FoodAlchemist\Support\MasterTeam;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 82 §13 H — P4: SCHREIBEN von globalem Wissen und globaler Steuerung nur das Master-Team (streng, ohne den Rückfall
 * „elternloses Team = Master"); die Wissens-Steuerung zeigt Nicht-Master nur den Hinweis.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    config(['foodalchemist.knowledge_master_regel' => true, 'foodalchemist.master_team_id' => null]);
    MasterTeam::flush();
    $this->master = Team::create(['name' => 'BHG.DIGITAL', 'user_id' => 1, 'personal_team' => false]);
    $this->registry = app(ToolRegistry::class);
    $zaehler = 0;
    $this->als = function (Team $team) use (&$zaehler): ToolContext {
        $user = $this->makeUser($team, 'U ' . $team->id . ' ' . (++$zaehler));
        $this->actingAs($user);

        return new ToolContext($user, $team);
    };
    $this->mitMaster = function () {
        config(['foodalchemist.master_team_id' => $this->master->id]);
        MasterTeam::flush();
    };
    $this->anlegen = fn (Team $team, string $titel) => $this->registry->get('foodalchemist.knowledge.POST')->execute(
        ['title' => $titel, 'category' => 'domain', 'content_md' => "# {$titel}\nInhalt."], ($this->als)($team));
});

it('★ Wurzel-Mandant legt Wissen team-eigen an, nicht global (auch ohne Master-Konfiguration)', function () {
    // Vorher: TeamScope::isMaster fiel ohne Konfiguration auf „elternlos" zurück → team_id NULL → der Mandant schrieb global und las es nie.
    $res = ($this->anlegen)($this->rootTeam, 'Mandanten Dossier');
    expect($res->success)->toBeTrue('POST: ' . ($res->error ?? ''));

    $zeile = DB::table('foodalchemist_knowledge_documents')->where('slug', $res->data['document']['slug'])->first();
    expect((int) $zeile->team_id)->toBe((int) $this->rootTeam->id);

    ($this->mitMaster)();
    $res2 = ($this->anlegen)($this->rootTeam, 'Mandanten Dossier Zwei');
    expect((int) DB::table('foodalchemist_knowledge_documents')->where('slug', $res2->data['document']['slug'])->value('team_id'))->toBe((int) $this->rootTeam->id);
});

it('Master-Team legt global an (team_id NULL)', function () {
    ($this->mitMaster)();
    $res = ($this->anlegen)($this->master, 'Master Dossier');

    expect(DB::table('foodalchemist_knowledge_documents')->where('slug', $res->data['document']['slug'])->value('team_id'))->toBeNull();
});

it('Mandant ändert kein globales/Master-Dokument per knowledge.PUT', function () {
    ($this->mitMaster)();
    $slug = ($this->anlegen)($this->master, 'Master Regel')->data['document']['slug'];

    $res = $this->registry->get('foodalchemist.knowledge.PUT')->execute(['slug' => $slug, 'content_md' => '# Fremd'], ($this->als)($this->childA));

    expect($res->success)->toBeFalse()
        ->and(DB::table('foodalchemist_knowledge_documents')->where('slug', $slug)->value('content_md'))->not->toContain('Fremd');
});

it('★ Routings und Budget: nur der Master schreibt (auch die Kategorie-Zeilen)', function () {
    ($this->mitMaster)();
    $routing = ['feature' => 'ai_test_feature', 'category' => 'kueche', 'mode' => 'discovery', 'max_docs' => 3];

    $mandant = $this->registry->get('foodalchemist.knowledge_routings.PUT')->execute($routing, ($this->als)($this->childA));
    expect($mandant->success)->toBeFalse()->and($mandant->errorCode)->toBe('FORBIDDEN')
        ->and(DB::table('foodalchemist_knowledge_routings')->where('feature', 'ai_test_feature')->count())->toBe(0);

    $master = $this->registry->get('foodalchemist.knowledge_routings.PUT')->execute($routing, ($this->als)($this->master));
    expect($master->success)->toBeTrue('Master: ' . ($master->error ?? ''));
});

it('Notbremse: ohne Regel schreibt ein elternloses Team trotzdem NICHT global (Spec 84 B1: eine Master-Definition)', function () {
    // Früher stellte die Notbremse den Rückfall „elternlos = Master“ wieder her. Seit B1 gibt es den nicht mehr —
    // ohne konfigurierten Master landet das Dokument im eigenen Team.
    config(['foodalchemist.knowledge_master_regel' => false]);
    $res = ($this->anlegen)($this->rootTeam, 'Alt Regel');

    expect(DB::table('foodalchemist_knowledge_documents')->where('slug', $res->data['document']['slug'])->value('team_id'))->not->toBeNull();
});

it('Wissens-Steuerung: Mandant sieht nur den Hinweis ohne Routings, der Master die Konfiguration', function () {
    ($this->mitMaster)();
    DB::table('foodalchemist_knowledge_routings')->insert([
        'feature' => 'ai_test_feature', 'category' => 'kueche', 'mode' => 'discovery', 'created_at' => now(), 'updated_at' => now(),
    ]);

    ($this->als)($this->childA);
    Livewire::test(Wissenssteuerung::class)
        ->assertViewHas('masterGesperrt', true)
        ->assertViewHas('routings', fn ($r) => count($r) === 0)
        ->assertSee('dem Master-Team vorbehalten');

    ($this->als)($this->master);
    Livewire::test(Wissenssteuerung::class)
        ->assertViewHas('masterGesperrt', false)
        ->assertViewHas('routings', fn ($r) => count($r) > 0);
});
