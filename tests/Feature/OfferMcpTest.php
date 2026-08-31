<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Services\AngebotService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * #380 Composer · MCP-Lockstep: offer_chapter/offer_block-Komposition + offer.INSERT_FORMAT +
 * angebot_presentation.{PUBLISH,WITHDRAW,GET}. Registry-Smoke + Round-Trip (Kapitel → concept_ref-
 * Block → Präsentation veröffentlichen → GET live) + Guards (confirm, Tenancy).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->registry = app(ToolRegistry::class);
    $this->kontext = new ToolContext($this->user, $this->rootTeam);
    $this->childKontext = new ToolContext($this->makeUser($this->childA), $this->childA);
    $this->run = fn (string $n, array $a, ?ToolContext $k = null) => $this->registry->get($n)->execute($a, $k ?? $this->kontext);
    $this->angebot = app(AngebotService::class)->create($this->rootTeam, ['name' => 'Gala 2027', 'personen' => 40, 'occasion' => 'Hochzeit']);
});

it('Registry-Smoke: alle 10 Composer-/Präsentations-Tools registriert mit type=object', function () {
    $namen = [
        'offer_chapter.POST', 'offer_chapter.PUT', 'offer_chapter.DELETE',
        'offer_block.POST', 'offer_block.PUT', 'offer_block.DELETE',
        'offer.INSERT_FORMAT',
        'angebot_presentation.PUBLISH', 'angebot_presentation.WITHDRAW', 'angebot_presentation.GET',
    ];
    foreach ($namen as $n) {
        $tool = $this->registry->get("foodalchemist.{$n}");
        expect($tool)->not->toBeNull($n);
        expect($tool->getSchema()['type'] ?? null)->toBe('object', $n);
    }
});

it('Round-Trip: offer_chapter.POST → offer_block.POST (concept_ref) → PUBLISH → GET live', function () {
    $concept = $this->makeConcept($this->rootTeam, 'Vorspeise', [
        'kind' => 'concept', 'price_per_person_cache' => 12.0, 'ek_per_person_cache' => 4.0,
    ]);

    $chap = ($this->run)('foodalchemist.offer_chapter.POST', [
        'offer_id' => $this->angebot->id, 'title' => 'Menü', 'consumer_title' => 'Unser Menü',
    ]);
    expect($chap->success)->toBeTrue('chapter: ' . ($chap->error ?? ''));
    $chapterId = $chap->data['kapitel']['id'];

    $block = ($this->run)('foodalchemist.offer_block.POST', [
        'chapter_id' => $chapterId, 'type' => 'concept_ref', 'concept_id' => $concept->id,
    ]);
    expect($block->success)->toBeTrue('block: ' . ($block->error ?? ''))
        ->and($block->data['block']['type'])->toBe('concept_ref');

    $pub = ($this->run)('foodalchemist.angebot_presentation.PUBLISH', [
        'angebot_id' => $this->angebot->id, 'expires_at' => now()->addDays(30)->toDateString(), 'design' => 'menu',
    ]);
    expect($pub->success)->toBeTrue('publish: ' . ($pub->error ?? ''))
        ->and($pub->data['token'])->not->toBeEmpty()
        ->and($pub->data['design'])->toBe('menu');

    $get = ($this->run)('foodalchemist.angebot_presentation.GET', ['angebot_id' => $this->angebot->id]);
    expect($get->data['enabled'])->toBeTrue()->and($get->data['live'])->toBeTrue();

    // Public-Link ohne Login erreichbar; nach WITHDRAW → 404.
    $this->get('/p/angebot/' . $pub->data['token'])->assertOk()->assertSee('Unser Menü');

    $wd = ($this->run)('foodalchemist.angebot_presentation.WITHDRAW', ['angebot_id' => $this->angebot->id]);
    expect($wd->success)->toBeTrue();
    expect(($this->run)('foodalchemist.angebot_presentation.GET', ['angebot_id' => $this->angebot->id])->data['live'])->toBeFalse();
    $this->get('/p/angebot/' . $pub->data['token'])->assertNotFound();
});

it('offer_chapter.PUT + offer_block.PUT übernehmen felder', function () {
    $chap = ($this->run)('foodalchemist.offer_chapter.POST', ['offer_id' => $this->angebot->id, 'title' => 'Menü']);
    $chapterId = $chap->data['kapitel']['id'];
    $put = ($this->run)('foodalchemist.offer_chapter.PUT', ['chapter_id' => $chapterId, 'felder' => ['consumer_title' => 'Gala-Menü']]);
    expect($put->success)->toBeTrue('chapter put: ' . ($put->error ?? ''))
        ->and($put->data['updated'])->toContain('consumer_title');

    $block = ($this->run)('foodalchemist.offer_block.POST', ['chapter_id' => $chapterId, 'type' => 'text', 'label' => 'Notiz']);
    $blockId = $block->data['block']['id'];
    $bput = ($this->run)('foodalchemist.offer_block.PUT', ['block_id' => $blockId, 'felder' => ['customer_text' => 'Willkommen']]);
    expect($bput->success)->toBeTrue('block put: ' . ($bput->error ?? ''));
});

it('DELETE braucht confirm; Tenancy-Guards greifen', function () {
    $chap = ($this->run)('foodalchemist.offer_chapter.POST', ['offer_id' => $this->angebot->id, 'title' => 'Menü']);
    $chapterId = $chap->data['kapitel']['id'];

    // confirm-Pflicht.
    expect(($this->run)('foodalchemist.offer_chapter.DELETE', ['chapter_id' => $chapterId])->errorCode)->toBe('CONFIRM_REQUIRED');

    // Fremd-Team (childA) sieht das rootTeam-Angebot via Hierarchie, besitzt es aber nicht → ACCESS_DENIED.
    // Kanonisches guardOwned-Verhalten (alle FA-Tools): visibleToTeam trifft, isOwnedBy nicht.
    expect(($this->run)('foodalchemist.offer_chapter.POST', ['offer_id' => $this->angebot->id, 'title' => 'X'], $this->childKontext)->errorCode)->toBe('ACCESS_DENIED');
    // Publish spiegelt FoodbookPresentationPublishTool: kein guardOwned, der Service wirft für ein
    // nicht-eigenes Angebot → \Throwable → VALIDATION_ERROR (canonical-treu, kein Link fürs Fremd-Angebot).
    expect(($this->run)('foodalchemist.angebot_presentation.PUBLISH', ['angebot_id' => $this->angebot->id, 'expires_at' => now()->addDay()->toDateString()], $this->childKontext)->errorCode)->toBe('VALIDATION_ERROR');

    // PUBLISH ohne expires_at → VALIDATION_ERROR (Pflicht-Datum).
    expect(($this->run)('foodalchemist.angebot_presentation.PUBLISH', ['angebot_id' => $this->angebot->id])->errorCode)->toBe('VALIDATION_ERROR');

    // Scharfes Löschen räumt das Kapitel weg.
    $del = ($this->run)('foodalchemist.offer_chapter.DELETE', ['chapter_id' => $chapterId, 'confirm' => true]);
    expect($del->success)->toBeTrue('del: ' . ($del->error ?? ''));
});
