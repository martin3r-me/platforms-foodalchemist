<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistConceptSlot;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\KonfiguratorService;
use Platform\FoodAlchemist\Services\PresentationService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 94 Stufe 5b · Konzept-Detail mit Wording und Fotos wie im Foodbook (keine eigene Pflege), Überschreibung nur im
 * Konfigurator mit Zurücksetzen, Reihenfolge der Schritte je Konfigurator (Standard: Zeitfenster zuerst).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->svc = app(KonfiguratorService::class);
    $this->gericht = FoodAlchemistRecipe::create(['team_id' => $this->rootTeam->id, 'recipe_key' => 'rf', 'name' => '[HG] Rind|Filet|intern 7',
        'status' => 'approved', 'is_sales_recipe' => true, 'ek_total_eur' => 4, 'image_path' => 'gerichte/rind.jpg']);
    $this->concept = FoodAlchemistConcept::create(['team_id' => $this->rootTeam->id, 'name' => 'Fire intern', 'kind' => 'concept', 'status' => 'active',
        'description' => 'Feuer, Rauch und Glut.', 'image_path' => 'concepts/fire.jpg', 'price_per_person_cache' => 45, 'ek_per_person_cache' => 14]);
    $this->slot = FoodAlchemistConceptSlot::create(['team_id' => $this->rootTeam->id, 'concept_id' => $this->concept->id, 'position' => 1, 'type' => 'gericht',
        'sales_recipe_id' => $this->gericht->id, 'wording' => 'Rinderfilet vom Grill']);
    $this->k = $this->svc->anlegen($this->rootTeam, ['name' => 'Detail', 'gueltig_bis' => now()->addMonth()->format('Y-m-d')]);
    $this->kz = $this->svc->konzeptHinzu($this->rootTeam, $this->k->id, ['rolle' => 'haupt', 'dinner_concept_id' => $this->concept->id, 'kunden_titel' => 'Fire']);
    $this->svc->paketAnlegen($this->rootTeam, $this->k->id, ['von_pax' => 100, 'bis_pax' => 199]);
    $this->publish = fn () => app(PresentationService::class)->publish($this->rootTeam, PresentationService::TYPE_KONFIGURATOR, $this->k->id,
        ['expires_at' => now()->addMonth()->toIso8601String(), 'design' => 'konfigurator', 'price_mode' => 'auto']);
});

function detail94($k): array
{
    return $k->fresh()->presentation_snapshot_json['content']['konzepte'][0]['detail'];
}

it('Detail zeigt Kunden-Wording und Fotos aus Concept und Gericht, nie den internen Namen', function () {
    $link = ($this->publish)();
    $d = detail94($this->k);
    expect($d['zeiten']['dinner'][0]['label'])->toBe('Rinderfilet vom Grill')
        ->and($d['zeiten']['dinner'][0]['image']['path'])->toBe('gerichte/rind.jpg')
        ->and($d['bilder'][0]['path'])->toBe('concepts/fire.jpg')   // ohne Bild am Konfigurator gilt das Concept-Bild
        ->and($d['beschreibung'])->toBe('Feuer, Rauch und Glut.');
    $json = json_encode($this->k->fresh()->presentation_snapshot_json);
    expect($json)->not->toContain('Rind|Filet')->not->toContain('Fire intern')->not->toContain('"recipe_id"')->not->toContain('"slot_id"');

    auth()->logout();
    $html = $this->get($link['url'])->assertOk()->assertSee('Rinderfilet vom Grill')->assertSee('data-konfig-details', false)->assertDontSee('Rind|Filet')->getContent();
    if (getenv('FA_KONFIG_HTML_DETAIL')) {   // Sichtprüfung
        file_put_contents(getenv('FA_KONFIG_HTML_DETAIL'), $html);
    }
});

it('Fotos im Kundenlink: Concept-Titelbild auf der Karte, Gericht-Foto im Detail (ohne eigene Pflege am Konfigurator)', function () {
    $link = ($this->publish)();
    auth()->logout();
    $html = $this->get($link['url'])->assertOk()->getContent();
    $karte = \Illuminate\Support\Str::between($html, 'data-konfig-karte="' . $this->kz->id . '"', 'data-konfig-details=');
    $detail = \Illuminate\Support\Str::between($html, 'data-konfig-detail="' . $this->kz->id . '"', '</dialog>');
    expect($karte)->toContain('class="pt-konfig-bild"')->toContain('concepts/fire.jpg')
        ->and($detail)->toContain('gerichte/rind.jpg')->toContain('concepts/fire.jpg');
});

