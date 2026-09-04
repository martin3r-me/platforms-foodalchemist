<?php

use Platform\FoodAlchemist\Models\FoodAlchemistPrice;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Services\Ai\AiProposal;
use Platform\FoodAlchemist\Services\RecipeService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * D (Dominique, 2026-09-04): Verpackungs-Einheiten → Masse.
 *
 * Geprüft wird die ENTSCHEIDUNG, nicht die Schätzung: übernommen werden darf nur, wo
 * Gebindegrösse (echte Daten) und KI-Schätzung zusammenpassen. Der Anlass steckt im
 * Vanillinzucker-Fall aus den Realdaten: die VPE sagt 1 kg (Lieferbeutel), gemeint ist
 * das Handels-Päckchen mit ~8 g. Eine Quelle allein hätte 1,5 kg in ein Rezept
 * geschrieben, und niemand hätte es gemerkt.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    // Der Befehl loggt einen Team-Nutzer ein (D1-Gate) und liest ihn über die Mitglieder-
    // Tabelle. makeUser setzt nur current_team_id — die Mitgliedschaft muss dazu.
    $nutzer = $this->makeUser($this->rootTeam, 'Root User');
    $this->rootTeam->users()->attach($nutzer->id, ['role' => 'owner']);
    $this->svc = app(RecipeService::class);
    $this->g = FoodAlchemistVocabEinheit::firstOrCreate(['team_id' => $this->rootTeam->id, 'slug' => 'g'],
        ['display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1]);
    FoodAlchemistVocabEinheit::firstOrCreate(['team_id' => $this->rootTeam->id, 'slug' => 'ml'],
        ['display_de' => 'Milliliter', 'dimension' => 'volume', 'default_in_ml' => 1]);
    $this->pck = FoodAlchemistVocabEinheit::firstOrCreate(['team_id' => $this->rootTeam->id, 'slug' => 'pck'],
        ['display_de' => 'Packung', 'dimension' => 'count']);
    $supplier = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Chefs Culinar']);

    /** GP mit Lead-LA und dessen Gebindegrösse (qty + kg/l). */
    $this->mkGp = function (string $name, ?float $gebinde, string $unit = 'kg') use ($supplier) {
        $gp = $this->makeGp($this->rootTeam, $name);
        if ($gebinde !== null) {
            $la = FoodAlchemistSupplierItem::create([
                'team_id' => $this->rootTeam->id, 'supplier_id' => $supplier->id,
                'designation' => $name, 'qty' => $gebinde, 'unit_code' => $unit,
            ]);
            FoodAlchemistSupplierItemStructure::create([
                'team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'gp_id' => $gp->id,
            ]);
            FoodAlchemistPrice::create(['team_id' => $this->rootTeam->id,
                'supplier_item_id' => $la->id, 'price' => 5.0, 'status' => '0']);
            $gp->update(['lead_la_supplier_item_id' => $la->id]);
        }

        return $gp->refresh();
    };

    $this->zeile = function ($gp, string $menge) {
        $r = $this->svc->create($this->rootTeam, ['name' => 'Rezept '.$gp->name.' '.$menge]);
        $this->svc->syncIngredients($this->rootTeam, $r->id, [[
            'id' => null, 'gp_id' => $gp->id, 'raw_text' => $menge.' pck',
            'quantity' => $menge, 'unit_vocab_id' => $this->pck->id,
        ]]);

        return $r;
    };

    $this->kiSagt = function (?float $masse) {
        $this->mock(AiGatewayService::class, fn ($m) => $m->shouldReceive('propose')->andReturn(
            new AiProposal($masse === null ? [] : ['masse_je_verpackung' => $masse, 'einheit' => 'g',
                'begruendung' => 'Testfall'], 0.9, 'm', [], 'x')
        ));
    };
});

it('stellt nur um, wenn Gebindegrösse und KI zusammenpassen', function () {
    $gp = ($this->mkGp)('Kraeuter: frisch, gehackt', 0.1);        // Gebinde 100 g
    $rezept = ($this->zeile)($gp, '2');
    ($this->kiSagt)(100.0);                                       // KI bestätigt

    $this->artisan('foodalchemist:recipe-packaging-units', ['--team' => $this->rootTeam->id, '--apply' => true])
        ->assertSuccessful();

    $zutat = FoodAlchemistRecipeIngredient::where('recipe_id', $rezept->id)->first();
    expect((float) $zutat->quantity)->toBe(200.0)                 // 2 × 100 g
        ->and($zutat->unit_vocab_id)->toBe($this->g->id);
});

it('lässt den Vanillinzucker-Fall in Ruhe — Gebinde und KI sind uneinig', function () {
    $gp = ($this->mkGp)('Vanillinzucker: trocken', 1.0);          // Gebinde 1000 g
    $rezept = ($this->zeile)($gp, '1.5');
    ($this->kiSagt)(8.0);                                         // Handels-Päckchen 8 g

    $this->artisan('foodalchemist:recipe-packaging-units', ['--team' => $this->rootTeam->id, '--apply' => true])
        ->assertSuccessful();

    $zutat = FoodAlchemistRecipeIngredient::where('recipe_id', $rezept->id)->first();
    expect((float) $zutat->quantity)->toBe(1.5)                   // unangetastet
        ->and($zutat->unit_vocab_id)->toBe($this->pck->id);
});

it('rührt unplausible Mengen und Zeilen ohne jede Quelle nicht an', function () {
    $winzig = ($this->mkGp)('Mirin: konserviert', 0.4, 'l');
    $rWinzig = ($this->zeile)($winzig, '0.001');                  // 0,001 Packungen = Eingabefehler
    $ohne = ($this->mkGp)('Kresse: frisch, ganz', null);          // kein Lead-LA
    $rOhne = ($this->zeile)($ohne, '2');
    ($this->kiSagt)(null);                                        // KI liefert nichts

    $this->artisan('foodalchemist:recipe-packaging-units', ['--team' => $this->rootTeam->id, '--apply' => true])
        ->assertSuccessful();

    expect((float) FoodAlchemistRecipeIngredient::where('recipe_id', $rWinzig->id)->value('quantity'))->toBe(0.001)
        ->and((float) FoodAlchemistRecipeIngredient::where('recipe_id', $rOhne->id)->value('quantity'))->toBe(2.0);
});

it('spielt ein Apply per Undo-Datei vollständig zurück', function () {
    $gp = ($this->mkGp)('Kraeuter: frisch, gehackt', 0.1);
    $rezept = ($this->zeile)($gp, '2');
    ($this->kiSagt)(100.0);
    $undo = sys_get_temp_dir().'/verp-test-'.uniqid();

    $this->artisan('foodalchemist:recipe-packaging-units',
        ['--team' => $this->rootTeam->id, '--apply' => true, '--report' => $undo])->assertSuccessful();
    expect((float) FoodAlchemistRecipeIngredient::where('recipe_id', $rezept->id)->value('quantity'))->toBe(200.0);

    $this->artisan('foodalchemist:recipe-packaging-units', ['--revert' => $undo.'.undo.json'])->assertSuccessful();

    $zutat = FoodAlchemistRecipeIngredient::where('recipe_id', $rezept->id)->first();
    expect((float) $zutat->quantity)->toBe(2.0)
        ->and($zutat->unit_vocab_id)->toBe($this->pck->id);
});
