<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistConceptSlot;
use Platform\FoodAlchemist\Models\FoodAlchemistOfferBlock;
use Platform\FoodAlchemist\Models\FoodAlchemistOfferChapter;
use Platform\FoodAlchemist\Services\AngebotKopieService;
use Platform\FoodAlchemist\Services\AngebotService;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Services\OfferCompositionService;
use Platform\FoodAlchemist\Services\PresentationDesignService;
use Platform\FoodAlchemist\Services\PresentationService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * 2026-11-12 („Green & Tasty"): Ein Agent baut über MCP drei Concepter-Pakete, hängt sie mit Stationstexten
 * in ein Konzept, setzt das Konzept per concept_ref ins Angebot — der öffentliche Link zeigt Texte, Pakete und
 * Gerichte mit Beschreibung über eigene CSS-Klassen. Dazu: Sanitizer, Schriften, Cover ohne Titel,
 * Kapitelbild in der Präsentation und Angebot kopieren.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->registry = app(ToolRegistry::class);
    $this->ctx = new ToolContext($this->user, $this->rootTeam);
    $this->run = function (string $n, array $a) {
        $r = $this->registry->get($n)->execute($a, $this->ctx);
        expect($r->success)->toBeTrue($n . ': ' . ($r->error ?? ''));

        return $r->data;
    };
    $this->dish = $this->makeRecipe($this->rootTeam, 'Ochsenbacke', ['is_sales_recipe' => true, 'sales_wording_standard' => 'Geschmorte Ochsenbacke', 'marketing_text' => 'acht Stunden geschmort, Selleriepüree']);
    $this->dish2 = $this->makeRecipe($this->rootTeam, 'Karottengrün', ['is_sales_recipe' => true, 'sales_wording_standard' => 'Karottengrün-Pesto']);
});

it('concepts.POST kind=paket legt ein Concepter-Paket an; ungültiges kind wird abgelehnt', function () {
    $d = ($this->run)('foodalchemist.concepts.POST', ['name' => 'NOSE TO TAIL', 'kind' => 'paket']);
    $c = FoodAlchemistConcept::find($d['concept']['id']);
    expect($c->kind)->toBe('paket')->and($c->status)->toBe('draft')->and($d['concept']['kind'])->toBe('paket');
    expect(app(ConceptService::class)->paginatePakete([], $this->rootTeam)->pluck('id')->all())->toContain($c->id);

    $k = ($this->run)('foodalchemist.concepts.POST', ['name' => 'Menü']);
    expect(FoodAlchemistConcept::find($k['concept']['id'])->kind)->toBe('concept');

    $bad = $this->registry->get('foodalchemist.concepts.POST')->execute(['name' => 'x', 'kind' => 'buffet'], $this->ctx);
    expect($bad->errorCode)->toBe('VALIDATION_ERROR');
});

