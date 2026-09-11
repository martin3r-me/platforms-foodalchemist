<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 52 — der Umzug des kuratierten Bestands nach global.
 *
 * Der Befehl ist destruktiv in dem Sinn, dass sich sein Ergebnis nicht aus den Daten
 * zurückrechnen lässt: nach dem Heben ist „war mal Team 6" von „war schon immer global"
 * nicht mehr zu unterscheiden. Darum ist der Trockenlauf der Standard und das Protokoll
 * Teil des Vertrags, nicht Beiwerk.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();

    $this->mkDoc = fn (string $slug, ?int $teamId) => DB::table('foodalchemist_knowledge_documents')->insert([
        'uuid' => (string) UuidV7::generate(), 'team_id' => $teamId, 'slug' => $slug,
        'title' => 'T '.$slug, 'category' => 'regelwerk', 'content_md' => '# x',
        'version' => 1, 'content_hash' => hash('sha256', $slug), 'char_count' => 3,
        'active' => 1, 'created_via' => 'ui', 'created_at' => now(), 'updated_at' => now(),
    ]);
});

it('★ der Trockenlauf zaehlt, schreibt aber nichts', function () {
    ($this->mkDoc)('a', $this->rootTeam->id);
    ($this->mkDoc)('b', $this->rootTeam->id);

    $this->artisan('foodalchemist:wissen-global-heben', ['--team' => $this->rootTeam->id])
        ->expectsOutputToContain('2 Dossiers')
        ->expectsOutputToContain('Trockenlauf')
        ->assertSuccessful();

    // ⚠ NICHT `whereNull('team_id')->count() === 0` prüfen: die Fixture bringt sechs global
    // geseedete Dossiers aus den Migrationen mit. Das wäre ein Test gegen den Migrationsstand,
    // nicht gegen den Trockenlauf — und er würde beim nächsten Seed-Dossier ohne Grund rot.
    expect(DB::table('foodalchemist_knowledge_documents')->whereIn('slug', ['a', 'b'])
        ->whereNull('team_id')->count())->toBe(0);
});

it('hebt mit --apply und laesst fremde Teams in Ruhe', function () {
    ($this->mkDoc)('meins', $this->rootTeam->id);
    ($this->mkDoc)('fremd', $this->childA->id);

    $this->artisan('foodalchemist:wissen-global-heben', ['--team' => $this->rootTeam->id, '--apply' => true])
        ->assertSuccessful();

    expect(DB::table('foodalchemist_knowledge_documents')->where('slug', 'meins')->value('team_id'))->toBeNull()
        ->and((int) DB::table('foodalchemist_knowledge_documents')->where('slug', 'fremd')->value('team_id'))
        ->toBe($this->childA->id);
});

it('★ schreibt den Rueckweg mit — ohne die ID-Liste ist der Umzug unumkehrbar', function () {
    ($this->mkDoc)('schon_global', null);     // war vorher global — darf NICHT im Protokoll stehen
    ($this->mkDoc)('gehoben', $this->rootTeam->id);

    $this->artisan('foodalchemist:wissen-global-heben', ['--team' => $this->rootTeam->id, '--apply' => true])
        ->assertSuccessful();

    $dateien = glob(storage_path('logs/wissen-global-heben-*.json'));
    expect($dateien)->not->toBeEmpty();
    $protokoll = json_decode((string) file_get_contents(end($dateien)), true);
    $gehoben = (int) DB::table('foodalchemist_knowledge_documents')->where('slug', 'gehoben')->value('id');
    $schonGlobal = (int) DB::table('foodalchemist_knowledge_documents')->where('slug', 'schon_global')->value('id');

    expect($protokoll['ids'])->toBe([$gehoben])
        ->and($protokoll['ids'])->not->toContain($schonGlobal)
        ->and($protokoll['quell_team'])->toBe($this->rootTeam->id);

    foreach ($dateien as $f) {
        @unlink($f);
    }
});

it('verweigert den Lauf ohne Quell-Team, statt irgendetwas zu raten', function () {
    config(['foodalchemist.master_team_id' => null]);

    $this->artisan('foodalchemist:wissen-global-heben')->assertFailed();
});

it('nimmt ohne --team das konfigurierte Master-Team', function () {
    config(['foodalchemist.master_team_id' => $this->rootTeam->id]);
    ($this->mkDoc)('per_config', $this->rootTeam->id);

    $this->artisan('foodalchemist:wissen-global-heben', ['--apply' => true])->assertSuccessful();

    expect(DB::table('foodalchemist_knowledge_documents')->where('slug', 'per_config')->value('team_id'))->toBeNull();
    foreach (glob(storage_path('logs/wissen-global-heben-*.json')) as $f) {
        @unlink($f);
    }
});
