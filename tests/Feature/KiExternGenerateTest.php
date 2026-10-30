<?php

use Illuminate\Support\Facades\DB;
use Platform\Core\Contracts\LLMProviderContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistPrice;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\Ai\FakeAiProvider;
use Platform\FoodAlchemist\Services\Ai\KiAuftragService;
use Platform\FoodAlchemist\Services\Ai\KiExternLauf;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * #1002 (P2c-1): recipes.GENERATE extern. Das Tool ist synchron (Rezept entsteht im Aufruf) und läuft über die Tool-Hülle: Transaktion,
 * Rollback bis zur Antwort, mehrere KI-Glieder = mehrere Runden. Vergleichstest Plattformweg = MCP-Weg.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    config(['foodalchemist.ai.provider' => 'fake', 'foodalchemist.ai.backoff' => [], 'foodalchemist.ai.mcp_extern' => true]);
    $this->registry = app(ToolRegistry::class);
    $this->mcp = new ToolContext($this->user, $this->rootTeam, ['team_source' => 'user_current']);
    $this->intern = new ToolContext($this->user, $this->rootTeam);

    foreach ([
        ['slug' => 'g', 'display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1],
        ['slug' => 'ml', 'display_de' => 'Milliliter', 'dimension' => 'volume', 'default_in_ml' => 1],
    ] as $e) {
        FoodAlchemistVocabEinheit::create(['team_id' => $this->rootTeam->id, ...$e]);
    }
    $supplier = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Necta']);
    $gp = $this->makeGp($this->rootTeam, 'Schalotten: frisch, ganz');
    $gp->update(['main_ingredient_slug' => 'schalotten', 'status' => 'approved']);
    $la = FoodAlchemistSupplierItem::create(['team_id' => $this->rootTeam->id, 'supplier_id' => $supplier->id, 'designation' => 'Schalotten', 'qty' => 1.0, 'unit_code' => 'kg']);
    FoodAlchemistSupplierItemStructure::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'gp_id' => $gp->id]);
    FoodAlchemistPrice::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'price' => 4.0, 'status' => '0']);
    $gp->update(['lead_la_supplier_item_id' => $la->id]);

    config(['foodalchemist.ai.provider' => 'core']);
    $this->stub = new class extends FakeAiProvider
    {
        public int $calls = 0;

        public function chat(array $messages, array $options = []): array
        {
            $this->calls++;

            return ['content' => json_encode(['werte' => [
                'name' => 'Reduktion: Rotwein-Schalotte', 'description' => 'Sirupartige Saucenbasis.', 'preparation' => '1. Schalotten anschwitzen.',
                'zutaten' => [['text' => 'Schalotten', 'slug' => 'schalotten', 'quantity' => 300, 'unit' => 'g']],
            ], 'confidence' => 0.8, 'reasoning' => 'stub'], JSON_UNESCAPED_UNICODE), 'usage' => [], 'model' => 'stub-1', 'tool_calls' => null];
        }
    };
    app()->instance(LLMProviderContract::class, $this->stub);

    $this->aufruf = fn (ToolContext $k, array $a = []) => $this->registry->get('foodalchemist.recipes.GENERATE')
        ->execute($a + ['description' => 'Dunkle Rotwein-Schalotten-Reduktion', 'convenience' => 'from_scratch'], $k);
    $this->momentaufnahme = function (int $id) {
        $r = FoodAlchemistRecipe::ohneTeamSicht('test')->find($id);

        return ['name' => $r->name, 'status' => $r->status->value, 'description' => $r->description, 'preparation' => $r->preparation, 'created_via' => $r->created_via,
            'zutaten' => FoodAlchemistRecipeIngredient::where('recipe_id', $id)->orderBy('id')->get()->map(fn ($z) => [(int) $z->gp_id, (float) $z->quantity])->all()];
    };
});

