<?php

use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Platform\FoodAlchemist\Jobs\EnrichRecipeJob;
use Platform\FoodAlchemist\Livewire\Planung\RezeptImport;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Services\Ai\AiProposal;
use Platform\FoodAlchemist\Services\PlanningSessionService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Rezept-Import mit Leitplanken (Kurator/Dominique 27.10.): Bio/Frische/Convenience der Planungs-Session gehen an legeAn und die Anreicherung
 * (vorher immer leer). Im Import-Dialog sichtbar und NUR für diesen Import änderbar — die Session bleibt unverändert. Ohne Session: Standard.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    config(['foodalchemist.semantic_search.enabled' => false]);
    FoodAlchemistVocabEinheit::create(['team_id' => $this->rootTeam->id, 'slug' => 'g', 'display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1]);
    $this->nr = 0;
    $this->gp = fn (string $name, string $slug, string $zustand, ?string $bio = null) => FoodAlchemistGp::create([
        'team_id' => $this->rootTeam->id, 'name' => $name, 'gp_key' => 'il-' . (++$this->nr) . '||', 'status' => 'approved', 'requires_la' => true,
        'commodity_group_code' => '01', 'condition' => $zustand, 'main_ingredient_slug' => $slug, 'bio' => $bio]);
    $this->g = [
        'zucker' => ($this->gp)('Rohrohrzucker: trocken', 'rohrohrzucker', 'trocken'),
        'zuckerBio' => ($this->gp)('Rohrohrzucker: trocken / (Bio)', 'rohrohrzucker', 'trocken', 'bio'),
        'erbsenFrisch' => ($this->gp)('Erbse: frisch', 'erbse', 'frisch'),
        'erbsenTk' => ($this->gp)('Erbse: TK', 'erbse', 'TK'),
    ];
    $this->mock(AiGatewayService::class, fn ($m) => $m->shouldReceive('propose')->andReturn(new AiProposal([
        'typ' => 'basisrezept', 'name' => 'Erbsenpüree süß', 'preparation' => 'Kochen.', 'komponenten' => [],
        'zutaten' => [['text' => 'Rohrohrzucker', 'quantity' => '10', 'unit' => 'g'], ['text' => 'Erbsen', 'quantity' => '500', 'unit' => 'g']],
    ], 0.9, 'Mock', [], 'extract')));
    $this->session = function (array $params) {
        $s = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'Import', 'brief' => 'x']);
        $s->forceFill(['generation_params' => $params])->save();

        return $s->fresh();
    };
    $this->zeilen = fn () => FoodAlchemistRecipe::where('team_id', $this->rootTeam->id)->where('name', 'Erbsenpüree süß')->latest('id')->firstOrFail()
        ->ingredients()->pluck('gp_id', 'raw_text')->all();
});

it('ohne Session: Standard (konventionell, frisch) — sichtbar als „Standard“, Abgleich konventionell und frisch', function () {
    Queue::fake();
    Livewire::test(RezeptImport::class)->set('importText', 'x')->call('importExtrahieren')
        ->assertSet('abgleichBio', 'konventionell')->assertSet('abgleichFrische', 'frisch')->assertSet('abgleichAusSession', false)
        ->assertSeeHtml('data-import-abgleich-text')->assertSee('Standard')
        ->call('importAnlegen');

    expect(($this->zeilen)())->toBe(['Rohrohrzucker' => $this->g['zucker']->id, 'Erbsen' => $this->g['erbsenFrisch']->id]);
});

it('Session-Leitplanken Bio + TK: vorbelegt, Abgleich nimmt Bio-GP und TK-Variante, die Anreicherung bekommt dieselben Leitplanken', function () {
    Queue::fake();
    $s = ($this->session)(['bio_pref' => 'bio', 'bio' => true, 'frische' => 'tk', 'frische_erlaubt' => ['TK', 'trocken']]);

    Livewire::test(RezeptImport::class, ['sessionId' => $s->id])->set('importText', 'x')->call('importExtrahieren')
        ->assertSet('abgleichBio', 'bio')->assertSet('abgleichFrische', 'tk')->assertSet('abgleichAusSession', true)
        ->assertSee('aus der Planungs-Session')
        ->call('importAnlegen');

    expect(($this->zeilen)())->toBe(['Rohrohrzucker' => $this->g['zuckerBio']->id, 'Erbsen' => $this->g['erbsenTk']->id]);
    Queue::assertPushed(EnrichRecipeJob::class, fn ($job) => ($job->leitplanken['bio_pref'] ?? null) === 'bio' && ($job->leitplanken['frische'] ?? null) === 'tk');
});

it('Override im Dialog wirkt nur auf diesen Import — die Session bleibt unverändert', function () {
    Queue::fake();
    $params = ['bio_pref' => 'bio', 'bio' => true, 'frische' => 'tk', 'frische_erlaubt' => ['TK', 'trocken']];
    $s = ($this->session)($params);

    Livewire::test(RezeptImport::class, ['sessionId' => $s->id])->set('importText', 'x')->call('importExtrahieren')
        ->set('abgleichBio', 'konventionell')->set('abgleichFrische', 'frisch')
        ->call('importAnlegen');

    expect(($this->zeilen)())->toBe(['Rohrohrzucker' => $this->g['zucker']->id, 'Erbsen' => $this->g['erbsenFrisch']->id])
        ->and($s->fresh()->generation_params)->toBe($params);
    Queue::assertPushed(EnrichRecipeJob::class, fn ($job) => ($job->leitplanken['bio_pref'] ?? null) === 'conventional');
});
