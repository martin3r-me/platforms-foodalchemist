<?php
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Services\IngredientMatchService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
uses(TestCase::class, SeedsTeamHierarchy::class);
it('probe', function () {
    $this->seedTeamHierarchy();
    foreach (['Tomaten: konserviert, geschaelt', 'Tomaten: TK', 'Tomaten: frisch'] as $i => $n) FoodAlchemistGp::create(['team_id' => $this->rootTeam->id, 'gp_key' => 'tp' . $i, 'name' => $n, 'status' => 'approved', 'main_ingredient_slug' => 'tomaten']);
    $r = app(IngredientMatchService::class)->matchIngredient($this->rootTeam, 'Tomaten', 'tomaten', 'gp_first', 'preserved_first', false, 'neutral');
    file_put_contents('/private/tmp/claude-1675406984/-Users-dbeutin-COOKING-JARVIS/425c5031-a64a-4f12-8e13-7b3fad306cfb/scratchpad/tomprobe.json', json_encode(array_map(fn ($v) => $v instanceof \BackedEnum ? $v->value : $v, $r), JSON_UNESCAPED_UNICODE));
    expect(true)->toBeTrue();
});
