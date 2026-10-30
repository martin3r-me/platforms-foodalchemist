<?php

use Illuminate\Support\Facades\DB;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistProduktpass;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\Ai\KiAuftragService;
use Platform\FoodAlchemist\Services\Websuche\CoreWebSucheTransport;
use Platform\FoodAlchemist\Services\Websuche\WebSucheTransport;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * #1002 · Paket 2d-2: Websuche per MCP. produktpass.RECHERCHE → KI-Auftrag mit web_suche=true; der MCP-Client sucht selbst und antwortet mit werte
 * UND quellen; eine Antwort ohne http(s)-Quelle wird abgelehnt (nichts gespeichert). Die Quellen kommen als `sources` im Vorschlag an, jede Angabe
 * der Produktdaten verweist auf eine dieser URLs (ArtikelWebRecherche unverändert).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    config(['foodalchemist.ai.provider' => 'fake', 'foodalchemist.ai.backoff' => [], 'foodalchemist.ai.mcp_extern' => true]);
    $this->app->bind(WebSucheTransport::class, fn () => $this->app->make(CoreWebSucheTransport::class));
    $this->registry = app(ToolRegistry::class);
    $this->mcp = new ToolContext($this->user, $this->rootTeam, ['team_source' => 'user_current']);
    $lief = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => "D'Arta"]);
    $this->la = FoodAlchemistSupplierItem::create(['team_id' => $this->rootTeam->id, 'supplier_id' => $lief->id, 'article_number' => 'P15003F5E', 'designation' => 'Salat, Bella Caio Tk']);
    $this->recherche = fn () => $this->registry->get('foodalchemist.produktpass.RECHERCHE')->execute(['supplier_item_id' => $this->la->id], $this->mcp);
    $this->antwort = ['felder' => ['herkunft' => ['wert' => 'Belgien', 'konfidenz' => 0.9, 'url' => 'https://shop.example/caprese']]];
    $this->quellen = [['url' => 'https://shop.example/caprese', 'title' => 'Shop']];
    $this->beantworte = fn (array $item) => $this->registry->get('foodalchemist.ki_auftraege.ANTWORT')->execute(['anbieter' => 'anthropic', 'modell' => 'claude-opus-5-5', 'antworten' => [$item]], $this->mcp);
});

it('RECHERCHE per MCP: Auftrag mit web_suche=true statt Plattform-Websuche; ki_auftraege.GET kennzeichnet ihn', function () {
    $r = ($this->recherche)();
    expect($r->success)->toBeTrue($r->error ?? '')->and($r->data['status'])->toBe('ki_auftrag_offen')->and($r->data['auftraege'][0]['feature'])->toBe('artikel.web_recherche')
        ->and(FoodAlchemistProduktpass::count())->toBe(0);
    $offen = $this->registry->get('foodalchemist.ki_auftraege.GET')->execute([], $this->mcp)->data['auftraege'];

    expect($offen)->toHaveCount(1)->and($offen[0]['web_suche'])->toBeTrue();
});

it('Antwort OHNE Quellen auf einen Websuche-Auftrag wird abgelehnt, nichts gespeichert, der Auftrag bleibt offen', function (mixed $quellen) {
    ($this->recherche)();
    $id = DB::table('foodalchemist_ki_auftraege')->value('uuid');

    $res = ($this->beantworte)(['auftrag_id' => $id, 'werte' => ['werte' => $this->antwort, 'confidence' => 0.8, 'reasoning' => 'x']] + ($quellen === null ? [] : ['quellen' => $quellen]));

    expect($res->data['ergebnisse'][0]['status'])->toBe('abgelehnt')->and($res->data['ergebnisse'][0]['grund'])->toContain('Quellen')
        ->and(DB::table('foodalchemist_ki_auftraege')->value('status'))->toBe('offen');
})->with([
    'fehlt' => [null],
    'leer' => [[]],
    'keine URL' => [[['url' => 'Shop', 'title' => 'x']]],
    'kein http' => [[['url' => 'ftp://shop.example/x']]],
]);

