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

    expect((int) $r->fresh()->vk_klasse?->id)->toBe((int) $this->eigenAlc->id);   // Spec 85 D-1: an der Standard-Darreichung
});

it('Code-Auflösung: Code nur beim fremden Team → keine Klasse am Gericht statt der fremden', function () {
    $this->code = 'BKT';
    $r = ($this->gen)($this->rootTeam);

    $klasseId = $r->fresh()->vk_klasse?->id;
    expect($klasseId)->not->toBe($this->fremdNur->id)->and($klasseId)->toBeNull();
});

it('Kette bleibt: Unter-Team ohne eigene Klasse erbt die des Oberteams (nicht die fremde)', function () {
    $r = ($this->gen)($this->childA);

    expect((int) $r->fresh()->vk_klasse?->id)->toBe((int) $this->eigenAlc->id)
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

    expect((int) $r->dish_class_id)->toBe((int) $klasse->id)->and($r->fresh()->vk_klasse)->toBeNull();
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

// ── Preisweg (CatalogPricingService::catalogPrice): EIN Riegel — nur sichtbare Klassen, nie der Master-Faktor ─────────────

function abo_rechnung(FoodAlchemistRecipe $masterFassung, ?int $darreichungsKlasse = null): \Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung
{
    // wie TeamVkService::fuer: Rechen-Kopie (nie gespeichert), Gericht = Master-Fassung mit Master-Klasse
    $d = new \Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung(['ek_portion' => 2, 'price_mode' => 'auto', 'markup_class_id' => $darreichungsKlasse]);
    $d->setRelation('recipe', $masterFassung);
    if ($darreichungsKlasse === null) {
        $d->setRelation('markupClass', null);
    }

    return $d;
}

describe('Preisweg', function () {
    beforeEach(function () {
        // Preisweg-Fixture: Kunde = rootTeam (Ziel-WE 25 % → Basisfaktor 4,0), Master = $this->fremd mit Klasse ALC (Faktor 300 %)
        config(['foodalchemist.master_team_id' => $this->fremd->id]);
        $this->fremdAlc->update(['class_factor_pct' => 300]);
        app(\Platform\FoodAlchemist\Services\TeamSettingsService::class)->update($this->rootTeam, ['target_food_cost_pct' => 25,
            'rundungsregeln' => ['nachkommastellen' => 2, 'mode' => 'kaufmaennisch']]);
        app(\Platform\FoodAlchemist\Services\TeamSettingsService::class)->update($this->fremd, ['target_food_cost_pct' => 25,
            'rundungsregeln' => ['nachkommastellen' => 2, 'mode' => 'kaufmaennisch']]);
        $this->masterFassung = FoodAlchemistRecipe::create(['team_id' => $this->fremd->id, 'recipe_key' => 's84-abo', 'name' => 'HG: Abo',
            'status' => 'approved', 'is_sales_recipe' => true, 'markup_class_id' => $this->fremdAlc->id]);
        $this->preis = fn ($team, $d) => app(\Platform\FoodAlchemist\Services\CatalogPricingService::class)->catalogPrice($team, $d);
        // eigene Klasse des Kunden mit dem GLEICHEN Code wie die Master-Klasse — darf nicht über den Code greifen
        $this->eigenAlc->update(['class_factor_pct' => 150]);
    });

    it('Preisweg: Abo-Fassung mit Master-Klasse, Kunde ohne Team-Standard → neutral 100 % mit Warnung, nie Faktor 300', function () {
        $p = ($this->preis)($this->rootTeam, abo_rechnung($this->masterFassung));

        expect($p['class_id'])->toBeNull()->and($p['class_source'])->toBe('neutral')->and($p['class_factor_pct'])->toBe(100.0)
            ->and($p['sales_net'])->toBe(8.0)
            ->and(implode(' ', $p['warnings']))->toContain('Keine Preisklasse');
    });

    it('Preisweg: auch eine Master-Klasse direkt an der Darreichung zählt beim Kunden nicht', function () {
        $p = ($this->preis)($this->rootTeam, abo_rechnung($this->masterFassung, (int) $this->fremdAlc->id));

        expect($p['class_id'])->toBeNull()->and($p['sales_net'])->toBe(8.0);
    });

    it('Preisweg: Kunde mit Team-Standard → dessen Faktor; eigene Klasse mit gleichem Code greift NICHT über den Code', function () {
        $std = FoodAlchemistMarkupClass::create(['team_id' => $this->rootTeam->id, 'code' => 'STD', 'label' => 'Standard eigen',
            'class_factor_pct' => 110, 'vat_rate' => 19, 'formula_type' => 'aufschlag']);
        app(\Platform\FoodAlchemist\Services\TeamSettingsService::class)->update($this->rootTeam, ['default_markup_class_id' => $std->id]);

        $p = ($this->preis)($this->rootTeam, abo_rechnung($this->masterFassung));

        // eigene ALC (150 %) hat denselben Code wie die Master-ALC — gilt trotzdem nicht; nur der Team-Standard (110 %)
        expect((int) $p['class_id'])->toBe((int) $std->id)->and($p['class_source'])->toBe('team_standard')
            ->and($p['sales_net'])->toBe(8.8);
    });

    it('Preisweg: der Master selbst rechnet mit seiner Klasse (Gegenprobe, Riegel sperrt nur Fremde)', function () {
        // Spec 85 D-1 PR-C: die Klasse steht nur noch an der Darreichung (kein Rückfall auf recipes.markup_class_id) —
        // dieselbe Darreichung mit Master-Klasse, die beim Kunden gesperrt ist, rechnet beim Master mit Faktor 300 %.
        $p = ($this->preis)($this->fremd, abo_rechnung($this->masterFassung, (int) $this->fremdAlc->id));

        expect((int) $p['class_id'])->toBe((int) $this->fremdAlc->id)->and($p['class_source'])->toBe('darreichung')
            ->and($p['sales_net'])->toBe(24.0);
    });
});
