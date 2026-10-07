<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient;
use Platform\FoodAlchemist\Services\Pairing\AnkerVarianten;
use Platform\FoodAlchemist\Services\Pairing\Kombinationslogik;
use Platform\FoodAlchemist\Services\Pairing\RezeptProfil;
use Platform\FoodAlchemist\Services\PairingService;
use Platform\FoodAlchemist\Tests\Support\Harmonie;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 60 · P7: Netz im Gericht — Basisrezepte im Vordergrund, Anker im Hintergrund. Gleicher Fall
 * wie KombinationslogikTest (Rosmarinkartoffeln + Chimichurri); das Netz zeigt dieselben Aussagen.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->a = [];
    $mk = function (string $slug, string $name, float $int) {
        DB::table('foodalchemist_vocab_pairing_anchors')->insert(['uuid' => (string) UuidV7::generate(), 'slug' => $slug,
            'display_de' => $name, 'aroma_intensitaet' => $int, 'created_at' => now(), 'updated_at' => now()]);

        return $this->a[$slug] = (int) DB::getPdo()->lastInsertId();
    };
    $mk('potato', 'Kartoffel', 1.0);
    $mk('rosemary', 'Rosmarin', 3.0);
    $mk('parsley', 'Petersilie', 3.0);
    $mk('vinegar', 'Rotweinessig', 2.0);
    $mk('crouton', 'Croutons', 1.0);
    $mk('chocolate', 'Schokolade', 3.0);
    app(AnkerVarianten::class)->ableiten();

    Harmonie::kante($this->a['potato'], $this->a['parsley'], 3);
    Harmonie::kante($this->a['potato'], $this->a['crouton'], 3);
    Harmonie::kante($this->a['potato'], $this->a['chocolate'], 3);

    $wissen = fn (string $t, array $z) => DB::table($t)->insert($z + ['status' => 'entwurf', 'created_at' => now(), 'updated_at' => now()]);
    $wissen('foodalchemist_anchor_bedarfe', ['anchor_id' => $this->a['potato'], 'achse' => 'saeure', 'staerke' => 'muss']);
    $wissen('foodalchemist_anchor_bedarfe', ['anchor_id' => $this->a['potato'], 'achse' => 'knusprig', 'staerke' => 'soll']);
    $wissen('foodalchemist_anchor_eigenschaften', ['anchor_id' => $this->a['vinegar'], 'achse' => 'saeure', 'stufe' => 3, 'quelle' => 'dossier']);
    $wissen('foodalchemist_anchor_eigenschaften', ['anchor_id' => $this->a['crouton'], 'achse' => 'knusprig', 'stufe' => 3, 'quelle' => 'dossier']);
    $wissen('foodalchemist_anchor_eigenschaften', ['anchor_id' => $this->a['chocolate'], 'achse' => 'knusprig', 'stufe' => 3, 'quelle' => 'dossier']);
    $wissen('foodalchemist_anchor_komponenten', ['anchor_id' => $this->a['potato'], 'name' => 'Kartoffelchips', 'liefert' => json_encode(['knusprig'])]);

    $this->gp = function (string $name, string $slug) {
        $gp = $this->makeGp($this->rootTeam, $name);
        DB::table('foodalchemist_gp_anchor_mappings')->insert(['uuid' => (string) UuidV7::generate(), 'team_id' => $this->rootTeam->id,
            'gp_id' => $gp->id, 'anchor_id' => $this->a[$slug], 'role' => 'kern', 'created_at' => now(), 'updated_at' => now()]);

        return $gp;
    };
    $this->basis = function (string $name, array $zutaten, array $attrs = []) {
        $r = $this->makeRecipe($this->rootTeam, $name);
        $r->update($attrs + ['is_sales_recipe' => false, 'taste_direction' => 'herzhaft', 'function' => 'Komponente']);
        foreach ($zutaten as $i => [$gpName, $slug, $menge]) {
            $this->makeIngredient($r, $gpName, ($this->gp)($gpName, $slug), $menge, $i + 1);
        }

        return $r->fresh();
    };
    $this->einsetzen = fn (FoodAlchemistRecipe $gericht, FoodAlchemistRecipe $sub, int $pos) => FoodAlchemistRecipeIngredient::create([
        'team_id' => $gericht->team_id, 'recipe_id' => $gericht->id, 'referenced_recipe_id' => $sub->id, 'raw_text' => $sub->name,
        'quantity' => '100', 'unit_vocab_id' => $this->unitG($this->rootTeam)->id, 'position' => $pos]);

    $this->kartoffeln = ($this->basis)('Beilage: Rosmarinkartoffeln', [['Kartoffeln: frisch', 'potato', '400'], ['Rosmarin: frisch', 'rosemary', '20']], ['function' => 'Beilage']);
    $this->chimi = ($this->basis)('Sauce: Chimichurri', [['Petersilie: frisch', 'parsley', '50'], ['Rotweinessig', 'vinegar', '50']], ['function' => 'Sauce']);
    $this->croutons = ($this->basis)('Garnitur: Croutons', [['Croutons', 'crouton', '100']], ['function' => 'Garnitur', 'spec_is_vegan' => true]);
    $this->crumble = ($this->basis)('Crumble: Schokolade', [['Schokolade', 'chocolate', '100']], ['taste_direction' => 'suess', 'function' => 'Dessert']);
    $this->beize = ($this->basis)('Trockenbeize: Knusper', [['Brotkrume', 'crouton', '100']], ['function' => 'Basis']);

    $this->gericht = $this->makeRecipe($this->rootTeam, 'Rosmarinkartoffeln mit Chimichurri');
    $this->gericht->update(['is_sales_recipe' => true, 'taste_direction' => 'herzhaft']);
    ($this->einsetzen)($this->gericht, $this->kartoffeln, 1);
    ($this->einsetzen)($this->gericht, $this->chimi, 2);
    ($this->einsetzen)($this->gericht, $this->chimi, 3);                    // doppelt eingesetzt
    $this->makeIngredient($this->gericht, 'Xylo-Würze', null, '5', 4);      // ohne Anker

    $profil = app(RezeptProfil::class);
    foreach ([$this->kartoffeln, $this->chimi, $this->croutons, $this->crumble, $this->beize] as $r) {
        $profil->fuer($r->id);
    }
    $this->logik = fn () => app(Kombinationslogik::class);
    $this->texte = fn (array $aussagen, string $typ) => array_values(array_map(fn ($x) => $x->text,
        array_filter($aussagen, fn ($x) => $x->typ->value === $typ)));
});

