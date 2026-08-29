<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookBlock;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel;
use Platform\FoodAlchemist\Services\Ai\FakeAiProvider;
use Platform\FoodAlchemist\Services\FoodbookService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * MCP-Steuerbarkeit · D7: Foodbook-Vervollständigung — List/Put/Status/Branding/Customer-Link +
 * Kapitel-Bausteine (Delete/Reorder/Move/Wording) + Block-Edits + Kundentext (W-geerdet).
 * Kundensichtbares Dokument: kein Buch-Delete via MCP; Struktur-Edits nur im Entwurf.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    config(['foodalchemist.ai.provider' => 'fake', 'foodalchemist.ai.backoff' => []]);
    $this->registry = app(ToolRegistry::class);
    $this->kontext = new ToolContext($this->user, $this->rootTeam);
    $this->childKontext = new ToolContext($this->makeUser($this->childA), $this->childA);
    $this->run = fn (string $n, array $a, ?ToolContext $k = null) => $this->registry->get($n)->execute($a, $k ?? $this->kontext);
    $this->svc = app(FoodbookService::class);
    $this->fb = $this->svc->create($this->rootTeam, ['label' => 'Sommerkarte 2027']);
    $this->kap = $this->svc->addKapitel($this->rootTeam, $this->fb->id, ['title' => 'Vorspeisen']);
});

it('Registry-Smoke: alle 14 D7-Tools registriert mit type=object', function () {
    $namen = [
        'foodbooks.LIST', 'foodbooks.PUT', 'foodbooks.STATUS', 'foodbooks.BRANDING', 'foodbooks.CUSTOMER_LINK',
        'foodbook_kapitel.DELETE', 'foodbook_kapitel.REORDER', 'foodbook_kapitel.MOVE', 'foodbook_kapitel.WORDING_GENERATE',
        'foodbook_blocks.PUT', 'foodbook_blocks.REORDER', 'foodbook_blocks.VARIANT_GROUP',
        'foodbook.KUNDENTEXT_GENERATE', 'foodbook_kapitel.KUNDENTEXT_GENERATE',
    ];
    foreach ($namen as $n) {
        $tool = $this->registry->get("foodalchemist.{$n}");
        expect($tool)->not->toBeNull($n);
        expect($tool->getSchema()['type'] ?? null)->toBe('object', $n);
    }
});

it('foodbooks: LIST / PUT / STATUS / BRANDING / CUSTOMER_LINK', function () {
    $list = ($this->run)('foodalchemist.foodbooks.LIST', []);
    expect($list->success)->toBeTrue()->and($list->data['total'])->toBeGreaterThanOrEqual(1);

    $put = ($this->run)('foodalchemist.foodbooks.PUT', ['id' => $this->fb->id, 'felder' => ['kundentyp' => 'Business', 'default_niveau' => 'premium']]);
    expect($put->success)->toBeTrue('put: ' . ($put->error ?? ''));
    expect($this->fb->fresh()->kundentyp)->toBe('Business');

    $br = ($this->run)('foodalchemist.foodbooks.BRANDING', ['id' => $this->fb->id, 'brand_color' => '#123456']);
    expect($br->success)->toBeTrue('branding: ' . ($br->error ?? ''));
    expect($this->fb->fresh()->brand_color)->toBe('#123456');

    $cl = ($this->run)('foodalchemist.foodbooks.CUSTOMER_LINK', ['id' => $this->fb->id, 'company_id' => 42]);
    expect($cl->success)->toBeTrue('link: ' . ($cl->error ?? ''))->and($cl->data['crm_company_id'])->toBe(42);

    $st = ($this->run)('foodalchemist.foodbooks.STATUS', ['id' => $this->fb->id, 'status' => 'aktiv']);
    expect($st->success)->toBeTrue('status: ' . ($st->error ?? ''));
    expect($this->fb->fresh()->statusWert()->value)->toBe('aktiv');
});

it('foodbook_kapitel: REORDER / MOVE / WORDING / DELETE (Entwurf)', function () {
    $kap2 = $this->svc->addKapitel($this->rootTeam, $this->fb->id, ['title' => 'Hauptgänge']);

    $re = ($this->run)('foodalchemist.foodbook_kapitel.REORDER', ['foodbook_id' => $this->fb->id, 'ids' => [$kap2->id, $this->kap->id]]);
    expect($re->success)->toBeTrue('reorder: ' . ($re->error ?? ''));
    expect(FoodAlchemistFoodbookKapitel::find($kap2->id)->position)->toBe(0);

    $mv = ($this->run)('foodalchemist.foodbook_kapitel.MOVE', ['kapitel_id' => $kap2->id, 'new_parent_id' => $this->kap->id]);
    expect($mv->success)->toBeTrue('move: ' . ($mv->error ?? ''));
    expect((int) FoodAlchemistFoodbookKapitel::find($kap2->id)->parent_id)->toBe($this->kap->id);

    $w = ($this->run)('foodalchemist.foodbook_kapitel.WORDING_GENERATE', ['kapitel_id' => $this->kap->id]);
    expect($w->success)->toBeTrue('wording: ' . ($w->error ?? ''))->and($w->data)->toHaveKey('updated_blocks');

    $del = ($this->run)('foodalchemist.foodbook_kapitel.DELETE', ['kapitel_id' => $kap2->id, 'confirm' => true]);
    expect($del->success)->toBeTrue('del: ' . ($del->error ?? ''));
    expect(FoodAlchemistFoodbookKapitel::find($kap2->id))->toBeNull();
});

