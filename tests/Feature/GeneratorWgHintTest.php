<?php

use Illuminate\Support\Facades\Queue;
use Platform\FoodAlchemist\Models\FoodAlchemistStammLieferant;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\RecipeGeneratorService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

/**
 * Spec 16·E1 — WG-Hint aus dem Erzeugungs-Kontext (KI-Feld `commodity_group`)
 * verengt die LA-Beschaffung des Mints auf die WG-Lead-Lieferanten. Beweist die
 * Kette KI-Schema-Feld → Generator-Caller → wgHint-Normalisierung → Finder-Scope
 * über den Override-Pfad (kein Live-LLM nötig; auch „01 Gemüse" → „01").
 */
uses(TestCase::class, SeedsTeamHierarchy::class);

beforeEach(function () {
    $this->seedTeamHierarchy();
    FoodAlchemistVocabEinheit::create(['team_id' => $this->rootTeam->id, 'slug' => 'g', 'display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1]);
});

it('mintet über den WG-Lead, wenn das KI-Rezept eine commodity_group mitliefert', function () {
    Queue::fake();   // S4-Klassifikation nicht inline
    $lead = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Gemüse-Lead']);
    $fremd = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Nicht-Lead']);
    FoodAlchemistStammLieferant::create(['team_id' => $this->rootTeam->id, 'supplier_id' => $lead->id, 'commodity_group_code' => '01']);
    $leadLa = FoodAlchemistSupplierItem::create(['team_id' => $this->rootTeam->id, 'supplier_id' => $lead->id, 'designation' => 'Spinat frisch', 'qty' => 1.0, 'unit_code' => 'kg']);
    $fremdLa = FoodAlchemistSupplierItem::create(['team_id' => $this->rootTeam->id, 'supplier_id' => $fremd->id, 'designation' => 'Spinat frisch', 'qty' => 1.0, 'unit_code' => 'kg']);

    $resultat = app(RecipeGeneratorService::class)->generiere(
        $this->rootTeam, 'Grüne Beilage', [], kiRezeptOverride: [
            'name' => 'Beilage: Rahmgemüse',   // kein Token-Overlap mit der Zutat (kein Sub-Selbsttreffer)
            'zutaten' => [['text' => 'Spinat frisch', 'quantity' => 200, 'unit' => 'g', 'commodity_group' => '01 Gemüse']],
        ],
    );

    expect($resultat['statistik']['gp_neu_aus_la'])->toBe(1);

    // Der WG-Lead-LA wurde verknüpft (bekam die Struktur mit gp_id), der Fremd-LA nicht.
    $leadStruktur = FoodAlchemistSupplierItemStructure::where('supplier_item_id', $leadLa->id)->first();
    $fremdStruktur = FoodAlchemistSupplierItemStructure::where('supplier_item_id', $fremdLa->id)->first();
    expect($leadStruktur)->not->toBeNull()
        ->and($leadStruktur->gp_id)->not->toBeNull()
        ->and($fremdStruktur?->gp_id)->toBeNull();
});
