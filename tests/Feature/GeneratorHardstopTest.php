<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Enums\MatchMethod;
use Platform\FoodAlchemist\Livewire\Recipes\GeneratorModal;
use Platform\FoodAlchemist\Livewire\Verkauf\VkGeneratorModal;
use Platform\FoodAlchemist\Models\FoodAlchemistGpNewProposal;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Services\HardstopResolveService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 03 L7b-2b — der Paritäts-Check der L7-DoD: Hard-Stops sind Wege, kein Text.
 *
 * Bewiesen wird dreierlei, und der dritte Punkt ist der, an dem die Doktrin hängt:
 *  1. Die Shortlist ist bedienbar („Meintest du?") und bindet als OVERRIDE —
 *     der Mensch übersteuert bewusst ein no_match des Matchers.
 *  2. Der Halbfabrikat-Fall legt wirklich an und verknüpft in einem Zug.
 *  3. Der GP-Fall legt KEINEN GP an. Unter LA-First (kein GP ohne LA) hat der
 *     Mint im selben Lauf schon erfolglos gesucht; der Knopf erzeugt darum
 *     einen Beschaffungs-Wunsch und die Zeile bleibt offen. Ein Test, der hier
 *     einen GP erwartete, würde den Guardrail zementieren wollen, den es nicht gibt.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
});

/**
 * Rezept mit genau einer offenen (ungemappten) Zeile an Position 1.
 * Die Fixture-Helfer des Traits sind `protected` — aus dem globalen Scope einer
 * Pest-Datei also nur über eine an die Testinstanz gebundene Closure erreichbar.
 */
function hardstopRezept($test): FoodAlchemistRecipe
{
    return \Closure::bind(function () {
        $recipe = $this->makeRecipe($this->rootTeam, 'One-Shot-Testgericht');
        $this->makeIngredient($recipe, 'Kalbsjus', null, '200', 1);

        return $recipe->refresh();
    }, $test, $test)();
}

function hardstopErgebnis(FoodAlchemistRecipe $recipe, array $shortlist = [], string $primaer = 'lieferantenartikel_waehlen', array $laKandidaten = []): array
{
    return [
        'recipe_id' => $recipe->id,
        'name' => $recipe->name,
        'statistik' => ['bestand_gp' => 2, 'bestand_sub' => 0, 'stub_neu' => 0, 'stubs' => [], 'offen' => 1],
        'offene' => [[
            'index' => 0,                       // Position 1 = Index 0 + 1
            'text' => 'Kalbsjus',
            'primaer' => $primaer,
            'shortlist' => $shortlist,
            'la_kandidaten' => $laKandidaten,
        ]],
    ];
}

it('«Meintest du?» bindet den Bestands-GP an die offene Zeile (als Override, ohne Score)', function () {
    $recipe = hardstopRezept($this);
    $gp = $this->makeGp($this->rootTeam, 'Kalbsfond dunkel');

    Livewire::test(GeneratorModal::class)
        ->set('ergebnis', hardstopErgebnis($recipe, [['kind' => 'gp', 'id' => $gp->id, 'name' => $gp->name, 'score' => 0.61]]))
        ->call('hardstopVerknuepfen', 0, 'gp', $gp->id)
        ->assertSet('ergebnis.offene', [])                       // Zeile ist weg …
        ->assertSet('ergebnis.statistik.offen', 0)               // … und der Zähler stimmt
        ->assertSet('ergebnis.statistik.bestand_gp', 3)
        ->assertSet('fehler', null)
        ->assertDispatched('recipe-gespeichert');

    $zeile = $recipe->ingredients()->where('position', 1)->first();
    expect((int) $zeile->gp_id)->toBe((int) $gp->id)
        ->and($zeile->referenced_recipe_id)->toBeNull()
        ->and($zeile->match_method)->toBe(MatchMethod::OverrideGp)
        ->and($zeile->match_confidence)->toBeNull();
});

