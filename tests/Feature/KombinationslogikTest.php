<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient;
use Platform\FoodAlchemist\Services\Pairing\AnkerVarianten;
use Platform\FoodAlchemist\Services\Pairing\Kombinationslogik;
use Platform\FoodAlchemist\Services\Pairing\RezeptProfil;
use Platform\FoodAlchemist\Tests\Support\Harmonie;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 60 · P6: Kombinationslogik eines Gerichts — Bestandteile sind Basisrezepte, die Anker
 * liegen im Hintergrund. Fall: Rosmarinkartoffeln + Chimichurri.
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

it('Bestandteile: Basisrezepte mit Profil, doppeltes nur einmal, ohne Anker als unbekannt', function () {
    $a = ($this->logik)()->analysiere($this->gericht->fresh());

    expect(array_column($a['bestandteile'], 'label'))->toBe(['Beilage: Rosmarinkartoffeln', 'Sauce: Chimichurri', 'Xylo-Würze'])
        ->and(($this->texte)($a['aussagen'], 'unbekannt'))->toBe(['Xylo-Würze: noch keinem Aroma zugeordnet']);
});

it('Harmonie, Spannung und offener Bedarf mit Grundlage', function () {
    $a = ($this->logik)()->analysiere($this->gericht->fresh());
    $harm = array_values(array_filter($a['aussagen'], fn ($x) => $x->typ->value === 'harmoniert'))[0];

    expect($harm->text)->toBe('Beilage: Rosmarinkartoffeln und Sauce: Chimichurri: harmonieren')
        ->and($harm->grundlage->value)->toBe('inspire_gemessen')
        // Kartoffel 87 % × Petersilie 60 % harmonieren gemessen
        ->and($harm->wert)->toBe(0.5218)
        ->and(($this->texte)($a['aussagen'], 'spannung'))->toBe(['Spannung: Säure von Sauce: Chimichurri für Beilage: Rosmarinkartoffeln'])
        ->and(($this->texte)($a['aussagen'], 'bedarf_offen'))->toBe(['Es könnte fehlen: Knusper (für Beilage: Rosmarinkartoffeln)'])
        ->and(array_column($a['offene_bedarfe'], 'achse'))->toBe(['knusprig']);
});

it('Vorschlag: zuerst Formwechsel, dann Teller-Basisrezepte — nicht süß, keine Vorbereitung', function () {
    $v = ($this->logik)()->vorschlaege($this->gericht->fresh());

    expect($v)->toHaveCount(1)
        ->and($v[0]['achse'])->toBe('knusprig')
        ->and($v[0]['formwechsel'])->toBe(['Beilage: Rosmarinkartoffeln: als Kartoffelchips'])
        // Croutons: herzhaft, Garnitur, harmonieren mit der Kartoffel. Crumble (süß) und die Beize (Vorbereitung) nicht.
        ->and(array_column($v[0]['basisrezepte'], 'name'))->toBe(['Garnitur: Croutons'])
        ->and($v[0]['basisrezepte'][0]['mit'])->toBe('Beilage: Rosmarinkartoffeln');
});

it('Vorschlag für ein veganes Gericht: nur ausdrücklich vegane Basisrezepte', function () {
    $this->gericht->update(['spec_is_vegan' => true]);
    $this->croutons->update(['spec_is_vegan' => null]);
    expect(($this->logik)()->vorschlaege($this->gericht->fresh())[0]['basisrezepte'])->toBe([]);

    $this->croutons->update(['spec_is_vegan' => true]);
    expect(array_column(($this->logik)()->vorschlaege($this->gericht->fresh())[0]['basisrezepte'], 'name'))->toBe(['Garnitur: Croutons']);
});
