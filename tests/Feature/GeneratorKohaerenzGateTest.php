<?php

use Platform\Core\Contracts\LLMProviderContract;
use Platform\FoodAlchemist\Models\FoodAlchemistPrice;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeFinding;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\RecipeGeneratorService;
use Platform\FoodAlchemist\Services\RecipeReviewService;
use Platform\FoodAlchemist\Tests\Support\CopilotStub;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Phase 2 (2026-08-07) — Kohärenz-Gate: der Fremdkörper-Post-Check NACH dem Verdrahten.
 *
 * Beweist das Verhalten am VERDRAHTETEN Ergebnis (nicht an der KI-Absicht):
 *  · Regel (deterministisch): süß-in-herzhaft zwischen Gericht und Sub-Rezept.
 *  · Kritiker (KI, CopilotStub): thematisch falsches Sub ohne Geschmacks-Kollision.
 *  · Konsequenz: ENTdrahten (Zeile bleibt offen) + offene[]-Eintrag mit `kritiker`-Grund.
 *  · Fail-open, Gating auf Sub-Rezepte, Schwelle 0.7, False-Positive-Schutz.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    config(['foodalchemist.ai.provider' => 'fake']);
    $this->svc = app(RecipeGeneratorService::class);

    foreach ([
        ['slug' => 'g', 'display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1],
        ['slug' => 'ml', 'display_de' => 'Milliliter', 'dimension' => 'volume', 'default_in_ml' => 1],
    ] as $e) {
        FoodAlchemistVocabEinheit::create(['team_id' => $this->rootTeam->id, ...$e]);
    }

    $supplier = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Necta']);
    $this->mkGp = function (string $name, ?string $slug) use ($supplier) {
        $gp = $this->makeGp($this->rootTeam, $name);
        $gp->update(['main_ingredient_slug' => $slug, 'status' => 'approved']);
        $la = FoodAlchemistSupplierItem::create([
            'team_id' => $this->rootTeam->id, 'supplier_id' => $supplier->id,
            'designation' => $name, 'qty' => 1.0, 'unit_code' => 'kg',
        ]);
        FoodAlchemistSupplierItemStructure::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'gp_id' => $gp->id]);
        FoodAlchemistPrice::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'price' => 5.0, 'status' => '0']);
        $gp->update(['lead_la_supplier_item_id' => $la->id]);

        return $gp->refresh();
    };

    // Standard-Lauf: 1 GP-Zutat + 1 Sub-Rezept (exakter Name ⇒ Exact-Match ⇒ verdrahtet).
    $this->gen = function (string $subName, ?string $subTaste, string $parentTaste = 'herzhaft') {
        ($this->mkGp)('Tomaten: frisch, geachtelt', 'tomaten');
        $this->makeRecipe($this->rootTeam, $subName, $subTaste !== null ? ['taste_direction' => $subTaste] : []);

        return $this->svc->generiere($this->rootTeam, 'Tomatensuppe', ['convenience' => 'from_scratch'], kiRezeptOverride: [
            'name' => 'Suppe: Tomate',
            'taste_direction' => $parentTaste,
            'zutaten' => [
                ['text' => 'Tomaten: frisch, geachtelt', 'slug' => 'tomaten', 'quantity' => 500, 'unit' => 'g'],
                ['text' => $subName, 'quantity' => 800, 'unit' => 'ml'],
            ],
        ]);
    };
});

it('smoke: ein Sub-Rezept wird über den exakten Namen verdrahtet', function () {
    $res = ($this->gen)('Gemüsefond: klar', 'herzhaft', 'herzhaft');
    expect($res['statistik']['bestand_sub'])->toBe(1);
});

