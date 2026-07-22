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

    $g = FoodAlchemistVocabEinheit::create(['team_id' => $this->rootTeam->id, 'slug' => 'g', 'display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1]);
    $hg = FoodAlchemistDishMainGroup::create(['team_id' => $this->rootTeam->id, 'code' => 'HG', 'label' => 'Hauptgericht']);
    $klasse = FoodAlchemistDishClass::create(['team_id' => $this->rootTeam->id, 'dish_main_group_id' => $hg->id, 'code' => 'HG_N', 'label' => 'Neutral', 'diet_form' => 'neutral']);

    $convGp = $this->makeGp($this->rootTeam, 'Fertig-Sauce');
    $convGp->update(['tag_is_convenience' => true]);
    $scratchGp = $this->makeGp($this->rootTeam, 'Frische Tomate');
    $scratchGp->update(['tag_is_convenience' => false]);

    $mk = function (string $key, string $name, $gp) use ($g, $klasse) {
        $r = FoodAlchemistRecipe::create([
            'team_id' => $this->rootTeam->id, 'recipe_key' => $key, 'name' => $name, 'status' => 'approved',
            'is_sales_recipe' => true, 'sales_net' => 10.00, 'dish_class_id' => $klasse->id,
        ]);
        $r->ingredients()->create(['team_id' => $this->rootTeam->id, 'position' => 0, 'gp_id' => $gp->id, 'raw_text' => $name, 'quantity' => 100, 'unit_vocab_id' => $g->id]);

        return $r;
    };
    $this->convDish = $mk('conv', 'HG: Convenience-Teller', $convGp);
    $this->scratchDish = $mk('scratch', 'HG: Scratch-Teller', $scratchGp);

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

it('teil_convenience / kein Ziel = neutral (kein Convenience-Bias, beide vorhanden)', function () {
    $teil = collect($this->svc->slotVorschlaege($this->rootTeam, $this->frame, $this->slot, 2, null, 'teil_convenience'))->pluck('id')->all();
    $neutral = collect($this->svc->slotVorschlaege($this->rootTeam, $this->frame, $this->slot, 2, null, null))->pluck('id')->all();

    expect($teil)->toContain($this->convDish->id, $this->scratchDish->id)
        ->and($neutral)->toContain($this->convDish->id, $this->scratchDish->id)
        // neutral fällt auf name asc zurück → 'Convenience-Teller' vor 'Scratch-Teller'
        ->and($neutral[0])->toBe($this->convDish->id);
});
