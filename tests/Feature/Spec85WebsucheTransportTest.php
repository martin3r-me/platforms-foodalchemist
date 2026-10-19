<?php

use Illuminate\Support\Facades\DB;
use Platform\Core\Contracts\LLMProviderContract;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Services\Ai\FakeAiProvider;
use Platform\FoodAlchemist\Services\ArtikelWebRecherche;
use Platform\FoodAlchemist\Services\Websuche\CoreWebSucheTransport;
use Platform\FoodAlchemist\Services\Websuche\WebSucheTransport;
use Platform\FoodAlchemist\Support\KiKlassen;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 85 I5-8: Web-Recherche über den Core-Contract (`include_web_search`, Core #935 liefert `sources`). Der Provider ist
 * gefälscht: Quellen vorhanden / leer / Schlüssel fehlt (Core vor #935) / Provider sucht nicht im Web (Anthropic-Weg).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    config(['foodalchemist.ai.provider' => 'core', 'foodalchemist.ai.backoff' => []]);
    $lief = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => "D'Arta"]);
    $this->la = FoodAlchemistSupplierItem::create(['team_id' => $this->rootTeam->id, 'supplier_id' => $lief->id, 'article_number' => 'P15003F5E',
        'designation' => 'Salat, Bella Caio Tk', 'ean_packaging' => '5413408981041']);

    $this->quelle = 'https://www.darta.be/bella-ciao-caprese';
    $this->werte = [
        'felder' => [
            'zutatenliste' => ['wert' => 'Nudeln, Tomate, Mozzarella', 'konfidenz' => 0.9, 'url' => $this->quelle],
            'herkunft' => ['wert' => 'Belgien', 'url' => 'https://erfunden.example/x'],       // URL nicht unter den sources
            'hersteller' => ['wert' => "D'Arta"],                                             // ohne URL
        ],
        'allergene' => [
            'gluten' => ['wert' => 'enthalten', 'url' => $this->quelle, 'zitat' => 'Enthält Weizen'],
            'milk' => ['wert' => 'nicht_enthalten', 'url' => $this->quelle],                 // ohne Zitat: nie ableiten
        ],
    ];
    // OpenAI-artiger Provider (zählt als suchfähig wie der FakeAiProvider): $sources === null → Schlüssel fehlt
    $this->provider = function (?array $sources) {
        $p = new class($this->werte, $sources) extends FakeAiProvider {
            public array $optionen = [];

            public function __construct(private array $werte, private ?array $sources) {}

            public function chat(array $messages, array $options = []): array
            {
                $this->optionen[] = $options;
                $antwort = ['content' => json_encode(['werte' => $this->werte, 'confidence' => 0.8]), 'usage' => [], 'model' => 'stub', 'tool_calls' => null];
                if ($this->sources !== null) {
                    $antwort['sources'] = $this->sources;
                    $antwort['web_search_calls'] = [['id' => 'ws_1'], ['id' => 'ws_2']];
                }

                return $antwort;
            }
        };
        app()->instance(LLMProviderContract::class, $p);

        return $p;
    };
    $this->zaehle = fn () => [
        'items' => DB::table('foodalchemist_supplier_items')->count(),
        'allergene' => DB::table('foodalchemist_item_allergens')->count(),
        'item' => (array) DB::table('foodalchemist_supplier_items')->where('id', $this->la->id)->first(),
    ];
});

it('der Produktiv-Transport ist der Core-Weg', function () {
    expect(app(WebSucheTransport::class))->toBeInstanceOf(CoreWebSucheTransport::class);
});

it('★ Quellen vorhanden: nur belegte Angaben kommen durch, nichts wird gespeichert; Aufruf mit include_web_search und Klasse stark', function () {
    $p = ($this->provider)([['url' => $this->quelle, 'title' => "D'Arta", 'start_index' => 0, 'end_index' => 10]]);
    $vorher = ($this->zaehle)();

    $r = app(ArtikelWebRecherche::class)->liefere($this->la);

    expect(array_keys($r['felder']))->toBe(['zutatenliste'])                      // fremde URL + ohne URL verworfen
        ->and(array_keys($r['allergene']))->toBe(['gluten'])                       // nicht_enthalten ohne Zitat verworfen
        ->and(array_column($r['quellen'], 'url'))->toBe([$this->quelle])
        ->and(($this->zaehle)())->toBe($vorher)                                    // keine Auto-Speicherung
        ->and($p->optionen)->toHaveCount(1)
        ->and($p->optionen[0]['include_web_search'] ?? null)->toBeTrue()
        ->and($p->optionen[0]['model'] ?? null)->toBe(KiKlassen::modell(KiKlassen::STARK));

    // Kosten sichtbar: Zahl der Quellen und Suchaufrufe im Call-Log
    $teile = json_decode((string) DB::table('foodalchemist_ai_call_log')->where('feature', 'artikel.web_recherche')->value('prompt_parts'), true);
    expect($teile['websuche'] ?? null)->toBe(['quellen' => 1, 'aufrufe' => 2]);
});

it('sources = [] (gesucht, nichts gefunden) → „Keine belegbaren Quellen“, keine Werte', function () {
    ($this->provider)([]);

    expect(fn () => app(ArtikelWebRecherche::class)->liefere($this->la))
        ->toThrow(RuntimeException::class, 'Keine belegbaren Quellen im Web gefunden.');
});

it('sources fehlt (Core vor #935) → „Websuche nicht verfügbar … Core-Update nötig“, auch wenn das Modell Werte mit URL erfindet', function () {
    ($this->provider)(null);

    expect(fn () => app(ArtikelWebRecherche::class)->liefere($this->la))
        ->toThrow(RuntimeException::class, 'Websuche nicht verfügbar: der Core liefert keine Quellen');
});

it('Provider ohne Websuche (Anthropic-Weg) → „Websuche nicht verfügbar“, der Provider wird gar nicht aufgerufen', function () {
    $p = new class implements LLMProviderContract {
        public int $aufrufe = 0;

        public function getName(): string { return 'anthropic-stub'; }

        public function chat(array $messages, array $options = []): array
        {
            $this->aufrufe++;

            return ['content' => '{}', 'usage' => [], 'model' => 'x', 'tool_calls' => null, 'sources' => []];
        }

        public function streamChat(array $messages, callable $onDelta, array $options = []): void {}

        public function getAvailableModels(): array { return ['x']; }

        public function getDefaultModel(): string { return 'x'; }

        public function isAvailable(): bool { return true; }
    };
    app()->instance(LLMProviderContract::class, $p);

    expect(fn () => app(ArtikelWebRecherche::class)->liefere($this->la))
        ->toThrow(RuntimeException::class, 'Websuche nicht verfügbar: der aktive KI-Provider')
        ->and($p->aufrufe)->toBe(0)
        ->and(DB::table('foodalchemist_ai_call_log')->count())->toBe(0);
});

it('AiProposal->quellen: ohne Flag und ohne sources null, mit sources die Liste', function () {
    ($this->provider)(null);
    expect(app(AiGatewayService::class)->propose('demo.echo')->quellen)->toBeNull();

    ($this->provider)([['url' => $this->quelle, 'title' => 't', 'start_index' => 0, 'end_index' => 1]]);
    expect(app(AiGatewayService::class)->propose('demo.echo')->quellen)->toBe([['url' => $this->quelle, 'title' => 't', 'start_index' => 0, 'end_index' => 1]]);
});