it('Durchstich: 3 Pakete per MCP → Konzept mit Stationstexten → concept_ref → öffentlicher Link mit Klassen', function () {
    $konzept = ($this->run)('foodalchemist.concepts.POST', ['name' => 'Green & Tasty'])['concept']['id'];
    $svc = app(ConceptService::class);
    foreach (['NOSE TO TAIL', 'FIN TO FORK', 'ROOT TO LEAF'] as $i => $station) {
        $text = $svc->addBlock($this->rootTeam, $konzept, 'text', ['title' => "Station {$station}", 'text_content' => "Text zu {$station}"]);
        $p = ($this->run)('foodalchemist.pakete.POST', ['name' => $station, 'consumer_name' => $station])['paket']['id'];
        ($this->run)('foodalchemist.paket_gerichte.SET', ['paket_id' => $p, 'items' => [['sales_recipe_id' => $this->dish->id], ['sales_recipe_id' => $this->dish2->id]]]);
        $slot = ($this->run)('foodalchemist.concept_slots.POST', ['concept_id' => $konzept, 'package_id' => $p, 'role' => 'station'])['slot'];
        expect($slot['package_id'])->toBe($p);
        expect(FoodAlchemistConceptSlot::find($slot['id'])->embedded_concept_id)->toBe($p);
    }

    $angebot = app(AngebotService::class)->create($this->rootTeam, ['name' => 'Brand X', 'personen' => 400]);
    $kap = app(OfferCompositionService::class)->addKapitel($this->rootTeam, $angebot->id, ['title' => 'Stationen']);
    app(OfferCompositionService::class)->addBlock($this->rootTeam, $kap->id, ['type' => 'concept_ref', 'concept_id' => $konzept]);
    app(OfferCompositionService::class)->addBlock($this->rootTeam, $kap->id, ['type' => 'text', 'label' => 'Freitext im Angebot']);

    $pres = app(PresentationService::class);
    $snap = $pres->buildSnapshot($this->rootTeam, $angebot->fresh(), 'angebot', ['design' => 'editorial']);
    $items = collect($snap['content']['sections'][0]['blocks'][0]['items']);
    expect($items->pluck('kind')->all())->toBe(['text', 'paket', 'gericht', 'gericht', 'text', 'paket', 'gericht', 'gericht', 'text', 'paket', 'gericht', 'gericht'])
        ->and($items[0]['title'])->toBe('Station NOSE TO TAIL')
        ->and($items[0]['label'])->toBe('Text zu NOSE TO TAIL')
        ->and($items[2])->not->toHaveKey('desc');   // Gericht-Beschreibung bewusst NICHT im Angebot (Entscheidung 2026-11-12)

    $res = $pres->publish($this->rootTeam, 'angebot', $angebot->id, ['expires_at' => now()->addDays(30)->toDateString()]);
    $this->get('/p/angebot/' . $res['token'])->assertOk()
        ->assertSeeHtml('pt-text-block--konzept')->assertSee('Station NOSE TO TAIL')->assertSee('Text zu ROOT TO LEAF')
        ->assertSeeHtml('pt-line--paket')->assertSeeHtml('pt-paket-name')
        ->assertSeeHtml('pt-line--dish')->assertSeeHtml('pt-dish-name')
        ->assertDontSeeHtml('pt-dish-desc')->assertDontSee('acht Stunden geschmort')
        ->assertSeeHtml('pt-text-block--angebot')
        ->assertDontSeeHtml('<h3 class="pt-block-header pt-block-header--text">');
});

it('concept_slots.POST: aktives Konzept → klare Fehlermeldung; Alt-Paket-Id → NOT_FOUND mit Hinweis', function () {
    $c = FoodAlchemistConcept::create(['team_id' => $this->rootTeam->id, 'name' => 'Aktiv', 'kind' => 'concept', 'status' => 'active']);
    $r = $this->registry->get('foodalchemist.concept_slots.POST')->execute(['concept_id' => $c->id], $this->ctx);
    expect($r->errorCode)->toBe('ACCESS_DENIED')->and($r->error)->toContain('Entwurf')->toContain('Concepter');

    $d = ($this->run)('foodalchemist.concepts.POST', ['name' => 'Entwurf']);
    $r = $this->registry->get('foodalchemist.concept_slots.POST')->execute(['concept_id' => $d['concept']['id'], 'package_id' => 999999], $this->ctx);
    expect($r->errorCode)->toBe('NOT_FOUND')->and($r->error)->toContain('kind=paket');
});

it('CSS-Sanitizer: > bleibt als Kind-Selektor, < und @import fliegen raus', function () {
    $css = app(PresentationDesignService::class)->sanitizeCss(".pt-block > .pt-line { color: red } </style><script>x</script> @import url(https://evil);");
    expect($css)->toContain('.pt-block > .pt-line')->not->toContain('<')->not->toContain('@import');
});

it('Schriften: Google-Font aus der Whitelist wird geladen, Unbekanntes nicht', function () {
    expect(PresentationDesignService::googleFont('cormorant garamond')[0])->toBe('Cormorant Garamond')
        ->and(PresentationDesignService::googleFont('Comic Sans'))->toBeNull();

    $angebot = app(AngebotService::class)->create($this->rootTeam, ['name' => 'Font-Test']);
    $d = ($this->run)('foodalchemist.presentation_designs.POST', [
        'name' => 'Green', 'base_slug' => 'editorial',
        'tokens_json' => ['typography' => ['heading' => 'Cormorant Garamond', 'body' => 'Manrope', 'scale' => 1.0]],
    ]);
    $slug = 'design:' . $d['id'];
    $res = app(PresentationService::class)->publish($this->rootTeam, 'angebot', $angebot->id, ['design' => $slug, 'expires_at' => now()->addDays(3)->toDateString()]);
    $this->get('/p/angebot/' . $res['token'])->assertOk()
        ->assertSeeHtml('family=Cormorant+Garamond')->assertSeeHtml('"Cormorant Garamond", Georgia')
        ->assertSeeHtml('family=Manrope');
});