it('Regel: süßes Sub in herzhaftem Gericht wird entdrahtet (ohne KI)', function () {
    CopilotStub::bind([]);                                        // Kritiker trägt bewusst nichts bei
    $res = ($this->gen)('Rahmeis: Balsamico', 'suess', 'herzhaft');

    expect($res['statistik']['kritiker']['entdrahtet'])->toBe(1)
        ->and($res['statistik']['bestand_sub'])->toBe(0);        // war verdrahtet, jetzt offen

    $krit = collect($res['offene'])->firstWhere('kritiker.quelle', 'regel');
    expect($krit)->not->toBeNull()
        ->and($krit['kritiker']['target'])->toBe('sub_recipe')
        ->and($krit['kritiker']['name'])->toBe('Rahmeis: Balsamico');

    // ENTdrahtet heisst: Verknüpfung gelöst, Zeile bleibt stehen (kein Löschen).
    $zeile = FoodAlchemistRecipeIngredient::where('recipe_id', $res['recipe']->id)
        ->where('raw_text', 'Rahmeis: Balsamico')->first();
    expect($zeile)->not->toBeNull()
        ->and($zeile->referenced_recipe_id)->toBeNull();
});

it('Kritiker: thematisch falsches Sub (keine Geschmacks-Kollision) wird per KI entdrahtet', function () {
    CopilotStub::bind([
        ['art' => 'fremdkoerper', 'zutat_text' => 'Gemüsefond: Bohne-Speck',
            'begruendung' => 'Bohne-Speck-Fond passt thematisch nicht in eine klare Tomatensuppe.', 'konfidenz' => 0.9],
    ]);
    $res = ($this->gen)('Gemüsefond: Bohne-Speck', 'herzhaft', 'herzhaft');   // KEINE Geschmacks-Kollision

    expect($res['statistik']['kritiker']['geprueft'])->toBeTrue()
        ->and($res['statistik']['kritiker']['entdrahtet'])->toBe(1);

    $krit = collect($res['offene'])->firstWhere('kritiker.quelle', 'ki');
    expect($krit)->not->toBeNull()
        ->and($krit['kritiker']['name'])->toBe('Gemüsefond: Bohne-Speck')
        ->and($krit['kritiker']['grund'])->toContain('thematisch');

    $zeile = FoodAlchemistRecipeIngredient::where('recipe_id', $res['recipe']->id)
        ->where('raw_text', 'Gemüsefond: Bohne-Speck')->first();
    expect($zeile->referenced_recipe_id)->toBeNull();
});

it('Fail-open: ein kaputter Kritiker bricht nichts — die Regel greift trotzdem', function () {
    config(['foodalchemist.ai.backoff' => []]);                  // keine Backoff-Sleeps im Test
    config(['foodalchemist.ai.provider' => 'core']);
    app()->bind(LLMProviderContract::class, fn () => new class implements LLMProviderContract
    {
        public function getName(): string { return 'boom'; }

        public function chat(array $messages, array $options = []): array { throw new \RuntimeException('Provider down'); }

        public function streamChat(array $messages, callable $onDelta, array $options = []): void { throw new \RuntimeException('Provider down'); }

        public function getAvailableModels(): array { return ['boom']; }

        public function getDefaultModel(): string { return 'boom'; }

        public function isAvailable(): bool { return true; }
    });

    $res = ($this->gen)('Rahmeis: Balsamico', 'suess', 'herzhaft');   // Regel-Kollision vorhanden

    expect($res['recipe'])->not->toBeNull()                          // Generierung kam durch
        ->and($res['statistik']['kritiker']['fehler'])->toBeTrue()   // Kritiker-Call scheiterte
        ->and($res['statistik']['kritiker']['entdrahtet'])->toBe(1); // … die Regel hat trotzdem gewirkt

    expect(collect($res['offene'])->firstWhere('kritiker.quelle', 'regel'))->not->toBeNull();
});

