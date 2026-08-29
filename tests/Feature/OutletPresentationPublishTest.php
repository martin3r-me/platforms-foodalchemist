<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistOutlet;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Models\FoodAlchemistServierform;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeisekarte;
use Platform\FoodAlchemist\Services\OutletSettingsService;
use Platform\FoodAlchemist\Services\PresentationService;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Ebene 2 — Slice F (publish-per-Betrieb): NEBEN dem Standard-Link kann je Betrieb EIN
 * weiterer Link entstehen — eigener Token, eingefrorener Snapshot mit DESSEN Preisen UND
 * DESSEN Vorlage, eigene Freigabe. Der Preis-Beweis (Betriebs-Basissatz statt Baseline)
 * ist in OutletOutputSurfacesTest verankert; hier: dass der Publish-Weg ihn einfriert,
 * öffentlich auflöst, zurückziehbar/listbar bleibt und Tenancy hält.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->userA = $this->makeUser($this->childA);
    $this->actingAs($this->userA);
    $this->registry = app(ToolRegistry::class);
    $this->kontextA = new ToolContext($this->userA, $this->childA);
    $this->pres = app(PresentationService::class);

    // Team-Basissatz 4,0 (Ziel-WE 25 %); Betrieb überschreibt auf 5,0 (Ziel-WE 20 %) + Vorlage.
    app(TeamSettingsService::class)->update($this->childA, ['target_food_cost_pct' => 25]);
    $this->betrieb = FoodAlchemistOutlet::create([
        'team_id' => $this->childA->id, 'name' => 'Betrieb Nord', 'presentation_design' => 'navigator',
    ]);
    app(OutletSettingsService::class)->update($this->childA, $this->betrieb, ['target_food_cost_pct' => 20]);

    // Speisekarte mit EINEM bepreisten Gericht (MEK 10 → Baseline 99, Betrieb 10×5,0 = 50).
    $this->gericht = $this->makeRecipe($this->childA, 'HG Zanderfilet', ['is_sales_recipe' => true, 'sales_net' => 99.0]);
    $sf = FoodAlchemistServierform::create(['team_id' => $this->childA->id, 'code' => 'teller', 'label' => 'Teller']);
    FoodAlchemistRecipeDarreichung::create([
        'team_id' => $this->childA->id, 'recipe_id' => $this->gericht->id, 'serving_form_id' => $sf->id,
        'is_standard' => true, 'ek_portion' => 10, 'sales_net' => 99.0,
    ]);
    $karteId = $this->registry->get('foodalchemist.speisekarten.POST')->execute(['name' => 'Abendkarte'], $this->kontextA)->data['speisekarte']['id'];
    $rubrikId = $this->registry->get('foodalchemist.speisekarte_rubrik.POST')->execute([
        'speisekarte_id' => $karteId, 'title' => 'Fisch', 'consumer_title' => 'Aus dem Wasser', 'art' => 'speisen',
    ], $this->kontextA)->data['rubrik']['id'];
    $this->registry->get('foodalchemist.speisekarte_positionen.POST')->execute([
        'rubrik_id' => $rubrikId, 'type' => 'gericht_ref', 'sales_recipe_id' => $this->gericht->id,
    ], $this->kontextA);
    $this->karte = FoodAlchemistSpeisekarte::find($karteId);
});

it('buildSnapshot friert bei gleicher Vorlage die Betriebs-Preise ein (nicht die Baseline)', function () {
    $base = $this->pres->buildSnapshot($this->childA, $this->karte, 'speisekarte', ['design' => 'menu']);
    $mitBetrieb = $this->pres->buildSnapshot($this->childA, $this->karte, 'speisekarte', ['design' => 'menu', 'outlet' => $this->betrieb]);

    // Nur der Betrieb unterscheidet die beiden — die Kundensicht (content) MUSS abweichen.
    expect(json_encode($mitBetrieb['content']))->not->toBe(json_encode($base['content']));
});

it('publishForOutlet: eigener Link mit Betriebs-Vorlage, öffentlich auflösbar, additiv zum Standard-Link', function () {
    $res = $this->pres->publishForOutlet($this->childA, 'speisekarte', $this->karte->id, $this->betrieb->id, [
        'expires_at' => now()->addDays(30)->toDateString(), 'price_display' => true,
    ]);

    expect($res['outlet_id'])->toBe($this->betrieb->id)
        ->and($res['design'])->toBe('navigator')          // Betriebs-Vorlage schlägt Dokument-Default
        ->and($res['token'])->not->toBeEmpty();

    // Öffentlich ohne Login erreichbar; Snapshot trägt die Betriebs-Vorlage.
    $this->get('/p/speisekarte/' . $res['token'])->assertOk()->assertSee('Abendkarte')->assertSee('HG Zanderfilet');
    $snap = $this->pres->resolveByToken('speisekarte', $res['token']);
    expect($snap)->not->toBeNull()->and($snap['resolved_design']['source'])->toBe('navigator');

    // Additiv: der Standard-Link (Dokument-Kopf) lebt unabhängig weiter.
    $std = $this->pres->publish($this->childA, 'speisekarte', $this->karte->id, ['expires_at' => now()->addDays(30)->toDateString()]);
    expect($std['token'])->not->toBe($res['token']);
    $this->get('/p/speisekarte/' . $std['token'])->assertOk();
    $this->get('/p/speisekarte/' . $res['token'])->assertOk();   // Betriebs-Link weiterhin live
});

