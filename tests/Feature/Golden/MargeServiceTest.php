<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Exceptions\FormelNichtDefiniertException;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\MargeService;
use Platform\FoodAlchemist\Services\RecipeRecomputeService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * M6-02: GL-02 §3.6 GT-8 wörtlich + Invariante I9 + Marge-Cockpit-Vertrag
 * (D-6 §7.2) + W-1-Guard (§7.3). MargeService = reine Berechnungs-Klasse.
 */
beforeEach(function () {
    $this->svc = new MargeService;
    $this->alc = (object) ['code' => 'ALC', 'rohaufschlag_pct' => 420.0, 'mwst_satz' => 19.0, 'formel_typ' => 'aufschlag'];
});

it('GT-8: ALC-Beispiel exakt — ek_basis 1,30 → vk_netto 6,76 → vk_brutto 8,04', function () {
    $v = $this->svc->vkVorschlag(5.20, 250.0, $this->alc);

    expect($v['ek_basis'])->toBe(1.30)
        ->and($v['vk_netto'])->toBe(6.76)
        ->and($v['vk_brutto'])->toBe(8.04)                            // ROUND(6.76 × 1.19, 2)
        ->and($v['mwst_satz'])->toBe(19.0);
});

it('GT-8 / I9: ein voller Recompute-Lauf verändert persistierte vk_*-Werte NICHT', function () {
    $this->seedTeamHierarchy();
    $rezept = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'i9', 'name' => 'Sauce: I9', 'status' => 'draft',
        'vk_netto' => 6.76, 'vk_brutto' => 8.04, 'mwst_satz' => 19,
    ]);

    app(RecipeRecomputeService::class)->recomputeAndPropagate($rezept->id);

    $danach = DB::table('foodalchemist_recipes')->where('id', $rezept->id)->first(['vk_netto', 'vk_brutto', 'mwst_satz']);
    expect((float) $danach->vk_netto)->toBe(6.76)
        ->and((float) $danach->vk_brutto)->toBe(8.04)
        ->and((float) $danach->mwst_satz)->toBe(19.0);
});

it('W-1-Guard: deckungsbeitrag wirft typisiert im Vorschlags-Pfad', function () {
    $klasse = (object) ['code' => 'PAUS', 'rohaufschlag_pct' => 0.0, 'mwst_satz' => 19.0, 'formel_typ' => 'deckungsbeitrag'];

    expect(fn () => $this->svc->vkVorschlag(5.20, 250.0, $klasse))
        ->toThrow(FormelNichtDefiniertException::class, 'W-1');
});

it('Cockpit-Vertrag: manueller vk_netto gewinnt gegen den Klassen-Vorschlag', function () {
    $mitManuell = $this->svc->effektiverVk(9.90, 5.20, 250.0, $this->alc);
    $ohneManuell = $this->svc->effektiverVk(null, 5.20, 250.0, $this->alc);

    expect($mitManuell['vk_netto'])->toBe(9.90)
        ->and($mitManuell['quelle'])->toBe('manuell')
        ->and($mitManuell['vorschlag']['vk_netto'])->toBe(6.76)       // Vorschlag bleibt sichtbar
        ->and($ohneManuell['vk_netto'])->toBe(6.76)
        ->and($ohneManuell['quelle'])->toBe('klasse');
});

it('Cockpit-Vertrag: margePct + wePct = 100 (gleiche Basis), Werte konsistent', function () {
    $m = $this->svc->marge(6.76, 1.30);

    expect($m['marge_eur'])->toBe(5.46)
        ->and($m['marge_pct'] + $m['wareneinsatz_pct'])->toBe(100.0)
        ->and($m['wareneinsatz_pct'])->toBe(19.2);                    // 1.30/6.76
});

it('Cockpit-Vertrag: pro-Einheit-Zerlegung netto/Anzahl + brutto', function () {
    $p = $this->svc->proEinheit(6.76, 4, 19.0);

    expect($p['vk_netto_pro_einheit'])->toBe(1.69)
        ->and($p['vk_brutto_pro_einheit'])->toBe(2.01);               // 1.69 × 1.19
});

it('Cockpit-Vertrag: ohne EK/Portionierung alles leer — Hinweis statt Fehler', function () {
    expect($this->svc->vkVorschlag(null, 250.0, $this->alc))->toBeNull()
        ->and($this->svc->vkVorschlag(5.20, null, $this->alc))->toBeNull()
        ->and($this->svc->vkVorschlag(5.20, 0.0, $this->alc))->toBeNull()
        ->and($this->svc->marge(null, 1.30))->toBeNull()
        ->and($this->svc->marge(6.76, null))->toBeNull()
        ->and($this->svc->proEinheit(6.76, null, 19.0))->toBeNull()
        ->and($this->svc->effektiverVk(null, null, null, null)['quelle'])->toBe('leer');
});
