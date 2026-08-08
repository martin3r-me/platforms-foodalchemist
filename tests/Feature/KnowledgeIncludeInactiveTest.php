<?php

use Illuminate\Support\Facades\DB;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * #3: include_inactive für knowledge.LIST + knowledge.SEARCH — deaktivierte Docs sind
 * normal ausgeblendet, mit include_inactive=true browsebar (zum Reaktivieren via
 * knowledge.SET_ACTIVE). Jede Zeile/jeder Treffer trägt active.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->registry = app(ToolRegistry::class);
    $this->kontext = new ToolContext($this->user, $this->rootTeam);

    foreach ([['inaktiv-wissen-beta', 'Inaktives Wissen Beta', false], ['aktiv-wissen-alpha', 'Aktives Wissen Alpha', true]] as [$slug, $title, $aktiv]) {
        DB::table('foodalchemist_knowledge_documents')->insert([
            'uuid' => (string) UuidV7::generate(),
            'team_id' => $this->rootTeam->id,
            'slug' => $slug,
            'title' => $title,
            'category' => 'cross_cutting',
            'content_md' => "# {$title}",
            'version' => 1,
            'content_hash' => hash('sha256', $slug),
            'imported_hash' => null,
            'char_count' => 20,
            'active' => $aktiv,
            'source_path' => null,
            'created_via' => 'test',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
});

it('LIST blendet inaktive per Default aus, zeigt sie mit include_inactive (+ active-Flag)', function () {
    $default = $this->registry->get('foodalchemist.knowledge.LIST')->execute(['category' => 'cross_cutting'], $this->kontext);
    expect(collect($default->data['documents'])->pluck('slug'))
        ->toContain('aktiv-wissen-alpha')->not->toContain('inaktiv-wissen-beta');

    $inkl = $this->registry->get('foodalchemist.knowledge.LIST')->execute(['category' => 'cross_cutting', 'include_inactive' => true], $this->kontext);
    $docs = collect($inkl->data['documents']);
    expect($docs->pluck('slug'))->toContain('inaktiv-wissen-beta')->toContain('aktiv-wissen-alpha')
        ->and((bool) $docs->firstWhere('slug', 'inaktiv-wissen-beta')['active'])->toBeFalse()
        ->and((bool) $docs->firstWhere('slug', 'aktiv-wissen-alpha')['active'])->toBeTrue();
});

it('SEARCH findet inaktive nur mit include_inactive', function () {
    $default = $this->registry->get('foodalchemist.knowledge.SEARCH')->execute(['q' => 'Beta'], $this->kontext);
    expect(collect($default->data['documents'])->pluck('slug'))->not->toContain('inaktiv-wissen-beta');

    $inkl = $this->registry->get('foodalchemist.knowledge.SEARCH')->execute(['q' => 'Beta', 'include_inactive' => true], $this->kontext);
    $treffer = collect($inkl->data['documents'])->firstWhere('slug', 'inaktiv-wissen-beta');
    expect($treffer)->not->toBeNull()->and((bool) $treffer['active'])->toBeFalse();
});
