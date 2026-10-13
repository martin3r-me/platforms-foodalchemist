<?php

use Illuminate\Support\Facades\DB;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Models\Team;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\AboService;
use Platform\FoodAlchemist\Services\FassungService;
use Platform\FoodAlchemist\Services\InhaltsFreigabeService;
use Platform\FoodAlchemist\Services\RecipeRecomputeService;
use Platform\FoodAlchemist\Support\StammPaket;
use Platform\FoodAlchemist\Support\TeamAncestryRegistry;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 82 Bauplan Auflage 3: Fassungs-Zeilen tauchen auf keinem Weg auf, auf dem sie nichts zu suchen haben — je Weg
 * einzeln. Beim Master: nie (seine Bibliothek, Zähler, Suchen, MCP-Listen, Batch-Pfade). Beim Mandanten: nur in der
 * Abo-Form (die Fassung, nie die Arbeitszeile).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->master = Team::create(['name' => 'BHG.DIGITAL (Master)', 'user_id' => 1, 'personal_team' => false]);
    config(['foodalchemist.master_team_id' => $this->master->id]);
    $this->broich = Team::create(['name' => 'Broich', 'user_id' => 1, 'personal_team' => false]);
    TeamAncestryRegistry::flushAll();
    StammPaket::freischalten($this->broich);
    $this->kurator = $this->makeUser($this->master, 'Kurator', 'owner');
    $this->broichChef = $this->makeUser($this->broich, 'Broich Chef', 'owner');

    $gp = FoodAlchemistGp::create(['team_id' => null, 'gp_key' => 'leck|knochen', 'name' => 'Kalbsknochen']);
    $this->jus = $this->makeRecipe($this->master, 'Kalbsjus Leckprobe', ['is_sales_recipe' => false]);
    $this->makeIngredient($this->jus, 'Kalbsknochen', $gp, '1000');
    $inhalte = app(InhaltsFreigabeService::class);
    $sid = $inhalte->sammlungAnlegen($this->master, 'Basis', null, $this->kurator);
    $inhalte->sammlungHinzu($this->master, $sid, 'recipe', [$this->jus->id], $this->kurator);
    app(AboService::class)->abonnieren($this->master, $this->broich, $sid, $this->kurator);
    $this->fassungId = app(FassungService::class)->veroeffentlichen($this->master, $this->jus->id, 'Erst', $this->kurator)['fassung_id'];
    $this->reg = app(ToolRegistry::class);
});

it('Weg 1 · Bibliothek: Master sieht nur die Arbeitszeile, Mandant nur die Fassung', function () {
    $sieht = fn (Team $t) => FoodAlchemistRecipe::visibleToTeam($t)->where('name', 'Kalbsjus Leckprobe')->pluck('foodalchemist_recipes.id')->map(fn ($v) => (int) $v)->all();
    expect($sieht($this->master))->toBe([$this->jus->id])
        ->and($sieht($this->broich))->toBe([$this->fassungId]);
});

it('Weg 2 · Zähler und direkte team_id-Abfragen (Dashboard, Controlling, Commands) zählen keine Fassung', function () {
    expect(FoodAlchemistRecipe::where('team_id', $this->master->id)->where('name', 'Kalbsjus Leckprobe')->count())->toBe(1)
        ->and(FoodAlchemistRecipe::query()->where('name', 'Kalbsjus Leckprobe')->pluck('id')->all())->toBe([$this->jus->id])
        ->and(FoodAlchemistRecipe::find($this->fassungId))->toBeNull()                       // by-id ohne Absicht: nicht gefunden
        ->and(FoodAlchemistRecipe::mitFassungen()->find($this->fassungId))->not->toBeNull();   // mit Absicht: gefunden
});

it('Weg 3 · Suche / Name-Lookup: Master findet die Fassung nicht, Mandant findet nur die Fassung', function () {
    $suche = fn (Team $t) => FoodAlchemistRecipe::visibleToTeam($t)->where('name', 'like', '%Leckprobe%')->pluck('foodalchemist_recipes.id')->map(fn ($v) => (int) $v)->all();
    expect($suche($this->master))->not->toContain($this->fassungId)
        ->and($suche($this->broich))->toBe([$this->fassungId]);
});

it('Weg 4 · MCP-Listen (recipes.LIST / recipes.SEARCH): Master ohne Fassung, Mandant ohne Arbeitszeile', function () {
    $ids = function (string $tool, Team $t, $user, array $args) {
        $this->actingAs($user);
        $r = $this->reg->get($tool)->execute($args, new ToolContext($user, $t));
        expect($r->success)->toBeTrue($tool . ': ' . ($r->error ?? ''));
        $json = json_encode($r->data);
        preg_match_all('/"id":(\d+)/', $json, $m);

        return array_map('intval', $m[1]);
    };
    foreach (['foodalchemist.recipes.LIST' => [], 'foodalchemist.recipes.SEARCH' => ['query' => 'Leckprobe', 'q' => 'Leckprobe']] as $tool => $args) {
        expect($ids($tool, $this->master, $this->kurator, $args))->not->toContain($this->fassungId, $tool . ' Master');
        expect($ids($tool, $this->broich, $this->broichChef, $args))->not->toContain($this->jus->id, $tool . ' Mandant');
    }
});

it('Weg 5 · Embeddings: die Kandidaten-Abfrage des Pools lässt Fassungen weg', function () {
    $kandidaten = DB::table('foodalchemist_recipes as r')->whereNull('r.deleted_at')->where('r.ist_fassung', false)->pluck('r.id')->all();
    $quelle = file_get_contents((new ReflectionClass(\Platform\FoodAlchemist\Services\Ai\PoolEmbeddingService::class))->getFileName());
    expect($kandidaten)->not->toContain($this->fassungId)
        ->and($quelle)->toContain("where('r.ist_fassung', false)");   // Riegel: der Pool-Pfad trägt den Filter
});

it('Weg 6 · recomputeAll lässt Fassungen aus; ein gezielter Recompute einer Fassung schreibt keine Kosten', function () {
    $r = app(RecipeRecomputeService::class);
    // Markierung, die ein Recompute der Fassung zurücksetzen würde (Knochen enthalten keine Milch)
    DB::table('foodalchemist_recipes')->where('id', $this->fassungId)->update(['allergen_milk' => 'enthalten']);
    $r->recomputeAll();
    expect(DB::table('foodalchemist_recipes')->where('id', $this->fassungId)->value('allergen_milk'))->toBe('enthalten');
    $r->recomputePipeline($this->fassungId);
    expect(FoodAlchemistRecipe::mitFassungen()->find($this->fassungId)->ek_total_eur)->toBeNull();
});

it('Weg 7 · 77d-Hüllen: eine Fassung kommt nur über das Abo, nie über die Team-Kette des Masters', function () {
    $unter = Team::create(['name' => 'Master-Unterteam', 'user_id' => 1, 'personal_team' => false, 'parent_team_id' => $this->master->id]);
    TeamAncestryRegistry::flushAll();
    expect(FoodAlchemistRecipe::visibleToTeam($unter)->pluck('foodalchemist_recipes.id')->all())->not->toContain($this->fassungId);
});
