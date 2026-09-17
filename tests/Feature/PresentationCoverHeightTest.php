<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Settings\PraesentationsDesigns;
use Platform\FoodAlchemist\Models\FoodAlchemistPresentationDesign;
use Platform\FoodAlchemist\Services\PresentationDesignService;
use Platform\FoodAlchemist\Services\PresentationService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Bug-Runde 2026-09-17 #2 — „Coverbild-Höhe wirkt in der Vorschau, aber nicht im Link".
 * Misst den vollen Weg, den ein Mensch geht: Design im Builder wählen → Höhe umstellen →
 * speichern → Ausgabe veröffentlichen → öffentliches HTML. Die Vorschau rendert den
 * UNGESPEICHERTEN Editor-Zustand, der Link nur den eingefrorenen Snapshot — genau dazwischen
 * lag der Verdacht.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->designs = app(PresentationDesignService::class);
    $this->pres = app(PresentationService::class);

    $this->baueFb = function ($team) {
        $fb = $this->makeFoodbook($team, 'Katalog', ['personen' => 8]);
        $kap = $this->makeChapter($fb, ['title' => 'Vorspeisen', 'consumer_title' => 'Vorspeisen', 'position' => 1]);
        $dish = $this->makeRecipe($team, 'Suppe', ['is_sales_recipe' => true, 'sales_net' => 5.0]);
        $this->makeFoodbookBlock($kap, ['type' => 'recipe_ref', 'sales_recipe_id' => $dish->id, 'position' => 1]);

        return $fb;
    };
});

it('Cover-Höhe: Builder speichert → Publish friert ein → Public-HTML trägt die Klasse', function () {
    $team = $this->rootTeam;
    $fb = ($this->baueFb)($team);
    $this->actingAs($this->makeUser($team, 'Designer'));

    // Ausgangslage wie bei Dominique: gespeichertes Design mit „groß".
    $design = $this->designs->create($team, [
        'name' => 'Broich Foodbook',
        'base_slug' => 'editorial',
        'output_types' => ['foodbook'],
        'layout_json' => [
            ['block_type' => 'cover', 'style' => ['align' => 'center', 'show_logo' => true, 'show_cover_image' => true, 'cover_fit' => 'cover', 'cover_height' => 'gross']],
            ['block_type' => 'chapter_loop', 'style' => ['show_price' => true]],
        ],
        'tokens_json' => [],
    ]);

    // 1) Der Weg durch die Oberfläche: Design wählen, Cover-Block wählen, Höhe umstellen, speichern.
    $c = Livewire::test(PraesentationsDesigns::class)
        ->call('waehlen', $design->id)
        ->call('blockWaehlen', 0)
        ->set('layout.0.style.cover_height', 'klein')
        ->call('speichern');

    expect($c->get('fehler'))->toBeNull();

    $frisch = FoodAlchemistPresentationDesign::find($design->id);
    expect(data_get($frisch->layout_json, '0.style.cover_height'))->toBe('klein');

    // 2) Veröffentlichen — der Snapshot muss den gespeicherten Stand einfrieren.
    $res = $this->pres->publish($team, 'foodbook', $fb->id, [
        'expires_at' => now()->addDay()->toDateString(),
        'design' => 'design:' . $design->id,
    ]);

    $fb->refresh();
    expect(data_get($fb->presentation_snapshot_json, 'resolved_design.layout.0.style.cover_height'))->toBe('klein');

    // 3) Das öffentliche HTML trägt die Höhen-Klasse — und die CSS-Regel dazu.
    $html = $this->get($res['url'])->assertOk()->getContent();
    expect($html)->toContain('pt-hero--h-klein');
    expect($html)->toContain('.pt-hero.pt-hero--h-klein');
});

