<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Platform\Core\Contracts\LLMProviderContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Livewire\Observatorium\Index;
use Platform\FoodAlchemist\Models\FoodAlchemistMarktdaten as Daten;
use Platform\FoodAlchemist\Models\FoodAlchemistMarktdatenEinordnung as Einordnung;
use Platform\FoodAlchemist\Services\Ai\FakeAiProvider;
use Platform\FoodAlchemist\Services\MarktdatenAnsicht as A;
use Platform\FoodAlchemist\Services\MarktdatenService;
use Platform\FoodAlchemist\Services\MarktEinordnungService as E;
use Platform\FoodAlchemist\Services\Trends\GenesisClient;
use Platform\FoodAlchemist\Support\KiUmleitung;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Markt in Zahlen — Ansicht (Kacheln, Balken, Schere) und KI-Einordnung. Grundsatz: ein Element nur bei vollständigen Daten.
 * Alle Werte erfunden, Testdaten „TEST-MA“.
 */

/** Reihe mit $n Monaten bis $bis; Wert steigt je Monat um $schritt (Startwert 100). $luecke = Monat ohne Wert. */
function maReihe(int $teamId, string $serie, int $n = 26, string $bis = '2026-09', float $schritt = 0.4, ?string $luecke = null, string $basis = '2021=100'): void
{
    $punkte = [];
    for ($i = $n - 1; $i >= 0; $i--) {
        $m = A::monatZurueck($bis, $i);
        $punkte[] = ['monat' => $m, 'wert' => $m === $luecke ? null : round(100 + ($n - 1 - $i) * $schritt, 1), 'einheit' => $basis, 'original' => []];
    }
    Daten::updateOrCreate(['team_id' => $teamId, 'serie' => $serie], ['punkte' => $punkte, 'abgerufen_at' => now(), 'versucht_at' => now()]);
}

beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->owner = $this->makeUser($this->rootTeam, 'TEST-MA Inhaber');
    $this->actingAs($this->owner);
    $this->travelTo(Carbon::parse('2026-11-05 09:00:00 UTC'));
    config(['foodalchemist.ai.provider' => 'core', 'foodalchemist.ai.backoff' => [], 'foodalchemist.ai.mcp_extern' => true,
        'foodalchemist.marktdaten.genesis_token' => 'TEST-KEIN-ECHTER-TOKEN']);
    Http::preventStrayRequests();
    $this->svc = app(MarktdatenService::class);
    $this->stub = new class extends FakeAiProvider
    {
        public int $calls = 0;

        public array $saetze = [];

        public function chat(array $messages, array $options = []): array
        {
            $this->calls++;

            return ['content' => json_encode(['werte' => ['saetze' => $this->saetze], 'confidence' => 0.9, 'reasoning' => 'stub']), 'usage' => [], 'model' => 'stub-1', 'tool_calls' => null];
        }
    };
    app()->instance(LLMProviderContract::class, $this->stub);
    $t = $this->rootTeam->id;
    $this->voll = function () use ($t) {
        maReihe($t, 'grosshandel_nahrungsmittel', schritt: 0.5);
        maReihe($t, 'gaststaettenpreise', basis: '2020=100', schritt: 0.3);
        maReihe($t, 'kantinenpreise', basis: '2020=100', schritt: 0.2);
        maReihe($t, 'caterer_umsatz_real', bis: '2026-08', schritt: -0.1);
        maReihe($t, 'caterer_umsatz_nominal', bis: '2026-08', schritt: 0.2);
        foreach (['grosshandel_fleisch' => 0.9, 'grosshandel_getraenke' => -0.3, 'grosshandel_milch' => 0.03] as $s => $sch) {
            maReihe($t, $s, schritt: $sch);
        }
    };
});

