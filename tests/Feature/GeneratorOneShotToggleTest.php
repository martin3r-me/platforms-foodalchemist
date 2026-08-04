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

it('Generierung und Anreicherung haben getrennte Job-Zeitfenster', function () {
    expect((new GenerateRecipeJob('r', 1, 1, 'x', [], false, false))->timeout)->toBe(300)
        ->and((new GenerateRecipeJob('r', 1, 1, 'x', [], true, true))->timeout)->toBe(300)
        ->and((new \Platform\FoodAlchemist\Jobs\EnrichGeneratedRecipeJob('r', 1, 1, 2, []))->timeout)->toBe(300)
        ->and((new GenerateRecipeJob('r', 1, 1, 'x', [], true, true))->tries)->toBe(1);
});

it('Poll zeigt das gespeicherte Rezept schon während der separaten Anreicherung', function () {
    $comp = Livewire::test(GeneratorModal::class)
        ->set('description', 'Kalbsfond')
        ->call('generieren');

    Cache::put(GenerateRecipeJob::cacheKey($comp->get('runId')), [
        'status' => 'enriching', 'recipe_id' => 98, 'name' => 'Kalbsfond',
        'statistik' => ['bestand_gp' => 2, 'bestand_sub' => 0, 'stub_neu' => 0, 'offen' => 0],
        'offene' => [],
    ], now()->addMinutes(5));

    $comp->call('pruefeErgebnis')
        ->assertSet('laeuft', true)
        ->assertSet('ergebnis.recipe_id', 98)
        ->assertSee('Vollanreicherung läuft separat')
        ->assertDispatched('recipe-selected');
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

// ── L7b-2: die beiden neuen Ergebnis-Flächen ────────────────────────────────

it('L7b-2: das Kohärenz-Urteil erscheint neben dem Aroma-Score, nicht verrechnet mit ihm', function () {
    $comp = Livewire::test(VkGeneratorModal::class)
        ->set('description', 'Rinderrücken mit Jus')
        ->call('generieren');

    Cache::put(GenerateRecipeJob::cacheKey($comp->get('runId')), [
        'status' => 'done', 'recipe_id' => 51, 'name' => '[TEL] Rinderrücken | Jus',
        'statistik' => ['bestand_gp' => 3, 'bestand_sub' => 1, 'stub_neu' => 0, 'stubs' => [], 'offen' => 0, 'kohaerenz' => 0.42],
        'offene' => [],
        'anreicherung' => [
            'run_id' => 11, 'schritte' => ['wording'], 'uebersprungen' => [],
            'uebernommen' => 1, 'offen' => 0, 'fehler' => null,
            'kohaerenz_urteil' => ['score' => 78, 'label' => 'Klassisch geschlossen', 'schwachstelle' => 'Säure fehlt', 'fehler' => null],
        ],
    ], now()->addMinutes(5));

    $comp->call('pruefeErgebnis')
        ->assertSee('Kohärenz 78 / 100')
        ->assertSee('Klassisch geschlossen')
        ->assertSee('Schwachstelle: Säure fehlt');
});

it('L7b-2: ein fehlendes Kohärenz-Urteil wird gesagt, nicht weggelassen', function () {
    $comp = Livewire::test(VkGeneratorModal::class)
        ->set('description', 'Irgendein Teller')
        ->call('generieren');

    Cache::put(GenerateRecipeJob::cacheKey($comp->get('runId')), [
        'status' => 'done', 'recipe_id' => 52, 'name' => '[TEL] Teller',
        'statistik' => ['bestand_gp' => 2, 'bestand_sub' => 0, 'stub_neu' => 0, 'stubs' => [], 'offen' => 0],
        'offene' => [],
        'anreicherung' => [
            'run_id' => 12, 'schritte' => ['wording'], 'uebersprungen' => [],
            'uebernommen' => 1, 'offen' => 0, 'fehler' => null,
            'kohaerenz_urteil' => ['score' => null, 'label' => null, 'schwachstelle' => null, 'fehler' => 'Judge lieferte kein verwertbares Urteil'],
        ],
    ], now()->addMinutes(5));

    $comp->call('pruefeErgebnis')
        ->assertSee('Kohärenz-Urteil offen')
        ->assertDontSee('/ 100');
});

it('L7b-2: neu angelegte Sub-Rezept-Stubs stehen beim Namen im Ergebnis — auch ohne One-Shot-Toggle', function () {
    $comp = Livewire::test(GeneratorModal::class)
        ->set('vollAnreichern', false)                       // Toggle aus ⇒ kein Anreicherungs-Block
        ->set('description', 'Rotwein-Reduktion')
        ->call('generieren');

    Cache::put(GenerateRecipeJob::cacheKey($comp->get('runId')), [
        'status' => 'done', 'recipe_id' => 53, 'name' => 'Reduktion: Rotwein-Schalotte',
        'statistik' => [
            'bestand_gp' => 2, 'bestand_sub' => 0, 'stub_neu' => 1, 'offen' => 0,
            'stubs' => [['id' => 777, 'name' => 'Kalbsfond braun']],
        ],
        'offene' => [],
    ], now()->addMinutes(5));

    $comp->call('pruefeErgebnis')
        ->assertSet('anreicherung', null)
        ->assertSee('ausrezeptieren offen')
        ->assertSee('Kalbsfond braun');
});

// ── L8b: die Wirtschaftlichkeits-Zeile an beiden Flächen ────────────────────

it('L8b: das bepreiste Ergebnis steht am Gericht — VK, Wareneinsatz-Ampel, Portion', function () {
    $comp = Livewire::test(VkGeneratorModal::class)
        ->set('description', 'Rinderrücken mit Jus')
        ->call('generieren');

    Cache::put(GenerateRecipeJob::cacheKey($comp->get('runId')), [
        'status' => 'done', 'recipe_id' => 61, 'name' => '[TEL] Rinderrücken | Jus',
        'statistik' => ['bestand_gp' => 3, 'bestand_sub' => 1, 'stub_neu' => 0, 'stubs' => [], 'offen' => 0],
        'offene' => [],
        'anreicherung' => [
            'run_id' => 21, 'schritte' => [], 'uebersprungen' => [], 'uebernommen' => 0, 'offen' => 0, 'fehler' => null,
            'wirtschaftlichkeit' => [
                'sales_net' => 9.6, 'ek_total_eur' => 24.0, 'ek_pro_portion' => 2.4,
                'wareneinsatz_pct' => 25.0, 'ziel_pct' => 30.0, 'ampel' => 'gruen',
                'portion_g' => 200.0, 'aufschlagsklasse' => 'ALC', 'vorlaeufig' => false,
                'luecken' => [], 'signal' => false, 'fehler' => null,
            ],
        ],
    ], now()->addMinutes(5));

    $comp->call('pruefeErgebnis')
        ->assertSee('VK 9,60 €')
        ->assertSee('W 25,0 % / Ziel 30 %')
        ->assertSee('200 g / Portion')
        ->assertDontSee('Kein Auto-VK')
        ->assertDontSee('vorläufig');
});

it('L8b: eine fehlende Portion ist eine sichtbare Lücke, kein stiller Null-Preis (V-041)', function () {
    $comp = Livewire::test(VkGeneratorModal::class)
        ->set('description', 'Irgendein Teller')
        ->call('generieren');

    Cache::put(GenerateRecipeJob::cacheKey($comp->get('runId')), [
        'status' => 'done', 'recipe_id' => 62, 'name' => '[TEL] Teller',
        'statistik' => ['bestand_gp' => 2, 'bestand_sub' => 0, 'stub_neu' => 0, 'stubs' => [], 'offen' => 0],
        'offene' => [],
        'anreicherung' => [
            'run_id' => 22, 'schritte' => [], 'uebersprungen' => [], 'uebernommen' => 0, 'offen' => 0, 'fehler' => null,
            'wirtschaftlichkeit' => [
                'sales_net' => null, 'ek_total_eur' => 24.0, 'ek_pro_portion' => null,
                'wareneinsatz_pct' => null, 'ziel_pct' => 30.0, 'ampel' => 'unbekannt',
                'portion_g' => null, 'aufschlagsklasse' => null, 'vorlaeufig' => false,
                'luecken' => ['portion', 'aufschlagsklasse'], 'signal' => false, 'fehler' => null,
            ],
        ],
    ], now()->addMinutes(5));

    $comp->call('pruefeErgebnis')
        ->assertSee('Kein Auto-VK')
        ->assertSee('Portionsgröße + Aufschlagsklasse')
        ->assertDontSee('💰 VK');
});

it('L8b: unbepreiste Zutaten machen den VK „vorläufig", und der Signal-Fall sagt es', function () {
    $comp = Livewire::test(VkGeneratorModal::class)
        ->set('description', 'Teller mit Park-GP')
        ->call('generieren');

    Cache::put(GenerateRecipeJob::cacheKey($comp->get('runId')), [
        'status' => 'done', 'recipe_id' => 63, 'name' => '[TEL] Teller',
        'statistik' => ['bestand_gp' => 2, 'bestand_sub' => 0, 'stub_neu' => 0, 'stubs' => [], 'offen' => 0],
        'offene' => [],
        'anreicherung' => [
            'run_id' => 23, 'schritte' => [], 'uebersprungen' => [], 'uebernommen' => 0, 'offen' => 0, 'fehler' => null,
            'wirtschaftlichkeit' => [
                'sales_net' => 4.0, 'ek_total_eur' => 12.0, 'ek_pro_portion' => 1.6,
                'wareneinsatz_pct' => 40.0, 'ziel_pct' => 30.0, 'ampel' => 'gelb',
                'portion_g' => 180.0, 'aufschlagsklasse' => 'ALC', 'vorlaeufig' => true,
                'luecken' => [], 'signal' => true, 'fehler' => null,
            ],
        ],
    ], now()->addMinutes(5));

    $comp->call('pruefeErgebnis')
        ->assertSee('vorläufig')
        ->assertSee('Unbepreiste Zutaten im EK')
        ->assertSee('als Signal im Cockpit vermerkt');
});

it('L8b: das Basisrezept-Modal zeigt keine Wirtschaftlichkeit (Basisrezept hat keinen VK)', function () {
    $comp = Livewire::test(GeneratorModal::class)
        ->set('description', 'Kalbsfond')
        ->call('generieren');

    Cache::put(GenerateRecipeJob::cacheKey($comp->get('runId')), [
        'status' => 'done', 'recipe_id' => 64, 'name' => 'Fond: Kalb',
        'statistik' => ['bestand_gp' => 3, 'bestand_sub' => 0, 'stub_neu' => 0, 'stubs' => [], 'offen' => 0],
        'offene' => [],
        'anreicherung' => [
            'run_id' => 24, 'schritte' => [], 'uebersprungen' => ['description'], 'uebernommen' => 0,
            'offen' => 0, 'fehler' => null, 'kohaerenz_urteil' => null, 'wirtschaftlichkeit' => null,
        ],
    ], now()->addMinutes(5));

    $comp->call('pruefeErgebnis')
        ->assertDontSee('Kein Auto-VK')
        ->assertDontSee('/ Portion');
});

// ── L8b-2: die Ziel-VK-Pill (Eingabe) und ihr Abgleich (Ergebnis) ───────────

it('L8b-2: der Ziel-VK geht als Constraint in den Lauf — deutsch getippt, normalisiert', function () {
    Livewire::test(VkGeneratorModal::class)
        ->set('description', 'Vorspeise mit gebeiztem Lachs')
        ->set('zielVk', '8,50 €')
        ->call('generieren')
        ->assertSet('laeuft', true);

    // Ein Transportweg für beides: Prompt-Constraint UND Maßstab für den Abgleich.
    Queue::assertPushed(GenerateRecipeJob::class, fn ($job) => ($job->parameter['ziel_vk_eur'] ?? null) === 8.5);
});

it('L8b-2: leer bleibt leer — kein Constraint, kein Abgleich', function () {
    Livewire::test(VkGeneratorModal::class)
        ->set('description', 'Irgendein Teller')
        ->call('generieren');

    Queue::assertPushed(GenerateRecipeJob::class, fn ($job) => ! array_key_exists('ziel_vk_eur', $job->parameter));
});

it('L8b-2: eine unplausible Eingabe wird gesagt, nicht still verworfen (Absender ist ein Mensch)', function () {
    // 850 statt 8,50 wäre kein Fehler, sondern eine Vorgabe, gegen die nachher jedes
    // Ergebnis „viel zu billig" aussieht. Also: Rückfrage statt Lauf.
    Livewire::test(VkGeneratorModal::class)
        ->set('description', 'Teller')
        ->set('zielVk', '850')
        ->call('generieren')
        ->assertSet('laeuft', false)
        ->assertSee('bitte einen Netto-Preis je Portion');

    Queue::assertNothingPushed();
});

it('L8b-2: der Abgleich steht am Ergebnis — Vorgabe, Abstand und was der Zielpreis kostet', function () {
    $comp = Livewire::test(VkGeneratorModal::class)
        ->set('description', 'Rinderrücken mit Jus')
        ->set('zielVk', '6,00')
        ->call('generieren');

    Cache::put(GenerateRecipeJob::cacheKey($comp->get('runId')), [
        'status' => 'done', 'recipe_id' => 65, 'name' => '[TEL] Rinderrücken | Jus',
        'statistik' => ['bestand_gp' => 3, 'bestand_sub' => 1, 'stub_neu' => 0, 'stubs' => [], 'offen' => 0],
        'offene' => [],
        'anreicherung' => [
            'run_id' => 25, 'schritte' => [], 'uebersprungen' => [], 'uebernommen' => 0, 'offen' => 0, 'fehler' => null,
            'wirtschaftlichkeit' => [
                'sales_net' => 9.6, 'ek_total_eur' => 24.0, 'ek_pro_portion' => 2.4,
                'wareneinsatz_pct' => 25.0, 'ziel_pct' => 30.0, 'ampel' => 'gruen',
                'portion_g' => 200.0, 'aufschlagsklasse' => 'ALC', 'vorlaeufig' => false,
                'luecken' => [], 'signal' => false, 'ziel_vk' => 6.0, 'ziel_delta_eur' => 3.6,
                'ziel_wareneinsatz_pct' => 40.0, 'ziel_ampel' => 'gelb', 'fehler' => null,
            ],
        ],
    ], now()->addMinutes(5));

    $comp->call('pruefeErgebnis')
        ->assertSee('Ziel-VK 6,00 €')
        ->assertSee('bei Ziel-VK: W 40,0 % / Ziel 30 %')
        ->assertSee('3,60 € über dem Ziel')
        ->assertSee('VK 9,60 €');                    // der Ist-Preis steht unverändert daneben
});

it('L8b-2: ohne Vorgabe erscheint keine Ziel-Zeile', function () {
    $comp = Livewire::test(VkGeneratorModal::class)
        ->set('description', 'Teller ohne Zielpreis')
        ->call('generieren');

    Cache::put(GenerateRecipeJob::cacheKey($comp->get('runId')), [
        'status' => 'done', 'recipe_id' => 66, 'name' => '[TEL] Teller',
        'statistik' => ['bestand_gp' => 2, 'bestand_sub' => 0, 'stub_neu' => 0, 'stubs' => [], 'offen' => 0],
        'offene' => [],
        'anreicherung' => [
            'run_id' => 26, 'schritte' => [], 'uebersprungen' => [], 'uebernommen' => 0, 'offen' => 0, 'fehler' => null,
            'wirtschaftlichkeit' => [
                'sales_net' => 9.6, 'ek_total_eur' => 24.0, 'ek_pro_portion' => 2.4,
                'wareneinsatz_pct' => 25.0, 'ziel_pct' => 30.0, 'ampel' => 'gruen',
                'portion_g' => 200.0, 'aufschlagsklasse' => 'ALC', 'vorlaeufig' => false,
                'luecken' => [], 'signal' => false, 'ziel_vk' => null, 'ziel_delta_eur' => null,
                'ziel_wareneinsatz_pct' => null, 'ziel_ampel' => 'unbekannt', 'fehler' => null,
            ],
        ],
    ], now()->addMinutes(5));

    $comp->call('pruefeErgebnis')
        ->assertSee('VK 9,60 €')
        ->assertDontSee('Ziel-VK 6,00 €')
        ->assertDontSee('bei Ziel-VK');
});
