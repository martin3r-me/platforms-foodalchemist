<?php

use Illuminate\Support\Facades\DB;
use Platform\Core\Contracts\LLMProviderContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient;
use Platform\FoodAlchemist\Services\Ai\FakeAiProvider;
use Platform\FoodAlchemist\Services\Ai\KiAuftragService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * #1002 (P2c-3a): recipes.REVISE mit accept=true — der SCHREIBENDE Weg samt Folge-Sensorik. Vergleichstest Plattformweg = MCP-Weg:
 * zwei gleich gebaute Rezepte (A Plattform, B MCP), dieselbe KI-Antwort, dasselbe Ergebnis; im MCP-Weg ruft weder die Revision noch das
 * nachgeholte Sensorik-Kind den Plattform-Provider.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    config(['foodalchemist.ai.provider' => 'core', 'foodalchemist.ai.backoff' => [], 'foodalchemist.ai.mcp_extern' => true]);
    $this->registry = app(ToolRegistry::class);
    $this->mcp = new ToolContext($this->user, $this->rootTeam, ['team_source' => 'user_current']);
    $this->intern = new ToolContext($this->user, $this->rootTeam);

    $this->gp = $this->makeGp($this->rootTeam, 'Schalotten: frisch, ganz');
    $this->rezept = function (string $name) {
        $r = $this->makeRecipe($this->rootTeam, $name, ['status' => 'draft', 'description' => 'Sirup.', 'preparation' => '1. Reduzieren.']);
        $this->makeIngredient($r, 'Schalotten', $this->gp, '300');

        return $r->fresh();
    };
    $this->stub = new class extends FakeAiProvider
    {
        public int $calls = 0;

        public array $werte = [];

        public function chat(array $messages, array $options = []): array
        {
            $this->calls++;

            return ['content' => json_encode(['werte' => $this->werte, 'confidence' => 0.8, 'reasoning' => 'stub'], JSON_UNESCAPED_UNICODE), 'usage' => [], 'model' => 'stub-1', 'tool_calls' => null];
        }
    };
    app()->instance(LLMProviderContract::class, $this->stub);
    $this->setzeWerte = function (FoodAlchemistRecipe $r) {
        $z = FoodAlchemistRecipeIngredient::where('recipe_id', $r->id)->first();
        $this->stub->werte = [
            'description' => 'Mild gesüßte Sirup-Reduktion.', 'preparation' => "1. Schalotten anschwitzen.\n2. Reduzieren.",
            'zutaten' => [['id' => $z->id, 'text' => 'Schalotten', 'quantity' => 250, 'einheit_slug' => 'g']],
        ];
    };
    $this->stand = function (FoodAlchemistRecipe $r) {
        $f = $r->fresh();

        return ['description' => $f->description, 'description_source' => $f->description_source, 'preparation_source' => $f->preparation_source,
            'schritte' => DB::table('foodalchemist_recipe_steps')->where('recipe_id', $r->id)->count(),
            'zutaten' => FoodAlchemistRecipeIngredient::where('recipe_id', $r->id)->orderBy('id')->get()->map(fn ($z) => [(int) $z->gp_id, (float) $z->quantity])->all(),
            'sensorik' => DB::table('foodalchemist_recipe_taste_vectors')->where('recipe_id', $r->id)->count()];
    };
});

it('Vergleichstest recipes.REVISE accept=true: Plattformweg und MCP-Weg schreiben dasselbe, das Sensorik-Kind ruft den Provider nie', function () {
    $a = ($this->rezept)('Reduktion: Rotwein-Schalotte (A)');
    $b = ($this->rezept)('Reduktion: Rotwein-Schalotte (B)');
    $revise = fn (FoodAlchemistRecipe $r, ToolContext $k) => $this->registry->get('foodalchemist.recipes.REVISE')
        ->execute(['recipe_id' => $r->id, 'anweisung' => 'Etwas weniger süß', 'accept' => true], $k);

    // Plattformweg (A): Revision + Folge-Sensorik laufen über den Plattform-Provider
    ($this->setzeWerte)($a);
    $rp = $revise($a, $this->intern);
    expect($rp->success)->toBeTrue($rp->error ?? '')->and($rp->data['accepted'])->toBeTrue()->and($rp->data['applied'])->toContain('description');
    $callsPlattform = $this->stub->calls;
    $standA = ($this->stand)($a);
    expect($callsPlattform)->toBeGreaterThan(1, 'Revision + nachgeholte Sensorik')
        ->and(DB::table('foodalchemist_ki_auftraege')->count())->toBe(0);

    // MCP-Weg (B): Tool → Auftrag → ANTWORT → Tool …, danach das Sensorik-Kind über Aufträge
    ($this->setzeWerte)($b);
    $antwort = $this->registry->get('foodalchemist.ki_auftraege.ANTWORT');
    $r = $revise($b, $this->mcp);
    $runden = 0;
    while ($runden++ < 30) {
        $offen = DB::table('foodalchemist_ki_auftraege')->where('status', 'offen')->get();
        if ($offen->isEmpty()) {
            break;
        }
        $vorher = $this->stub->calls;
        $antworten = $offen->map(fn ($o) => ['auftrag_id' => $o->uuid, 'werte' => json_decode($this->stub->chat(json_decode($o->messages, true), [])['content'], true)])->all();
        $this->stub->calls = $vorher;                            // die Antwort-Berechnung des Tests ist kein Plattform-Call
        $antwort->execute(['antworten' => $antworten, 'anbieter' => 'anthropic', 'modell' => 'claude-opus-5-5'], $this->mcp);
        if (($r->data['status'] ?? null) === 'ki_auftrag_offen') {
            $r = $revise($b, $this->mcp);                         // Hülle-Tool: der Client wiederholt den Aufruf
        }
    }

    expect($r->success)->toBeTrue($r->error ?? '')->and($r->data['accepted'] ?? null)->toBeTrue()
        ->and($this->stub->calls)->toBe($callsPlattform, 'weder die Revision noch das Sensorik-Kind rufen den Provider im MCP-Weg')
        ->and(($this->stand)($b))->toBe($standA, 'gleiches Ergebnis wie der Plattformweg')
        ->and(DB::table('foodalchemist_ki_auftraege')->where('lauf_key', 'like', 'sensorik-nachholen:%')->count())->toBeGreaterThan(0, 'das Kind lief über Aufträge')
        ->and(DB::table('foodalchemist_ki_auftraege')->where('status', 'offen')->count())->toBe(0)
        ->and(app(KiAuftragService::class)->zaehler($this->rootTeam->id)['verwaist'])->toBe(0);
});
