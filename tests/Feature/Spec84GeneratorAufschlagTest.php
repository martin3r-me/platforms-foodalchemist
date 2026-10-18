<?php

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistDishClass;
use Platform\FoodAlchemist\Models\FoodAlchemistDishMainGroup;
use Platform\FoodAlchemist\Models\FoodAlchemistMarkupClass;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Services\Ai\AiProposal;
use Platform\FoodAlchemist\Services\RecipeGeneratorService;
use Platform\FoodAlchemist\Services\RecipeOneShotService;
use Platform\FoodAlchemist\Support\TeamAncestryRegistry;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 84 (18.10.): Der VK-Generator las Aufschlagsklassen ohne Team-Filter — die Klassen ALLER Teams (auch die BHG-Margen
 * des Masters) gingen in den Prompt, und der KI-Code wurde über alle Teams aufgelöst (erste Zeile gewinnt = fremde Klasse
 * am Gericht des Mandanten). Jetzt: nur sichtbare Klassen, eigene vor geerbter, fremde nie.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    config(['foodalchemist.ai.provider' => 'fake']);
    $this->fremd = Team::create(['name' => 'Master fremd', 'user_id' => 1, 'personal_team' => false]);
    TeamAncestryRegistry::flushAll();
    FoodAlchemistVocabEinheit::create(['team_id' => null, 'slug' => 'g', 'display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1]);
    $ak = fn (?int $team, string $code, string $label) => FoodAlchemistMarkupClass::create(['team_id' => $team, 'code' => $code, 'label' => $label,
        'raw_markup_pct' => 300, 'vat_rate' => 19, 'formula_type' => 'aufschlag']);
    // fremde Klassen zuerst angelegt = kleinere id — vorher gewann genau diese bei ->first()
    $this->fremdAlc = $ak((int) $this->fremd->id, 'ALC', 'BHG-Marge geheim');
    $this->fremdNur = $ak((int) $this->fremd->id, 'BKT', 'Bankett BHG');
    $this->eigenAlc = $ak((int) $this->rootTeam->id, 'ALC', 'A la Carte eigen');

    // Prompt-Kontext abgreifen, Vorschlag mit gewünschtem AK-Code zurückgeben
    $this->prompt = null;
    $this->code = 'ALC';
    $this->mock(AiGatewayService::class, function ($mock) {
        $mock->shouldReceive('propose')->andReturnUsing(function (string $key, array $kontext) {
            if (array_key_exists('aufschlagsklassen', $kontext)) {
                $this->prompt = $kontext['aufschlagsklassen'];
            }

            return new AiProposal(['name' => 'HG: Testgericht', 'zutaten' => [['text' => 'Salz', 'quantity' => 1, 'unit' => 'g', 'slug' => 'salz']],
                'aufschlagsklasse_code' => $this->code], 0.9);
        });
    });
    $this->vorbereitet = ['prompt' => [], 'knowledge' => '', 'knowledge_used' => []];
    $this->gen = fn (Team $team) => app(RecipeGeneratorService::class)->generiere($team, 'Test', [], null, true, null, $this->vorbereitet)['recipe'];
});

it('Prompt: nur sichtbare Aufschlagsklassen, fremde Labels nie — bei gleichem Code steht das eigene Label', function () {
    ($this->gen)($this->rootTeam);

    expect($this->prompt)->toBe(['ALC' => 'A la Carte eigen'])
        ->and(json_encode($this->prompt))->not->toContain('BHG');
});

it('Code-Auflösung: gleicher Code → eigene Klasse, nie die fremde (auch wenn die fremde die kleinere id hat)', function () {
    $r = ($this->gen)($this->rootTeam);

    expect((int) $r->markup_class_id)->toBe((int) $this->eigenAlc->id);
});

it('Code-Auflösung: Code nur beim fremden Team → keine Klasse am Gericht statt der fremden', function () {
    $this->code = 'BKT';
    $r = ($this->gen)($this->rootTeam);

    expect($r->markup_class_id)->not->toBe($this->fremdNur->id)->and($r->markup_class_id)->toBeNull();
});

it('Kette bleibt: Unter-Team ohne eigene Klasse erbt die des Oberteams (nicht die fremde)', function () {
    $r = ($this->gen)($this->childA);

    expect((int) $r->markup_class_id)->toBe((int) $this->eigenAlc->id)
        ->and($this->prompt)->toBe(['ALC' => 'A la Carte eigen']);
});

// ── Default über die Speisen-Klasse (Master-Vokabular → Master-Aufschlagsklasse) ─────────────────────────────

it('Generator: Klassen-Default auf eine fremde Aufschlagsklasse → keine Klasse am Gericht, nicht die fremde', function () {
    $hg = FoodAlchemistDishMainGroup::create(['code' => 'HG', 'label' => 'Hauptgang', 'default_markup_class_id' => $this->fremdNur->id]);
    $klasse = FoodAlchemistDishClass::create(['dish_main_group_id' => $hg->id, 'code' => 'HG_X', 'label' => 'X', 'diet_form' => 'fleisch',
        'default_markup_class_id' => $this->fremdAlc->id]);
    $this->code = null;
    $r = app(RecipeGeneratorService::class)->generiere($this->rootTeam, 'Test', [], ['name' => 'HG: Testgericht',
        'zutaten' => [['text' => 'Salz', 'quantity' => 1, 'unit' => 'g', 'slug' => 'salz']], 'dish_class_id' => $klasse->id], true)['recipe'];

    expect((int) $r->dish_class_id)->toBe((int) $klasse->id)->and($r->markup_class_id)->toBeNull();
});

it('OneShot-Kaskade: Klassen-/HG-Default auf eine fremde Klasse zählt als nicht gesetzt; eine sichtbare greift', function () {
    $hg = FoodAlchemistDishMainGroup::create(['code' => 'HG', 'label' => 'Hauptgang', 'default_markup_class_id' => $this->fremdNur->id]);
    $klasse = FoodAlchemistDishClass::create(['dish_main_group_id' => $hg->id, 'code' => 'HG_X', 'label' => 'X', 'diet_form' => 'fleisch',
        'default_markup_class_id' => $this->fremdAlc->id]);
    $rezept = FoodAlchemistRecipe::create(['team_id' => $this->rootTeam->id, 'recipe_key' => 's84ak', 'name' => 'HG: Test', 'status' => 'draft',
        'is_sales_recipe' => true, 'dish_class_id' => $klasse->id, 'dish_main_group_id' => $hg->id]);
    $vb = fn () => app(RecipeOneShotService::class)->vkVorbedingungen($this->rootTeam, $rezept->fresh());

    expect($vb()['aufschlagsklasse'])->toBeNull()->and($vb()['aufschlagsklasse_quelle'])->toBe('keine');

    // Gegenstück (Paket kopiert die Klasse ins Mandanten-Team und hängt den Default um): dann greift sie
    $klasse->update(['default_markup_class_id' => $this->eigenAlc->id]);
    expect($vb()['aufschlagsklasse'])->toBe('ALC')->and($vb()['aufschlagsklasse_quelle'])->toBe('speisen_klasse');
});
