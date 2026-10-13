<?php

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Livewire\Settings\Wissenskategorien;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 82 §13 H (Sofort-Fix): Die Einstellung „Wissens-Kategorien“ zeigt nur globale Kategorien und die eigene Team-Kette,
 * nie die Kategorien (oder Dossier-Zahlen) fremder Teams.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->fremd = Team::create(['name' => 'Fremd GmbH', 'user_id' => 1, 'personal_team' => false]);

    $this->kat = fn (?int $teamId, string $slug, string $label) => DB::table('foodalchemist_knowledge_categories')->insert([
        'uuid' => (string) UuidV7::generate(), 'team_id' => $teamId, 'slug' => $slug, 'label' => $label, 'description' => null,
        'sort_order' => 500, 'active' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->doc = fn (?int $teamId, string $slug, string $kategorie) => DB::table('foodalchemist_knowledge_documents')->insert([
        'uuid' => (string) UuidV7::generate(), 'slug' => $slug, 'title' => $slug, 'category' => $kategorie, 'content_md' => "# {$slug}\n",
        'version' => 1, 'content_hash' => hash('sha256', $slug), 'char_count' => 10, 'active' => 1, 'team_id' => $teamId,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    ($this->kat)(null, 'glob_kat', 'Globale Kategorie');
    ($this->kat)($this->rootTeam->id, 'eigene_kat', 'Eigene Kategorie');
    ($this->kat)($this->childA->id, 'kind_kat', 'Kind-A-Kategorie');
    ($this->kat)($this->fremd->id, 'fremde_kat', 'GEHEIME FREMDKATEGORIE');
});

it('zeigt nur globale und eigene Kategorien, nie die eines fremden Teams', function () {
    $this->actingAs($this->makeUser($this->rootTeam, 'Root User'));

    $kategorien = Livewire::test(Wissenskategorien::class)->viewData('kategorien');

    expect($kategorien->pluck('slug')->all())->toContain('glob_kat', 'eigene_kat')->not->toContain('fremde_kat');
    Livewire::test(Wissenskategorien::class)->assertSee('Globale Kategorie')->assertSee('Eigene Kategorie')->assertDontSee('GEHEIME FREMDKATEGORIE');
});

it('Eltern-Kategorien sind für das Unter-Team sichtbar (Kette), Geschwister und Fremde nicht', function () {
    $this->actingAs($this->makeUser($this->childA, 'Kind A User'));
    $slugs = Livewire::test(Wissenskategorien::class)->viewData('kategorien')->pluck('slug')->all();

    expect($slugs)->toContain('glob_kat', 'eigene_kat', 'kind_kat')->not->toContain('fremde_kat');

    $this->actingAs($this->makeUser($this->childB, 'Kind B User'));
    $slugsB = Livewire::test(Wissenskategorien::class)->viewData('kategorien')->pluck('slug')->all();
    expect($slugsB)->toContain('glob_kat', 'eigene_kat')->not->toContain('kind_kat')->not->toContain('fremde_kat');   // Geschwister-Kategorie nicht
});

it('die Dossier-Zähler zählen nur sichtbare Dokumente, nicht die eines fremden Teams', function () {
    ($this->doc)(null, 'g1', 'glob_kat');
    ($this->doc)($this->rootTeam->id, 'e1', 'glob_kat');
    ($this->doc)($this->fremd->id, 'f1', 'glob_kat');
    ($this->doc)($this->fremd->id, 'f2', 'glob_kat');
    $this->actingAs($this->makeUser($this->rootTeam, 'Root User'));

    $counts = Livewire::test(Wissenskategorien::class)->viewData('docCounts');

    expect((int) ($counts['glob_kat'] ?? 0))->toBe(2);   // global + eigenes, ohne die zwei fremden
});
