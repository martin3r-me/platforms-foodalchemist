<?php

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Suppliers\ItemModal;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistItemAllergen;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\GpAggregateService;
use Platform\FoodAlchemist\Services\RecipeRecomputeService;
use Platform\FoodAlchemist\Support\AllergenAnnahme;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 85 (Dominique 19.10.): Lieferanten, die in Necta nur POSITIVE Allergenwerte liefern (BOS Food, Handelshof Mönchengladbach E,
 * Kröswang, Edna). Bei ihnen gilt beim Lesen: leer = angenommen nicht enthalten — ein ausdrücklicher Wert gewinnt immer, Lieferanten
 * ohne Merkmal bleiben unbekannt, `item_allergens` wird nie beschrieben, die Sicherheit ist höchstens MED.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    AllergenAnnahme::vergessen();
    $this->agg = app(GpAggregateService::class);
    $this->lieferant = function (string $name, bool $nurPositiv) {
        return FoodAlchemistSupplier::create(['team_id' => null, 'name' => $name, 'allergene_nur_positiv' => $nurPositiv]);
    };
    /** LA am GP mit Allergen-Zeile ($werte = feld => wert; der Rest bleibt NULL). */
    $this->la = function (FoodAlchemistGp $gp, FoodAlchemistSupplier $l, ?array $werte, string $name = 'Artikel') {
        $la = FoodAlchemistSupplierItem::create(['team_id' => $this->rootTeam->id, 'supplier_id' => $l->id, 'designation' => $name . ' ' . uniqid(), 'unit_code' => 'kg']);
        FoodAlchemistSupplierItemStructure::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'gp_id' => $gp->id]);
        if ($werte !== null) {
            FoodAlchemistItemAllergen::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id]
                + collect($werte)->mapWithKeys(fn ($v, $k) => ["allergen_{$k}" => $v])->all());
        }
        AllergenAnnahme::vergessen();

        return $la;
    };
    $this->bos = ($this->lieferant)('BOS Food', true);
    $this->hanos = ($this->lieferant)('Hanos', false);
});

it('Positiv-Lieferant: leer = angenommen nicht enthalten, ausdrücklicher Wert bleibt; Sicherheit MED', function () {
    $gp = $this->makeGp($this->rootTeam, 'Butter');
    ($this->la)($gp, $this->bos, ['milk' => 'enthalten']);

    $a = $this->agg->allergene($gp);
    $k = $this->agg->allergenKonfidenz($gp);

    expect($a['milk']['value']->value)->toBe('enthalten')->and($a['milk']['source'])->toBe('la')
        ->and($a['gluten']['value']->value)->toBe('nicht_enthalten')->and($a['gluten']['source'])->toBe(AllergenAnnahme::QUELLE)
        ->and(collect($a)->where('source', AllergenAnnahme::QUELLE)->count())->toBe(13)
        ->and($k['confidence'])->toBe('medium')->and($k['needs_review'])->toBeFalse()->and($k['angenommen_felder'])->toHaveCount(13)
        ->and($this->agg->hatLaAllergenProfil($gp))->toBeTrue()
        ->and(DB::table('foodalchemist_item_allergens')->whereNotNull('allergen_gluten')->count())->toBe(0);   // nichts geschrieben
});

it('Lieferant ohne Merkmal bleibt unbekannt (Quelle keine, Sicherheit unverändert)', function () {
    $gp = $this->makeGp($this->rootTeam, 'Sahne');
    ($this->la)($gp, $this->hanos, ['milk' => 'enthalten']);

    $a = $this->agg->allergene($gp);

    expect($a['gluten']['value']->value)->toBe('unbekannt')->and($a['gluten']['source'])->toBe('keine')
        ->and($this->agg->allergenKonfidenz($gp)['confidence'])->toBe('high');
});

it('ein ausdrücklicher Necta-Wert schlägt die Annahme — am selben und an einem anderen Artikel; kein Konflikt, keine Review-Flut', function () {
    $gp = $this->makeGp($this->rootTeam, 'Brot');
    ($this->la)($gp, $this->bos, ['milk' => 'enthalten', 'sesame' => 'spuren']);
    ($this->la)($gp, $this->hanos, ['gluten' => 'enthalten', 'milk' => 'enthalten', 'sesame' => 'spuren']);

    $a = $this->agg->allergene($gp);
    $k = $this->agg->allergenKonfidenz($gp);

    expect($a['gluten']['value']->value)->toBe('enthalten')->and($a['gluten']['source'])->toBe('la')   // BOS leer (angenommen nein) < Hanos enthalten
        ->and($a['sesame']['value']->value)->toBe('spuren')
        ->and($k['needs_review'])->toBeFalse()->and($k['konflikt_felder'])->toBe([])
        ->and($k['angenommen_felder'])->not->toContain('gluten');
});

