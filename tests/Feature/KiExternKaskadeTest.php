<?php

use Illuminate\Support\Facades\DB;
use Platform\Core\Contracts\LLMProviderContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRun;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRunStep;
use Platform\FoodAlchemist\Services\Ai\FakeAiProvider;
use Platform\FoodAlchemist\Services\Ai\KiExternModus;
use Platform\FoodAlchemist\Services\PlanningCascadeService;
use Platform\FoodAlchemist\Services\PlanningSessionService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * #1002 · Paket Kaskade: die Jobs der Planungs-Kaskade laufen extern (KI-Auftrag statt Plattform-Provider), wenn die Kaskade aus einem MCP-Aufruf
 * startet. Der gebundene Plattform-Provider WIRFT — jeder Plattform-Aufruf eines Kaskaden-Jobs im externen Weg macht den Test rot. Die Antworten
 * erzeugt ein getrennter Skript-Provider, der nur als Antwort-Quelle des MCP-Clients dient.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    config(['foodalchemist.ai.provider' => 'core', 'foodalchemist.ai.backoff' => [], 'foodalchemist.ai.mcp_extern' => true]);
    $this->registry = app(ToolRegistry::class);
    $this->mcp = new ToolContext($this->user, $this->rootTeam, ['team_source' => 'user_current']);

    $this->plattformCalls = 0;
    $zaehler = new class extends FakeAiProvider
    {
        public int $calls = 0;

        public function chat(array $messages, array $options = []): array
        {
            $this->calls++;

            throw new \RuntimeException('Plattform-Provider darf im externen Weg nicht gerufen werden');
        }
    };
    $this->wirft = $zaehler;
    app()->instance(LLMProviderContract::class, $zaehler);

    // Antwort-Quelle des MCP-Clients: beantwortet jede Anfrage nach Kontext-Form
    $this->antwort = function (array $messages): array {
        $roh = json_encode($messages, JSON_UNESCAPED_UNICODE);
        if (str_contains($roh, 'kapitel_beschreibung')) {
            return ['ideen' => [['titel' => 'Geschmorte Schulter vom Rind ' . substr(md5($roh), 0, 6), 'beschreibung' => 'Mit Wurzelgemüse und Jus.',
                'komponenten' => [['name' => 'Rinderschulter', 'funktion' => 'Hauptkomponente', 'herstellung' => 'schmoren']]]]];
        }

        if (str_contains($roh, 'diaet_vokabular')) {
            return ['name' => 'Sommerfest', 'target_price_pp' => 45, 'slots' => [
                ['label' => 'Vorspeise', 'slot_type' => 'gang'], ['label' => 'Hauptgang', 'slot_type' => 'gang'], ['label' => 'Dessert', 'slot_type' => 'gang']]];
        }

        // Rezept-/Gericht-Generator und alle übrigen Prompts: eine Antwort mit Rezeptform plus den üblichen Textfeldern
        return ['name' => 'Geschmorte Rinderschulter', 'description' => 'Zart geschmort.', 'preparation' => '1. Schmoren.', 'text' => 'Zart geschmort', 'intro' => 'Einleitung', 'titel' => 'Titel',
            'zutaten' => [['text' => 'Schalotten', 'slug' => 'schalotten', 'quantity' => 300, 'unit' => 'g']]];
    };
    // MCP-Client-Schleife: offene Aufträge holen, beantworten (ANTWORT startet die Jobs neu), bis nichts mehr offen ist
    $this->beantworteAlle = function (int $maxRunden = 15): int {
        $runden = 0;
        while ($runden < $maxRunden) {
            $offen = $this->registry->get('foodalchemist.ki_auftraege.GET')->execute([], $this->mcp)->data['auftraege'];
            if ($offen === []) {
                return $runden;
            }
            $antworten = [];
            foreach ($offen as $a) {
                $antworten[] = ['auftrag_id' => $a['auftrag_id'], 'werte' => ['werte' => ($this->antwort)($a['messages']), 'confidence' => 0.8, 'reasoning' => 'stub']];
            }
            $ok = $this->registry->get('foodalchemist.ki_auftraege.ANTWORT')->execute(['anbieter' => 'anthropic', 'modell' => 'claude-opus-5-5', 'antworten' => $antworten], $this->mcp);
            expect($ok->success)->toBeTrue($ok->error ?? '');
            $runden++;
        }
        throw new \RuntimeException("nicht fertig nach {$maxRunden} Runden");
    };
    // Bestand für den Generator: Einheiten, ein GP mit Lieferantenartikel und Preis (wie KiExternGenerateTest)
    foreach ([['slug' => 'g', 'display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1], ['slug' => 'ml', 'display_de' => 'Milliliter', 'dimension' => 'volume', 'default_in_ml' => 1]] as $e) {
        \Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit::create(['team_id' => $this->rootTeam->id, ...$e]);
    }
    $sup = \Platform\FoodAlchemist\Models\FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Necta']);
    $gp = $this->makeGp($this->rootTeam, 'Schalotten: frisch, ganz');
    $gp->update(['main_ingredient_slug' => 'schalotten', 'status' => 'approved']);
    $la = \Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem::create(['team_id' => $this->rootTeam->id, 'supplier_id' => $sup->id, 'designation' => 'Schalotten', 'qty' => 1.0, 'unit_code' => 'kg']);
    \Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'gp_id' => $gp->id]);
    \Platform\FoodAlchemist\Models\FoodAlchemistPrice::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'price' => 4.0, 'status' => '0']);
    $gp->update(['lead_la_supplier_item_id' => $la->id]);
    $this->session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'Sommer', 'brief' => 'Sommerliches Hauptgericht, regional, gehoben']);
    $this->startImMcp = fn (string $scope, array $optionen) => app(KiExternModus::class)->imMcp(
        fn () => app(PlanningCascadeService::class)->starteKaskade($this->rootTeam, $scope, $this->session, 'voll_kreativ', $optionen));
});

