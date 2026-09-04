<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Recipes\RecipeModal;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Services\Ai\AiProposal;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 51 — was die KI bei Behältern darf und was nicht.
 *
 * DARF: Dichteklasse und Skalierung — Produkteigenschaften.
 * DARF NICHT: die Anzahl Behälter. Das ist eine Rechnung, und die Datenbank kennt die Kilogramm
 * exakt. Genau deshalb ist `vk.behaelter` ersatzlos entfallen.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));

    $this->rezept = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'ki1', 'name' => 'Sauce: Pfeffer',
        'status' => 'approved', 'is_sales_recipe' => false, 'yield_kg' => 10,
    ]);

    $this->mockKi = function (array $werte) {
        $this->mock(AiGatewayService::class, function ($mock) use ($werte) {
            $mock->shouldReceive('propose')->andReturn(new AiProposal($werte, 0.8));
        });
    };
});

it('übernimmt Dichteklasse und Skalierung — aber schreibt nichts in die DB', function () {
    ($this->mockKi)(['dichteklasse' => 'fluessig', 'skalierung' => 'tiefer_fuellbar']);

    Livewire::test(RecipeModal::class)
        ->call('oeffnen', $this->rezept->id)
        ->call('tabLaden', 'regeneration')
        ->call('kiDichteklasse')
        ->assertSet('dichteklasse', 'fluessig')
        ->assertSet('behaelterForm.abfuellen.skalierung', 'tiefer_fuellbar')
        ->assertSet('regenMeldung', fn ($m) => str_contains((string) $m, 'Noch nicht gespeichert'));

    // GL-07: die Übernahme ist eine Entscheidung des Menschen — der Vorschlag allein schreibt nicht.
    expect(FoodAlchemistRecipe::find($this->rezept->id)->dichteklasse)->toBeNull();
});

it('überschreibt keine gepflegte Skalierung — Override-First', function () {
    ($this->mockKi)(['dichteklasse' => 'locker', 'skalierung' => 'hoehe_gebunden']);

    Livewire::test(RecipeModal::class)
        ->call('oeffnen', $this->rezept->id)
        ->call('tabLaden', 'regeneration')
        ->set('behaelterForm.abfuellen.skalierung', 'lagenware')     // von Hand entschieden
        ->call('kiDichteklasse')
        ->assertSet('behaelterForm.abfuellen.skalierung', 'lagenware')
        ->assertSet('behaelterForm.regenerieren.skalierung', 'hoehe_gebunden');   // leer war frei
});

it('verwirft Unsinn statt ihn zu übernehmen', function () {
    ($this->mockKi)(['dichteklasse' => 'schwerelos', 'skalierung' => 'irgendwas']);

    Livewire::test(RecipeModal::class)
        ->call('oeffnen', $this->rezept->id)
        ->call('tabLaden', 'regeneration')
        ->call('kiDichteklasse')
        ->assertSet('dichteklasse', '')
        ->assertSet('regenMeldung', fn ($m) => str_contains((string) $m, 'nichts übernommen'));
});

it('die KI hat keinen Weg mehr, eine Behälterzahl zu setzen', function () {
    // `vk.behaelter` ist aus der Gateway-Whitelist entfernt — ein Aufruf muss scheitern,
    // nicht still durchlaufen.
    $erlaubt = (new ReflectionClass(AiGatewayService::class))->getConstants();
    $slugs = collect($erlaubt)->flatten()->all();

    expect($slugs)->not->toContain('vk.behaelter')
        ->and(config('foodalchemist.prompts'))->not->toHaveKey('vk.behaelter')
        ->and(config('foodalchemist.prompts'))->toHaveKey('recipe.dichteklasse');
});

it('der Prompt-Vertrag nennt genau die drei erlaubten Felder — strukturell, nicht per Wortsuche', function () {
    $task = (string) (config('foodalchemist.prompts')['recipe.dichteklasse']['task'] ?? '');

    // Auf Prompt-WOERTER zu pruefen ist die falsche Sonde: die erste Fassung dieses Tests brach,
    // als der Prompt »keine Behaelterzahl« schrieb — eine Verneinung, die die Wortsuche traf.
    // Der Vertrag ist die werte-Klammer, und nur die. Sie ist inzwischen VERSCHACHTELT, also
    // wird auf Klammer-Tiefe gesplittet statt an jedem Komma (ein `[^}]*` schnitt die Zeile mitten
    // im inneren Objekt ab und haette den Vertrag falsch gelesen).
    $ab = mb_strpos($task, 'werte = {');
    expect($ab)->not->toBeFalse();

    $rest = mb_substr($task, $ab + mb_strlen('werte = {'));
    $tiefe = 0;
    $oben = [];
    $puffer = '';
    foreach (mb_str_split($rest) as $z) {
        if ($z === '{') { $tiefe++; }
        if ($z === '}') { if ($tiefe === 0) { break; } $tiefe--; }
        if ($z === ',' && $tiefe === 0) { $oben[] = trim($puffer); $puffer = ''; continue; }
        $puffer .= $z;
    }
    if (trim($puffer) !== '') { $oben[] = trim($puffer); }

    // Oberste Ebene: die drei erlaubten Felder — und NICHTS, was nach Menge oder Anzahl riecht.
    $namen = array_map(fn (string $f) => trim(explode(':', $f)[0]), $oben);
    expect($namen)->toBe(['dichteklasse', 'skalierung', 'behaelter_je_zweck']);

    // Die Behaelterwahl deckt genau die vier Zwecke ab — kein fuenftes Feld, in dem eine
    // Referenzmenge oder eine Anzahl mitreisen koennte.
    preg_match('/behaelter_je_zweck:\s*\{([^}]*)\}/u', $task, $inner);
    $zwecke = collect(explode(',', $inner[1] ?? ''))->map(fn ($f) => trim($f))->filter()->values()->all();
    expect($zwecke)->toBe(\Platform\FoodAlchemist\Models\FoodAlchemistVocabContainer::ZWECKE);
});

