<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Models\FoodAlchemistRule;
use Platform\FoodAlchemist\Services\Knowledge\KnowledgeCanonService;
use Platform\FoodAlchemist\Services\Regeln\RegelKonsumentenPruefung;
use Platform\FoodAlchemist\Services\Regeln\RegelService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 81 — Schutz vor Paket 5: liest ein Prompt ein Regelwerk-Dossier mit aktiver Regel über den Kanon, muss er die
 * Regel nach der Bereinigung über den Regel-Block bekommen. Sonst verlöre er sie still.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->regel = FoodAlchemistRule::where('schluessel', 'basisrezept.1.2.typ')->firstOrFail();
    DB::table('foodalchemist_knowledge_documents')->insert([
        'uuid' => (string) \Symfony\Component\Uid\UuidV7::generate(), 'team_id' => $this->rootTeam->id,
        'slug' => $this->regel->dossier_slug, 'title' => 'Typ-Vokabular', 'category' => 'regelwerk',
        'content_md' => 'Typen …', 'version' => 1, 'content_hash' => str_repeat('b', 64), 'char_count' => 9, 'active' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->kanon = fn (string $key) => app(KnowledgeCanonService::class)->set($this->rootTeam,
        ['scope' => 'prompt_key', 'scope_key' => $key, 'slug' => $this->regel->dossier_slug, 'mode' => 'pflicht']);
});

it('meldet einen Kanon-Leser einer aktiven Regel, der den Regel-Block nicht bekommt', function () {
    ($this->kanon)('recipe.generator');        // Konsument → kein Befund
    ($this->kanon)('vk.wording');              // bewusst ausgenommen → kein Befund
    ($this->kanon)('recipe.equipment');        // liest das Dossier, bekäme die Regel nach Paket 5 nicht mehr

    expect(app(RegelKonsumentenPruefung::class)->befunde())->toBe([
        ['prompt_key' => 'recipe.equipment', 'schluessel' => 'basisrezept.1.2.typ', 'dossier' => $this->regel->dossier_slug],
    ]);
    $this->artisan('foodalchemist:regeln-konsumenten')->assertExitCode(1);
});

it('eine ausgeschaltete Regel ist kein Befund (das Dossier trägt sie weiter)', function () {
    ($this->kanon)('recipe.equipment');
    app(RegelService::class)->setzeAktiv($this->regel->id, false);

    expect(app(RegelKonsumentenPruefung::class)->befunde())->toBe([]);
    $this->artisan('foodalchemist:regeln-konsumenten')->assertExitCode(0);
});
