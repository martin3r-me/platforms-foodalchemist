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
