<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Platform\FoodAlchemist\Enums\BulkProposalStatus;
use Platform\FoodAlchemist\Enums\BulkRunStatus;
use Platform\FoodAlchemist\Enums\BulkRunType;
use Platform\FoodAlchemist\Jobs\BulkEnrichGpJob;
use Platform\FoodAlchemist\Livewire\Gps\GpModal;
use Platform\FoodAlchemist\Models\FoodAlchemistBulkGpProposal;
use Platform\FoodAlchemist\Models\FoodAlchemistBulkRun;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Services\Ai\AiProposal;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * io 29.10.2026, GP „Amerikaner mit Zuckerguss“: „Alles anreichern“ meldete „0 Vorschläge“, obwohl Anker (sponge_cake) und Zustand offen
 * in der Datenbank standen. Ursache: `status` ist ein Enum, die Ansicht verglich mit 'running' → nie gepollt, sofort „fertig: 0“,
 * danach ein zweiter Klick → zweiter Lauf. Dazu: ein leerer KI-Wert endete still als „Leer“.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    config(['foodalchemist.bearbeitungssperre' => false]);
    $this->gp = $this->makeGp($this->rootTeam, 'Amerikaner mit Zuckerguss: TK, 25 g pro Stück, mini');
    DB::table('foodalchemist_vocab_pairing_anchors')->insert(['uuid' => (string) Str::uuid7(), 'slug' => 'sponge_cake', 'display_de' => 'Rührkuchen',
        'created_at' => now(), 'updated_at' => now()]);
    $this->ki = function (array $antworten) {
        $this->mock(AiGatewayService::class, function ($mock) use ($antworten) {
            $mock->shouldReceive('propose')->andReturnUsing(fn (string $key) => new AiProposal($antworten[$key] ?? [], 0.8, "Mock {$key}", [], $key));
            $mock->shouldReceive('stempleAccepted', 'stempleRejected')->andReturnNull();
        });
    };
});

it('ein laufender Lauf pollt und zeigt „läuft“, nicht „fertig: 0 Vorschläge“', function () {
    Queue::fake();                                                     // Job liegt in der Queue, Lauf bleibt „running“ (wie auf io)

    Livewire::test(GpModal::class)->call('oeffnen', $this->gp->id)->call('allesAnreichern')
        ->assertSeeHtml('wire:poll.2s')->assertSee('Anreicherung läuft')->assertDontSee('Anreicherung fertig');
});

it('ein Lauf mit offenem Anker-Vorschlag zeigt mindestens einen Vorschlag', function () {
    $geschmack = ['suess' => 0.7, 'salzig' => 0.1, 'sauer' => 0, 'bitter' => 0, 'umami' => 0, 'fettig' => 0.3, 'scharf' => 0];
    ($this->ki)(['gp.anker' => ['anchor_slug' => 'sponge_cake'], 'gp.condition' => ['condition' => 'TK'], 'gp.tags' => ['tags' => []],
        'gp.sensorik' => ['geschmack' => $geschmack]]);

    $c = Livewire::test(GpModal::class)->call('oeffnen', $this->gp->id)->call('allesAnreichern');

    $runId = $c->get('bulkRunId');
    expect(FoodAlchemistBulkGpProposal::where('run_id', $runId)->where('field', 'anker')->value('status'))->toBe(BulkProposalStatus::Offen);
    $c->assertSee('Anreicherung fertig: 3 Vorschläge zum Übernehmen')->assertDontSeeHtml('data-gp-anreichern-fehler');   // anker, condition, sensorik (tags [] = keine Änderung)
});

it('ein zweiter Klick startet keinen zweiten Lauf, solange der erste läuft', function () {
    Queue::fake();

    $c = Livewire::test(GpModal::class)->call('oeffnen', $this->gp->id)->call('allesAnreichern');
    $erster = $c->get('bulkRunId');
    $c->call('allesAnreichern');

    expect($c->get('bulkRunId'))->toBe($erster)
        ->and(FoodAlchemistBulkRun::where('type', BulkRunType::EnrichGp)->count())->toBe(1);
    Queue::assertPushed(BulkEnrichGpJob::class, 1);
});

it('nach Ende des ersten Laufs ist ein neuer Lauf wieder möglich', function () {
    Queue::fake();
    $c = Livewire::test(GpModal::class)->call('oeffnen', $this->gp->id)->call('allesAnreichern');
    FoodAlchemistBulkRun::whereKey($c->get('bulkRunId'))->update(['status' => BulkRunStatus::Done->value]);

    $c->call('allesAnreichern');

    expect(FoodAlchemistBulkRun::where('type', BulkRunType::EnrichGp)->count())->toBe(2);
});

