<?php

use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Livewire\Speisekarte\Index;
use Platform\FoodAlchemist\Services\PresentationService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 43 (Phase 2) — Speisekarte-Präsentation: Service-Sanitizer, Public-Route,
 * MCP-Round-Trip + Tenancy, Editor-Publish-Tab.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->registry = app(ToolRegistry::class);
    $this->kontext = new ToolContext($this->user, $this->rootTeam);
    $this->pres = app(PresentationService::class);

    $this->baueKarte = function ($team, $kontext) {
        $gericht = $this->makeRecipe($team, 'HG Zanderfilet', ['is_sales_recipe' => true, 'sales_net' => 24.0]);
        $karteId = $this->registry->get('foodalchemist.speisekarten.POST')->execute(['name' => 'Abendkarte'], $kontext)->data['speisekarte']['id'];
        $rubrikId = $this->registry->get('foodalchemist.speisekarte_rubrik.POST')->execute([
            'speisekarte_id' => $karteId, 'title' => 'Fischgerichte', 'consumer_title' => 'Aus dem Wasser', 'art' => 'speisen',
        ], $kontext)->data['rubrik']['id'];
        $this->registry->get('foodalchemist.speisekarte_positionen.POST')->execute([
            'rubrik_id' => $rubrikId, 'type' => 'gericht_ref', 'sales_recipe_id' => $gericht->id,
        ], $kontext);

        return $karteId;
    };
});

it('buildSnapshot der Speisekarte ist interna-frei (Allowlist)', function () {
    $karteId = ($this->baueKarte)($this->rootTeam, $this->kontext);
    $karte = \Platform\FoodAlchemist\Models\FoodAlchemistSpeisekarte::find($karteId);

    $snap = $this->pres->buildSnapshot($this->rootTeam, $karte, 'speisekarte', ['design' => 'menu']);
    expect($snap['title'])->toBe('Abendkarte')
        ->and($snap['content']['sections'][0]['title'])->toBe('Aus dem Wasser');

    $keys = [];
    $walk = function ($n) use (&$walk, &$keys) {
        foreach ($n as $k => $v) {
            if (is_string($k)) {
                $keys[] = $k;
            }
            if (is_array($v)) {
                $walk($v);
            }
        }
    };
    $walk($snap['content']);
    foreach (['preis_quelle', 'karte', 'kaskaden', 'intern', 'ek'] as $verboten) {
        expect($keys)->not->toContain($verboten);
    }
});

it('öffentlicher Speisekarte-Link ohne Login + Snapshot-Stabilität + 404-Matrix', function () {
    $karteId = ($this->baueKarte)($this->rootTeam, $this->kontext);
    $res = $this->pres->publish($this->rootTeam, 'speisekarte', $karteId, ['expires_at' => now()->addDays(30)->toDateString()]);

    $this->get('/p/speisekarte/' . $res['token'])
        ->assertOk()->assertSee('Abendkarte')->assertSee('Aus dem Wasser')->assertSee('HG Zanderfilet')
        ->assertDontSee('preis_quelle')->assertDontSee('Wareneinsatz');

    // Stabil nach Edit.
    \Platform\FoodAlchemist\Models\FoodAlchemistSpeisekarte::find($karteId)->update(['name' => 'GEÄNDERT']);
    $this->get('/p/speisekarte/' . $res['token'])->assertOk()->assertSee('Abendkarte')->assertDontSee('GEÄNDERT');

    // Zurückgezogen → 404.
    $this->pres->withdraw($this->rootTeam, 'speisekarte', $karteId);
    $this->get('/p/speisekarte/' . $res['token'])->assertNotFound();
});

it('Speisekarte-MCP: PUBLISH → GET Round-Trip + Public erreichbar; Registry-Smoke; fremd → NOT_FOUND', function () {
    foreach (['PUBLISH', 'WITHDRAW', 'GET'] as $verb) {
        expect($this->registry->get('foodalchemist.speisekarte_presentation.' . $verb))->not->toBeNull($verb);
    }

    $karteId = ($this->baueKarte)($this->rootTeam, $this->kontext);
    $pub = $this->registry->get('foodalchemist.speisekarte_presentation.PUBLISH')->execute([
        'speisekarte_id' => $karteId, 'expires_at' => now()->addDays(30)->toDateString(),
    ], $this->kontext);
    expect($pub->success)->toBeTrue();
    $this->get('/p/speisekarte/' . $pub->data['token'])->assertOk();

    $get = $this->registry->get('foodalchemist.speisekarte_presentation.GET')->execute(['speisekarte_id' => $karteId], $this->kontext);
    expect($get->data['live'])->toBeTrue();

    // fremd-Team (childA) auf childB-Karte → NOT_FOUND.
    $kontextB = new ToolContext($this->makeUser($this->childB), $this->childB);
    $fremdKarte = ($this->baueKarte)($this->childB, $kontextB);
    $kontextA = new ToolContext($this->makeUser($this->childA), $this->childA);
    $res = $this->registry->get('foodalchemist.speisekarte_presentation.PUBLISH')->execute([
        'speisekarte_id' => $fremdKarte, 'expires_at' => now()->addDays(30)->toDateString(),
    ], $kontextA);
    expect($res->success)->toBeFalse()->and($res->errorCode)->toBe('NOT_FOUND');
});

it('Editor-Tab: Veröffentlichen aktiviert, ohne gültig-bis nicht (Pflicht-Datum)', function () {
    $karteId = ($this->baueKarte)($this->rootTeam, $this->kontext);

    Livewire::test(Index::class)
        ->call('waehle', $karteId)
        ->set('presentationGueltigBis', now()->addDays(15)->toDateString())
        ->call('veroeffentlichen');
    expect(\Platform\FoodAlchemist\Models\FoodAlchemistSpeisekarte::find($karteId)->presentation_enabled)->toBeTrue();

    $karte2 = ($this->baueKarte)($this->rootTeam, $this->kontext);
    $c = Livewire::test(Index::class)->call('waehle', $karte2)->set('presentationGueltigBis', null)->call('veroeffentlichen');
    expect($c->get('presentationFehler'))->not->toBeNull();
    expect(\Platform\FoodAlchemist\Models\FoodAlchemistSpeisekarte::find($karte2)->presentation_enabled)->toBeFalse();
});
