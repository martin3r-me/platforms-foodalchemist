<?php

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Settings\Wissenskategorien;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Kategorie-Massen-Delete (Wissen-Modul): Kategorie SAMT aller löschbaren Docs endgültig
 * entfernen (echtes Aufräumen). Prüft Reichweite (eigene + global-als-Master), FK-Cascade
 * auf Alias/Bindung und die Mandanten-Trennung (fremde Docs bleiben, Kategorie dann nicht weg).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();

    $this->mkKat = function (?int $teamId, string $slug, string $label): int {
        return (int) DB::table('foodalchemist_knowledge_categories')->insertGetId([
            'uuid' => (string) UuidV7::generate(), 'team_id' => $teamId, 'slug' => $slug,
            'label' => $label, 'description' => null, 'sort_order' => 500, 'active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    };

    $this->mkDoc = function (?int $teamId, string $slug, string $category): int {
        return (int) DB::table('foodalchemist_knowledge_documents')->insertGetId([
            'uuid' => (string) UuidV7::generate(), 'slug' => $slug, 'title' => $slug,
            'category' => $category, 'content_md' => "# {$slug}\n", 'version' => 1,
            'content_hash' => hash('sha256', $slug), 'char_count' => 10, 'active' => 1,
            'team_id' => $teamId, 'created_at' => now(), 'updated_at' => now(),
        ]);
    };
});

it('löscht als Master die Kategorie samt globaler + eigener Docs inkl. FK-Cascade', function () {
    $this->actingAs($this->makeUser($this->rootTeam, 'Master User'));   // rootTeam = kein parent = Master

    $katId = ($this->mkKat)(null, 'tmp_purge', 'Temp Purge');           // global, master-löschbar
    $globalDoc = ($this->mkDoc)(null, 'tmp_purge.global', 'tmp_purge'); // global
    $ownDoc = ($this->mkDoc)($this->rootTeam->id, 'tmp_purge.own', 'tmp_purge'); // eigen

    // Kind-Zeilen an einem Doc, um den Cascade zu belegen.
    DB::table('foodalchemist_knowledge_aliases')->insert([
        'alias_slug' => 'tmp_purge_alias', 'knowledge_document_id' => $ownDoc,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('foodalchemist_knowledge_bindings')->insert([
        'uuid' => (string) UuidV7::generate(), 'team_id' => $this->rootTeam->id,
        'knowledge_document_id' => $ownDoc, 'binding_type' => 'layer', 'target_key' => 'gp',
        'mode' => 'discovery', 'weight' => 0, 'active' => 1, 'source' => 'ui',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    Livewire::test(Wissenskategorien::class)
        ->call('delete', $katId)
        ->assertSet('fehler', null);

    // Kategorie + beide Docs weg.
    expect(DB::table('foodalchemist_knowledge_categories')->where('id', $katId)->exists())->toBeFalse();
    expect(DB::table('foodalchemist_knowledge_documents')->whereIn('id', [$globalDoc, $ownDoc])->exists())->toBeFalse();
    // Cascade: Kind-Zeilen mitgenommen.
    expect(DB::table('foodalchemist_knowledge_aliases')->where('knowledge_document_id', $ownDoc)->exists())->toBeFalse();
    expect(DB::table('foodalchemist_knowledge_bindings')->where('knowledge_document_id', $ownDoc)->exists())->toBeFalse();
});

it('verweigert einem Kind-Team das Löschen einer globalen Kategorie (Schutz des BHG-Korpus)', function () {
    $this->actingAs($this->makeUser($this->childA, 'Kind User'));       // childA hat parent = kein Master

    $katId = ($this->mkKat)(null, 'tmp_global', 'Temp Global');
    $doc = ($this->mkDoc)(null, 'tmp_global.doc', 'tmp_global');

    Livewire::test(Wissenskategorien::class)
        ->call('delete', $katId)
        ->assertSet('fehler', fn ($f) => $f !== null && str_contains($f, 'Master-Team'));

    // Nichts gelöscht.
    expect(DB::table('foodalchemist_knowledge_categories')->where('id', $katId)->exists())->toBeTrue();
    expect(DB::table('foodalchemist_knowledge_documents')->where('id', $doc)->exists())->toBeTrue();
});

it('lässt fremde Team-Docs unangetastet und behält dann die Kategorie', function () {
    $this->actingAs($this->makeUser($this->rootTeam, 'Master User'));

    $katId = ($this->mkKat)(null, 'tmp_mixed', 'Temp Mixed');
    $globalDoc = ($this->mkDoc)(null, 'tmp_mixed.global', 'tmp_mixed');   // fällt (Master)
    $fremdDoc = ($this->mkDoc)($this->childB->id, 'tmp_mixed.fremd', 'tmp_mixed'); // fremdes Team → bleibt

    Livewire::test(Wissenskategorien::class)
        ->call('delete', $katId)
        ->assertSet('fehler', fn ($f) => $f !== null && str_contains($f, 'anderer Teams'));

    // Globales Doc weg, Fremd-Doc bleibt, Kategorie NICHT entfernt.
    expect(DB::table('foodalchemist_knowledge_documents')->where('id', $globalDoc)->exists())->toBeFalse();
    expect(DB::table('foodalchemist_knowledge_documents')->where('id', $fremdDoc)->exists())->toBeTrue();
    expect(DB::table('foodalchemist_knowledge_categories')->where('id', $katId)->exists())->toBeTrue();
});
