<?php

use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Planung\RezeptImport;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Services\Ai\AiProposal;
use Platform\FoodAlchemist\Support\DeutscheZahl;
use Platform\FoodAlchemist\Support\EinheitAusText;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * PDF-Rezeptimport (Kurator/Dominique 27.10., Fall „Roastbeef“ von demo): verschachtelte Komponenten bis 3 Ebenen, deutsche Zahlen
 * („2.000 ml“ ist zweitausend), Einheit „Stück“ statt still Gramm.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    foreach ([['g', 'Gramm', 'mass'], ['ml', 'Milliliter', 'volume'], ['kg', 'Kilogramm', 'mass'], ['stk', 'Stück', 'count'], ['zweig', 'Zweig', 'count'], ['zehe', 'Zehe', 'count']] as [$slug, $name, $dim]) {
        FoodAlchemistVocabEinheit::create(['team_id' => $this->rootTeam->id, 'slug' => $slug, 'display_de' => $name, 'dimension' => $dim, 'default_in_g' => 1]);
    }
    $this->mock(AiGatewayService::class, fn ($m) => $m->shouldReceive('propose')->andReturn(new AiProposal([
        'typ' => 'basisrezept', 'name' => 'Roastbeef mit Kruste und Fond',
        'zutaten' => [], 'preparation' => 'Anrichten.',
        'komponenten' => [
            ['name' => 'Roastbeef', 'preparation' => 'Garen.', 'zutaten' => [['text' => 'Roastbeef vom Weiderind', 'quantity' => '1.600', 'unit' => 'g']],
                'komponenten' => [
                    ['name' => 'Kruste', 'preparation' => 'Aufstreichen.', 'zutaten' => [['text' => 'Butter', 'quantity' => '100', 'unit' => 'g']],
                        'komponenten' => [
                            ['name' => 'Grundkruste', 'preparation' => 'Mischen.', 'zutaten' => [['text' => 'Semmelbrösel', 'quantity' => '50', 'unit' => 'g']]],
                        ]],
                ]],
            ['name' => 'Brauner Fond', 'preparation' => 'Kochen.', 'zutaten' => [
                ['text' => 'Kalbsknochen', 'quantity' => '5.000', 'unit' => 'g'],
                ['text' => 'Wasser', 'quantity' => '2.000', 'unit' => 'ml'],
                ['text' => 'Schalotten', 'quantity' => '10', 'unit' => 'Stück'],
                ['text' => 'Thymian', 'quantity' => '2', 'unit' => 'Zweige'],
                ['text' => 'Salz', 'quantity' => '2,5', 'unit' => 'g'],
            ]],
        ],
    ], 0.9, 'Mock', [], 'extract')));
    $this->rezept = fn (string $name) => FoodAlchemistRecipe::where('team_id', $this->rootTeam->id)->where('name', $name)->latest('id')->firstOrFail();
    $this->subs = fn (FoodAlchemistRecipe $r) => FoodAlchemistRecipe::whereIn('id', $r->ingredients()->whereNotNull('referenced_recipe_id')->pluck('referenced_recipe_id'))->pluck('name')->sort()->values()->all();
});

it('Vorschau zeigt alle Komponenten-Ebenen; Anlegen baut den Baum mit 3 Ebenen unter dem Rezept', function () {
    Queue::fake();
    Livewire::test(RezeptImport::class)
        ->set('importText', 'Roastbeef …')
        ->call('importExtrahieren')
        ->assertSeeHtml('data-import-komponente="3"')                  // Grundkruste
        ->assertSet('importVorschau.komponenten.0.komponenten.0.komponenten.0.name', 'Grundkruste')
        ->call('importAnlegen')
        ->assertSet('importStep', 'fertig');

    expect(($this->subs)(($this->rezept)('Roastbeef mit Kruste und Fond')))->toBe(['Brauner Fond', 'Roastbeef'])
        ->and(($this->subs)(($this->rezept)('Roastbeef')))->toBe(['Kruste'])
        ->and(($this->subs)(($this->rezept)('Kruste')))->toBe(['Grundkruste'])
        ->and(($this->rezept)('Grundkruste')->ingredients()->value('raw_text'))->toBe('Semmelbrösel');
});

it('Mengen als deutsche Zahl und Einheiten aus dem Vokabular (Stück, Zweige) kommen im Rezept an', function () {
    Queue::fake();
    Livewire::test(RezeptImport::class)->set('importText', 'Roastbeef …')->call('importExtrahieren')->call('importAnlegen');

    $zeilen = ($this->rezept)('Brauner Fond')->ingredients()->get()->keyBy('raw_text');
    $einheit = fn (string $t) => FoodAlchemistVocabEinheit::find($zeilen[$t]->unit_vocab_id)?->slug;
    expect((float) $zeilen['Kalbsknochen']->quantity)->toBe(5000.0)
        ->and((float) $zeilen['Wasser']->quantity)->toBe(2000.0)
        ->and($einheit('Wasser'))->toBe('ml')
        ->and((float) $zeilen['Schalotten']->quantity)->toBe(10.0)
        ->and($einheit('Schalotten'))->toBe('stk')
        ->and($einheit('Thymian'))->toBe('zweig')
        ->and((float) $zeilen['Salz']->quantity)->toBe(2.5)
        ->and((float) ($this->rezept)('Roastbeef')->ingredients()->whereNull('referenced_recipe_id')->value('quantity'))->toBe(1600.0);
});

it('DeutscheZahl: Punkt als Tausendertrenner bei Dreiergruppen, Komma dezimal, sonst null', function (mixed $ein, ?float $aus) {
    expect(DeutscheZahl::alsFloat($ein))->toBe($aus);
})->with([
    ['2.000', 2000.0], ['1.600', 1600.0], ['5.000', 5000.0], ['1.234,5', 1234.5], ['2,5', 2.5], ['0,5', 0.5], ['12', 12.0],
    ['2.5', 2.5], ['0.75', 0.75], ['0.500', 0.5], [3, 3.0], [1.5, 1.5], ['', null], ['2-3', null], ['etwas', null], [null, null],
]);

it('EinheitAusText: Slug, Anzeigename, Abkürzung und Plural', function (string $text, ?string $slug) {
    $id = EinheitAusText::id($this->rootTeam, $text);
    expect($id === null ? null : FoodAlchemistVocabEinheit::find($id)->slug)->toBe($slug);
})->with([
    ['Stück', 'stk'], ['stück', 'stk'], ['Stk.', 'stk'], ['St', 'stk'], ['Stueck', 'stk'], ['Zweig', 'zweig'], ['Zweige', 'zweig'],
    ['Zehen', 'zehe'], ['Gramm', 'g'], ['g', 'g'], ['ml', 'ml'], ['Becher', null],
]);