it('die Skalierungs-Werte sind kulinarisch begruendet, nicht mechanisch', function () {
    // Erster Echtdaten-Lauf: 6 von 6 Rezepten kamen als »tiefer_fuellbar« zurueck — kein Urteil,
    // sondern der erstgenannte Wert. Die Beschreibung war die Sicht des Rechners (»nur die Flaeche
    // skaliert«), nicht die der Kueche. Der Riegel: die Entscheidungsfrage muss im Prompt stehen,
    // und der Zweifelsfall muss die konservative Richtung nennen.
    $task = (string) (config('foodalchemist.prompts')['recipe.dichteklasse']['task'] ?? '');

    expect($task)->toContain('doppelt so')                 // die Entscheidungsfrage
        ->and($task)->toContain('Im Zweifel hoehe_gebunden')
        ->and($task)->toContain('lagenware');
});

/**
 * DARF AUCH: den Behaelter je Zweck waehlen. Das ist keine Rechnung, sondern Kuechenwissen
 * („Suppe kommt in den Eimer, nicht ins GN") — dieselbe Sorte Urteil wie `skalierung`.
 * Aber nur mit Riegeln, sonst sieht ein geratener Behaelter aus wie eine gepflegte Entscheidung.
 */
it('übernimmt die Behälterwahl je Zweck — und hält Erfundenes, Unfreigegebenes und Gepflegtes raus', function () {
    $behaelter = function (string $name, ?array $eignung) {
        return DB::table('foodalchemist_vocab_containers')->insertGetId([
            'uuid' => (string) Str::uuid7(), 'team_id' => $this->rootTeam->id,
            'slug' => Str::slug($name, '_'), 'name' => $name, 'sort_order' => 10,
            'familie' => str_starts_with($name, 'GN') ? 'GN' : 'Eimer',
            'volumen_l' => 10.0, 'eignung' => $eignung !== null ? json_encode($eignung) : null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    };

    $eimer = $behaelter('Eimer 10 l', ['abfuellen', 'transport']);   // NICHT fuer den Ofen frei
    $gn = $behaelter('GN 1/1 65mm', ['regenerieren', 'ausgabe']);
    $schale = $behaelter('Schale flach', null);                      // eignung NULL = noch nicht gepflegt

    ($this->mockKi)([
        'dichteklasse' => 'fluessig',
        'skalierung' => 'tiefer_fuellbar',
        'behaelter_je_zweck' => [
            'abfuellen' => $eimer,
            'regenerieren' => $eimer,      // ✗ Riegel 2: Eimer ist nicht ofenfreigegeben
            'ausgabe' => $schale,          // ✓ ungepflegte Eignung heisst „nicht verboten"
            'transport' => 999999,         // ✗ Riegel 1: gibt es nicht
        ],
    ]);

    $c = Livewire::test(RecipeModal::class)->call('oeffnen', $this->rezept->id)->call('tabLaden', 'regeneration');

    // Riegel 3: was schon gepflegt ist, ruehrt der Vorschlag nicht an.
    $c->set('behaelterForm.abfuellen.container_vocab_id', (string) $gn)
        ->call('kiDichteklasse')
        ->assertSet('behaelterForm.abfuellen.container_vocab_id', (string) $gn)
        ->assertSet('behaelterForm.regenerieren.container_vocab_id', '')
        ->assertSet('behaelterForm.ausgabe.container_vocab_id', (string) $schale)
        ->assertSet('behaelterForm.transport.container_vocab_id', '');

    // Und die Referenzmenge bleibt leer: eine Schaetzung dort waere Rang 1 mit Konfidenz „hoch"
    // und wuerde die Dichteklasse ueberstimmen, die es genau fuer Schaetzungen gibt.
    $c->assertSet('behaelterForm.ausgabe.referenz_menge_kg', '');
});