it('nur NULL wird angenommen: ausdrückliches „unbekannt“ bleibt am Artikel unbekannt, Artikel ohne Allergen-Zeile auch', function () {
    $gp = $this->makeGp($this->rootTeam, 'Senf');
    ($this->la)($gp, $this->bos, ['mustard' => 'unbekannt', 'milk' => 'nicht_enthalten']);
    $ohneZeile = $this->makeGp($this->rootTeam, 'Pfeffer');
    ($this->la)($ohneZeile, $this->bos, null);

    $zeile = AllergenAnnahme::zeile(FoodAlchemistItemAllergen::first(), $this->bos->id);

    expect($zeile['mustard'])->toBe(['wert' => 'unbekannt', 'angenommen' => false])
        ->and($zeile['gluten'])->toBe(['wert' => 'nicht_enthalten', 'angenommen' => true])
        ->and($this->agg->allergene($ohneZeile)['gluten']['source'])->toBe('keine')
        ->and($this->agg->hatLaAllergenProfil($ohneZeile))->toBeFalse();
});

it('Bulk-Ränge (Liste) gleich der Einzel-Auflösung', function () {
    $gp = $this->makeGp($this->rootTeam, 'Käse');
    ($this->la)($gp, $this->bos, ['milk' => 'enthalten']);
    $hanosGp = $this->makeGp($this->rootTeam, 'Quark');
    ($this->la)($hanosGp, $this->hanos, ['milk' => 'enthalten']);

    $bulk = $this->agg->laMaxRaengeBulk([$gp->id, $hanosGp->id]);

    expect($bulk[$gp->id]['milk'])->toBe(3)->and($bulk[$gp->id]['gluten'])->toBe(1)
        ->and($bulk[$hanosGp->id]['gluten'])->toBe(0);
});

