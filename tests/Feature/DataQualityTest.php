<?php

use Platform\FoodAlchemist\Enums\SignalStatus;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient;
use Platform\FoodAlchemist\Models\FoodAlchemistServierform;
use Platform\FoodAlchemist\Models\FoodAlchemistSignal;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\DataQualityService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * P1 — Datenqualitäts-Ampel: der DataQualityService misst die Kaskaden-Lücken
 * (read-only) und emittiert sie idempotent als Signale in die Inbox.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->dq = app(DataQualityService::class);
});

/** Findet eine Metrik über alle Ebenen hinweg per key. */
function metrik(array $ebenen, string $key): array
{
    foreach ($ebenen as $ebene) {
        foreach ($ebene['metriken'] as $m) {
            if ($m['key'] === $key) {
                return $m;
            }
        }
    }
    throw new RuntimeException("Metrik {$key} nicht gefunden");
}

it('misst die Kaskaden-Lücken pro Ebene korrekt (read-only)', function () {
    // approved GP ohne Lead-LA, in einem Rezept genutzt, ohne Anker
    $gp = $this->makeGp($this->rootTeam, 'Zanderfilet');
    $gp->update(['status' => 'approved']);

    $basis = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'basis-x', 'name' => 'Basis X',
        'status' => 'approved', 'is_sales_recipe' => false,
    ]);
    $g = FoodAlchemistVocabEinheit::create([
        'team_id' => $this->rootTeam->id, 'slug' => 'g', 'display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1,
    ]);
    FoodAlchemistRecipeIngredient::create([
        'team_id' => $this->rootTeam->id, 'recipe_id' => $basis->id, 'gp_id' => $gp->id,
        'raw_text' => 'Zander', 'quantity' => '100', 'unit_vocab_id' => $g->id, 'position' => 1,
    ]);

    // VK-Gericht mit Standard-Darreichung auf „unbestimmt"
    $sf = FoodAlchemistServierform::create([
        'team_id' => $this->rootTeam->id, 'code' => 'unbestimmt', 'label' => 'Unbestimmt', 'sort_order' => 99,
    ]);
    $vk = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'vk-x', 'name' => 'Gericht X',
        'status' => 'approved', 'is_sales_recipe' => true,
    ]);
    FoodAlchemistRecipeDarreichung::create([
        'team_id' => $this->rootTeam->id, 'recipe_id' => $vk->id, 'serving_form_id' => $sf->id, 'is_standard' => true,
    ]);

    $e = $this->dq->messeAlleEbenen($this->rootTeam);

    expect(metrik($e, 'gp_ohne_lead')['wert'])->toBe(1)
        ->and(metrik($e, 'gp_anker_fehlt')['wert'])->toBe(1)
        ->and(metrik($e, 'gp_allergen_konfidenz')['wert'])->toBe(1)     // frisch angelegter GP: allergens_confidence NULL
        ->and(metrik($e, 'br_ek_null')['wert'])->toBe(1)                // Basisrezept ohne EK
        ->and(metrik($e, 'vk_ek_null')['wert'])->toBe(1)
        ->and(metrik($e, 'vk_servierform_unbestimmt')['wert'])->toBe(1)
        ->and(metrik($e, 'gp_ohne_lead')['severity'])->toBe('gelb');    // 1 ≤ Schwelle
});

it('grün wenn keine Lücken (leeres Team)', function () {
    $e = $this->dq->messeAlleEbenen($this->childB);
    expect(metrik($e, 'gp_ohne_lead')['wert'])->toBe(0)
        ->and(metrik($e, 'gp_ohne_lead')['severity'])->toBe('gruen')
        ->and(metrik($e, 'vk_servierform_unbestimmt')['wert'])->toBe(0);
});

it('emittiert Lücken als Signale und ist idempotent (Dedup)', function () {
    $gp = $this->makeGp($this->rootTeam, 'Lachs');
    $gp->update(['status' => 'approved']);

    $n1 = $this->dq->emittiereSignale($this->rootTeam);
    $offen1 = FoodAlchemistSignal::where('team_id', $this->rootTeam->id)->where('status', SignalStatus::Offen->value)->count();

    // zweiter Lauf: aktualisiert statt dupliziert
    $n2 = $this->dq->emittiereSignale($this->rootTeam);
    $offen2 = FoodAlchemistSignal::where('team_id', $this->rootTeam->id)->where('status', SignalStatus::Offen->value)->count();

    expect($n1)->toBeGreaterThan(0)
        ->and($n2)->toBe($n1)
        ->and($offen2)->toBe($offen1);           // kein Dauerfeuer
});