it('withdrawForOutlet nimmt nur den Betriebs-Link vom Netz + outletPresentations listet ihn', function () {
    $res = $this->pres->publishForOutlet($this->childA, 'speisekarte', $this->karte->id, $this->betrieb->id, [
        'expires_at' => now()->addDays(30)->toDateString(),
    ]);
    $this->get('/p/speisekarte/' . $res['token'])->assertOk();

    $liste = $this->pres->outletPresentations($this->childA, 'speisekarte', $this->karte->id);
    expect($liste)->toHaveCount(1)
        ->and($liste[0]['outlet_id'])->toBe($this->betrieb->id)
        ->and($liste[0]['enabled'])->toBeTrue();

    $this->pres->withdrawForOutlet($this->childA, 'speisekarte', $this->karte->id, $this->betrieb->id);
    $this->get('/p/speisekarte/' . $res['token'])->assertNotFound();
    expect($this->pres->resolveByToken('speisekarte', $res['token']))->toBeNull();

    $listeNach = $this->pres->outletPresentations($this->childA, 'speisekarte', $this->karte->id);
    expect($listeNach[0]['enabled'])->toBeFalse();   // Zeile bleibt (Wieder-Freigabe möglich)
});

it('nimmt eine per-Link-Vorlage + einen eigenen Slug (überschreibt die Betriebs-Vorlage)', function () {
    // Betrieb hat presentation_design = navigator; hier explizit 'menu' + eigener Link-Name.
    $res = $this->pres->publishForOutlet($this->childA, 'speisekarte', $this->karte->id, $this->betrieb->id, [
        'expires_at' => now()->addDays(30)->toDateString(),
        'design' => 'menu',
        'slug' => 'broich-nord-test',
    ]);
    expect($res['design'])->toBe('menu')
        ->and($res['slug'])->toBe('broich-nord-test')
        ->and($res['url'])->toContain('broich-nord-test');

    $this->get('/p/speisekarte/broich-nord-test')->assertOk()->assertSee('Abendkarte');
    $snap = $this->pres->resolveByToken('speisekarte', 'broich-nord-test');
    expect($snap['resolved_design']['source'])->toBe('menu');   // per-Link-Vorlage, nicht die Betriebs-Default
});

it('idempotent je (Dokument, Betrieb): zweiter Publish ersetzt denselben Link', function () {
    $a = $this->pres->publishForOutlet($this->childA, 'speisekarte', $this->karte->id, $this->betrieb->id, [
        'expires_at' => now()->addDays(30)->toDateString(),
    ]);
    $b = $this->pres->publishForOutlet($this->childA, 'speisekarte', $this->karte->id, $this->betrieb->id, [
        'expires_at' => now()->addDays(60)->toDateString(),
    ]);
    expect($b['token'])->toBe($a['token']);   // gleicher Betrieb → gleiche Zeile, Token stabil
    expect($this->pres->outletPresentations($this->childA, 'speisekarte', $this->karte->id))->toHaveCount(1);
});

it('MCP speisekarte_presentation.PUBLISH mit outlet_id erzeugt den Betriebs-Link; fremdes Betrieb → NOT_FOUND', function () {
    $pub = $this->registry->get('foodalchemist.speisekarte_presentation.PUBLISH')->execute([
        'speisekarte_id' => $this->karte->id, 'expires_at' => now()->addDays(30)->toDateString(), 'outlet_id' => $this->betrieb->id,
    ], $this->kontextA);
    expect($pub->success)->toBeTrue()
        ->and($pub->data['outlet_id'])->toBe($this->betrieb->id)
        ->and($pub->data['design'])->toBe('navigator');
    $this->get('/p/speisekarte/' . $pub->data['token'])->assertOk()->assertSee('Abendkarte');

    // Fremdes Betrieb (childB) an childA-Dokument über MCP → sauberer Fehlercontract.
    $betriebB = FoodAlchemistOutlet::create(['team_id' => $this->childB->id, 'name' => 'Fremd']);
    $res = $this->registry->get('foodalchemist.speisekarte_presentation.PUBLISH')->execute([
        'speisekarte_id' => $this->karte->id, 'expires_at' => now()->addDays(30)->toDateString(), 'outlet_id' => $betriebB->id,
    ], $this->kontextA);
    expect($res->success)->toBeFalse()->and($res->errorCode)->toBe('NOT_FOUND');
});

it('Tenancy: fremdes Betrieb / fremd-Team-Dokument werfen', function () {
    $betriebB = FoodAlchemistOutlet::create(['team_id' => $this->childB->id, 'name' => 'Fremd-Betrieb']);

    // Betrieb aus childB an childA-Dokument → nicht auflösbar (findOrFail).
    expect(fn () => $this->pres->publishForOutlet($this->childA, 'speisekarte', $this->karte->id, $betriebB->id, [
        'expires_at' => now()->addDays(30)->toDateString(),
    ]))->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

    // childB-Team an childA-Dokument (Geschwister-Isolation) → Dokument nicht sichtbar.
    expect(fn () => $this->pres->publishForOutlet($this->childB, 'speisekarte', $this->karte->id, $betriebB->id, [
        'expires_at' => now()->addDays(30)->toDateString(),
    ]))->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
});