it('GenerateDishProposalJob: aus dem MCP-Aufruf extern — Auftrag statt Provider, Step bleibt running, nach Antwort geplant', function () {
    $run = ($this->startImMcp)('gericht', ['brief' => 'Sommerliches Hauptgericht, regional, gehoben', 'proposal_first' => true]);

    $step = FoodAlchemistCascadeRunStep::where('cascade_run_id', $run->id)->first();
    expect($this->wirft->calls)->toBe(0)
        ->and(DB::table('foodalchemist_ki_auftraege')->where('status', 'offen')->pluck('feature')->all())->toBe(['foodbook.kapitel_ideen'])
        ->and($step->fresh()->status)->toBe('running');

    ($this->beantworteAlle)();

    expect($this->wirft->calls)->toBe(0)
        ->and($step->fresh()->status)->toBe('geplant')
        ->and(DB::table('foodalchemist_dish_ideas')->where('planning_session_id', $this->session->id)->count())->toBe(1);
});

it('GenerateDishProposalJob ohne MCP-Herkunft: Plattformweg, kein Auftrag (Provider wird gerufen)', function () {
    FoodAlchemistCascadeRun::query()->delete();
    $run = app(PlanningCascadeService::class)->starteKaskade($this->rootTeam, 'gericht', $this->session, 'voll_kreativ',
        ['brief' => 'Sommerliches Hauptgericht, regional, gehoben', 'proposal_first' => true]);

    expect(DB::table('foodalchemist_ki_auftraege')->count())->toBe(0)->and($this->wirft->calls)->toBeGreaterThan(0)
        ->and(FoodAlchemistCascadeRunStep::where('cascade_run_id', $run->id)->first()->status)->toBe('failed');
});

it('Wächter: foodbook.PLAN_FROM_BRIEF läuft komplett extern — der Plattform-Provider wird nie gerufen, kein Lauf bleibt hängen', function (string $modus) {
    $r = $this->registry->get('foodalchemist.foodbook.PLAN_FROM_BRIEF')->execute(['brief' => 'Sommerfest, 60 Gäste, vegetarisch, regional, 45 EUR p. P.', 'creative_mode' => $modus], $this->mcp);
    expect($r->success)->toBeTrue($r->error ?? '')->and($r->data['status'] ?? null)->toBe('ki_auftrag_offen');

    for ($i = 0; $i < 12; $i++) {
        ($this->beantworteAlle)();
        $r = $this->registry->get('foodalchemist.foodbook.PLAN_FROM_BRIEF')->execute(['brief' => 'Sommerfest, 60 Gäste, vegetarisch, regional, 45 EUR p. P.', 'creative_mode' => $modus], $this->mcp);
        if (($r->data['status'] ?? null) !== 'ki_auftrag_offen') {
            break;
        }
    }
    ($this->beantworteAlle)();

    // gestufte Kaskade: der Mensch (hier der MCP-Client) gibt jede Ebene frei, die Freigabe startet die Jobs der nächsten Stufe
    for ($stufe = 0; $stufe < 10; $stufe++) {
        $offen = FoodAlchemistCascadeRunStep::whereIn('status', ['geplant', 'done'])->orderBy('id')->pluck('id')->all();
        if ($offen === []) {
            break;
        }
        foreach ($offen as $id) {
            $f = $this->registry->get('foodalchemist.planung_kaskade.FREIGABE')->execute(['step_id' => $id], $this->mcp);
            expect($f->success)->toBeTrue($f->error ?? '');
        }
        ($this->beantworteAlle)();
    }

    expect($r->success)->toBeTrue($r->error ?? '')->and($this->wirft->calls)->toBe(0);
    $run = FoodAlchemistCascadeRun::orderByDesc('id')->first();
    expect($run)->not->toBeNull();
    $steps = FoodAlchemistCascadeRunStep::where('cascade_run_id', $run->id)->pluck('status', 'id')->all();
    expect($steps)->not->toBeEmpty()->and(array_values(array_unique($steps)))->not->toContain('running')->not->toContain('failed');
    expect(DB::table('foodalchemist_ki_auftraege')->where('status', 'offen')->count())->toBe(0)
        ->and(DB::table('foodalchemist_ki_auftraege')->where('status', 'verbraucht')->pluck('feature')->unique()->all())->not->toBeEmpty();
})->with(['datenbank', 'voll_kreativ']);

