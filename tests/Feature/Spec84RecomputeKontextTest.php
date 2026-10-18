<?php

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Jobs\RecomputeTeamRecipesJob;
use Platform\FoodAlchemist\Models\FoodAlchemistPrice;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Services\PricingCascadeService;
use Platform\FoodAlchemist\Services\RecipeRecomputeService;
use Platform\FoodAlchemist\Support\FaTeam;
use Platform\FoodAlchemist\Support\TeamAncestryRegistry;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 84 B3 · Veto-Prüfung Kurator zu 11db6432: Recompute lädt das Rezept mit ohneTeamSicht('recompute'), läuft aber im AKTUELLEN
 * Kontext. Fällt aus dem Blick eines fremden Kontexts (Master, Oberteam) ein Unter-Rezept oder ein GP weg, fehlt es still in den Kosten.
 *
 * Aufbau: Unter-Team childA besitzt Unter-Rezept S (mit eigenem, bepreistem GP) und Gericht E (mit S als Zutat). Gerechnet wird aus Kontexten,
 * die childA-Zeilen NICHT sehen (Sicht geht nur aufwärts): Master direkt, Master über recomputeAll, Oberteam über RecomputeTeamRecipesJob.
 * Maßstab: dieselben Kosten wie bei Stufe 0 — bei Stufe 1 (Rezepte gefiltert) und Stufe 3 (zusätzlich GP/Artikel gefiltert; Vorbedingung Stufe 3).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->master = Team::create(['name' => 'Master', 'user_id' => 1, 'personal_team' => false]);
    config(['foodalchemist.master_team_id' => $this->master->id]);
    TeamAncestryRegistry::flushAll();

    $kind = $this->childA;
    $supplier = FoodAlchemistSupplier::create(['team_id' => $kind->id, 'name' => 'Händler A']);
    $gp = $this->makeGp($kind, 'Limette');
    $la = FoodAlchemistSupplierItem::create(['team_id' => $kind->id, 'supplier_id' => $supplier->id, 'designation' => 'Limette', 'qty' => 1.0, 'unit_code' => 'kg']);
    FoodAlchemistSupplierItemStructure::create(['team_id' => $kind->id, 'supplier_item_id' => $la->id, 'gp_id' => $gp->id]);
    FoodAlchemistPrice::create(['team_id' => $kind->id, 'supplier_item_id' => $la->id, 'price' => 4.00, 'status' => '0']);
    $gp->update(['lead_la_supplier_item_id' => $la->id]);

    $this->sub = $this->makeRecipe($kind, 'Limettensud');
    $this->makeIngredient($this->sub, 'Limette', $gp->refresh(), '1000');
    $this->gericht = $this->makeRecipe($kind, 'Gericht mit Sud');
    FoodAlchemistRecipeIngredient::create(['team_id' => $kind->id, 'recipe_id' => $this->gericht->id, 'referenced_recipe_id' => $this->sub->id,
        'raw_text' => 'Limettensud', 'quantity' => '500', 'unit_vocab_id' => $this->unitG($this->rootTeam)->id, 'position' => 1]);

    $this->stand = function (int $stufe, Team $kontext, callable $lauf): array {
        config(['foodalchemist.team_sicht.stufe' => $stufe]);
        DB::beginTransaction();
        try {
            FaTeam::imTeam($kontext, $lauf);
            $zeilen = [];
            foreach ([$this->sub->id, $this->gericht->id] as $id) {
                $z = (array) DB::table('foodalchemist_recipes')->where('id', $id)->first();
                // Zeitstempel (updated_at, allergens_aggregated_at …) hängen an der Uhr, nicht an der Sicht — unter Last kippt sonst die Sekunde
                $z = array_filter($z, fn ($k) => ! str_ends_with((string) $k, '_at'), ARRAY_FILTER_USE_KEY);
                $zeilen[$id] = $z;
            }

            return $zeilen;
        } finally {
            DB::rollBack();
        }
    };
    $this->vergleiche = function (Team $kontext, callable $lauf) {
        $null = ($this->stand)(0, $kontext, $lauf);
        // Plausibilität: Stufe 0 rechnet wirklich einen EK (sonst verglichen wir null mit null)
        expect((float) $null[$this->sub->id]['ek_per_kg_eur'])->toBeGreaterThan(0.0)
            ->and((float) $null[$this->gericht->id]['ek_per_kg_eur'])->toBeGreaterThan(0.0);
        expect(($this->stand)(1, $kontext, $lauf))->toBe($null);
        // Spec 84 Vorbedingung Stufe 3: GP und LA-Preis des Unter-Teams aus fremdem Kontext (Stamm-Modelle gefiltert)
        expect(($this->stand)(3, $kontext, $lauf))->toBe($null);
    };
    // Kontext-Teams sehen childA-Zeilen bei Stufe 1 nicht (sonst prüfte der Test nichts) — bei Stufe 3 auch GP und Artikel nicht
    config(['foodalchemist.team_sicht.stufe' => 3]);
    expect(FaTeam::imTeam($this->master, fn () => \Platform\FoodAlchemist\Models\FoodAlchemistGp::whereKey($gp->id)->exists()))->toBeFalse()
        ->and(FaTeam::imTeam($this->master, fn () => FoodAlchemistSupplierItem::whereKey($la->id)->exists()))->toBeFalse();
    config(['foodalchemist.team_sicht.stufe' => 1]);
    expect(FaTeam::imTeam($this->master, fn () => FoodAlchemistRecipe::whereKey($this->sub->id)->exists()))->toBeFalse()
        ->and(FaTeam::imTeam($this->rootTeam, fn () => FoodAlchemistRecipe::whereKey($this->sub->id)->exists()))->toBeFalse();
});

it('Master rechnet ein Unter-Team-Gericht direkt: Kosten bei Stufe 1 = Stufe 0', function () {
    ($this->vergleiche)($this->master, function () {
        app(RecipeRecomputeService::class)->recomputePipeline($this->sub->id);
        app(RecipeRecomputeService::class)->recomputePipeline($this->gericht->id);
    });
});

it('Master über recomputeAll: Kosten bei Stufe 1 = Stufe 0', function () {
    ($this->vergleiche)($this->master, fn () => app(RecipeRecomputeService::class)->recomputeAll());
});

it('Oberteam über RecomputeTeamRecipesJob (Team + Nachfahren): Kosten bei Stufe 1 = Stufe 0', function () {
    ($this->vergleiche)($this->rootTeam, fn () => (new RecomputeTeamRecipesJob($this->rootTeam->id))->handle(app(RecipeRecomputeService::class), app(PricingCascadeService::class)));
});
