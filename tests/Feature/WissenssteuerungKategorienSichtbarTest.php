<?php

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Livewire\Settings\Wissenssteuerung;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 82 §13 H (Sofort-Fix, Teil 2): Die Wissens-Steuerung listet Kategorien und ihre Namen nur aus globalen Kategorien und der eigenen
 * Team-Kette, nie die eines fremden Teams (gleiche Klasse wie der Fix an den Wissens-Kategorien).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $fremd = Team::create(['name' => 'Fremd GmbH', 'user_id' => 1, 'personal_team' => false]);
    $kat = fn (?int $teamId, string $slug, string $label) => DB::table('foodalchemist_knowledge_categories')->insert([
        'uuid' => (string) UuidV7::generate(), 'team_id' => $teamId, 'slug' => $slug, 'label' => $label, 'description' => null,
        'sort_order' => 500, 'active' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $kat(null, 'glob_kat', 'Globale Kategorie');
    $kat($this->rootTeam->id, 'eigene_kat', 'Eigene Kategorie');
    $kat($this->childA->id, 'kind_kat', 'Kind-A-Kategorie');
    $kat($fremd->id, 'fremde_kat', 'GEHEIME FREMDKATEGORIE');
});

it('Kategorie-Liste und Namen enthalten global und eigene, nie die eines fremden Teams', function () {
    $this->actingAs($this->makeUser($this->rootTeam, 'Root User'));

    $c = Livewire::test(Wissenssteuerung::class);

    expect($c->viewData('kategorien'))->toContain('glob_kat', 'eigene_kat')->not->toContain('fremde_kat')->not->toContain('kind_kat');
    expect($c->viewData('kategorieLabels'))->toHaveKeys(['glob_kat', 'eigene_kat'])->not->toHaveKey('fremde_kat');
    $c->assertDontSee('GEHEIME FREMDKATEGORIE');
});

it('Unter-Team sieht die Kette (Eltern + eigene), Geschwister und Fremde nicht', function () {
    $this->actingAs($this->makeUser($this->childA, 'Kind A User'));
    $a = Livewire::test(Wissenssteuerung::class);
    expect($a->viewData('kategorien'))->toContain('glob_kat', 'eigene_kat', 'kind_kat')->not->toContain('fremde_kat');

    $this->actingAs($this->makeUser($this->childB, 'Kind B User'));
    $b = Livewire::test(Wissenssteuerung::class);
    expect($b->viewData('kategorien'))->toContain('glob_kat', 'eigene_kat')->not->toContain('kind_kat')->not->toContain('fremde_kat');
});