it('Wächter: jeder Job der Planungs-Kaskade mit KI-Aufruf ist KiExternWiederholbar', function () {
    foreach (['GenerateRecipeJob', 'EnrichRecipeJob', 'EnrichGeneratedRecipeJob', 'ConformanceCheckJob', 'GenerateRecipePlanJob', 'GenerateDishProposalJob', 'ReviseDishProposalJob',
        'GenerateConceptJob', 'FanoutConceptJob', 'MaterializeConceptIdeaJob', 'MaterializeSpeiseplanCellJob', 'MaterializeSpeisekartePositionJob', 'BuildZukaufRecipeJob', 'StubAusrezeptierenJob'] as $job) {
        expect(is_subclass_of('Platform\\FoodAlchemist\\Jobs\\' . $job, \Platform\FoodAlchemist\Services\Ai\KiExternWiederholbar::class))->toBeTrue($job);
    }
});

it('Wächter: im Verzeichnis Jobs bleibt kein Job mit KI-Aufruf ohne KiExternWiederholbar (Ausnahmen begründet)', function () {
    // Jobs, die KI nutzen (Gateway, Generator, Service-Aufrufe mit propose) und bewusst Plattform bleiben, stehen hier mit Grund; neue Jobs müssen sich einreihen
    $ausnahmen = [
        'BulkEnrichJob' => 'Mandanten-Bulk, kein MCP-Weg (Tabelle D)', 'BulkEnrichGpJob' => 'GP-Bulk, kein MCP-Weg (Tabelle D)', 'NectaPruefallSammelJob' => 'Prüf-Sammellauf',
        'ProduktpassLesenJob' => 'Dateien/Storage, ausgenommen', 'ProduktpassRechercheJob' => 'Websuche, 2d-2', 'ImportArticlesJob' => 'Import/Websuche, Paket LA offen',
        'QualityRunJob' => 'Qualitätslauf ohne Generierung', 'RecipeFindingsRunJob' => 'Befund-Lauf', 'RecomputeTeamRecipesJob' => 'reine Berechnung',
        'LeadRepickJob' => 'reine Berechnung', 'SignalFixJob' => 'deterministisch', 'MandantExportJob' => 'Export', 'BestellMailSendenJob' => 'Mail',
        'QueueKnowledgeEmbedJob' => 'Embedding, ausgenommen', 'InhaltsFreigabeHuelleJob' => 'Freigabe-Hülle', 'BausteinFolgeJob' => 'Folge-Berechnung', 'RezeptFolgeJob' => 'Folge-Berechnung',
        'ClassifyLaJob' => 'bereits KiExternWiederholbar', 'GpAromaNachholenJob' => 'bereits KiExternWiederholbar', 'NonFoodKiBatchJob' => 'bereits KiExternWiederholbar', 'SensorikNachholenJob' => 'bereits KiExternWiederholbar',
    ];
    $dir = dirname(__DIR__, 2) . '/src/Jobs';
    foreach (glob($dir . '/*Job.php') as $datei) {
        $name = basename($datei, '.php');
        $src = file_get_contents($datei);
        $ruftKi = (bool) preg_match('/->propose\(|RecipeGeneratorService|generiere\(|IdeenService|kiDivergenz|wordingPass|fanoutConceptInvention|materialisiere/', $src);
        if (! $ruftKi || array_key_exists($name, $ausnahmen)) {
            continue;
        }
        expect(str_contains($src, 'KiExternWiederholbar'))->toBeTrue("{$name} ruft KI und ist weder KiExternWiederholbar noch als Ausnahme begründet");
    }
});

