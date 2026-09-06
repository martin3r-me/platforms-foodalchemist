<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistOfferBlock;
use Platform\FoodAlchemist\Services\AngebotService;
use Platform\FoodAlchemist\Services\FoodbookService;
use Platform\FoodAlchemist\Services\OfferCompositionService;
use Platform\FoodAlchemist\Services\PlanningFrameService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 50 · C-7 — „Angebot auf Foodbook-Niveau".
 *
 * Vier Befunde, die zusammengehören und alle dieselbe Wurzel haben: das Angebot hat die
 * Kompositions-Engine des Foodbooks gespiegelt, aber vier Anschlüsse blieben offen —
 * das Gerüst wurde nie zu Kapiteln, `header_source` fiel aus der Feld-Whitelist, das
 * Staffel-Preset versprach eine Rechnung, die es am Angebot nicht gibt, und die
 * Reihenfolge war über MCP nicht steuerbar.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->registry = app(ToolRegistry::class);
    $this->kontext = new ToolContext($this->user, $this->rootTeam);
    $this->run = fn (string $n, array $a) => $this->registry->get($n)->execute($a, $this->kontext);
    $this->comp = fn () => app(OfferCompositionService::class);
    $this->angebot = fn (array $in = []) => app(AngebotService::class)->create($this->rootTeam, $in + ['name' => 'C7-Angebot']);
});

it('Gerüst-Slots werden zu Kapiteln — statt eines einzigen Kapitels „Menü"', function () {
    $a = ($this->angebot)();
    $frames = app(PlanningFrameService::class);
    $frame = $frames->frameFor($this->rootTeam, 'offer', (int) $a->id);
    $frames->addSlot($this->rootTeam, $frame, ['label' => 'Aperitif', 'position' => 0, 'target_count' => 2]);
    $frames->addSlot($this->rootTeam, $frame, ['label' => 'Hauptgang', 'position' => 1, 'price_anchor' => 28.5]);

    $res = ($this->run)('foodalchemist.offer.STRUKTUR_AUS_GERUEST', ['offer_id' => (int) $a->id]);

    expect($res->success)->toBeTrue('struktur: ' . ($res->error ?? ''))
        ->and($res->data['kein_geruest'])->toBeFalse()
        ->and($res->data['angelegt'])->toBe(2);

    $titel = ($this->comp)()->kapitelTree($this->rootTeam, (int) $a->id);
    expect(array_column($titel, 'title'))->toBe(['Aperitif', 'Hauptgang']);

    // Die Slot-Ziele wandern mit ans Kapitel — sonst wäre die Planung beim Materialisieren verloren.
    $kapitel = ($this->comp)()->kapitelTree($this->rootTeam, (int) $a->id);
    $aperitif = \Platform\FoodAlchemist\Models\FoodAlchemistOfferChapter::find($kapitel[0]['id']);
    expect((int) $aperitif->target_count)->toBe(2);
});

it('STRUKTUR_AUS_GERUEST ist idempotent und meldet ohne Gerüst ehrlich kein_geruest', function () {
    $a = ($this->angebot)();

    $ohne = ($this->run)('foodalchemist.offer.STRUKTUR_AUS_GERUEST', ['offer_id' => (int) $a->id]);
    expect($ohne->success)->toBeTrue()
        ->and($ohne->data['kein_geruest'])->toBeTrue()
        ->and($ohne->data['angelegt'])->toBe(0);

    $frames = app(PlanningFrameService::class);
    $frame = $frames->frameFor($this->rootTeam, 'offer', (int) $a->id);
    $frames->addSlot($this->rootTeam, $frame, ['label' => 'Dessert', 'position' => 0]);

    ($this->run)('foodalchemist.offer.STRUKTUR_AUS_GERUEST', ['offer_id' => (int) $a->id]);
    $zweit = ($this->run)('foodalchemist.offer.STRUKTUR_AUS_GERUEST', ['offer_id' => (int) $a->id]);

    expect($zweit->data['angelegt'])->toBe(0)
        ->and($zweit->data['uebersprungen'])->toBe(1)
        ->and(($this->comp)()->kapitelTree($this->rootTeam, (int) $a->id))->toHaveCount(1);
});

it('die Reife meldet ein geplantes, aber nicht materialisiertes Gerüst — mit dem Weg dahin', function () {
    $a = ($this->angebot)(['personen' => 40]);
    $frames = app(PlanningFrameService::class);
    $frame = $frames->frameFor($this->rootTeam, 'offer', (int) $a->id);
    $frames->addSlot($this->rootTeam, $frame, ['label' => 'Vorspeise', 'position' => 0]);

    $res = ($this->run)('foodalchemist.reife.GET', ['kind' => 'angebot', 'id' => (int) $a->id]);
    $codes = array_column($res->data['luecken'], 'code');
    $wie = collect($res->data['luecken'])->firstWhere('code', 'geruest_nicht_materialisiert');

    expect($codes)->toContain('geruest_nicht_materialisiert')
        ->and($wie['wie']['tool'])->toBe('foodalchemist.offer.STRUKTUR_AUS_GERUEST')
        ->and($wie['slots'])->toBe(['Vorspeise'])
        ->and($res->data['kennzahlen']['geruest_slots'])->toBe(1);

    ($this->run)('foodalchemist.offer.STRUKTUR_AUS_GERUEST', ['offer_id' => (int) $a->id]);
    $nachher = ($this->run)('foodalchemist.reife.GET', ['kind' => 'angebot', 'id' => (int) $a->id]);

    expect(array_column($nachher->data['luecken'], 'code'))->not->toContain('geruest_nicht_materialisiert')
        ->and($nachher->data['erfuellt'])->toContain('geruest_nicht_materialisiert');
});

