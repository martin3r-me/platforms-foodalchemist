<?php

use Platform\FoodAlchemist\Services\KonfiguratorService;
use Platform\FoodAlchemist\Services\PresentationService;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Druck auf weißem Papier (Dominique 08.11.2026, Konfigurator „Glorious Bastards“ im dunklen Design): die hellen Schriftfarben des
 * dunklen Designs waren im PDF kaum lesbar. Im Druck bekommen zu schwache Farben eine lesbare Ersatzfarbe; Bildschirm unverändert.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $svc = app(KonfiguratorService::class);
    $this->k = $svc->anlegen($this->rootTeam, ['name' => 'Druck', 'kunden_titel' => 'Druck', 'gueltig_bis' => now()->addMonth()->format('Y-m-d')]);
    $c = FoodAlchemistConcept::create(['team_id' => $this->rootTeam->id, 'name' => 'C', 'kind' => 'concept', 'status' => 'active', 'price_per_person_cache' => 50, 'ek_per_person_cache' => 15]);
    $svc->konzeptHinzu($this->rootTeam, $this->k->id, ['rolle' => 'haupt', 'dinner_concept_id' => $c->id, 'kunden_titel' => 'C']);
    $svc->paketAnlegen($this->rootTeam, $this->k->id, ['von_pax' => 100, 'bis_pax' => 199]);
    $this->html = function (string $design) {
        $link = app(PresentationService::class)->publish($this->rootTeam, PresentationService::TYPE_KONFIGURATOR, $this->k->id,
            ['expires_at' => now()->addMonth()->toIso8601String(), 'design' => $design, 'price_mode' => 'auto']);

        return $this->get($link['url'])->assertOk()->getContent();
    };
});

it('dunkles Design: im Druck dunkle Schrift auf Weiß, am Bildschirm die hellen Designfarben', function () {
    $html = ($this->html)('konfigurator_dunkel');
    $druck = \Illuminate\Support\Str::between($html, '/* druck-ersatzfarben */', '}');

    expect($html)->toContain('--pt-text: #f2efe9;')                       // Bildschirm unverändert
        ->and($druck)->toContain('--pt-text: #1a1712;')->toContain('--pt-primary: #1a1712;')->toContain('--pt-muted: #5f5850;')
        ->and($druck)->toContain('--pt-accent: color-mix(in srgb, #8fb0d4 55%, #000);')->toContain('--pt-bg: #ffffff;');
});

it('helles Design: Schrift- und Primärfarbe bleiben im Druck (auf Weiß lesbar), nur zu blasse Nebenschrift wird dunkler', function () {
    $html = ($this->html)('konfigurator');
    $druck = \Illuminate\Support\Str::between($html, '/* druck-ersatzfarben */', '}');

    expect($druck)->not->toContain('--pt-text:')->not->toContain('--pt-primary:')->toContain('--pt-muted: #5f5850;');
});
