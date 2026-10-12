<?php

use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Jobs\EnrichRecipeJob;
use Platform\FoodAlchemist\Livewire\Planung\Index as PlanungIndex;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRun;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Services\Ai\AiProposal;
use Platform\FoodAlchemist\Services\RecipeExtractService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Rezept-IMPORT (2026-08-22): bestehende Rezeptur (Rohtext) TREU extrahieren (recipe.extract) und
 * GEERDET als Draft anlegen (kiRezeptOverride-Pfad = Resolver + syncIngredients + Recompute).
 * Verschachtelte Quellen (Komponenten mit eigenen Zutaten) → verknüpfte Sub-Rezepte (§4).
 * Der Override-Pfad ruft KEINEN LLM — nur `extrahiere` tut das (hier gemockt).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    foreach (['g' => 1, 'ml' => 1, 'kg' => 1000, 'stk' => 50] as $slug => $g) {
        FoodAlchemistVocabEinheit::create([
            'team_id' => $this->rootTeam->id, 'slug' => $slug, 'display_de' => strtoupper($slug),
            'dimension' => $slug === 'ml' ? 'volume' : 'mass', 'default_in_g' => $g,
        ]);
    }

    // recipe.extract (der einzige LLM-Call des Imports) faken → liefert die vorgegebene Struktur.
    $this->mockExtract = function (array $werte) {
        $this->mock(AiGatewayService::class, fn ($m) => $m->shouldReceive('propose')
            ->andReturn(new AiProposal($werte, 0.9, 'Mock', [], 'extract')));
    };
});

it('extrahiere strukturiert den Rohtext (treu, keine Erfindung)', function () {
    ($this->mockExtract)([
        'typ' => 'basisrezept', 'name' => 'Tomatensauce',
        'zutaten' => [['text' => 'Tomaten', 'quantity' => 500, 'unit' => 'g']],
        'preparation' => 'Kochen.', 'komponenten' => [],
    ]);

    $out = app(RecipeExtractService::class)->extrahiere($this->rootTeam, '500 g Tomaten, kochen.');

    expect($out['name'])->toBe('Tomatensauce')
        ->and($out['typ'])->toBe('basisrezept')
        ->and($out['zutaten'])->toHaveCount(1);
});

it('legeAn legt einen GEERDETEN Import-Draft an (created_via=import, Zutaten synchronisiert)', function () {
    $extrakt = [
        'typ' => 'basisrezept', 'name' => 'Tomatensauce',
        'zutaten' => [
            ['text' => 'Tomaten', 'quantity' => 500, 'unit' => 'g'],
            ['text' => 'Olivenöl', 'quantity' => 20, 'unit' => 'ml'],
        ],
        'preparation' => 'Kochen.',
    ];

    $res = app(RecipeExtractService::class)->legeAn($this->rootTeam, $extrakt);
    $r = $res['recipe'];

    expect($r->status->value)->toBe('draft')
        ->and($r->created_via)->toBe('import')
        ->and((int) $r->n_ingredients_total)->toBe(2)
        ->and((bool) $r->is_sales_recipe)->toBeFalse();
});

it('legeAn legt verschachtelte Komponenten als verknüpfte Sub-Rezepte an', function () {
    $extrakt = [
        'typ' => 'basisrezept', 'name' => 'Rahmgemüse',
        'zutaten' => [['text' => 'Karotten', 'quantity' => 300, 'unit' => 'g']],
        'preparation' => 'Anrichten.',
        'komponenten' => [
            ['name' => 'Rahmsauce', 'zutaten' => [['text' => 'Sahne', 'quantity' => 200, 'unit' => 'ml']], 'preparation' => 'Reduzieren.'],
        ],
    ];

    $res = app(RecipeExtractService::class)->legeAn($this->rootTeam, $extrakt);
    $parent = $res['recipe'];

    expect($res['sub_recipes'])->toHaveCount(1);
    $subId = (int) $res['sub_recipes'][0]['id'];
    $sub = FoodAlchemistRecipe::find($subId);
    expect($sub)->not->toBeNull()
        ->and($sub->created_via)->toBe('import');
    // Der Parent trägt eine Zutatenzeile, die auf das Sub-Rezept verweist (nicht flach aufgelöst).
    $verweise = $parent->ingredients()->pluck('referenced_recipe_id')->filter()->all();
    expect($verweise)->toContain($subId);
});

