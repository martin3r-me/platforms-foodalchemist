<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 94 Stufe 4 · MCP: der typische Ablauf (Konfigurator anlegen → Concept → Vorlage übernehmen → Pakete vorschlagen und
 * übernehmen → schnüren → Preise → veröffentlichen) läuft über die Tools; lesende Tools sind read_only; Fremdteam sieht nichts.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->registry = app(ToolRegistry::class);
    $this->kontext = new ToolContext($this->user, $this->rootTeam);
    $this->tool = fn (string $name, array $args = [], ?ToolContext $ctx = null) => $this->registry->get($name)->execute($args, $ctx ?? $this->kontext);
});

it('typischer MCP-Ablauf bis zum veröffentlichten Link', function () {
    $c = FoodAlchemistConcept::create(['team_id' => $this->rootTeam->id, 'name' => 'Fire', 'kind' => 'concept', 'status' => 'active', 'price_per_person_cache' => 45, 'ek_per_person_cache' => 14]);
    $t = $this->tool;

    $v = $t('foodalchemist.leistung_vorlagen.POST', ['kategorie' => 'logistik', 'bezeichnung' => 'LKW', 'mengen_regel' => 'stufen',
        'stufen_json' => [['bis_pax' => 199, 'anzahl' => 1], ['bis_pax' => null, 'anzahl' => 2]], 'preis_je' => 'stueck', 'haeufigkeit' => 'je_event',
        'ek_satz' => 808, 'vk_modus' => 'fixed', 'vk_satz' => 2020, 'vk_begruendung' => 'DOEC 2,5×']);
    expect($v->success)->toBeTrue($v->error ?? '');

    $k = $t('foodalchemist.konfiguratoren.POST', ['name' => 'Diva', 'gueltig_bis' => now()->addMonth()->format('Y-m-d')]);
    $kid = $k->data['konfigurator']['id'];
    expect($t('foodalchemist.konfigurator_konzepte.POST', ['konfigurator_id' => $kid, 'rolle' => 'haupt', 'dinner_concept_id' => $c->id, 'kunden_titel' => 'Fire'])->success)->toBeTrue();
    expect($t('foodalchemist.konfigurator_leistungen.AUS_VORLAGE', ['konfigurator_id' => $kid, 'vorlage_ids' => [$v->data['vorlage']['id']]])->success)->toBeTrue();

    $vorschlag = $t('foodalchemist.konfiguratoren.PAKETE_VORSCHLAGEN', ['id' => $kid, 'von' => 100, 'bis' => 400]);
    expect($vorschlag->success)->toBeTrue($vorschlag->error ?? '')->and($vorschlag->data['vorschlag'])->not->toBeEmpty();
    expect($t('foodalchemist.konfiguratoren.PAKETE_VORSCHLAG_UEBERNEHMEN', ['id' => $kid, 'pakete' => $vorschlag->data['vorschlag']])->success)->toBeTrue();
    expect($t('foodalchemist.konfiguratoren.SCHNUEREN', ['id' => $kid])->data['pakete'])->toBeGreaterThan(0);

    $preise = $t('foodalchemist.konfiguratoren.PREISE', ['id' => $kid]);
    expect($preise->success)->toBeTrue()->and($preise->data['pakete'][0]['faelle'][0]['preis_pp'])->toBeGreaterThan(45);

    $pub = $t('foodalchemist.konfigurator_presentation.PUBLISH', ['id' => $kid]);
    expect($pub->success)->toBeTrue($pub->error ?? '')->and($pub->data['url'])->toContain('/p/konfigurator/');
    expect($t('foodalchemist.konfigurator_presentation.GET', ['id' => $kid])->data['live'])->toBeTrue();
});

it('Metadaten: lesende Tools read_only, schreibende nicht; Fremdteam bekommt NOT_FOUND', function () {
    foreach (['foodalchemist.konfiguratoren.GET', 'foodalchemist.konfiguratoren.PREISE', 'foodalchemist.konfiguratoren.PAKETE_VORSCHLAGEN', 'foodalchemist.leistung_vorlagen.GET'] as $n) {
        expect($this->registry->get($n)->getMetadata()['read_only'])->toBeTrue($n);
    }
    expect($this->registry->get('foodalchemist.konfiguratoren.SCHNUEREN')->getMetadata()['read_only'])->toBeFalse();

    $eigen = new ToolContext($this->makeUser($this->childA, 'A'), $this->childA);   // Geschwister sehen sich nie (Eltern-Daten dagegen schon)
    $kid = ($this->tool)('foodalchemist.konfiguratoren.POST', ['name' => 'Intern'], $eigen)->data['konfigurator']['id'];
    $fremd = new ToolContext($this->makeUser($this->childB, 'B'), $this->childB);
    $r = ($this->tool)('foodalchemist.konfiguratoren.PREISE', ['id' => $kid], $fremd);
    expect($r->success)->toBeFalse()->and($r->errorCode)->toBe('NOT_FOUND');
});