it('Cover-Höhe greift auch ohne Coverbild (no-media darf sie nicht überschreiben)', function () {
    // Regression zum Spezifitäts-Fund: `.pt-hero.no-media` (0,2,0) schlug die Modifier (0,1,0),
    // also war die Einstellung ohne Coverbild wirkungslos. Die Modifier tragen jetzt dieselbe
    // Spezifität und stehen danach.
    $css = file_get_contents(__DIR__ . '/../../resources/views/layouts/presentation.blade.php');

    $posNoMedia = strpos($css, '.pt-hero.no-media {');
    $posKlein = strpos($css, '.pt-hero.pt-hero--h-klein {');

    expect($posNoMedia)->not->toBeFalse();
    expect($posKlein)->not->toBeFalse();
    expect($posKlein)->toBeGreaterThan($posNoMedia);
    expect($css)->not->toContain("\n        .pt-hero--h-klein {");
});

it('Editor markiert ungespeicherte Design-Änderungen und räumt den Marker beim Speichern ab', function () {
    $team = $this->rootTeam;
    ($this->baueFb)($team);
    $this->actingAs($this->makeUser($team, 'Designer'));

    $design = $this->designs->create($team, [
        'name' => 'Marker-Test',
        'base_slug' => 'editorial',
        'layout_json' => [['block_type' => 'cover', 'style' => ['cover_height' => 'gross']]],
        'tokens_json' => [],
    ]);

    $c = Livewire::test(PraesentationsDesigns::class)
        ->call('waehlen', $design->id)
        ->assertSet('ungespeichert', false);

    // Stil ändern → Vorschau folgt sofort, gespeichert ist nichts.
    $c->set('layout.0.style.cover_height', 'mittel')->assertSet('ungespeichert', true);

    $c->call('speichern')->assertSet('ungespeichert', false);
    expect(data_get(FoodAlchemistPresentationDesign::find($design->id)->layout_json, '0.style.cover_height'))->toBe('mittel');

    // Auch Struktur-Eingriffe (kein wire:model) müssen den Marker setzen.
    $c->call('blockHinzufuegen', 'cta')->assertSet('ungespeichert', true);

    // Reines Umschalten der Vorschau ist keine Änderung am Design.
    $c->call('speichern')->assertSet('ungespeichert', false)
        ->set('previewType', 'angebot')->assertSet('ungespeichert', false);
});

it('meldet ein Design, das nach der Veröffentlichung geändert wurde', function () {
    $team = $this->rootTeam;
    $fb = ($this->baueFb)($team);
    $this->actingAs($this->makeUser($team, 'Designer'));

    $design = $this->designs->create($team, [
        'name' => 'Kundenbuch',
        'base_slug' => 'editorial',
        'layout_json' => [['block_type' => 'cover', 'style' => ['cover_height' => 'gross']]],
        'tokens_json' => [],
    ]);

    $this->pres->publish($team, 'foodbook', $fb->id, [
        'expires_at' => now()->addDay()->toDateString(),
        'design' => 'design:' . $design->id,
    ]);
    $fb->refresh();

    // Frisch veröffentlicht: Link und Design sind derselbe Stand.
    expect($this->pres->designGeaendertSeitPublish($fb))->toBeFalse();

    // Genau Dominiques Fall: Design speichern, Ausgabe NICHT neu veröffentlichen.
    $this->travel(2)->minutes();
    $this->designs->update($team, $design->id, [
        'layout_json' => [['block_type' => 'cover', 'style' => ['cover_height' => 'klein']]],
    ]);
    $fb->refresh();
    expect($this->pres->designGeaendertSeitPublish($fb))->toBeTrue();

    // Der Link hinkt bis zum erneuten Veröffentlichen hinterher — danach stimmt beides wieder.
    $res = $this->pres->publish($team, 'foodbook', $fb->id, [
        'expires_at' => now()->addDay()->toDateString(),
        'design' => 'design:' . $design->id,
    ]);
    $fb->refresh();
    expect($this->pres->designGeaendertSeitPublish($fb))->toBeFalse();
    expect($this->get($res['url'])->getContent())->toContain('pt-hero--h-klein');
});