it('recipes.EXTRACT (MCP): dry_run nur Struktur, sonst geerdeter Draft', function () {
    ($this->mockExtract)([
        'typ' => 'basisrezept', 'name' => 'Jus',
        'zutaten' => [['text' => 'Kalbsknochen', 'quantity' => 1, 'unit' => 'kg']],
        'preparation' => 'Rösten.', 'komponenten' => [],
    ]);
    $registry = app(ToolRegistry::class);
    $kontext = new ToolContext($this->user, $this->rootTeam);

    $dry = $registry->get('foodalchemist.recipes.EXTRACT')->execute(['raw_text' => 'x', 'dry_run' => true], $kontext);
    expect($dry->success)->toBeTrue()
        ->and($dry->data['dry_run'])->toBeTrue()
        ->and($dry->data['extrakt']['name'])->toBe('Jus');

    $post = $registry->get('foodalchemist.recipes.EXTRACT')->execute(['raw_text' => 'x'], $kontext);
    expect($post->success)->toBeTrue();
    $r = FoodAlchemistRecipe::find($post->data['recipe']['id']);
    expect($r->created_via)->toBe('import')
        ->and($r->status->value)->toBe('draft');
});

it('#6: Import mit leerem Namen (Quelle ohne Titel) → Nachfassen statt namenlosem Rezept; Bezeichnung kommt an', function () {
    // Quelltext ohne Titel → recipe.extract liefert name="" (nichts erfinden). Die Zutat-Bezeichnung
    // wird aber sehr wohl erfasst (belegt hier + im echten Call-Log 08-25).
    ($this->mockExtract)([
        'typ' => 'basisrezept', 'name' => '',
        'zutaten' => [['text' => 'Rindergulasch', 'quantity' => 500, 'unit' => 'g']],
        'preparation' => '', 'komponenten' => [],
    ]);

    Livewire::test(PlanungIndex::class)
        ->set('importText', '500 g Rindergulasch')
        ->call('importExtrahieren')
        ->assertSet('importStep', 'vorschau')
        ->assertSet('importVorschau.zutaten.0.text', 'Rindergulasch')   // Bezeichnung KOMMT in der Vorschau an
        ->call('importAnlegen')
        ->assertSet('importStep', 'vorschau')                          // Guard: NICHT „fertig"
        ->assertSet('importMeldung', fn ($m) => is_string($m) && str_contains($m, 'Namen'));

    expect(FoodAlchemistRecipe::where('team_id', $this->rootTeam->id)->where('created_via', 'import')->count())->toBe(0);
});

/**
 * Foto-/Scan-Import (Dominique 2026-10-12): Bilder gehen als Teile DERSELBEN Nutzer-Nachricht an die KI
 * (Core normalisiert, Fix #925). Geprüft über den Fake-Provider: er zählt die Anhänge ins Echo.
 */
it('Gateway: Bild-Anhänge werden Teile der Nutzer-Nachricht, der Text-Weg bleibt unverändert', function () {
    config(['foodalchemist.ai.provider' => 'fake']);
    $gw = app(AiGatewayService::class);

    $ohne = $gw->propose('recipe.extract', ['roh_text' => '50 g Basilikum']);
    expect($ohne->werte)->not->toHaveKey('_anhaenge');

    $mit = $gw->propose('recipe.extract', ['roh_text' => '(siehe Anhang)', 'quelle' => 'bild'], ['bilder' => [
        ['typ' => 'image', 'url' => 'data:image/png;base64,iVBORw0KGgo='],
        ['typ' => 'pdf', 'name' => 'scan.pdf', 'data' => 'data:application/pdf;base64,JVBERi0='],
    ]]);
    expect($mit->werte['_anhaenge'])->toBe(2)
        ->and($mit->werte['quelle'])->toBe('bild');

    // Nachrichten-Teile in der Chat-Completions-Form, die der Core kennt
    $teile = AiGatewayService::bildTeile([['typ' => 'image', 'url' => 'data:image/jpeg;base64,/9j/'], ['typ' => 'pdf', 'name' => 'k.pdf', 'data' => 'data:application/pdf;base64,JVBERi0=']]);
    expect($teile[0])->toBe(['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,/9j/', 'detail' => 'high']])
        ->and($teile[1])->toBe(['type' => 'input_file', 'filename' => 'k.pdf', 'file_data' => 'data:application/pdf;base64,JVBERi0='])
        ->and(AiGatewayService::inhaltText(['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Hallo'], $teile[0]]]))->toBe("Hallo\n[Bild]");
});