it('Ansicht vollständig: 4 Kacheln mit Sparkline und Farbe nach Bedeutung, Balken sortiert, Schere über 12 gemeinsame Monate', function () {
    ($this->voll)();
    $a = $this->svc->lesen($this->rootTeam)['ansicht'];
    expect(array_column($a['kacheln'], 'serie'))->toBe(['gaststaettenpreise', 'grosshandel_nahrungsmittel', 'kantinenpreise', 'caterer_umsatz_real'])
        ->and(collect($a['kacheln'])->firstWhere('serie', 'grosshandel_nahrungsmittel')['farbe'])->toBe('schlecht')   // Einkauf teurer = rot
        ->and(collect($a['kacheln'])->firstWhere('serie', 'gaststaettenpreise')['farbe'])->toBe('neutral')
        ->and(collect($a['kacheln'])->firstWhere('serie', 'caterer_umsatz_real')['farbe'])->toBe('schlecht')        // Umsatz real sinkt
        ->and(collect($a['kacheln'])->firstWhere('serie', 'caterer_umsatz_real')['monat'])->toBe('2026-08')
        ->and(substr_count($a['kacheln'][0]['sparkline']['punkte'], ','))->toBe(12)
        ->and(array_column($a['balken']['zeilen'], 'serie'))->toBe(['grosshandel_fleisch', 'grosshandel_milch', 'grosshandel_getraenke'])
        ->and(array_column($a['balken']['zeilen'], 'farbe'))->toBe(['schlecht', 'neutral', 'gut'])
        ->and($a['schere']['von'])->toBe('2025-10')->and($a['schere']['bis'])->toBe('2026-09')
        ->and(array_column($a['schere']['linien'], 'strich'))->toBe(['voll', 'gestrichelt', 'gepunktet']);
    $html = Livewire::test(Index::class)->call('ansichtSetzen', 'zahlen')->assertSeeHtml('data-marktdaten-kacheln')->assertSeeHtml('data-marktdaten-balken')
        ->assertSeeHtml('data-marktdaten-schere')->assertSee('Was heißt das für dich?')->assertSeeHtml('data-marktdaten-alle')->html();
    // Die Markt-Sektion selbst kommt ohne style-Attribute aus (Geometrie als SVG-Attribute, Farben über Klassen/Tokens)
    $sektion = substr($html, strpos($html, 'data-observatorium-marktdaten'), strpos($html, '</section>', strpos($html, 'data-observatorium-marktdaten')) - strpos($html, 'data-observatorium-marktdaten'));
    expect($sektion)->not->toContain('style=')->and($sektion)->not->toMatch('/#[0-9a-fA-F]{3,6}\b/');
});

it('unvollständig = ausgeblendet: Lücke in den letzten 12 Monaten, zu wenig Monate, < 3 Warengruppen, anderer Monat', function () {
    $t = $this->rootTeam->id;
    maReihe($t, 'grosshandel_nahrungsmittel', schritt: 0.5, luecke: '2026-03');      // Lücke → keine Kachel, keine Schere
    maReihe($t, 'gaststaettenpreise', n: 14, basis: '2020=100');                       // 14 Monate: Kachel ja (12 lückenlos), Schere braucht 24
    maReihe($t, 'kantinenpreise', basis: '2020=100');
    maReihe($t, 'grosshandel_fleisch', schritt: 0.9);
    maReihe($t, 'grosshandel_milch', schritt: 0.1);
    maReihe($t, 'grosshandel_getraenke', bis: '2026-08', schritt: -0.3);               // anderer Monat → zählt nicht
    $a = $this->svc->lesen($this->rootTeam)['ansicht'];
    expect(array_column($a['kacheln'], 'serie'))->toBe(['gaststaettenpreise', 'kantinenpreise'])
        ->and($a['balken'])->toBeNull()->and($a['schere'])->toBeNull();
    Livewire::test(Index::class)->call('ansichtSetzen', 'zahlen')->assertDontSeeHtml('data-marktdaten-balken')->assertDontSeeHtml('data-marktdaten-schere');
});

it('Riegel: Zahlen nur aus dem Kontext; Prognose/Empfehlung gesperrt, Passiv-„wird“ erlaubt (beide Richtungen)', function () {
    $k = ['kennzahlen' => ['a' => ['name' => 'Großhandel', 'monat' => '2026-09', 'vorjahr_prozent' => '6,2', 'quelle' => 'Destatis 61281-0004']], 'regelsaetze' => []];
    foreach ([
        'Der Großhandel liegt 6,2 % über dem Vorjahresmonat.',
        'Es wird mehr umgesetzt, die Kalkulation wird enger.',
        'Im September 2026 wird der Einkauf teurer bewertet als im Vorjahr.',
    ] as $erlaubt) {
        expect(E::satzErlaubt($erlaubt, $k))->toBeTrue($erlaubt);
    }
    foreach ([
        'Der Großhandel liegt 6,5 % über dem Vorjahr.',
        'Die Preise werden weiter steigen.',
        'Voraussichtlich bleibt der Druck hoch.',
        'In Zukunft bleibt der Einkauf teuer.',
        'Caterer sollten ihre Preise anpassen.',
        'Wir empfehlen, Verträge zu prüfen.',
        'Betriebe müssen nachkalkulieren.',
        'Ein Lieferantenwechsel lohnt sich.',
    ] as $gesperrt) {
        expect(E::satzErlaubt($gesperrt, $k))->toBeFalse($gesperrt);
    }
});