it('Rezept aus Positiv-Lieferanten: vollständig (nichts unbekannt), Sicherheit nicht HIGH; ohne Merkmal bleibt es unbekannt', function () {
    $g = FoodAlchemistVocabEinheit::create(['team_id' => $this->rootTeam->id, 'slug' => 'g', 'display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1])->id;
    $rezept = function (FoodAlchemistGp $gp, string $n) use ($g) {
        $r = FoodAlchemistRecipe::create(['team_id' => $this->rootTeam->id, 'recipe_key' => 'pos_' . $n, 'name' => $n, 'status' => 'draft']);
        FoodAlchemistRecipeIngredient::create(['team_id' => $this->rootTeam->id, 'recipe_id' => $r->id, 'position' => 1, 'raw_text' => $gp->name,
            'match_method' => 'manual', 'unit_vocab_id' => $g, 'quantity' => 100, 'gp_id' => $gp->id]);
        app(RecipeRecomputeService::class)->recomputePipeline($r->id);

        return $r->refresh();
    };
    $butter = $this->makeGp($this->rootTeam, 'Butter');
    $butter->update(['n_las_total' => 1]);
    ($this->la)($butter, $this->bos, ['milk' => 'enthalten']);
    $sahne = $this->makeGp($this->rootTeam, 'Sahne');
    $sahne->update(['n_las_total' => 1]);
    ($this->la)($sahne, $this->hanos, ['milk' => 'enthalten']);

    $mit = $rezept($butter, 'Buttersauce');
    $ohne = $rezept($sahne, 'Sahnesauce');
    $felder = fn (FoodAlchemistRecipe $r) => collect(FoodAlchemistGp::ALLERGEN_FIELDS)->mapWithKeys(fn ($f) => [$f => $r->{"allergen_{$f}"}]);

    expect($felder($mit)->filter(fn ($v) => $v === 'unbekannt' || $v === null))->toHaveCount(0)
        ->and($mit->allergen_milk)->toBe('enthalten')->and($mit->allergen_gluten)->toBe('nicht_enthalten')
        ->and($mit->allergens_confidence)->toBe('medium')
        ->and($ohne->allergen_gluten)->toBe('unbekannt');
});

it('Artikel-Modal: „14 von 14 (13 angenommen)“; Speichern ohne Änderung schreibt die Annahme nicht fest', function () {
    $this->actingAs($this->makeUser($this->rootTeam, 'Kuratorin', 'owner'));
    $gp = $this->makeGp($this->rootTeam, 'Butter');
    $la = ($this->la)($gp, $this->bos, ['milk' => 'enthalten']);

    $c = Livewire::test(ItemModal::class)->call('oeffnen', $la->id);
    expect($c->html())->toContain('data-allergen-bewertet="14 von 14 (13 angenommen)"')->toContain('data-allergen-annahme')
        ->toContain('data-angenommen="gluten"')->not->toContain('data-angenommen="milk"');

    $c->call('speichern');
    $zeile = FoodAlchemistItemAllergen::where('supplier_item_id', $la->id)->first();
    expect($zeile->allergen_gluten)->toBeNull()->and($zeile->allergen_milk)->toBe('enthalten');
});

it('Befehl: Probelauf schreibt nichts, Sperrliste lehnt ab, --apply setzt mit Grund/Datum/Von, --aus nimmt zurück', function () {
    $edna = ($this->lieferant)('Edna', false);
    ($this->lieferant)('Handelshof Mönchengladbach', false);

    $this->artisan('foodalchemist:allergene-nur-positiv', ['--lieferant' => ['Edna']])->assertSuccessful();
    expect($edna->fresh()->allergene_nur_positiv)->toBeFalse();

    $this->artisan('foodalchemist:allergene-nur-positiv', ['--lieferant' => ['Handelshof Mönchengladbach'], '--grund' => 'x', '--apply' => true])->assertFailed();
    $this->artisan('foodalchemist:allergene-nur-positiv', ['--lieferant' => ['Hanos'], '--grund' => 'x', '--apply' => true])->assertFailed();
    $this->artisan('foodalchemist:allergene-nur-positiv', ['--lieferant' => ['Edna'], '--apply' => true])->assertFailed();   // ohne Grund
    $this->artisan('foodalchemist:allergene-nur-positiv', ['--lieferant' => ['Gibtsnicht'], '--grund' => 'x', '--apply' => true])->assertFailed();

    $this->artisan('foodalchemist:allergene-nur-positiv', ['--lieferant' => ['Edna'], '--grund' => 'Necta io 19.10.: 0 % nein', '--von' => 7, '--apply' => true])->assertSuccessful();
    $edna->refresh();
    expect($edna->allergene_nur_positiv)->toBeTrue()->and($edna->allergene_nur_positiv_grund)->toBe('Necta io 19.10.: 0 % nein')
        ->and($edna->allergene_nur_positiv_seit?->toDateString())->toBe(now()->toDateString())->and((int) $edna->allergene_nur_positiv_von)->toBe(7)
        ->and(AllergenAnnahme::giltFuer($edna->id))->toBeTrue();

    $this->artisan('foodalchemist:allergene-nur-positiv', ['--lieferant' => ['Edna'], '--aus' => true, '--apply' => true])->assertSuccessful();
    expect($edna->fresh()->allergene_nur_positiv)->toBeFalse()->and($edna->fresh()->allergene_nur_positiv_grund)->toBeNull();
});

it('Helfer: ohne Allergen-Zeile keine Annahme (Necta hat keinen Block geliefert)', function () {
    $zeile = AllergenAnnahme::zeile(null, $this->bos->id);

    expect($zeile['gluten'])->toBe(['wert' => null, 'angenommen' => false]);   // keine Zeile = keine Annahme
});

it('Wortlaut (Dominique 23.10.): angenommene Werte heißen „nicht enthalten (angenommen) · Spuren unbekannt“ — Artikel-Modal und GP-Panel, mit Erklärung als Tooltip', function () {
    expect(AllergenAnnahme::ANZEIGE)->toBe('nicht enthalten (angenommen) · Spuren unbekannt');
    $this->actingAs($this->makeUser($this->rootTeam, 'Kuratorin', 'owner'));
    $gp = $this->makeGp($this->rootTeam, 'Butter');
    $la = ($this->la)($gp, $this->bos, ['milk' => 'enthalten']);

    $modal = Livewire::test(ItemModal::class)->call('oeffnen', $la->id)->html();
    expect($modal)->toContain('· ' . AllergenAnnahme::ANZEIGE)->toContain('title="' . e(AllergenAnnahme::ERKLAERUNG) . '"')
        ->not->toContain('· angenommen nicht enthalten');

    $panel = Livewire::test(\Platform\FoodAlchemist\Livewire\Gps\DetailPanel::class, ['gpId' => $gp->id])->html();
    expect($panel)->toContain('davon 13 ' . e(AllergenAnnahme::ANZEIGE))->toContain('data-allergene-angenommen');
});