it('Antwort MIT Quellen: Vorschlag aus belegten Quellen, sources im Vorschlag, Wiederholung legt den Produktpass an', function () {
    ($this->recherche)();
    $id = DB::table('foodalchemist_ki_auftraege')->value('uuid');
    $res = ($this->beantworte)(['auftrag_id' => $id, 'werte' => ['werte' => $this->antwort, 'confidence' => 0.8, 'reasoning' => 'x'], 'quellen' => $this->quellen]);
    expect($res->data['ergebnisse'][0]['status'])->toBe('ok');

    $r = ($this->recherche)();

    expect($r->success)->toBeTrue($r->error ?? '')->and($r->data['status'])->toBe('gelesen')->and($r->data['felder'])->toBe(['herkunft'])
        ->and($r->data['quellen'])->toBe(['https://shop.example/caprese'])
        ->and(FoodAlchemistProduktpass::count())->toBe(1)
        ->and(FoodAlchemistProduktpass::first()->extrakt['felder']['herkunft']['url'])->toBe('https://shop.example/caprese')
        ->and(json_decode(DB::table('foodalchemist_ai_call_log')->whereNull('error')->orderByDesc('id')->value('prompt_parts'), true)['quelle'])->toBe('mcp');
});

it('Quellen in der Antwort: eine Angabe mit URL außerhalb der gemeldeten Quellen fällt weg (ArtikelWebRecherche unverändert)', function () {
    ($this->recherche)();
    $id = DB::table('foodalchemist_ki_auftraege')->value('uuid');
    ($this->beantworte)(['auftrag_id' => $id, 'werte' => ['werte' => $this->antwort + ['felder2' => 1], 'confidence' => 0.8, 'reasoning' => 'x'] + ['felder' => [
        'herkunft' => ['wert' => 'Belgien', 'url' => 'https://shop.example/caprese'], 'marke' => ['wert' => 'Bella Ciao', 'url' => 'https://erfunden.example/x']]], 'quellen' => $this->quellen]);

    $r = ($this->recherche)();

    expect($r->data['felder'])->toBe(['herkunft']);
});

it('KI-fremde Aufträge brauchen keine Quellen (Regel nur für Websuche-Aufträge)', function () {
    DB::table('foodalchemist_ki_auftraege')->insert(['uuid' => 'u-1', 'team_id' => $this->rootTeam->id, 'feature' => 'gp.sensorik', 'messages' => '[]', 'messages_hash' => 'h',
        'optionen' => '{"max_versuche":1}', 'status' => 'offen', 'laeuft_ab_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);

    app(KiAuftragService::class)->beantworte($this->rootTeam->id, 'u-1', ['werte' => []], 'anthropic', 'x');

    expect(DB::table('foodalchemist_ki_auftraege')->where('uuid', 'u-1')->value('status'))->toBe('beantwortet');
});

it('Ein Provider, der nicht im Web sucht: extern trotzdem ein Auftrag (der MCP-Client sucht selbst), auf der Plattform die Meldung „Websuche nicht verfügbar"', function () {
    config(['foodalchemist.ai.provider' => 'core']);
    app()->instance(\Platform\Core\Contracts\LLMProviderContract::class, new class implements \Platform\Core\Contracts\LLMProviderContract
    {
        public function getName(): string { return 'ohne-websuche'; }

        public function chat(array $messages, array $options = []): array { throw new \RuntimeException('Plattform-Provider darf nicht gerufen werden'); }

        public function streamChat(array $messages, callable $onDelta, array $options = []): void {}

        public function getAvailableModels(): array { return []; }

        public function getDefaultModel(): string { return 'x'; }

        public function isAvailable(): bool { return true; }
    });

    $extern = ($this->recherche)();
    expect($extern->success)->toBeTrue($extern->error ?? '')->and($extern->data['status'])->toBe('ki_auftrag_offen');

    $intern = $this->registry->get('foodalchemist.produktpass.RECHERCHE')->execute(['supplier_item_id' => $this->la->id], new ToolContext($this->user, $this->rootTeam));
    expect($intern->success)->toBeFalse()->and($intern->error)->toContain('Websuche nicht verfügbar');
});