it('Builtin-Designs gelten nie als veraltet (sie ändern sich nicht)', function () {
    $team = $this->rootTeam;
    $fb = ($this->baueFb)($team);
    $this->actingAs($this->makeUser($team, 'Designer'));

    $this->pres->publish($team, 'foodbook', $fb->id, [
        'expires_at' => now()->addDay()->toDateString(),
        'design' => 'editorial',
    ]);

    expect($this->pres->designGeaendertSeitPublish($fb->refresh()))->toBeFalse();
});

it('Cover-Höhe ist frei einstellbar (% der Fensterhöhe) und landet im öffentlichen HTML', function () {
    $team = $this->rootTeam;
    $fb = ($this->baueFb)($team);
    $this->actingAs($this->makeUser($team, 'Designer'));

    $design = $this->designs->create($team, [
        'name' => 'Freie Höhe',
        'base_slug' => 'editorial',
        'layout_json' => [['block_type' => 'cover', 'style' => ['cover_height' => 'gross']]],
        'tokens_json' => [],
    ]);

    // Wie im Editor: Schieberegler → stilSetzen mit einer Zahl.
    Livewire::test(PraesentationsDesigns::class)
        ->call('waehlen', $design->id)
        ->call('blockWaehlen', 0)
        ->call('stilSetzen', 0, 'cover_height', 32)
        ->call('stilSetzen', 0, 'cover_height_max_px', 340)
        ->call('speichern');

    expect(data_get(FoodAlchemistPresentationDesign::find($design->id)->layout_json, '0.style.cover_height'))->toBe(32);

    $res = $this->pres->publish($team, 'foodbook', $fb->id, [
        'expires_at' => now()->addDay()->toDateString(),
        'design' => 'design:' . $design->id,
    ]);

    $html = $this->get($res['url'])->assertOk()->getContent();
    expect($html)->toContain('min-height: min(32vh, 340px)');
});

it('Alt-Designs mit klein/mittel/groß rendern unverändert weiter', function () {
    $team = $this->rootTeam;
    $fb = ($this->baueFb)($team);
    $this->actingAs($this->makeUser($team, 'Designer'));

    $design = $this->designs->create($team, [
        'name' => 'Altbestand',
        'base_slug' => 'editorial',
        'layout_json' => [['block_type' => 'cover', 'style' => ['cover_height' => 'mittel']]],
        'tokens_json' => [],
    ]);

    $res = $this->pres->publish($team, 'foodbook', $fb->id, [
        'expires_at' => now()->addDay()->toDateString(),
        'design' => 'design:' . $design->id,
    ]);

    $html = $this->get($res['url'])->assertOk()->getContent();
    expect($html)->toContain('pt-hero--h-mittel');
    // Alt-Werte rendern über die Klasse, NICHT über einen inline-style am Hero.
    expect($html)->not->toContain('<header class="pt-hero pt-hero--h-mittel" style=');
    expect($html)->not->toMatch('/<header[^>]*style="min-height/');
});

it('freie Höhe wird auf 10–100 geklemmt (kein CSS aus Fremdwerten)', function () {
    $team = $this->rootTeam;
    $fb = ($this->baueFb)($team);
    $this->actingAs($this->makeUser($team, 'Designer'));

    // Zahlen werden geklemmt; alles Nicht-Numerische fällt auf die Default-Stufe zurück
    // (kein Fremdtext erreicht je einen inline-style).
    foreach ([['999', 'min-height: 100vh'], ['1', 'min-height: 10vh'], ['40px; background:url(x)', 'pt-hero--h-gross']] as [$eingabe, $erwartet]) {
        $design = $this->designs->create($team, [
            'name' => 'Klemm ' . $eingabe,
            'base_slug' => 'editorial',
            'layout_json' => [['block_type' => 'cover', 'style' => ['cover_height' => $eingabe]]],
            'tokens_json' => [],
        ]);
        $res = $this->pres->publish($team, 'foodbook', $fb->id, [
            'expires_at' => now()->addDay()->toDateString(),
            'design' => 'design:' . $design->id,
        ]);
        $html = $this->get($res['url'])->assertOk()->getContent();
        expect($html)->toContain($erwartet);
        expect($html)->not->toContain('background:url(x)');
    }
});