it('Gateway: Bilder nur über den OpenAI-Weg — ein anderer Provider wird klar abgewiesen', function () {
    config(['foodalchemist.ai.provider' => 'core']);
    // Provider-Stub, der weder OpenAI noch der Fake ist (so sähe der Anthropic-Weg aus): Bild muss abgewiesen werden.
    app()->bind(\Platform\Core\Contracts\LLMProviderContract::class, fn () => new class implements \Platform\Core\Contracts\LLMProviderContract
    {
        public function getName(): string { return 'anthropic-stub'; }
        public function chat(array $messages, array $options = []): array { return ['content' => '{"werte":{},"confidence":0.5,"reasoning":"x"}', 'usage' => [], 'model' => 'stub', 'tool_calls' => null]; }
        public function streamChat(array $messages, callable $onDelta, array $options = []): void {}
        public function getAvailableModels(): array { return ['stub']; }
        public function getDefaultModel(): string { return 'stub'; }
        public function isAvailable(): bool { return true; }
    });
    expect(fn () => app(AiGatewayService::class)->propose('recipe.extract', ['roh_text' => 'x'], ['bilder' => [['typ' => 'image', 'url' => 'data:image/png;base64,AA==']]]))
        ->toThrow(\RuntimeException::class, 'OpenAI');
});

it('Import-Tab: Foto-Upload geht als Bild an die KI, die Vorschau warnt „aus Foto gelesen"', function () {
    config(['foodalchemist.ai.provider' => 'core']);
    $this->mock(AiGatewayService::class, fn ($m) => $m->shouldReceive('propose')->once()
        ->withArgs(fn (string $key, array $ctx, array $opt) => $key === 'recipe.extract'
            && ($ctx['quelle'] ?? null) === 'bild'
            && str_starts_with((string) ($opt['bilder'][0]['url'] ?? ''), 'data:image/jpeg;base64,'))
        ->andReturn(new AiProposal(['typ' => 'basisrezept', 'name' => 'Pesto vom Foto',
            'zutaten' => [['text' => 'Basilikum', 'quantity' => 50, 'unit' => 'g']], 'preparation' => 'Mixen.', 'komponenten' => []], 0.8, 'Mock', [], 'extract-bild')));

    // Die Tab-Region rendert nur mit aktiver Session (@if($active)) — wie im Layout-Fix-Test oben.
    $session = app(\Platform\FoodAlchemist\Services\PlanningSessionService::class)->create($this->rootTeam, ['title' => 'Foto-Import', 'brief' => 'x']);
    Livewire::test(PlanungIndex::class)
        ->set('sessionId', $session->id)
        ->set('importPdf', \Illuminate\Http\UploadedFile::fake()->image('rezeptkarte.jpg', 800, 600))
        ->call('importExtrahieren')
        ->assertSet('importStep', 'vorschau')
        ->assertSet('importQuelle', 'bild')
        ->assertSeeHtml('data-import-quelle="bild"')
        ->assertSee('aus Foto gelesen');
});

it('Import-Tab: Scan-PDF ohne Text geht als Datei an die KI (Text-PDF weiter günstig als Text)', function () {
    config(['foodalchemist.ai.provider' => 'core']);
    $this->mock(AiGatewayService::class, fn ($m) => $m->shouldReceive('propose')->once()
        ->withArgs(fn (string $key, array $ctx, array $opt) => ($opt['bilder'][0]['typ'] ?? null) === 'pdf'
            && str_starts_with((string) ($opt['bilder'][0]['data'] ?? ''), 'data:application/pdf;base64,'))
        ->andReturn(new AiProposal(['typ' => 'basisrezept', 'name' => 'Jus vom Scan',
            'zutaten' => [['text' => 'Knochen', 'quantity' => 1, 'unit' => 'kg']], 'preparation' => 'Rösten.', 'komponenten' => []], 0.8, 'Mock', [], 'extract-scan')));

    // Minimales PDF ohne Textoperatoren = Scan aus Sicht des Parsers
    $scan = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
        ."3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>endobj\nxref\n0 4\n0000000000 65535 f \n0000000009 00000 n \n0000000052 00000 n \n0000000101 00000 n \ntrailer<</Size 4/Root 1 0 R>>\nstartxref\n160\n%%EOF\n";

    $session = app(\Platform\FoodAlchemist\Services\PlanningSessionService::class)->create($this->rootTeam, ['title' => 'Scan-Import', 'brief' => 'x']);
    Livewire::test(PlanungIndex::class)
        ->set('sessionId', $session->id)
        ->set('importPdf', \Illuminate\Http\UploadedFile::fake()->createWithContent('kochbuch-scan.pdf', $scan))
        ->call('importExtrahieren')
        ->assertSet('importStep', 'vorschau')
        ->assertSet('importQuelle', 'scan')
        ->assertSee('aus Scan gelesen');
});