it('eine leere KI-Antwort endet nicht still: Grund am Vorschlag, Fehler am Lauf, Hinweis im Dialog', function () {
    ($this->ki)(['gp.condition' => ['condition' => null], 'gp.tags' => ['tags' => []]]);   // anker: [] (leere Antwort)

    $c = Livewire::test(GpModal::class)->call('oeffnen', $this->gp->id)->call('allesAnreichern');
    $runId = $c->get('bulkRunId');

    expect(FoodAlchemistBulkGpProposal::where('run_id', $runId)->where('field', 'anker')->value('error'))->toContain('KI-Antwort ohne Werte')
        ->and(FoodAlchemistBulkGpProposal::where('run_id', $runId)->where('field', 'condition')->value('error'))->toBeNull()   // ausdrücklich „kein Zustand“
        ->and(FoodAlchemistBulkRun::find($runId)->failed)->toBe(1);
    $c->assertSeeHtml('data-gp-anreichern-fehler')->assertSee('Aroma-Anker: KI-Antwort ohne Werte');
});

it('ein Anker außerhalb der Vorauswahl wird mit Grund gemeldet', function () {
    ($this->ki)(['gp.anker' => ['anchor_slug' => 'butter_cake'], 'gp.condition' => ['condition' => 'TK'], 'gp.tags' => ['tags' => []]]);

    $c = Livewire::test(GpModal::class)->call('oeffnen', $this->gp->id)->call('allesAnreichern');

    expect(FoodAlchemistBulkGpProposal::where('run_id', $c->get('bulkRunId'))->where('field', 'anker')->value('error'))
        ->toContain('KI wählte „butter_cake“, nicht in der Vorauswahl');
});

it('laeuft(): running ja, verwaist nein, fertig nein', function () {
    $lauf = FoodAlchemistBulkRun::starte($this->rootTeam->id, BulkRunType::EnrichGp, 1, []);
    expect($lauf->laeuft())->toBeTrue();

    $lauf->update(['updated_at' => now()->subHours(FoodAlchemistBulkRun::VERWAIST_NACH_STUNDEN + 1)]);
    expect($lauf->fresh()->laeuft())->toBeFalse();

    $lauf->update(['status' => BulkRunStatus::Done]);
    expect($lauf->fresh()->laeuft())->toBeFalse();
});

it('VK- und Rezept-Dialog pollen, solange ihr Anreicherungs-Lauf läuft (gleiche Enum-Falle)', function () {
    $lauf = FoodAlchemistBulkRun::starte($this->rootTeam->id, BulkRunType::Enrich, 1, []);
    $vk = \Platform\FoodAlchemist\Models\FoodAlchemistRecipe::create(['team_id' => $this->rootTeam->id, 'recipe_key' => 'vk-lauf',
        'name' => '[HG] Senfrostbraten | Jus', 'status' => 'draft', 'is_sales_recipe' => true]);
    $basis = \Platform\FoodAlchemist\Models\FoodAlchemistRecipe::create(['team_id' => $this->rootTeam->id, 'recipe_key' => 'basis-lauf',
        'name' => 'Jus: Kalb', 'status' => 'draft', 'is_sales_recipe' => false]);

    Livewire::test(\Platform\FoodAlchemist\Livewire\Verkauf\VkModal::class)->call('oeffnen', $vk->id)->set('bulkRunId', $lauf->id)
        ->assertSeeHtml('data-vk-anreichern-status')->assertSeeHtml('wire:poll.2s')->assertSee('Anreicherung läuft')
        ->assertDontSeeHtml('data-vk-anreichern-uebernehmen');
    Livewire::test(\Platform\FoodAlchemist\Livewire\Recipes\RecipeModal::class)->call('oeffnen', $basis->id)->set('bulkRunId', $lauf->id)
        ->assertSeeHtml('data-anreichern-status')->assertSee('Anreicherung läuft')->assertDontSeeHtml('data-anreichern-uebernehmen');

    $lauf->update(['status' => BulkRunStatus::Done]);
    Livewire::test(\Platform\FoodAlchemist\Livewire\Verkauf\VkModal::class)->call('oeffnen', $vk->id)->set('bulkRunId', $lauf->id)
        ->assertSeeHtml('data-vk-anreichern-uebernehmen')->assertDontSee('Anreicherung läuft');
});