it('Plattform: Abruf mit apply stößt genau eine Einordnung an, Riegel verwirft, Box zeigt KI oben und Regelsätze als Beleg; gleiche Werte → kein zweiter Aufruf', function () {
    ($this->voll)();
    $m = $this->svc->lesenFuerTeam($this->rootTeam);
    $pct = collect($m['reihen'])->firstWhere('serie', 'grosshandel_nahrungsmittel')['vorjahr_prozent'];
    $txt = number_format($pct, 1, ',', '');
    $this->stub->saetze = ["Der Einkauf im Großhandel liegt {$txt} % über dem Vorjahresmonat.", 'Erfunden: 12,3 % mehr Gäste.', 'Die Preise werden weiter steigen.'];

    expect(app(E::class)->nachAbruf($this->rootTeam, $m))->not->toBeNull();
    $e = Einordnung::first();
    expect($this->stub->calls)->toBe(1)->and($e->status)->toBe('fertig')->and($e->saetze)->toBe(["Der Einkauf im Großhandel liegt {$txt} % über dem Vorjahresmonat."])
        ->and($e->verworfen)->toBe(2)->and($e->stand_monat)->toBe('2026-09');
    expect(app(E::class)->nachAbruf($this->rootTeam, $this->svc->lesenFuerTeam($this->rootTeam)))->toBeNull()->and($this->stub->calls)->toBe(1);

    Livewire::test(Index::class)->call('ansichtSetzen', 'zahlen')->assertSeeHtml('data-marktdaten-ki')->assertSee("liegt {$txt} % über dem Vorjahresmonat")
        ->assertSee('KI-Einordnung · Stand 2026-09')->assertSee('Aus den Werten berechnet:')->assertDontSee('Erfunden: 12,3 %');
});

it('KI fällt aus: Einordnung „fehler“, die Box zeigt nur die Regelsätze', function () {
    ($this->voll)();
    app()->instance(LLMProviderContract::class, new class extends FakeAiProvider
    {
        public function chat(array $messages, array $options = []): array
        {
            throw new RuntimeException('TEST-MA Provider weg');
        }
    });
    app(E::class)->nachAbruf($this->rootTeam, $this->svc->lesenFuerTeam($this->rootTeam));
    expect(Einordnung::first()->status)->toBe('fehler');
    Livewire::test(Index::class)->call('ansichtSetzen', 'zahlen')->assertDontSeeHtml('data-marktdaten-ki')->assertSeeHtml('data-marktdaten-aussagen')
        ->assertDontSee('Aus den Werten berechnet:');
});

it('MCP: ABRUF mit apply legt einen KI-Auftrag an (0 Provider-Calls), die Antwort läuft durch dieselben Riegel', function () {
    ($this->voll)();
    expect(KiUmleitung::LAUF_FLAG)->toHaveKey('foodalchemist.marktdaten.ABRUF');
    // Abruf liefert für alle Reihen eine Antwort ohne passende Zeilen (Fehler je Reihe) — außer Restaurantpreise: neuer Wert → neuer Stand
    Http::fake([GenesisClient::ENDPOINT => fn ($r) => Http::response("time;1_variable_attribute_code;2_variable_attribute_code;value;value_unit;value_variable_label\n"
        ."2025;MONAT09;{$r['classifyingkey1']};100,0;2020=100;Verbraucherpreisindex\n2026;MONAT09;{$r['classifyingkey1']};103,1;2020=100;Verbraucherpreisindex\n", 200)]);
    $mcp = new ToolContext($this->owner, $this->rootTeam, ['team_source' => 'user_current']);
    $reg = app(ToolRegistry::class);
    $r = $reg->get('foodalchemist.marktdaten.ABRUF')->execute(['apply' => true, 'reihen' => ['gaststaettenpreise']], $mcp);
    $offen = DB::table('foodalchemist_ki_auftraege')->where('feature', 'markt.einordnung')->where('status', 'offen');
    expect($r->success)->toBeTrue()->and($r->data['einordnung_angestossen'])->toBeTrue()->and($this->stub->calls)->toBe(0)->and($offen->count())->toBe(1);

    $antworten = $offen->get()->map(fn ($a) => ['auftrag_id' => $a->uuid, 'werte' => ['werte' => ['saetze' => [
        'Die Restaurantpreise liegen 3,1 % über dem Vorjahresmonat.', 'Caterer sollten jetzt die Preise erhöhen.',
    ]], 'confidence' => 0.9, 'reasoning' => 'TEST-MA']])->all();
    expect($reg->get('foodalchemist.ki_auftraege.ANTWORT')->execute(['antworten' => $antworten, 'anbieter' => 'anthropic', 'modell' => 'claude-opus-5-5'], $mcp)->success)->toBeTrue();
    $e = Einordnung::where('status', 'fertig')->first();
    expect($e)->not->toBeNull()->and($e->saetze)->toBe(['Die Restaurantpreise liegen 3,1 % über dem Vorjahresmonat.'])->and($e->verworfen)->toBe(1)
        ->and($this->stub->calls)->toBe(0);
});