it('Gäste stehen nur einmal: Zahl in der Preisleiste, darunter ein kompakter Regler mit Preisstufe, keine große Zahl', function () {
    $link = ($this->publish)();
    auth()->logout();
    $html = $this->get($link['url'])->assertOk()->getContent();
    $gaeste = \Illuminate\Support\Str::between($html, 'data-konfig-gaeste>', '</section>');
    expect($html)->not->toContain('pt-konfig-gaeste-zahl')
        ->and(substr_count($html, 'class="pt-konfig-regler"'))->toBe(1)
        ->and($gaeste)->toContain('data-konfig-stufe')->not->toContain('data-konfig-pax-anzeige');
});

it('Kartenpreis live: Karte trägt den Preis-Haken, die Preistabelle hat den Einzelpreis, den auch die Leiste nimmt', function () {
    $tag = FoodAlchemistConcept::create(['team_id' => $this->rootTeam->id, 'name' => 'Lunch intern', 'kind' => 'concept', 'status' => 'active',
        'price_per_person_cache' => 30, 'ek_per_person_cache' => 9]);
    $this->svc->konzeptHinzu($this->rootTeam, $this->k->id, ['rolle' => 'haupt', 'lunch_concept_id' => $tag->id, 'dinner_concept_id' => $this->concept->id, 'kunden_titel' => 'Ganztag']);
    $this->svc->paketAnlegen($this->rootTeam, $this->k->id, ['von_pax' => 200, 'bis_pax' => 299]);
    $link = ($this->publish)();
    $c = $this->k->fresh()->presentation_snapshot_json['content'];
    // Leiste bei einer Tageszeit ohne Extras = tab['k{id}:{t}']; dieselbe Zelle liest die Karte je Paket
    foreach ($c['preise'] as $tab) {
        expect($tab)->toHaveKey('k' . $this->kz->id . ':dinner');
    }

    auth()->logout();
    $html = $this->get($link['url'])->assertOk()->getContent();
    expect(substr_count($html, 'class="pt-konfig-ab" data-konfig-karte-preis-feld'))->toBe(2)
        ->and($html)->toContain('data-konfig-karte-kombi')->toContain("tab['k' + kk.id + ':' + kt]");
    if (getenv('FA_KONFIG_HTML_KARTE')) {   // Sichtprüfung im Browser
        file_put_contents(getenv('FA_KONFIG_HTML_KARTE'), $html);
    }
});

it('Speisen-Detail wie im Foodbook-Link: Gang-Überschrift, Wording, Foto, Codes; Tag und Abend nebeneinander; Extras mit Bild und Details', function () {
    \Platform\FoodAlchemist\Models\FoodAlchemistConceptSlot::create(['team_id' => $this->rootTeam->id, 'concept_id' => $this->concept->id, 'position' => 0, 'type' => 'header', 'title' => 'Hauptgang']);
    $tag = FoodAlchemistConcept::create(['team_id' => $this->rootTeam->id, 'name' => 'Lunch intern', 'kind' => 'concept', 'status' => 'active',
        'image_path' => 'concepts/lunch.jpg', 'price_per_person_cache' => 30, 'ek_per_person_cache' => 9]);
    FoodAlchemistConceptSlot::create(['team_id' => $this->rootTeam->id, 'concept_id' => $tag->id, 'position' => 1, 'type' => 'gericht',
        'sales_recipe_id' => $this->gericht->id, 'wording' => 'Rinderfilet am Mittag']);
    $this->kz->update(['lunch_concept_id' => $tag->id]);
    $dessert = FoodAlchemistConcept::create(['team_id' => $this->rootTeam->id, 'name' => 'Dessert intern', 'kind' => 'concept', 'status' => 'active',
        'image_path' => 'concepts/dessert.jpg', 'price_per_person_cache' => 8, 'ek_per_person_cache' => 2]);
    FoodAlchemistConceptSlot::create(['team_id' => $this->rootTeam->id, 'concept_id' => $dessert->id, 'position' => 1, 'type' => 'gericht',
        'sales_recipe_id' => $this->gericht->id, 'wording' => 'Schokoladen-Tarte']);
    $addon = $this->svc->konzeptHinzu($this->rootTeam, $this->k->id, ['rolle' => 'addon', 'dinner_concept_id' => $dessert->id, 'kunden_titel' => 'Dessert-Buffet', 'addon_tageszeit' => 'beide']);

    $link = ($this->publish)();
    auth()->logout();
    $html = $this->get($link['url'])->assertOk()->getContent();
    $detail = \Illuminate\Support\Str::between($html, 'data-konfig-detail="' . $this->kz->id . '"', '</dialog>');
    expect($detail)->toContain('pt-konfig-detail-zeiten--zwei')            // Tag und Abend nebeneinander
        ->toContain('class="pt-subheader')->toContain('Hauptgang')           // Gang aus dem Header-Slot, wie im Foodbook
        ->toContain('class="pt-line pt-item')->toContain('Rinderfilet vom Grill')->toContain('Rinderfilet am Mittag')
        ->toContain('class="pt-item-foto')->toContain('gerichte/rind.jpg')
        ->toContain('pt-konfig-detail-titelbild')->not->toContain('Rind|Filet');
    $extra = \Illuminate\Support\Str::between($html, 'data-konfig-addon-zeile="' . $addon->id . '"', '</div>');
    expect($extra)->toContain('class="pt-konfig-bild"')->toContain('concepts/dessert.jpg');
    expect($html)->toContain('data-konfig-details="' . $addon->id . '"')->toContain('data-konfig-addon-waehlen="' . $addon->id . '"')
        ->toContain('Schokoladen-Tarte')->not->toContain('Dessert intern');
    if (getenv('FA_KONFIG_HTML_SHEET')) {
        file_put_contents(getenv('FA_KONFIG_HTML_SHEET'), $html);
    }
});

