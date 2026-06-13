<?php

use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\PaketService;
use Platform\FoodAlchemist\Services\SpeiseplanService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * M14-01: Speiseplan — Belegung (Concept/Paket/Gericht), Kosten pro Tag/Woche,
 * Wiederholungs-Check (Mindestabstand).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->plan = app(SpeiseplanService::class);
    $this->pakete = app(PaketService::class);

    $this->gericht = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'g1', 'name' => 'Tagessuppe', 'status' => 'approved',
        'ist_verkaufsrezept' => true, 'vk_netto' => 3.50, 'ek_total_eur' => 1.00,
    ]);
    $this->paket = $this->pakete->create($this->rootTeam, ['name' => 'Salatbar', 'rolle' => 'Beilage', 'preis_modus' => 'manuell']);
    $this->pakete->update($this->rootTeam, $this->paket->id, ['preis_pro_person' => 2.50, 'ek_pro_person' => 0.80]);
});

it('M14: Eintrag belegt Zelle mit GENAU EINEM Inhalt; Raster + Kosten pro Tag', function () {
    $sp = $this->plan->create($this->rootTeam, ['name' => 'KW 24', 'zyklus_wochen' => 1]);
    // Mo Mittag: Gericht (3,50) + Paket (2,50)
    $this->plan->addEintrag($this->rootTeam, $sp->id, ['woche' => 1, 'wochentag' => 1, 'mahlzeit' => 'mittag', 'vk_recipe_id' => $this->gericht->id]);
    $this->plan->addEintrag($this->rootTeam, $sp->id, ['woche' => 1, 'wochentag' => 1, 'mahlzeit' => 'mittag', 'paket_id' => $this->paket->id]);
    // Eintrag mit BEIDEN gesetzt → nur concept gewinnt (genau eines)
    $beide = $this->plan->addEintrag($this->rootTeam, $sp->id, ['woche' => 1, 'wochentag' => 2, 'mahlzeit' => 'mittag', 'paket_id' => $this->paket->id, 'vk_recipe_id' => $this->gericht->id]);
    expect($beide->paket_id)->toBe($this->paket->id)->and($beide->vk_recipe_id)->toBeNull();

    $raster = $this->plan->raster($sp->refresh());
    expect($raster[1][1]['mittag'])->toHaveCount(2);

    $kosten = $this->plan->kosten($sp);
    expect($kosten['pro_tag'][1][1]['vk'])->toBe(6.0)               // 3,50 + 2,50
        ->and($kosten['pro_tag'][1][1]['ek'])->toBe(1.8)            // 1,00 + 0,80
        ->and($kosten['gesamt']['vk'])->toBe(8.5);                  // + Di 2,50
});

it('M14: Wiederholungs-Check flaggt zu engen Abstand', function () {
    $sp = $this->plan->create($this->rootTeam, ['name' => 'Zyklus', 'zyklus_wochen' => 2, 'min_abstand_tage' => 5]);
    // Tagessuppe Mo (Tag 1) und Mi (Tag 3) → Abstand 2 < 5 → Konflikt
    $this->plan->addEintrag($this->rootTeam, $sp->id, ['woche' => 1, 'wochentag' => 1, 'mahlzeit' => 'mittag', 'vk_recipe_id' => $this->gericht->id]);
    $this->plan->addEintrag($this->rootTeam, $sp->id, ['woche' => 1, 'wochentag' => 3, 'mahlzeit' => 'mittag', 'vk_recipe_id' => $this->gericht->id]);

    $w = collect($this->plan->wiederholungen($sp->refresh()))->firstWhere('key', 'g' . $this->gericht->id);
    expect($w['vorkommen'])->toBe(2)->and($w['min_abstand'])->toBe(2)->and($w['konflikt'])->toBeTrue();
});

it('M14: Owner-Guard — Kind-Team kann geerbten Speiseplan nicht pflegen', function () {
    $sp = $this->plan->create($this->rootTeam, ['name' => 'Root-Plan']);
    expect(fn () => $this->plan->update($this->childA, $sp->id, ['name' => 'Hack']))->toThrow(\RuntimeException::class)
        ->and(fn () => $this->plan->addEintrag($this->childA, $sp->id, ['vk_recipe_id' => $this->gericht->id]))->toThrow(\RuntimeException::class);
});
