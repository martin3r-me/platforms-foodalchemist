<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Platform\Core\Contracts\LLMProviderContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Jobs\ClassifyLaJob;
use Platform\FoodAlchemist\Jobs\GpAromaNachholenJob;
use Platform\FoodAlchemist\Jobs\NonFoodKiBatchJob;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemNonfood;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Services\Ai\FakeAiProvider;
use Platform\FoodAlchemist\Services\Ai\KiAuftragService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * #1002 (LA-Wege): Jobs, die ein MCP-Aufruf anstößt (ClassifyLaJob, GpAromaNachholenJob), laufen extern — KI-Auftrag statt Plattform-Provider,
 * Rollback bis zur Antwort, ANTWORT startet den Job neu. Das Extern-Flag kommt aus dem MCP-Aufruf (KiExternModus::imMcp), nicht nur aus Extern-Läufen.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    config(['foodalchemist.ai.provider' => 'core', 'foodalchemist.ai.backoff' => [], 'foodalchemist.ai.mcp_extern' => true]);
    $this->mcp = new ToolContext($this->user, $this->rootTeam, ['team_source' => 'user_current']);
    $this->intern = new ToolContext($this->user, $this->rootTeam);
    $this->registry = app(ToolRegistry::class);
    $this->sup = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Chefs']);
    $this->nr = 0;
    $this->la = fn (string $bez) => FoodAlchemistSupplierItem::create(['team_id' => $this->rootTeam->id, 'supplier_id' => $this->sup->id, 'designation' => $bez,
        'article_number' => 'Z' . (++$this->nr), 'qty' => 1, 'unit_code' => 'kg']);
    $this->stub = new class extends FakeAiProvider
    {
        public int $calls = 0;

        public ?array $vorgabe = null;

        public function chat(array $messages, array $options = []): array
        {
            $this->calls++;
            if ($this->vorgabe !== null) {
                return ['content' => json_encode(['werte' => $this->vorgabe, 'confidence' => 0.9, 'reasoning' => 'stub'], JSON_UNESCAPED_UNICODE), 'usage' => [], 'model' => 'stub-1', 'tool_calls' => null];
            }

            return ['content' => json_encode(['werte' => ['hauptzutat' => 'Zwiebel', 'condition' => 'frisch', 'processing' => 'ganz', 'pflichtangabe' => 'Kochtyp: festkochend',
                'commodity_group_code' => '01'], 'confidence' => 0.9, 'reasoning' => 'stub'], JSON_UNESCAPED_UNICODE), 'usage' => [], 'model' => 'stub-1', 'tool_calls' => null];
        }
    };
    app()->instance(LLMProviderContract::class, $this->stub);
    $this->antworteOffene = function () {
        $vorher = $this->stub->calls;
        $antworten = DB::table('foodalchemist_ki_auftraege')->where('status', 'offen')->get()->map(fn ($a) => [
            'auftrag_id' => $a->uuid, 'werte' => json_decode($this->stub->chat(json_decode($a->messages, true), [])['content'], true)])->all();   // Umschlag: werte, confidence, reasoning
        $this->stub->calls = $vorher;

        return $this->registry->get('foodalchemist.ki_auftraege.ANTWORT')->execute(['antworten' => $antworten, 'anbieter' => 'anthropic', 'modell' => 'claude-opus-5-5'], $this->mcp);
    };
    $this->struktur = fn ($la) => FoodAlchemistSupplierItemStructure::where('supplier_item_id', $la->id)->whereNull('deleted_at')->first()?->only(
        ['main_ingredient_display', 'condition', 'processing', 'classifier', 'needs_review']);
});

it('Vergleichstest ClassifyLaJob: extern und Plattformweg klassifizieren gleich; bis zur Antwort nichts geschrieben, Provider 0×', function () {
    $a = ($this->la)('Zwiebeln gelb 1 kg Netz');
    $b = ($this->la)('Zwiebeln gelb 1 kg Netz');

    (new ClassifyLaJob($a->id, $this->rootTeam->id))->handle(app(\Platform\FoodAlchemist\Services\Ai\AiGatewayService::class), app(\Platform\FoodAlchemist\Services\GpNamingService::class));
    $plattform = ($this->struktur)($a);
    $callsPlattform = $this->stub->calls;
    expect($plattform['main_ingredient_display'])->toBe('Zwiebel')->and($callsPlattform)->toBe(1);

    $job = new ClassifyLaJob($b->id, $this->rootTeam->id, true);
    expect($job)->toBeInstanceOf(\Platform\FoodAlchemist\Services\Ai\KiExternWiederholbar::class);
    $job->handle(app(\Platform\FoodAlchemist\Services\Ai\AiGatewayService::class), app(\Platform\FoodAlchemist\Services\GpNamingService::class));
    expect(($this->struktur)($b))->toBeNull('wartend: nichts geschrieben')
        ->and(DB::table('foodalchemist_ki_auftraege')->where('status', 'offen')->count())->toBe(1)
        ->and(DB::table('foodalchemist_ki_auftraege')->value('feature'))->toBe('gp.suggest');

    ($this->antworteOffene)();                                    // ANTWORT startet den Job neu (sync-Queue) und verbraucht die Antwort

    expect(($this->struktur)($b))->toBe($plattform, 'gleiches Ergebnis wie der Plattformweg')
        ->and($this->stub->calls)->toBe($callsPlattform, 'der Provider wird im Extern-Weg nicht gerufen')
        ->and(DB::table('foodalchemist_ki_auftraege')->pluck('status')->unique()->values()->all())->toBe(['verbraucht'])
        ->and(app(KiAuftragService::class)->zaehler($this->rootTeam->id)['doppelt'])->toBe(0);
});