it('foodbook_blocks: PUT / REORDER / VARIANT_GROUP (Entwurf)', function () {
    $b1 = $this->svc->addBlock($this->rootTeam, $this->kap->id, ['type' => 'text', 'label' => 'A']);
    $b2 = $this->svc->addBlock($this->rootTeam, $this->kap->id, ['type' => 'text', 'label' => 'B']);

    $put = ($this->run)('foodalchemist.foodbook_blocks.PUT', ['block_id' => $b1->id, 'felder' => ['customer_text' => 'Frisch & regional']]);
    expect($put->success)->toBeTrue('put: ' . ($put->error ?? ''));
    expect(FoodAlchemistFoodbookBlock::find($b1->id)->customer_text)->toBe('Frisch & regional');

    $re = ($this->run)('foodalchemist.foodbook_blocks.REORDER', ['kapitel_id' => $this->kap->id, 'ids' => [$b2->id, $b1->id]]);
    expect($re->success)->toBeTrue('reorder: ' . ($re->error ?? ''));

    $vg = ($this->run)('foodalchemist.foodbook_blocks.VARIANT_GROUP', ['block_ids' => [$b1->id, $b2->id]]);
    expect($vg->success)->toBeTrue('variant: ' . ($vg->error ?? ''));
    expect(FoodAlchemistFoodbookBlock::find($b1->id)->variant_group_id)
        ->toBe(FoodAlchemistFoodbookBlock::find($b2->id)->variant_group_id)
        ->not->toBeNull();
});

it('KUNDENTEXT_GENERATE (Buch + Kapitel) via Spy-Provider, W-geerdet', function () {
    $spy = new class extends FakeAiProvider
    {
        public function chat(array $messages, array $options = []): array
        {
            return [
                'content' => json_encode(['werte' => ['text' => 'Ein sommerlicher Genuss.'], 'confidence' => 0.8]),
                'usage' => ['input_tokens' => 0, 'output_tokens' => 0], 'model' => 'spy', 'tool_calls' => null,
            ];
        }
    };
    app()->instance(FakeAiProvider::class, $spy);

    $buch = ($this->run)('foodalchemist.foodbook.KUNDENTEXT_GENERATE', ['foodbook_id' => $this->fb->id]);
    expect($buch->success)->toBeTrue('buch: ' . ($buch->error ?? ''))->and($buch->data['text'])->toBe('Ein sommerlicher Genuss.');

    $kap = ($this->run)('foodalchemist.foodbook_kapitel.KUNDENTEXT_GENERATE', ['kapitel_id' => $this->kap->id]);
    expect($kap->success)->toBeTrue('kap: ' . ($kap->error ?? ''))->and($kap->data['text'])->toBe('Ein sommerlicher Genuss.');
});

it('GET-Modernisierung: Tonalität + Branding + Presentation + Block-Ids', function () {
    $this->svc->addBlock($this->rootTeam, $this->kap->id, ['type' => 'text', 'label' => 'X']);
    $get = ($this->run)('foodalchemist.foodbook.GET', ['id' => $this->fb->id]);
    expect($get->success)->toBeTrue()
        ->and($get->data)->toHaveKeys(['writing_style_id', 'kundentyp', 'branding', 'presentation'])
        ->and($get->data['defaults'])->toHaveKeys(['niveau', 'convenience']);
    $block = $get->data['kapitel'][0]['blocks'][0] ?? [];
    expect($block)->toHaveKeys(['id', 'visible', 'level', 'variant_group_id']);
});

it('Draft-Gate + Guards: aktives Buch nicht struktur-editierbar; fremd/unbekannt', function () {
    // unbekannt / fremd
    expect(($this->run)('foodalchemist.foodbooks.PUT', ['id' => 999999, 'felder' => ['kundentyp' => 'x']])->errorCode)->toBe('NOT_FOUND');
    expect(($this->run)('foodalchemist.foodbooks.PUT', ['id' => $this->fb->id, 'felder' => ['kundentyp' => 'x']], $this->childKontext)->errorCode)->toBe('ACCESS_DENIED');

    // Buch aktiv → Kapitel-Struktur nicht mehr via MCP editierbar (Draft-Gate)
    $this->svc->update($this->rootTeam, $this->fb->id, ['status' => 'aktiv']);
    expect(($this->run)('foodalchemist.foodbook_kapitel.DELETE', ['kapitel_id' => $this->kap->id, 'confirm' => true])->errorCode)->toBe('ACCESS_DENIED');
    expect(($this->run)('foodalchemist.foodbook_blocks.REORDER', ['kapitel_id' => $this->kap->id, 'ids' => [1]])->errorCode)->toBe('ACCESS_DENIED');
});