it('Kritiker unter Schwelle: Zeile bleibt verdrahtet, Befund landet in der Ablage', function () {
    CopilotStub::bind([
        ['art' => 'fremdkoerper', 'zutat_text' => 'Gemüsefond: Bohne-Speck',
            'begruendung' => 'Möglicherweise unpassend.', 'konfidenz' => 0.5],   // < 0.7
    ]);
    $res = ($this->gen)('Gemüsefond: Bohne-Speck', 'herzhaft', 'herzhaft');

    expect($res['statistik']['kritiker']['entdrahtet'])->toBe(0)     // nicht entdrahtet
        ->and($res['statistik']['bestand_sub'])->toBe(1);            // bleibt verdrahtet

    $zeile = FoodAlchemistRecipeIngredient::where('recipe_id', $res['recipe']->id)
        ->where('raw_text', 'Gemüsefond: Bohne-Speck')->first();
    expect($zeile->referenced_recipe_id)->not->toBeNull();

    // Der schwache Befund ist als Fremdkörper in der Ablage (Copilot/Signale sehen ihn).
    expect(FoodAlchemistRecipeFinding::where('recipe_id', $res['recipe']->id)
        ->where('kind', 'fremdkoerper')->exists())->toBeTrue();
});

it('Gating + False-Positive-Schutz: reines GP-Rezept überspringt den Kritiker', function () {
    CopilotStub::bind([   // selbst wenn der Stub etwas läge: er darf hier nie aufgerufen werden
        ['art' => 'fremdkoerper', 'zutat_text' => 'Tomaten: frisch, geachtelt', 'begruendung' => 'x', 'konfidenz' => 0.99],
    ]);
    ($this->mkGp)('Tomaten: frisch, geachtelt', 'tomaten');
    $res = $this->svc->generiere($this->rootTeam, 'Salat', [], kiRezeptOverride: [
        'name' => 'Salat: Tomate',
        'taste_direction' => 'herzhaft',
        'zutaten' => [['text' => 'Tomaten: frisch, geachtelt', 'slug' => 'tomaten', 'quantity' => 300, 'unit' => 'g']],
    ]);

    expect($res['statistik']['kritiker']['uebersprungen_gating'])->toBeTrue()
        ->and($res['statistik']['kritiker']['geprueft'])->toBeFalse()
        ->and($res['statistik']['kritiker']['entdrahtet'])->toBe(0)
        ->and(collect($res['offene'])->contains(fn ($o) => isset($o['kritiker'])))->toBeFalse();

    // Der GP bleibt verdrahtet — kein False Positive durch einen ungefragten Kritiker.
    $zeile = FoodAlchemistRecipeIngredient::where('recipe_id', $res['recipe']->id)->first();
    expect($zeile->gp_id)->not->toBeNull();
});

it('Copilot-Pfad: normalisiere macht Fremdkörper anwendbar, uebernehmen entdrahtet die Zeile', function () {
    CopilotStub::bind([]);                                        // Generierung wired sauber, Gate tut nichts
    $res = ($this->gen)('Gemüsefond: Bohne-Speck', 'herzhaft', 'herzhaft');
    $recipeId = $res['recipe']->id;

    $zeile = FoodAlchemistRecipeIngredient::where('recipe_id', $recipeId)
        ->where('raw_text', 'Gemüsefond: Bohne-Speck')->first();
    expect($zeile->referenced_recipe_id)->not->toBeNull();        // vorher verdrahtet

    // normalisiere (über bewerte): ein Fremdkörper-Befund per Name ist anwendbar.
    $befunde = app(RecipeReviewService::class)->bewerte($this->rootTeam, $recipeId, [
        ['art' => 'fremdkoerper', 'zutat_text' => 'Gemüsefond: Bohne-Speck', 'begruendung' => 'passt nicht', 'konfidenz' => 0.9],
    ]);
    expect($befunde[0]['auto_applicable'])->toBeTrue()
        ->and($befunde[0]['zutat_id'])->toBe($zeile->id);

    // uebernehmen ENTdrahtet (Zeile bleibt bestehen, Verknüpfung weg).
    app(RecipeReviewService::class)->uebernehmen($this->rootTeam, $recipeId, $befunde[0]);
    $zeile->refresh();
    expect($zeile->referenced_recipe_id)->toBeNull()
        ->and(FoodAlchemistRecipeIngredient::whereKey($zeile->id)->exists())->toBeTrue();
});