it('Extern-Flag kommt aus dem MCP-Aufruf: gps.POST per MCP stößt GpAromaNachholenJob extern an, intern nicht', function () {
    Queue::fake();
    $this->registry->get('foodalchemist.gps.POST')->execute(['hauptzutat' => 'Ananas'], $this->mcp);
    Queue::assertPushed(GpAromaNachholenJob::class, fn ($j) => $j->extern === true);

    $this->registry->get('foodalchemist.gps.POST')->execute(['hauptzutat' => 'Birne'], $this->intern);
    Queue::assertPushed(GpAromaNachholenJob::class, fn ($j) => $j->extern === false);

    config(['foodalchemist.ai.mcp_extern' => false]);
    $this->registry->get('foodalchemist.gps.POST')->execute(['hauptzutat' => 'Mango'], $this->mcp);
    Queue::assertPushed(GpAromaNachholenJob::class, 3);
    Queue::assertPushed(GpAromaNachholenJob::class, fn ($j) => $j->extern === false && $j->gpId !== null && \Platform\FoodAlchemist\Models\FoodAlchemistGp::find($j->gpId)?->name !== null);
});

it('GpAromaNachholenJob extern: KI-Aufträge statt Provider, bis zur Antwort nichts geschrieben', function () {
    Queue::fake();
    $gpId = $this->registry->get('foodalchemist.gps.POST')->execute(['hauptzutat' => 'Ananas'], $this->mcp)->data['id'];
    Queue::fake();                                                // Queue-Stand zurücksetzen; der Job läuft jetzt von Hand
    $job = new GpAromaNachholenJob($this->rootTeam->id, (int) $this->user->id, $gpId, true);
    $job->handle(app(\Platform\FoodAlchemist\Services\BulkEnrichService::class), app(\Platform\FoodAlchemist\Services\GpAromaService::class));

    $features = DB::table('foodalchemist_ki_auftraege')->where('status', 'offen')->pluck('feature')->all();
    expect($features)->not->toBe([])->and($this->stub->calls)->toBe(0, 'Plattform-Provider nicht gerufen')
        ->and($job->externSchluessel())->toBe("gp-aroma-nachholen:{$this->rootTeam->id}:{$gpId}")
        ->and(array_diff($features, ['gp.anker', 'gp.sensorik']))->toBe([]);
});

it('Vergleichstest NonFoodKiBatchJob: extern und Plattformweg urteilen gleich, Stapel wartet auf einen Auftrag', function () {
    $a = ($this->la)('Salatschale Bagasse 900 ml');
    $b = ($this->la)('Salatschale Bagasse 900 ml');
    foreach ([$a, $b] as $la) {
        FoodAlchemistSupplierItemNonfood::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'ist_nonfood' => null, 'quelle' => FoodAlchemistSupplierItemNonfood::QUELLE_REGEL]);
    }
    $this->stub->vorgabe = ['urteile' => [['id' => $a->id, 'nonfood' => true, 'konfidenz' => 0.95, 'unterkategorie' => null], ['id' => $b->id, 'nonfood' => true, 'konfidenz' => 0.95, 'unterkategorie' => null]]];
    $stand = fn ($la) => FoodAlchemistSupplierItemNonfood::where('supplier_item_id', $la->id)->first()->only(['ist_nonfood', 'quelle', 'pruefung_offen', 'begruendung']);

    (new NonFoodKiBatchJob([$a->id]))->handle(app(\Platform\FoodAlchemist\Services\Ai\AiGatewayService::class));
    $plattform = $stand($a);
    expect($plattform['ist_nonfood'])->toBeTrue()->and($this->stub->calls)->toBe(1);

    $this->stub->vorgabe = ['urteile' => [['id' => $b->id, 'nonfood' => true, 'konfidenz' => 0.95, 'unterkategorie' => null]]];
    (new NonFoodKiBatchJob([$b->id], true))->handle(app(\Platform\FoodAlchemist\Services\Ai\AiGatewayService::class));
    expect($stand($b)['ist_nonfood'])->toBeNull('wartend: kein Urteil geschrieben')
        ->and(DB::table('foodalchemist_ki_auftraege')->where('status', 'offen')->value('feature'))->toBe('la.nonfood');

    ($this->antworteOffene)();

    expect($stand($b))->toBe($plattform)
        ->and($this->stub->calls)->toBe(1, 'Provider im Extern-Weg nicht gerufen')
        ->and(DB::table('foodalchemist_ki_auftraege')->pluck('status')->unique()->values()->all())->toBe(['verbraucht']);
});