it('Gericht: Bestandteile statt Anker, Linien aus der Kombinationslogik, Vorschläge als Basisrezepte', function () {
    $netz = app(PairingService::class)->pairingNetz($this->rootTeam, $this->gericht->id);
    $knoten = collect($netz['nodes']);

    expect($netz['meta']['art'])->toBe('gericht')
        ->and($knoten->where('kind', 'anker'))->toHaveCount(0)
        ->and($knoten->where('kind', 'bestandteil')->pluck('label')->all())
        ->toBe(['Beilage: Rosmarinkartoffeln', 'Sauce: Chimichurri', 'Xylo-Würze'])
        ->and($knoten->firstWhere('label', 'Xylo-Würze')['ohne_profil'])->toBeTrue()
        ->and($knoten->firstWhere('label', 'Sauce: Chimichurri')['recipe_id'])->toBe($this->chimi->id);

    $linien = collect($netz['edges'])->where('kind', 'teil_teil')->pluck('typ', 'text')->all();
    expect($linien)->toBe([
        'Beilage: Rosmarinkartoffeln und Sauce: Chimichurri: harmonieren' => 'stern3',
        'Spannung: Säure von Sauce: Chimichurri für Beilage: Rosmarinkartoffeln' => 'kontrast',
    ]);

    // Croutons decken den offenen Bedarf „Knusper" (Kontrast); der Crumble (süß) und die Beize nicht.
    $vorschlag = $knoten->where('kind', 'basisrezept')->values();
    expect($vorschlag->pluck('label')->all())->toBe(['Garnitur: Croutons'])
        ->and($vorschlag[0]['typ'])->toBe('stern3')                                  // harmoniert mit der Kartoffel
        ->and($netz['meta']['counts']['bestandteile'])->toBe(3);
});

it('Basisrezept: Kern-Anker des Aromenprofils mit Anteil', function () {
    $netz = app(PairingService::class)->pairingNetz($this->rootTeam, $this->kartoffeln->id);
    $anker = collect($netz['nodes'])->where('kind', 'anker')->values();

    expect($netz['meta'])->not->toHaveKey('art')
        ->and($anker->pluck('label')->all())->toBe(['Kartoffel', 'Rosmarin'])          // nach Anteil
        ->and($anker[0]['anteil'])->toBeGreaterThan($anker[1]['anteil']);
});

it('Gericht-Editor: Tab Sensorik & Pairing zeigt Kombinationslogik und Netz statt der alten Kohäsion', function () {
    $this->actingAs($this->makeUser($this->rootTeam));
    $c = \Livewire\Livewire::test(\Platform\FoodAlchemist\Livewire\Verkauf\VkModal::class)->call('oeffnen', $this->gericht->id);
    // erst beim Besuch des Tabs gerechnet (sonst bei jedem Neuzeichnen des Editors)
    expect($c->html())->toContain('data-vk-pairing-laedt')->and($c->html())->not->toContain('data-editor-kombination');
    $html = $c->call('tabLaden', 'sensorik')->html();

    expect($html)->toContain('data-editor-kombination')
        ->and($html)->toContain('Beilage: Rosmarinkartoffeln und Sauce: Chimichurri: harmonieren')
        ->and($html)->toContain('data-editor-netz')
        ->and($html)->toContain('data-pairing-empfehlungen')
        ->and($html)->not->toContain('Aroma-Kohäsion')
        ->and($html)->not->toContain('Macht den Teller eigen');
});

it('Rezept-Editor (Basisrezept): Aromenprofil mit Anteil statt Kern-Anker-Liste', function () {
    $this->actingAs($this->makeUser($this->rootTeam));
    $html = \Livewire\Livewire::test(\Platform\FoodAlchemist\Livewire\Recipes\RecipeModal::class)
        ->call('oeffnen', $this->kartoffeln->id)->call('tabLaden', 'sensorik')->html();

    expect($html)->toContain('data-editor-kombination')
        ->and($html)->not->toContain('Aroma-Kohäsion');
});