it('LA und GP werden in zwei getrennten Schritten bestätigt; erst dann wird die Zeile gebunden', function () {
    $recipe = hardstopRezept($this);
    $supplier = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Necta']);
    $la = FoodAlchemistSupplierItem::create([
        'team_id' => $this->rootTeam->id, 'supplier_id' => $supplier->id,
        'designation' => 'Kalbsjus Premium', 'qty' => 1, 'unit_code' => 'kg',
    ]);
    $laDaten = [['id' => $la->id, 'designation' => $la->designation, 'supplier' => 'Necta', 'score' => 0.9, 'gp_id' => null, 'gp_name' => null]];

    $comp = Livewire::test(GeneratorModal::class)
        ->set('ergebnis', hardstopErgebnis($recipe, [], 'lieferantenartikel_waehlen', $laDaten))
        ->call('hardstopLaWaehlen', 0, $la->id)
        ->assertSet('ergebnis.offene.0.selected_la_id', $la->id)
        ->assertSet('ergebnis.statistik.offen', 1)
        ->assertSee('Passendes GP bestätigen')
        ->call('hardstopLaGpBestaetigen', 0)
        ->assertSet('ergebnis.statistik.offen', 0);

    $zeile = $recipe->ingredients()->where('position', 1)->first();
    expect($zeile->gp_id)->not->toBeNull()
        ->and(FoodAlchemistSupplierItemStructure::where('supplier_item_id', $la->id)->value('gp_id'))->toBe($zeile->gp_id);
});

it('Rezeptfreigabe ist separat und erst ohne offene Zutaten möglich', function () {
    $recipe = hardstopRezept($this);

    Livewire::test(GeneratorModal::class)
        ->set('ergebnis', hardstopErgebnis($recipe))
        ->call('generatorFreigeben')
        ->assertSet('freigegeben', false)
        ->assertSet('fehler', 'Vor der Freigabe bitte alle offenen Zutaten zuordnen.');

    $gp = $this->makeGp($this->rootTeam, 'Kalbsjus');
    app(HardstopResolveService::class)->verknuepfe($this->rootTeam, $recipe->id, 1, 'gp', $gp->id);

    $bereit = hardstopErgebnis($recipe);
    $bereit['offene'] = [];
    $bereit['statistik']['offen'] = 0;

    Livewire::test(GeneratorModal::class)
        ->set('ergebnis', $bereit)
        ->call('generatorFreigeben')
        ->assertSet('freigegeben', true);

    expect($recipe->refresh()->status->value)->toBe('approved');
});

it('Halbfabrikat-Lücke: Basisrezept-Stub wird angelegt, verknüpft und als Bringschuld gezählt', function () {
    $recipe = hardstopRezept($this);

    $comp = Livewire::test(VkGeneratorModal::class)
        ->set('ergebnis', hardstopErgebnis($recipe, [], 'basisrezept_anlegen'))
        ->call('hardstopStubAnlegen', 0)
        ->assertSet('ergebnis.statistik.offen', 0)
        ->assertSet('ergebnis.statistik.stub_neu', 1)             // NEU zählt als Stub, nicht als Reuse
        ->assertSet('ergebnis.statistik.bestand_sub', 0);

    expect($comp->get('ergebnis.statistik.stubs'))->toHaveCount(1);

    $zeile = $recipe->ingredients()->where('position', 1)->first();
    $stub = FoodAlchemistRecipe::find($zeile->referenced_recipe_id);
    expect($stub)->not->toBeNull()
        ->and($stub->status->value)->toBe('stub')
        ->and($zeile->match_method)->toBe(MatchMethod::RecipeRef);   // Generator-Provenienz, kein Override
});

it('GP-Lücke: der Knopf legt KEINEN GP an, sondern einen Beschaffungs-Wunsch — die Zeile bleibt offen', function () {
    $recipe = hardstopRezept($this);

    Livewire::test(GeneratorModal::class)
        ->set('ergebnis', hardstopErgebnis($recipe))
        ->call('hardstopBeschaffen', 0)
        ->assertSet('ergebnis.statistik.offen', 1)                // unverändert: kein GP ohne LA
        ->assertCount('ergebnis.offene', 1);

    expect(FoodAlchemistGpNewProposal::where('team_id', $this->rootTeam->id)->count())->toBe(1);
    expect($recipe->ingredients()->where('position', 1)->first()->gp_id)->toBeNull();
});

