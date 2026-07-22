<?php

use Platform\FoodAlchemist\Models\FoodAlchemistDishClass;
use Platform\FoodAlchemist\Models\FoodAlchemistDishMainGroup;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\ConceptGeneratorService;
use Platform\FoodAlchemist\Services\PlanningFrameService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Convenience-Leitplanke im per-Slot-Ranking: der Convenience-Anteil eines Gerichts
 * (Quote tag_is_convenience-GPs unter den Zutaten) biast die Vorschläge — voll_convenience
 * bevorzugt Convenience-Gerichte, from_scratch das Gegenteil, teil_convenience/null neutral.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->frames = app(PlanningFrameService::class);
    $this->svc = app(ConceptGeneratorService::class);

    $this->g = FoodAlchemistVocabEinheit::create(['team_id' => $this->rootTeam->id, 'slug' => 'g', 'display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1]);
    $hg = FoodAlchemistDishMainGroup::create(['team_id' => $this->rootTeam->id, 'code' => 'HG', 'label' => 'Hauptgericht']);
    $this->klasse = FoodAlchemistDishClass::create(['team_id' => $this->rootTeam->id, 'dish_main_group_id' => $hg->id, 'code' => 'HG_N', 'label' => 'Neutral', 'diet_form' => 'neutral']);

    $this->convGp = $this->makeGp($this->rootTeam, 'Fertig-Sauce');
    $this->convGp->update(['tag_is_convenience' => true]);
    $this->scratchGp = $this->makeGp($this->rootTeam, 'Frische Tomate');
    $this->scratchGp->update(['tag_is_convenience' => false]);

    $mk = function (string $key, string $name, $gp) {
        $r = FoodAlchemistRecipe::create([
            'team_id' => $this->rootTeam->id, 'recipe_key' => $key, 'name' => $name, 'status' => 'approved',
            'is_sales_recipe' => true, 'sales_net' => 10.00, 'dish_class_id' => $this->klasse->id,
        ]);
        $r->ingredients()->create(['team_id' => $this->rootTeam->id, 'position' => 0, 'gp_id' => $gp->id, 'raw_text' => $name, 'quantity' => 100, 'unit_vocab_id' => $this->g->id]);

        return $r;
    };
    $this->convDish = $mk('conv', 'HG: Convenience-Teller', $this->convGp);
    $this->scratchDish = $mk('scratch', 'HG: Scratch-Teller', $this->scratchGp);

    $this->fb = FoodAlchemistFoodbook::create(['team_id' => $this->rootTeam->id, 'label' => 'Convenience-FB']);
    $this->frame = $this->frames->frameFor($this->rootTeam, 'foodbook', $this->fb->id);
    $this->slot = $this->frames->addSlot($this->rootTeam, $this->frame, ['label' => 'Hauptgang', 'slot_type' => 'gang', 'target_count' => 2]);
});

it('voll_convenience rankt das Convenience-Gericht zuerst', function () {
    $out = $this->svc->slotVorschlaege($this->rootTeam, $this->frame, $this->slot, 2, null, 'voll_convenience');
    expect($out[0]['id'])->toBe($this->convDish->id);
});

it('from_scratch rankt das Scratch-Gericht zuerst', function () {
    $out = $this->svc->slotVorschlaege($this->rootTeam, $this->frame, $this->slot, 2, null, 'from_scratch');
    expect($out[0]['id'])->toBe($this->scratchDish->id);
});

it('Convenience wirkt bis ins Basisrezept: Gericht mit Convenience-Sub-Rezept schlägt reines Scratch-Gericht', function () {
    // Basisrezept (kein VK) aus dem Convenience-GP + ein Scratch-GP direkt am Gericht.
    $basisrezept = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'br-conv', 'name' => 'BR: Fertig-Fond', 'status' => 'approved',
        'is_sales_recipe' => false, 'dish_class_id' => $this->klasse->id,
    ]);
    $basisrezept->ingredients()->create(['team_id' => $this->rootTeam->id, 'position' => 0, 'gp_id' => $this->convGp->id, 'raw_text' => 'Fertig-Sauce', 'quantity' => 100, 'unit_vocab_id' => $this->g->id]);

    $tief = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'tief', 'name' => 'HG: Tiefen-Teller', 'status' => 'approved',
        'is_sales_recipe' => true, 'sales_net' => 10.00, 'dish_class_id' => $this->klasse->id,
    ]);
    // direkte Zutat = Scratch-GP (nicht convenience), plus Referenz auf das Convenience-Basisrezept
    $tief->ingredients()->create(['team_id' => $this->rootTeam->id, 'position' => 0, 'gp_id' => $this->scratchGp->id, 'raw_text' => 'Frische Tomate', 'quantity' => 100, 'unit_vocab_id' => $this->g->id]);
    $tief->ingredients()->create(['team_id' => $this->rootTeam->id, 'position' => 1, 'referenced_recipe_id' => $basisrezept->id, 'raw_text' => 'Fertig-Fond', 'quantity' => 50, 'unit_vocab_id' => $this->g->id]);

    // Baum des Tiefen-Tellers: scratchGp + convGp = 0.5 Convenience → unter voll_convenience
    // muss er ÜBER dem reinen Scratch-Teller (0.0) ranken. Ohne Rekursion wäre er 0.0 (nur direkte GPs).
    $ids = collect($this->svc->slotVorschlaege($this->rootTeam, $this->frame, $this->slot, 5, null, 'voll_convenience'))->pluck('id')->all();
    $posTief = array_search($tief->id, $ids, true);
    $posScratch = array_search($this->scratchDish->id, $ids, true);

    expect($posTief)->not->toBeFalse()
        ->and($posScratch)->not->toBeFalse()
        ->and($posTief)->toBeLessThan($posScratch);
});

it('teil_convenience / kein Ziel = neutral (kein Convenience-Bias, beide vorhanden)', function () {
    $teil = collect($this->svc->slotVorschlaege($this->rootTeam, $this->frame, $this->slot, 2, null, 'teil_convenience'))->pluck('id')->all();
    $neutral = collect($this->svc->slotVorschlaege($this->rootTeam, $this->frame, $this->slot, 2, null, null))->pluck('id')->all();

    expect($teil)->toContain($this->convDish->id, $this->scratchDish->id)
        ->and($neutral)->toContain($this->convDish->id, $this->scratchDish->id)
        // neutral fällt auf name asc zurück → 'Convenience-Teller' vor 'Scratch-Teller'
        ->and($neutral[0])->toBe($this->convDish->id);
});