it('Überschreibung wirkt nur im Konfigurator, Zurücksetzen führt aufs Concept-Wording; Concept-Änderung wirkt nach erneutem Veröffentlichen', function () {
    $this->svc->wordingSetzen($this->rootTeam, $this->kz->id, $this->slot->id, 'Dry Aged Filet am Feuer');
    ($this->publish)();
    expect(detail94($this->k)['zeiten']['dinner'][0]['label'])->toBe('Dry Aged Filet am Feuer')
        ->and($this->slot->fresh()->wording)->toBe('Rinderfilet vom Grill');   // Concept unverändert

    $this->svc->wordingSetzen($this->rootTeam, $this->kz->id, $this->slot->id, null);
    $this->slot->update(['wording' => 'Filet vom Rind, Glut und Rauch']);
    ($this->publish)();
    expect(detail94($this->k)['zeiten']['dinner'][0]['label'])->toBe('Filet vom Rind, Glut und Rauch');

    // fremde Position wird abgelehnt
    $anderes = FoodAlchemistConcept::create(['team_id' => $this->rootTeam->id, 'name' => 'Anderes', 'kind' => 'concept', 'status' => 'active']);
    $fremd = FoodAlchemistConceptSlot::create(['team_id' => $this->rootTeam->id, 'concept_id' => $anderes->id, 'position' => 1, 'type' => 'gericht', 'sales_recipe_id' => $this->gericht->id]);
    expect(fn () => $this->svc->wordingSetzen($this->rootTeam, $this->kz->id, $fremd->id, 'x'))->toThrow(InvalidArgumentException::class);
});

it('MCP: konfigurator_konzepte.WORDING setzt und leert die Überschreibung', function () {
    $reg = app(ToolRegistry::class);
    $ctx = new ToolContext($this->user, $this->rootTeam);
    $r = $reg->get('foodalchemist.konfigurator_konzepte.WORDING')->execute(['konzept_id' => $this->kz->id, 'concept_slot_id' => $this->slot->id, 'text' => 'Nur hier'], $ctx);
    expect($r->success)->toBeTrue($r->error ?? '')->and($r->data['wording_overrides'][(string) $this->slot->id])->toBe('Nur hier');
    $r = $reg->get('foodalchemist.konfigurator_konzepte.WORDING')->execute(['konzept_id' => $this->kz->id, 'concept_slot_id' => $this->slot->id], $ctx);
    expect($r->data['wording_overrides'])->toBe([]);
});

