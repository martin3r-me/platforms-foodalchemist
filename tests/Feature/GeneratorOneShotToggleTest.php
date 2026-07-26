<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Platform\FoodAlchemist\Jobs\GenerateRecipeJob;
use Platform\FoodAlchemist\Livewire\Recipes\GeneratorModal;
use Platform\FoodAlchemist\Livewire\Verkauf\VkGeneratorModal;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 03 L7b-1 — der One-Shot-Toggle an BEIDEN Generator-Flächen, und die
 * dafür nötige Strecken-Vereinheitlichung.
 *
 * Zwei Dinge sind zu beweisen, und das zweite ist der eigentliche Punkt:
 *
 * 1. Der Toggle steht standardmäßig AN und reicht als `vollAnreichern` bis in
 *    den Job durch (nicht nur in die Property).
 * 2. Das **VK-Modal** generiert nicht mehr synchron im Web-Request, sondern
 *    dispatcht wie das Basisrezept-Modal (V-035). Genau daran hing die Frage,
 *    ob der Toggle auf der wichtigeren der beiden Flächen überhaupt baubar ist:
 *    die Kaskade kostet 1–4 weitere Provider-Calls, im Web-Request hätte das
 *    garantiert getimeoutet.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    Queue::fake();
});

it('Basisrezept-Generator: Toggle ist Default AN und geht als vollAnreichern in den Job', function () {
    Livewire::test(GeneratorModal::class)
        ->assertSet('vollAnreichern', true)
        ->set('description', 'Dunkle Rotwein-Schalotten-Reduktion')
        ->call('generieren')
        ->assertSet('laeuft', true);

    Queue::assertPushed(GenerateRecipeJob::class, fn ($job) => $job->vollAnreichern === true && $job->vkModus === false);
});

it('VK-Generator: dispatcht in die Queue statt synchron zu rechnen (V-035)', function () {
    Livewire::test(VkGeneratorModal::class)
        ->assertSet('vollAnreichern', true)
        ->set('description', 'Geschmortes Rind mit Wurzelgemüse')
        ->call('generieren')
        ->assertSet('laeuft', true)
        ->assertSet('ergebnis', null);          // nichts wird im Request gerechnet

    Queue::assertPushed(GenerateRecipeJob::class, fn ($job) => $job->vkModus === true && $job->vollAnreichern === true);
});

it('Toggle aus lässt den Job den Bestandspfad fahren (kein Anreicherungs-Pass)', function () {
    Livewire::test(VkGeneratorModal::class)
        ->set('description', 'Kartoffelgratin als Beilage')
        ->set('vollAnreichern', false)
        ->call('generieren');

    Queue::assertPushed(GenerateRecipeJob::class, fn ($job) => $job->vollAnreichern === false);
});

it('One-Shot hebt das Job-Timeout an (Kaskade = 1–4 Calls mehr, Kill wäre ein halbes Wrack)', function () {
    expect((new GenerateRecipeJob('r', 1, 1, 'x', [], false, false))->timeout)->toBe(300)
        ->and((new GenerateRecipeJob('r', 1, 1, 'x', [], true, true))->timeout)->toBe(540)   // < Worker-Timeout 600 s
        ->and((new GenerateRecipeJob('r', 1, 1, 'x', [], true, true))->tries)->toBe(1);      // kein stiller Auto-Retry der KI-Kosten
});

it('leere Beschreibung startet keinen Lauf (beide Flächen)', function () {
    Livewire::test(GeneratorModal::class)->set('description', '   ')->call('generieren')
        ->assertSet('laeuft', false)->assertSet('fehler', 'Beschreibung ist Pflicht.');
    Livewire::test(VkGeneratorModal::class)->set('description', '')->call('generieren')
        ->assertSet('laeuft', false)->assertSet('fehler', 'Beschreibung ist Pflicht.');

    Queue::assertNothingPushed();
});

it('Poll übernimmt den Anreicherungs-Block und zeigt ihn als Ergebnis-Zeile', function () {
    $comp = Livewire::test(VkGeneratorModal::class)
        ->set('description', 'Lachsforelle mit Fenchel')
        ->call('generieren');

    $runId = $comp->get('runId');
    Cache::put(GenerateRecipeJob::cacheKey($runId), [
        'status' => 'done', 'recipe_id' => 4242, 'name' => '[HG] Lachsforelle | Fenchel',
        'statistik' => ['bestand_gp' => 3, 'bestand_sub' => 1, 'stub_neu' => 0, 'offen' => 0],
        'offene' => [],
        'anreicherung' => [
            'run_id' => 7, 'schritte' => ['description', 'wording'], 'uebersprungen' => ['plating'],
            'uebernommen' => 2, 'offen' => 0, 'fehler' => null,
        ],
    ], now()->addMinutes(5));

    $comp->call('pruefeErgebnis')
        ->assertSet('laeuft', false)
        ->assertSet('anreicherung.uebernommen', 2)
        ->assertSee('2 Felder gefüllt')
        ->assertSee('1 schon belegt')
        ->assertDispatched('vk-recipe-selected');
});

it('gescheiterte Anreicherung ist eine Lücken-Zeile, kein Generierungs-Fehler', function () {
    $comp = Livewire::test(GeneratorModal::class)
        ->set('description', 'Kalbsfond')
        ->call('generieren');

    Cache::put(GenerateRecipeJob::cacheKey($comp->get('runId')), [
        'status' => 'done', 'recipe_id' => 99, 'name' => 'Kalbsfond',
        'statistik' => ['bestand_gp' => 2, 'bestand_sub' => 0, 'stub_neu' => 0, 'offen' => 0],
        'offene' => [],
        'anreicherung' => [
            'run_id' => 8, 'schritte' => ['description'], 'uebersprungen' => [],
            'uebernommen' => 0, 'offen' => 0, 'fehler' => 'Provider nicht erreichbar',
        ],
    ], now()->addMinutes(5));

    $comp->call('pruefeErgebnis')
        ->assertSet('fehler', null)                       // die Generierung selbst war erfolgreich
        ->assertSee('Kalbsfond')
        ->assertSee('Anreicherung unvollständig');
});

it('Job-Fehler kommt weiter als Fehler durch (VK-Pfad verliert die Meldung nicht)', function () {
    $comp = Livewire::test(VkGeneratorModal::class)
        ->set('description', 'Irgendwas')
        ->call('generieren');

    Cache::put(GenerateRecipeJob::cacheKey($comp->get('runId')), [
        'status' => 'error', 'fehler' => 'KI-Provider ist deaktiviert.',
    ], now()->addMinutes(5));

    $comp->call('pruefeErgebnis')
        ->assertSet('laeuft', false)
        ->assertSet('ergebnis', null)
        ->assertSet('fehler', 'KI-Provider ist deaktiviert.');
});