it('Cover: show_title=false blendet den Angebotsnamen aus und vergrößert das Logo', function () {
    $angebot = app(AngebotService::class)->create($this->rootTeam, ['name' => 'Brand X']);
    $pres = app(PresentationService::class);
    $res = $pres->publish($this->rootTeam, 'angebot', $angebot->id, ['show_title' => false, 'expires_at' => now()->addDays(3)->toDateString()]);
    $this->get('/p/angebot/' . $res['token'])->assertOk()->assertSeeHtml('pt-hero--ohne-titel')->assertDontSeeHtml('<h1 class="pt-hero-title">Brand X</h1>');

    $res = $pres->publish($this->rootTeam, 'angebot', $angebot->id, ['show_title' => true, 'expires_at' => now()->addDays(3)->toDateString()]);
    $this->get('/p/angebot/' . $res['token'])->assertOk()->assertSeeHtml('<h1 class="pt-hero-title">Brand X</h1>');
});

it('Kapitelbild des Angebots landet in der Präsentation', function () {
    Storage::fake('public');
    $angebot = app(AngebotService::class)->create($this->rootTeam, ['name' => 'Bild-Test']);
    $comp = app(OfferCompositionService::class);
    $kap = $comp->addKapitel($this->rootTeam, $angebot->id, ['title' => 'Stationen']);
    $comp->setKapitelImage($this->rootTeam, $kap->id, UploadedFile::fake()->image('kap.jpg', 400, 300));

    $snap = app(PresentationService::class)->buildSnapshot($this->rootTeam, $angebot->fresh(), 'angebot', ['design' => 'editorial']);
    $img = $snap['content']['sections'][0]['image'];
    expect($img)->not->toBeNull()
        ->and($img['context_file_id'] ?? $img['path'] ?? null)->not->toBeNull()
        ->and($snap['content']['sections'][0]['images'])->not->toBeEmpty();
});

it('Angebot kopieren: Kapitel, Blöcke, lokale Concepts, neue Identität, Original unverändert', function () {
    $comp = app(OfferCompositionService::class);
    $orig = app(AngebotService::class)->create($this->rootTeam, ['name' => 'Brand X', 'personen' => 500]);
    $lokal = FoodAlchemistConcept::create(['team_id' => $this->rootTeam->id, 'name' => 'Lokal', 'kind' => 'concept', 'status' => 'draft', 'offer_id' => $orig->id]);
    $this->makeConceptSlot($lokal, ['position' => 0, 'type' => 'gericht', 'sales_recipe_id' => $this->dish->id]);
    $k1 = $comp->addKapitel($this->rootTeam, $orig->id, ['title' => 'Eltern']);
    $k2 = $comp->addKapitel($this->rootTeam, $orig->id, ['title' => 'Kind'], $k1->id);
    $comp->addBlock($this->rootTeam, $k2->id, ['type' => 'concept_ref', 'concept_id' => $lokal->id]);
    $orig->forceFill(['presentation_token' => 'abc123', 'presentation_enabled' => true])->save();

    $kopie = app(AngebotKopieService::class)->kopieren($this->rootTeam, $orig->id);
    expect($kopie->id)->not->toBe($orig->id)
        ->and($kopie->name)->toBe('Brand X (Kopie)')
        ->and((int) $kopie->personen)->toBe(500)
        ->and($kopie->status)->toBe(\Platform\FoodAlchemist\Enums\AngebotStatus::Anfrage)
        ->and($kopie->presentation_token)->toBeNull()
        ->and($kopie->uuid)->not->toBe($orig->uuid);

    $kapitel = FoodAlchemistOfferChapter::where('offer_id', $kopie->id)->get()->keyBy('title');
    expect($kapitel)->toHaveCount(2)
        ->and($kapitel['Kind']->parent_id)->toBe($kapitel['Eltern']->id);
    $block = FoodAlchemistOfferBlock::where('chapter_id', $kapitel['Kind']->id)->first();
    $lokalKopie = FoodAlchemistConcept::find($block->concept_id);
    expect($lokalKopie->id)->not->toBe($lokal->id)
        ->and((int) $lokalKopie->offer_id)->toBe($kopie->id)
        ->and($lokalKopie->slots()->count())->toBe(1);

    // Original unberührt
    expect(FoodAlchemistOfferChapter::where('offer_id', $orig->id)->count())->toBe(2)
        ->and(FoodAlchemistOfferBlock::where('chapter_id', $k2->id)->value('concept_id'))->toBe($lokal->id);

    $mcp = ($this->run)('foodalchemist.angebote.DUPLICATE', ['id' => $orig->id, 'name' => 'Brand Y']);
    expect($mcp['angebot']['name'])->toBe('Brand Y');
});