it('Vergleichstest: Plattformweg und MCP-Weg erzeugen dasselbe Rezept — Provider im Extern-Weg 0×, nichts doppelt, alle Aufträge verbraucht', function () {
    // Plattformweg, danach Rollback auf den Ausgangszustand
    DB::beginTransaction();
    $p = ($this->aufruf)($this->intern);
    expect($p->success)->toBeTrue($p->error ?? '');
    $plattform = ($this->momentaufnahme)((int) $p->data['recipe']['id']);
    DB::rollBack();
    $callsPlattform = $this->stub->calls;
    expect($callsPlattform)->toBeGreaterThan(0)
        ->and(DB::table('foodalchemist_ki_auftraege')->count())->toBe(0)
        ->and(FoodAlchemistRecipe::ohneTeamSicht('test')->where('name', 'Reduktion: Rotwein-Schalotte')->count())->toBe(0);

    // MCP-Weg: Tool → Auftrag → ANTWORT → Tool … bis es sein normales Ergebnis liefert (ebenfalls in einer äußeren Transaktion:
    // die Folge-Jobs hängen an afterCommit und fallen mit dem Rollback weg — wie beim Plattformweg, der Vergleich bleibt fair)
    DB::beginTransaction();
    $antwort = app(ToolRegistry::class)->get('foodalchemist.ki_auftraege.ANTWORT');
    $nebenwirkungen = [];
    $r = ($this->aufruf)($this->mcp);
    $runden = 0;
    while (($r->data['status'] ?? null) === 'ki_auftrag_offen' && $runden++ < 12) {
        expect(FoodAlchemistRecipe::ohneTeamSicht('test')->where('name', 'Reduktion: Rotwein-Schalotte')->count())->toBe(0, 'wartend: kein Halb-Rezept');
        $vorher = $this->stub->calls;
        $antworten = DB::table('foodalchemist_ki_auftraege')->where('status', 'offen')->get()->map(fn ($a) => [
            'auftrag_id' => $a->uuid, 'werte' => json_decode($this->stub->chat(json_decode($a->messages, true), [])['content'], true),
        ])->all();
        $this->stub->calls = $vorher;                            // die Antwort-Berechnung des Tests ist kein Plattform-Call
        $antwort->execute(['antworten' => $antworten, 'anbieter' => 'anthropic', 'modell' => 'claude-opus-5-5'], $this->mcp);
        $r = ($this->aufruf)($this->mcp);
        $nebenwirkungen = array_merge($nebenwirkungen, KiExternLauf::$nebenwirkungen);
    }

    expect($r->success)->toBeTrue($r->error ?? '')->and($r->data['recipe']['name'] ?? null)->toBe('Reduktion: Rotwein-Schalotte');
    $mcpStand = ($this->momentaufnahme)((int) $r->data['recipe']['id']);
    $auftragStatus = DB::table('foodalchemist_ki_auftraege')->pluck('status')->unique()->values()->all();
    $zaehler = app(KiAuftragService::class)->zaehler($this->rootTeam->id);
    DB::rollBack();
    expect($mcpStand)->toBe($plattform, 'gleiches Ergebnis wie der Plattformweg')
        ->and($this->stub->calls)->toBe($callsPlattform, 'der Provider wird im Extern-Weg nicht gerufen')
        ->and($nebenwirkungen)->toBe([], 'keine Nicht-DB-Nebenwirkung vor dem Commit')
        ->and($auftragStatus)->toBe(['verbraucht']);
    expect($zaehler['doppelt'])->toBe(0)->and($zaehler['verwaist'])->toBe(0)->and($zaehler['offen'])->toBe(0);
});

it('Wartend legt weder Rezept noch Zutaten an', function () {
    $r = ($this->aufruf)($this->mcp);

    expect($r->data['status'])->toBe('ki_auftrag_offen')
        ->and(FoodAlchemistRecipe::ohneTeamSicht('test')->where('created_via', 'mcp')->count())->toBe(0)
        ->and(FoodAlchemistRecipeIngredient::count())->toBe(0)
        ->and($this->stub->calls)->toBe(0);
});

it('interner Weg (ohne MCP-Herkunft) bleibt beim Plattform-Provider, kein Auftrag', function () {
    $r = ($this->aufruf)($this->intern);

    expect($r->success)->toBeTrue($r->error ?? '')->and($r->data['recipe']['name'])->toBe('Reduktion: Rotwein-Schalotte')
        ->and($this->stub->calls)->toBeGreaterThan(0)
        ->and(DB::table('foodalchemist_ki_auftraege')->count())->toBe(0);
});

it('Kind-Job im Extern-Lauf: die nachgeholte Sensorik legt einen KI-Auftrag an und ruft den Plattform-Provider nie', function () {
    // ohne äußere Transaktion: nach dem Commit läuft das Kind (SensorikNachholenJob, afterCommit; Sync-Queue = sofort)
    $antwort = app(ToolRegistry::class)->get('foodalchemist.ki_auftraege.ANTWORT');
    $r = ($this->aufruf)($this->mcp);
    $runden = 0;
    $feature = fn () => DB::table('foodalchemist_ki_auftraege')->orderBy('id')->pluck('feature')->all();
    while ($runden++ < 15) {
        $offen = DB::table('foodalchemist_ki_auftraege')->where('status', 'offen')->get();
        if ($offen->isEmpty()) {
            break;
        }
        $vorher = $this->stub->calls;
        $antworten = $offen->map(fn ($a) => ['auftrag_id' => $a->uuid, 'werte' => json_decode($this->stub->chat(json_decode($a->messages, true), [])['content'], true)])->all();
        $this->stub->calls = $vorher;
        $antwort->execute(['antworten' => $antworten, 'anbieter' => 'anthropic', 'modell' => 'claude-opus-5-5'], $this->mcp);
        if (($r->data['status'] ?? null) === 'ki_auftrag_offen') {
            $r = ($this->aufruf)($this->mcp);                     // Hülle-Tool: der Client wiederholt den Aufruf
        }
    }

    expect($r->success)->toBeTrue($r->error ?? '')->and($r->data['recipe']['name'] ?? null)->toBe('Reduktion: Rotwein-Schalotte')
        ->and($feature())->toContain('recipe.sensorik')   // das Kind hat einen eigenen Auftrag angelegt
        
        ->and($this->stub->calls)->toBe(0, 'weder das Rezept noch das Kind rufen den Plattform-Provider')
        ->and(DB::table('foodalchemist_ki_auftraege')->where('lauf_key', 'like', 'sensorik-nachholen:%')->count())->toBeGreaterThan(0);
});