it('zweiter Beschaffungs-Ruf legt nicht doppelt an (Dedupe über den normalisierten Namen)', function () {
    $recipe = hardstopRezept($this);
    $service = app(HardstopResolveService::class);

    $service->beschaffungAnstossen($this->rootTeam, $recipe->id, 'Kalbsjus');
    $zweiter = $service->beschaffungAnstossen($this->rootTeam, $recipe->id, 'Kalbsjus');

    expect($zweiter['created'])->toBeFalse()
        ->and(FoodAlchemistGpNewProposal::where('team_id', $this->rootTeam->id)->count())->toBe(1);
});

it('eine schon verknüpfte Zeile wird nicht ein zweites Mal überschrieben (Doppelklick/zweiter Tab)', function () {
    $recipe = hardstopRezept($this);
    $erster = $this->makeGp($this->rootTeam, 'Kalbsfond dunkel');
    $zweiter = $this->makeGp($this->rootTeam, 'Geflügelfond hell');
    $service = app(HardstopResolveService::class);

    expect($service->verknuepfe($this->rootTeam, $recipe->id, 1, 'gp', $erster->id)['ok'])->toBeTrue();

    $nochmal = $service->verknuepfe($this->rootTeam, $recipe->id, 1, 'gp', $zweiter->id);
    expect($nochmal['ok'])->toBeFalse()
        ->and((int) $recipe->ingredients()->where('position', 1)->first()->gp_id)->toBe((int) $erster->id);
});

it('geerbtes Rezept wird vom Kind-Team nicht geschrieben (D1 / #504-Muster)', function () {
    $geerbt = $this->makeRecipe($this->rootTeam, 'Geerbtes Rezept');       // Besitzer = Root
    $this->makeIngredient($geerbt, 'Kalbsjus', null, '200', 1);
    $gp = $this->makeGp($this->rootTeam, 'Kalbsfond dunkel');

    // Sichtbar (Team-Kette aufwärts), aber NICHT editierbar — genau die D1-Grenze.
    expect(fn () => app(HardstopResolveService::class)->verknuepfe($this->childA, $geerbt->id, 1, 'gp', $gp->id))
        ->toThrow(RuntimeException::class);

    expect($geerbt->ingredients()->where('position', 1)->first()->gp_id)->toBeNull();
});

it('Selbstreferenz und unbekanntes Ziel werden abgewiesen statt gebunden', function () {
    $recipe = hardstopRezept($this);
    $service = app(HardstopResolveService::class);

    expect($service->verknuepfe($this->rootTeam, $recipe->id, 1, 'sub', $recipe->id)['ok'])->toBeFalse()
        ->and($service->verknuepfe($this->rootTeam, $recipe->id, 1, 'gp', 999999)['ok'])->toBeFalse()
        ->and($recipe->ingredients()->where('position', 1)->first()->gp_id)->toBeNull();
});

it('beide Generator-Flächen rendern die Hard-Stop-Knöpfe samt aufklappbarer Shortlist', function () {
    $recipe = hardstopRezept($this);
    $gp = $this->makeGp($this->rootTeam, 'Kalbsfond dunkel');
    $ergebnis = hardstopErgebnis($recipe, [['kind' => 'gp', 'id' => $gp->id, 'name' => $gp->name, 'score' => 0.61]]);

    Livewire::test(GeneratorModal::class)->set('ergebnis', $ergebnis)
        ->assertSeeHtml('data-hardstop-beschaffen="0"')
        ->assertSeeHtml('data-hardstop-shortlist="0"')
        ->assertDontSeeHtml('data-hardstop-kandidaten="0"')       // erst nach dem Aufklappen
        ->call('toggleShortlist', 0)
        ->assertSeeHtml('data-hardstop-kandidaten="0"')
        ->assertSee('Kalbsfond dunkel');

    Livewire::test(VkGeneratorModal::class)->set('ergebnis', hardstopErgebnis($recipe, [], 'basisrezept_anlegen'))
        ->assertSeeHtml('data-vk-hardstop-stub="0"');
});