it('ohne Gerüst ist die Materialisierung nicht messbar statt eine Lücke zu erfinden', function () {
    $a = ($this->angebot)();

    $res = ($this->run)('foodalchemist.reife.GET', ['kind' => 'angebot', 'id' => (int) $a->id]);

    expect(array_column($res->data['nicht_messbar'], 'code'))->toContain('geruest_nicht_materialisiert')
        ->and(array_column($res->data['luecken'], 'code'))->not->toContain('geruest_nicht_materialisiert');
});

it('header_source wird gespeichert statt still verworfen', function () {
    $a = ($this->angebot)();
    $k = ($this->comp)()->addKapitel($this->rootTeam, (int) $a->id, ['title' => 'Kapitel']);

    $block = ($this->comp)()->addBlock($this->rootTeam, (int) $k->id, [
        'type' => 'header_neutral', 'label' => 'Hauptgang', 'header_source' => 'gang.hauptgang',
    ]);

    expect($block->header_source)->toBe('gang.hauptgang')
        ->and($block->type)->toBe('header');   // der Foodbook-Alias wird aufgelöst
});

it('das Staffel-Preset ist am Angebot nicht wählbar und wird auch nicht durchgelassen', function () {
    // Foodbook kennt es, Angebot nicht — FoodAlchemistOfferBlock::PRICE_BASES = person|pauschal.
    $alleBasen = collect(FoodbookService::headerPresets())->flatten(1)->pluck('price_basis')->filter()->all();
    $offerBasen = collect(OfferCompositionService::headerPresets())->flatten(1)->pluck('price_basis')->filter()->all();

    expect($alleBasen)->toContain('staffel')
        ->and($offerBasen)->not->toContain('staffel')
        ->and(array_unique($offerBasen))->each->toBeIn(FoodAlchemistOfferBlock::PRICE_BASES);

    $a = ($this->angebot)();
    $k = ($this->comp)()->addKapitel($this->rootTeam, (int) $a->id, ['title' => 'Kapitel']);

    expect(fn () => ($this->comp)()->addBlock($this->rootTeam, (int) $k->id, [
        'type' => 'header_frei_preis', 'label' => 'Paket', 'price_basis' => 'staffel',
    ]))->toThrow(RuntimeException::class, 'Staffelpreise gibt es nur im Foodbook');
});

it('Kapitel und Blöcke lassen sich über MCP ordnen und verschachteln', function () {
    $a = ($this->angebot)();
    $comp = ($this->comp)();
    $eins = $comp->addKapitel($this->rootTeam, (int) $a->id, ['title' => 'Eins']);
    $zwei = $comp->addKapitel($this->rootTeam, (int) $a->id, ['title' => 'Zwei']);

    $r = ($this->run)('foodalchemist.offer_chapter.REORDER', [
        'offer_id' => (int) $a->id, 'ids' => [(int) $zwei->id, (int) $eins->id],
    ]);
    expect($r->success)->toBeTrue('reorder: ' . ($r->error ?? ''))
        ->and(array_column($comp->kapitelTree($this->rootTeam, (int) $a->id), 'title'))->toBe(['Zwei', 'Eins']);

    $m = ($this->run)('foodalchemist.offer_chapter.MOVE', [
        'chapter_id' => (int) $eins->id, 'parent_id' => (int) $zwei->id,
    ]);
    expect($m->success)->toBeTrue('move: ' . ($m->error ?? ''))
        ->and((int) $eins->refresh()->parent_id)->toBe((int) $zwei->id);

    // Zyklus bleibt verboten — der Schutz sitzt im Service, das Tool meldet ihn sauber.
    $zyklus = ($this->run)('foodalchemist.offer_chapter.MOVE', [
        'chapter_id' => (int) $zwei->id, 'parent_id' => (int) $eins->id,
    ]);
    expect($zyklus->success)->toBeFalse()
        ->and($zyklus->errorCode)->toBe('VALIDATION_ERROR');

    $b1 = $comp->addBlock($this->rootTeam, (int) $zwei->id, ['type' => 'text', 'customer_text' => 'A']);
    $b2 = $comp->addBlock($this->rootTeam, (int) $zwei->id, ['type' => 'text', 'customer_text' => 'B']);
    $br = ($this->run)('foodalchemist.offer_block.REORDER', [
        'chapter_id' => (int) $zwei->id, 'ids' => [(int) $b2->id, (int) $b1->id],
    ]);
    expect($br->success)->toBeTrue('block-reorder: ' . ($br->error ?? ''))
        ->and((int) $b2->refresh()->position)->toBeLessThan((int) $b1->refresh()->position);
});

it('fremde Angebote bleiben unerreichbar', function () {
    $fremd = \Platform\Core\Models\Team::create(['name' => 'Fremd', 'user_id' => 1, 'personal_team' => false]);
    $a = app(AngebotService::class)->create($fremd, ['name' => 'Fremdangebot']);

    $res = ($this->run)('foodalchemist.offer.STRUKTUR_AUS_GERUEST', ['offer_id' => (int) $a->id]);

    expect($res->success)->toBeFalse()
        ->and($res->errorCode)->toBe('NOT_FOUND');
});