it('Import-Tab (Livewire): extrahieren → Vorschau, anlegen → Draft + Worker-Übergabe', function () {
    Queue::fake();   // #6/Import: importAnlegen übergibt jetzt an den Worker (EnrichRecipeJob), nicht synchron.
    ($this->mockExtract)([
        'typ' => 'basisrezept', 'name' => 'Pesto',
        'zutaten' => [['text' => 'Basilikum', 'quantity' => 50, 'unit' => 'g']],
        'preparation' => 'Mixen.', 'komponenten' => [],
    ]);

    Livewire::test(PlanungIndex::class)
        ->set('importText', '50 g Basilikum, mixen.')
        ->call('importExtrahieren')
        ->assertSet('importStep', 'vorschau')
        ->call('importAnlegen')
        ->assertSet('importStep', 'fertig');

    // Der Import erdet nicht mehr synchron, sondern legt einen Import-Lauf an + dispatcht die Anreicherung.
    $recipe = FoodAlchemistRecipe::where('team_id', $this->rootTeam->id)->where('created_via', 'import')->latest('id')->first();
    expect($recipe)->not->toBeNull();
    expect(FoodAlchemistCascadeRun::where('team_id', $this->rootTeam->id)->where('created_via', 'import')->count())->toBe(1);
    Queue::assertPushed(EnrichRecipeJob::class, fn ($job) => $job->recipeId === (int) $recipe->id);
});

it('#6 Import-Vorschau rendert die Bezeichnungs-Spalte (Layout-Fix — vorher kollabiert)', function () {
    // Die Tab-Region rendert nur mit aktiver Session (@if($active)); auf demo IST der User in einer.
    $session = app(\Platform\FoodAlchemist\Services\PlanningSessionService::class)
        ->create($this->rootTeam, ['title' => 'Import-Test', 'brief' => 'x']);

    ($this->mockExtract)([
        'typ' => 'basisrezept', 'name' => '',   // titellose Quelle → aber Zutat-Bezeichnung kommt
        'zutaten' => [['text' => 'Rindfleisch aus der Keule', 'quantity' => 500, 'unit' => 'g']],
        'preparation' => '', 'komponenten' => [],
    ]);

    Livewire::test(PlanungIndex::class)
        ->set('sessionId', $session->id)
        ->set('importText', '500 g Rindfleisch aus der Keule')
        ->call('importExtrahieren')
        ->assertSet('importStep', 'vorschau')
        // Menge/Einheit fest schmal, Bezeichnung breit → alle drei Felder sind im DOM (vorher kollabierte die breite Spalte).
        ->assertSeeHtml('data-import-zutat-menge')
        ->assertSeeHtml('data-import-zutat-einheit')
        ->assertSeeHtml('data-import-zutat-text')
        // Name-Feld + Typ-Dropdown rendern (Name kollabierte vorher durch denselben w-full-Konflikt).
        ->assertSeeHtml('data-import-name')
        ->assertSeeHtml('data-import-typ');
});

it('#6: der Extrakt überschreibt NICHT die explizite Typ-Vorauswahl des Users', function () {
    // User wählt „Basisrezept"; der Extrakt hält es für ein „gericht" → Vorauswahl muss gewinnen.
    ($this->mockExtract)([
        'typ' => 'gericht', 'name' => 'Rindergulasch',
        'zutaten' => [['text' => 'Rindfleisch', 'quantity' => 500, 'unit' => 'g']],
        'preparation' => 'Schmoren.', 'komponenten' => [],
    ]);

    Livewire::test(PlanungIndex::class)
        ->set('importTyp', 'basisrezept')             // explizite Wahl im Eingabe-Schritt
        ->set('importText', '500 g Rindfleisch')
        ->call('importExtrahieren')
        ->assertSet('importStep', 'vorschau')
        ->assertSet('importTyp', 'basisrezept');       // bleibt — kein stiller Switch auf „gericht"
});