it('Reihenfolge: Standard Zeitfenster zuerst, einstellbar auf Konzept zuerst (auch per MCP-PUT)', function () {
    expect($this->k->fresh()->reihenfolge)->toBe('tageszeit_zuerst');
    $link = ($this->publish)();
    auth()->logout();
    $html = $this->get($link['url'])->getContent();
    expect(strpos($html, 'data-konfig-zu="tageszeit"'))->toBeLessThan(strpos($html, 'data-konfig-zu="konzepte"'))
        ->and($html)->toContain('data-konfig-reihenfolge="tageszeit_zuerst"')->toContain('data-dinner="1"');

    $this->actingAs($this->user);
    $r = app(ToolRegistry::class)->get('foodalchemist.konfiguratoren.PUT')->execute(['id' => $this->k->id, 'reihenfolge' => 'konzept_zuerst'], new ToolContext($this->user, $this->rootTeam));
    expect($r->success)->toBeTrue($r->error ?? '');
    $link = ($this->publish)();
    auth()->logout();
    $html = $this->get($link['url'])->getContent();
    expect(strpos($html, 'data-konfig-zu="konzepte"'))->toBeLessThan(strpos($html, 'data-konfig-zu="tageszeit"'));
    expect(fn () => $this->svc->aendern($this->rootTeam, $this->k->id, ['reihenfolge' => 'quer']))->toThrow(InvalidArgumentException::class);
});

it('KI-Wording wie im Foodbook-Kapitel: Schreibstil Pflicht, Texte nur im Konfigurator, im Snapshot eingefroren; MCP-Gegenstück', function () {
    $stil = \Platform\FoodAlchemist\Models\FoodAlchemistWritingStyle::create(['team_id' => $this->rootTeam->id, 'slug' => 'kf-stil', 'name' => 'Verspielt', 'sprach_duktus' => 'locker.']);
    $kontext = null;
    $this->mock(\Platform\FoodAlchemist\Services\Ai\AiGatewayService::class, function ($mock) use (&$kontext) {
        $mock->shouldReceive('propose')->andReturnUsing(function (string $key, array $k) use (&$kontext) {
            $kontext = ['key' => $key] + $k;

            return new \Platform\FoodAlchemist\Services\Ai\AiProposal(werte: ['slots' => [$this->slot->id => 'Rinderfilet, über Buchenholz gegrillt']], confidence: 0.8);
        });
    });

    // Editor: ohne Schreibstil kein KI-Aufruf
    $editor = \Livewire\Livewire::test(\Platform\FoodAlchemist\Livewire\Angebote\KonfiguratorEditor::class)->call('oeffnen', $this->k->id)
        ->assertSeeHtml('data-kf-ki-wording="' . $this->kz->id . '"')
        ->call('wordingGenerieren', $this->kz->id)->assertSet('fehler', 'Bitte zuerst einen Schreibstil wählen.');
    expect($kontext)->toBeNull();

    $editor->set('wordingStil', (string) $stil->id)->call('wordingGenerieren', $this->kz->id)
        ->assertSet('fehler', null)->assertSet('hinweis', '1 Gericht(e) im gewählten Stil betextet.');
    expect($kontext['key'])->toBe('concept.wording')->and($kontext['schreibstil'])->toBe('Verspielt')
        ->and(collect($kontext['positionen'])->pluck('slot_id')->all())->toBe([$this->slot->id])
        ->and($this->kz->fresh()->payload_json['wording_overrides'][(string) $this->slot->id])->toBe('Rinderfilet, über Buchenholz gegrillt')
        ->and($this->slot->fresh()->wording)->toBe('Rinderfilet vom Grill');   // Concept unverändert

    ($this->publish)();
    expect(detail94($this->k)['zeiten']['dinner'][0]['label'])->toBe('Rinderfilet, über Buchenholz gegrillt');

    // MCP: gleicher Weg, fremder Schreibstil abgewiesen
    $tool = app(\Platform\Core\Tools\ToolRegistry::class)->get('foodalchemist.konfigurator_konzepte.WORDING_GENERATE');
    $ctx = new \Platform\Core\Contracts\ToolContext($this->user, $this->rootTeam);
    $r = $tool->execute(['konzept_id' => $this->kz->id, 'writing_style_id' => $stil->id], $ctx);
    expect($r->success)->toBeTrue($r->error ?? '')->and($r->data['gesetzt'])->toBe(1)
        ->and($tool->getMetadata()['cost_class'])->toBe('llm');
    $fremd = \Platform\FoodAlchemist\Models\FoodAlchemistWritingStyle::create(['team_id' => $this->childB->id, 'slug' => 'fremd', 'name' => 'Fremd', 'sprach_duktus' => 'x']);
    expect($tool->execute(['konzept_id' => $this->kz->id, 'writing_style_id' => $fremd->id], $ctx)->error)->toContain('Schreibstil');
});
