<?php

use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Services\IngredientMatchService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * §5 Default-GP nach der GP-Bereinigung (Migration 2026_12_22_100000, Entscheid Dominique 11.11.2026): die Ziele heißen wie die GPs auf io.
 * Vorher trafen „Wasser“, „Eigelb“, „Öl“ … ins Leere und blieben in jedem Import offen.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    config(['foodalchemist.semantic_search.enabled' => false]);
    $this->gp = fn (string $name, string $slug, ?string $zustand, string $wg) => FoodAlchemistGp::create([
        'team_id' => $this->rootTeam->id, 'name' => $name, 'gp_key' => 'dz-' . bin2hex(random_bytes(4)) . '||', 'status' => 'approved', 'requires_la' => true,
        'commodity_group_code' => $wg, 'condition' => $zustand, 'main_ingredient_slug' => $slug]);
    $this->auto = function (string $t, bool $roh = false) {
        $r = app(IngredientMatchService::class)->matchIngredient($this->rootTeam, $t, null, 'gp_first', 'fresh_first', $roh, 'conventional');

        return IngredientMatchService::istAutomatischVerdrahtbar($r) ? ($r['gp_id'] ?? null) : null;
    };
});

it('die umgestellten Ziele treffen die GP-Namen von io', function (string $zeile, string $gp, string $slug, ?string $zustand, string $wg, bool $roh = false) {
    $ziel = ($this->gp)($gp, $slug, $zustand, $wg);
    ($this->gp)($gp . ', Würfel', $slug, $zustand, $wg);   // Geschwister: ohne Default-Ziel kein eindeutiger Treffer

    expect(($this->auto)($zeile, $roh))->toBe($ziel->id);
})->with([
    ['Wasser', 'Leitungswasser', 'leitungswasser', null, '15'],
    ['Eigelb', 'Eigelb: pasteurisiert, frisch, flüssig', 'eigelb', 'frisch', '07'],
    ['Eiweiß', 'Eiklar / Eiweiß: pasteurisiert, frisch, flüssig', 'eiklar', 'frisch', '07'],
    ['Weißwein', 'Weißwein: zum Kochen', 'weisswein', null, '15'],
    ['Olivenöl', 'Olivenöl', 'olivenoel', null, '11'],
    ['Sojasauce', 'Sojasauce: konserviert / (Kikkoman) / (Glutenfrei)', 'sojasauce', 'konserviert', '12'],
    ['Zwiebeln', 'Zwiebel: ganz, frisch', 'zwiebel', 'frisch', '01', true],
    ['Tomaten', 'Tomate: ganz, frisch', 'tomate', 'frisch', '01', true],
    ['Karotten', 'Möhre: frisch, mittel', 'moehre', 'frisch', '01', true],
    ['Öl', 'Rapsöl / Pflanzenöl: raffiniert', 'rapsoel', null, '11'],
    ['Pfefferkörner', 'Pfeffer schwarz: ganz, trocken', 'pfeffer_schwarz', 'trocken', '10'],
]);

it('„Pfeffer“ allein bleibt gemahlen, „Pfefferkörner“ wird ganz', function () {
    $gemahlen = ($this->gp)('Pfeffer schwarz: gemahlen, trocken', 'pfeffer_schwarz', 'trocken', '10');
    $ganz = ($this->gp)('Pfeffer schwarz: ganz, trocken', 'pfeffer_schwarz', 'trocken', '10');
    ($this->gp)('Pfeffer: ganz, trocken, grün', 'pfeffer', 'trocken', '10');

    expect(($this->auto)('Pfeffer'))->toBe($gemahlen->id)
        ->and(($this->auto)('Pfefferkörner'))->toBe($ganz->id);
});