it('Team-Vererbung: Kinder-Teams sehen die Einordnung des Eltern-Teams, ohne eigenen KI-Aufruf', function () {
    ($this->voll)();
    $this->stub->saetze = ['Die Daten zeigen den Stand 2026-09.'];
    app(E::class)->nachAbruf($this->rootTeam, $this->svc->lesenFuerTeam($this->rootTeam));
    $this->actingAs($this->makeUser($this->childA, 'TEST-MA Kind'));
    expect($this->svc->lesen($this->childA)['einordnung']['saetze'])->toBe(['Die Daten zeigen den Stand 2026-09.'])->and($this->stub->calls)->toBe(1);
});

it('EINORDNEN (MCP): aus gespeicherten Werten ohne Abruf ein KI-Auftrag; gleicher Stand, Kinder-Team und Leser stoßen nichts an', function () {
    ($this->voll)();
    $reg = app(ToolRegistry::class);
    expect(KiUmleitung::LAUF_FLAG)->toHaveKey('foodalchemist.marktdaten.EINORDNEN');
    $mcp = new ToolContext($this->owner, $this->rootTeam, ['team_source' => 'user_current']);
    $r = $reg->get('foodalchemist.marktdaten.EINORDNEN')->execute([], $mcp);
    expect($r->success)->toBeTrue()->and($r->data['angestossen'])->toBeTrue()->and($r->data['stand'])->toBe('2026-09')
        ->and(DB::table('foodalchemist_ki_auftraege')->where('feature', 'markt.einordnung')->where('status', 'offen')->count())->toBe(1)
        ->and($this->stub->calls)->toBe(0);
    Http::assertNothingSent();

    $nochmal = $reg->get('foodalchemist.marktdaten.EINORDNEN')->execute([], $mcp);
    expect($nochmal->data['angestossen'])->toBeFalse()->and($nochmal->data['grund'])->toContain('schon eine Einordnung');
    $kind = $this->makeUser($this->childA, 'TEST-MA Kind');
    $this->actingAs($kind);
    expect($reg->get('foodalchemist.marktdaten.EINORDNEN')->execute([], new ToolContext($kind, $this->childA, ['team_source' => 'user_current']))->data['angestossen'])->toBeFalse();
    $leser = $this->makeUser($this->rootTeam, 'TEST-MA Leser', 'viewer');
    expect($reg->get('foodalchemist.marktdaten.EINORDNEN')->execute([], new ToolContext($leser, $this->rootTeam))->success)->toBeFalse()
        ->and(Einordnung::count())->toBe(1);
});

it('Knopf „Einordnung erzeugen“: nur für Kuratieren und nur ohne Einordnung zum aktuellen Stand; Klick erzeugt sie (Plattform-KI)', function () {
    ($this->voll)();
    $this->stub->saetze = ['Die Daten zeigen den Stand 2026-09.'];
    $leser = $this->makeUser($this->rootTeam, 'TEST-MA Leser', 'viewer');
    $this->actingAs($leser);
    Livewire::test(Index::class)->call('ansichtSetzen', 'zahlen')->assertDontSeeHtml('data-marktdaten-einordnen')->call('einordnungErzeugen')->assertForbidden();
    $this->actingAs($this->owner);
    Livewire::test(Index::class)->call('ansichtSetzen', 'zahlen')->assertSeeHtml('data-marktdaten-einordnen')
        ->call('einordnungErzeugen')->assertSee('Einordnung wird erstellt');
    expect(Einordnung::first()->status)->toBe('fertig')->and($this->stub->calls)->toBe(1);
    Livewire::test(Index::class)->call('ansichtSetzen', 'zahlen')->assertDontSeeHtml('data-marktdaten-einordnen')->assertSeeHtml('data-marktdaten-ki');
    maReihe($this->rootTeam->id, 'gaststaettenpreise', basis: '2020=100', schritt: 0.35);   // neuer Stand → Knopf wieder da
    Livewire::test(Index::class)->call('ansichtSetzen', 'zahlen')->assertSeeHtml('data-marktdaten-einordnen');
});
