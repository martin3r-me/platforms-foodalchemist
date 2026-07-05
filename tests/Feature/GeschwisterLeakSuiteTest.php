<?php

use Platform\Core\Contracts\ToolContext;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\GpService;
use Platform\FoodAlchemist\Services\RecipeService;
use Platform\FoodAlchemist\Services\SalesRecipeService;
use Platform\FoodAlchemist\Services\SupplierItemService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * M8-03: Geschwister-Leak-Suite (D1-Risiko) — EIN gebündelter Lauf über alle
 * Sektionen: Kind A sieht Eltern-Katalog (gewollt), aber NIE Geschwister-Daten
 * (Kind B); Kinder-Daten leaken nie aufwärts in fremde Ketten. Ergänzt die
 * sektions-lokalen Tests (GpVisibilityLeakTest etc.) um den Quervergleich.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();                                       // Root + Kind A + Kind B

    // Je Team ein Datensatz pro Sektion
    $this->mkRezept = fn ($team, $key, $vk = false) => FoodAlchemistRecipe::create([
        'team_id' => $team->id, 'recipe_key' => $key, 'name' => strtoupper($key), 'status' => 'draft',
        'is_sales_recipe' => $vk,
    ]);
});

it('M3/GPs: Kind A sieht Root-GPs, nie Kind-B-GPs; Root sieht keine Kind-GPs', function () {
    $rootGp = $this->makeGp($this->rootTeam, 'Root-GP');
    $aGp = $this->makeGp($this->childA, 'A-GP');
    $bGp = $this->makeGp($this->childB, 'B-GP');

    $sicht = fn ($team) => app(GpService::class)->paginate([], $team, 100)->pluck('id');

    expect($sicht($this->childA))->toContain($rootGp->id)->toContain($aGp->id)->not->toContain($bGp->id)
        ->and($sicht($this->childB))->not->toContain($aGp->id)
        ->and($sicht($this->rootTeam))->not->toContain($aGp->id)->not->toContain($bGp->id);
});

it('M2/Artikel: globale Suche leakt keine Geschwister-Artikel', function () {
    $supplier = FoodAlchemistSupplier::create(['team_id' => $this->childB->id, 'name' => 'B-Lieferant']);
    FoodAlchemistSupplierItem::create(['team_id' => $this->childB->id, 'supplier_id' => $supplier->id, 'designation' => 'GEHEIMER B-ARTIKEL']);

    expect(app(SupplierItemService::class)->searchGlobal($this->childA, 'GEHEIMER', [], 50)->total())->toBe(0)
        ->and(app(SupplierItemService::class)->searchGlobal($this->childB, 'GEHEIMER', [], 50)->total())->toBe(1);
});

it('M4+M6/Rezepte: Basis- und VK-Sicht — Geschwister nie, Kette aufwärts ja', function () {
    $rootBasis = ($this->mkRezept)($this->rootTeam, 'root_fond');
    $aBasis = ($this->mkRezept)($this->childA, 'a_fond');
    $bBasis = ($this->mkRezept)($this->childB, 'b_fond');
    $bVk = ($this->mkRezept)($this->childB, 'b_vk', true);

    $basisSicht = fn ($team) => app(RecipeService::class)->paginateBrowser([], $team, 100)->pluck('id');
    $vkSicht = fn ($team) => app(SalesRecipeService::class)->paginateBrowser([], $team, 100)->pluck('id');

    expect($basisSicht($this->childA))->toContain($rootBasis->id)->toContain($aBasis->id)->not->toContain($bBasis->id)
        ->and($vkSicht($this->childA))->not->toContain($bVk->id)
        ->and($vkSicht($this->childB))->toContain($bVk->id)
        ->and(app(RecipeService::class)->detail($this->childA, $bBasis->id))->toBeNull()
        ->and(app(SalesRecipeService::class)->detail($this->childA, $bVk->id))->toBeNull();
});

it('M5/Pairing-Schreibpfad: setRecipeAnker auf Geschwister-Rezept wirft (visibleToTeam)', function () {
    $bBasis = ($this->mkRezept)($this->childB, 'b_geheim');
    \Illuminate\Support\Facades\DB::table('foodalchemist_vocab_pairing_anchors')->insert([
        'uuid' => (string) \Symfony\Component\Uid\UuidV7::generate(), 'slug' => 'zimt', 'display_de' => 'Zimt',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $ankerId = (int) \Illuminate\Support\Facades\DB::getPdo()->lastInsertId();

    expect(fn () => app(\Platform\FoodAlchemist\Services\PairingService::class)->setRecipeAnker($this->childA, $bBasis->id, $ankerId))
        ->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
});

it('M7/Bulk + M8/Tools: Run-Status fremder Teams unsichtbar; Tools antworten team-scoped', function () {
    $this->actingAs($this->makeUser($this->childB, 'B-User'));
    config(['foodalchemist.ai.provider' => 'fake', 'foodalchemist.ai.backoff' => []]);
    $bBasis = ($this->mkRezept)($this->childB, 'b_bulk');
    $runId = app(\Platform\FoodAlchemist\Services\BulkEnrichService::class)->starte($this->childB, [$bBasis->id]);

    expect(app(\Platform\FoodAlchemist\Services\BulkEnrichService::class)->status($this->childA, $runId))->toBeNull();

    $userA = $this->makeUser($this->childA, 'A-User');
    $suche = app(\Platform\Core\Tools\ToolRegistry::class)->get('foodalchemist.recipes.SEARCH')
        ->execute(['q' => 'B_BULK'], new ToolContext($userA, $this->childA));
    expect($suche->data['total'])->toBe(0);                           // Tool leakt nicht über Team-Grenzen
});
