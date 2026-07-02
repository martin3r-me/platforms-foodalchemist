<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Gps\DetailPanel;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Services\Ai\AiProposal;
use Platform\FoodAlchemist\Services\GpAggregateService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * R10 (Ist-Feature): ✨ Allergene/Nährwerte per KI schätzen, wenn keine
 * LA-Daten — GL-07 (Vorschlag → Übernehmen schreibt Override bzw. Fallback-
 * Schicht). KI-Fallback gilt NUR der Panel-Anzeige; KURATIERTE Werte
 * (nutri_quelle='manual') fließen seit dem Salz-Fix auch in die GL-08-
 * Rezept-Aggregation (LA-Lücken wie Speisesalz ohne kcal-Label).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    config(['foodalchemist.ai.provider' => 'fake']);
    $this->gp = $this->makeGp($this->rootTeam, 'Berliner gebacken');
});

it('kiNaehrwerte: Vorschlag validiert → Übernehmen schreibt Fallback-Schicht; Aggregat liefert sie NUR mit Flag', function () {
    $this->mock(AiGatewayService::class, function ($mock) {
        $mock->shouldReceive('propose')->andReturn(new AiProposal(
            ['kcal' => 389, 'protein_g' => 6.5, 'fat_g' => 17.2, 'carbs_g' => 51.0, 'salt_g' => 0.6, 'quatsch' => -5], 0.82,
        ));
    });

    Livewire::test(DetailPanel::class, ['gpId' => $this->gp->id])
        ->call('kiNaehrwerte')
        ->assertSet('fehler', null)
        ->assertSet('kiVorschlag.typ', 'naehrwerte')
        ->call('kiUebernehmen');

    $gp = $this->gp->fresh();
    expect((float) $gp->nutri_kcal_per_100g)->toBe(389.0)
        ->and($gp->nutri_quelle)->toBe('ki')
        ->and((float) $gp->nutri_ai_confidence)->toBe(0.82);

    $agg = app(GpAggregateService::class);
    expect($agg->naehrwerte($gp)['energy_kcal']['avg'])->toBeNull()           // Rezept-Pfad: KEIN Fallback
        ->and($agg->naehrwerte($gp, mitKiFallback: true)['energy_kcal']['avg'])->toBe(389.0)
        ->and($agg->naehrwerte($gp, mitKiFallback: true)['quelle'])->toBe('ki');
});

it('kiAllergene: nur gültige Werte werden Vorschlag, unbekannt fliegt; Übernehmen schreibt den Override (GL-01 Prio 1)', function () {
    $this->mock(AiGatewayService::class, function ($mock) {
        $mock->shouldReceive('propose')->andReturn(new AiProposal(
            ['allergene' => ['glutenhaltiges_getreide' => 'enthalten', 'milch' => 'spuren', 'fisch' => 'unbekannt', 'eier' => 'kaese']], 0.9,
        ));
    });

    Livewire::test(DetailPanel::class, ['gpId' => $this->gp->id])
        ->call('kiAllergene')
        ->assertSet('fehler', null)
        ->assertSet('kiVorschlag.werte', ['glutenhaltiges_getreide' => 'enthalten', 'milch' => 'spuren'])
        ->call('kiUebernehmen');

    $gp = $this->gp->fresh();
    expect($gp->allergen_glutenhaltiges_getreide)->toBe('enthalten')
        ->and($gp->allergen_milch)->toBe('spuren')
        ->and($gp->allergen_fisch)->toBeNull()                                 // unbekannt ⇒ KEIN Override (F7.1)
        ->and((float) $gp->allergene_ki_confidence)->toBe(0.9);

    // Aggregat zeigt Override-Quelle
    $allergene = app(GpAggregateService::class)->allergene($gp);
    expect($allergene['glutenhaltiges_getreide']['quelle'])->toBe('override');
});

it('FakeProvider-Echo ⇒ ehrlicher Fehler, nichts geschrieben; Kind-Team blockt am Curate-Gate', function () {
    Livewire::test(DetailPanel::class, ['gpId' => $this->gp->id])
        ->call('kiNaehrwerte')
        ->assertSet('fehler', fn ($f) => str_contains((string) $f, 'echter Provider'));
    expect($this->gp->fresh()->nutri_quelle)->toBeNull();

    $this->actingAs($this->makeUser($this->childA, 'Kind User'));
    Livewire::test(DetailPanel::class, ['gpId' => $this->gp->id])
        ->call('kiAllergene')
        ->assertSet('fehler', fn ($f) => str_contains((string) $f, 'Kurations-Team'));
});

it('Salz-Fix: manual-kuratierte GP-Werte fließen in die Rezept-Aggregation, KI-Werte nicht', function () {
    $g = \Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit::create(['team_id' => $this->rootTeam->id, 'slug' => 'g', 'display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1]);
    $svc = app(\Platform\FoodAlchemist\Services\RecipeService::class);

    // Speisesalz-Muster: LA hat sodium, aber KEIN kcal → Leit-Indikator schlug bisher fehl
    $salz = $this->makeGp($this->rootTeam, 'Speisesalz');
    $supplier = \Platform\FoodAlchemist\Models\FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Necta']);
    $la = \Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem::create([
        'team_id' => $this->rootTeam->id, 'supplier_id' => $supplier->id, 'designation' => 'Speisesalz 10 kg', 'qty' => 10, 'unit_code' => 'kg',
    ]);
    \Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'gp_id' => $salz->id]);
    \Platform\FoodAlchemist\Models\FoodAlchemistItemNutritional::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'sodium' => 39480]);

    // Kuratierung wie beim echten Salz-GP: 0 kcal/Makros, 100 g Salz, quelle=manual
    $salz->update(['nutri_kcal_per_100g' => 0, 'nutri_protein_g_per_100g' => 0, 'nutri_fat_g_per_100g' => 0,
        'nutri_carbs_g_per_100g' => 0, 'nutri_salt_g_per_100g' => 100, 'nutri_quelle' => 'manual']);

    $wasser = $this->makeGp($this->rootTeam, 'Wasser still');       // ohne Daten → verdünnt nur
    $rezept = $svc->create($this->rootTeam, ['name' => 'Sole: Test']);
    $rezept = $svc->syncIngredients($this->rootTeam, $rezept->id, [
        ['gp_id' => $salz->id, 'raw_text' => '10 g Salz', 'menge' => '10', 'einheit_vocab_id' => $g->id],
        ['gp_id' => $wasser->id, 'raw_text' => '990 g Wasser', 'menge' => '990', 'einheit_vocab_id' => $g->id],
    ]);

    // 10 g Salz × 100 g/100g ÷ 1000 g Gesamt = 1,0 g Salz/100 g — kcal ehrlich 0
    expect((float) $rezept->nutri_salt_g_per_100g)->toBe(1.0)
        ->and((float) $rezept->nutri_kcal_per_100g)->toBe(0.0);

    // Gegenprobe: gleiche Lage mit quelle=ki → Aggregation ignoriert den Fallback weiter
    $salz->update(['nutri_quelle' => 'ki']);
    $rezept = $svc->syncIngredients($this->rootTeam, $rezept->id, [
        ['gp_id' => $salz->id, 'raw_text' => '10 g Salz', 'menge' => '10', 'einheit_vocab_id' => $g->id],
    ]);
    expect($rezept->nutri_kcal_per_100g)->toBeNull();
});
