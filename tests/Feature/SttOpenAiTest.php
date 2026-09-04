<?php

use Illuminate\Support\Facades\Http;
use Platform\FoodAlchemist\Services\Stt\AssemblyAiSttService;
use Platform\FoodAlchemist\Services\Stt\FakeSttService;
use Platform\FoodAlchemist\Services\Stt\OpenAiSttService;
use Platform\FoodAlchemist\Services\Stt\SttServiceContract;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class);

/**
 * Der Sprachbefehl lief auf demo technisch fehlerfrei und antwortete trotzdem immer
 * dasselbe: `stt.provider` stand auf 'fake', also wurde JEDE Aufnahme durch den Fixtext
 * »Suche BBQ Sauce« ersetzt. Ein Standard, der stillschweigend Testdaten liefert, ist die
 * schlechteste Wahl — er sieht wie ein Feature aus. Diese Tests pinnen die Auflösung.
 */
it('auto wählt OpenAI, sobald der Plattform-Schlüssel da ist', function () {
    config(['foodalchemist.stt.provider' => 'auto', 'services.openai.api_key' => 'sk-test']);

    expect(app(SttServiceContract::class))->toBeInstanceOf(OpenAiSttService::class);
});

it('auto fällt auf AssemblyAI, wenn nur dessen Schlüssel da ist', function () {
    config([
        'foodalchemist.stt.provider' => 'auto',
        'services.openai.api_key' => '',
        'foodalchemist.stt.key' => 'aai-test',
    ]);

    expect(app(SttServiceContract::class))->toBeInstanceOf(AssemblyAiSttService::class);
});

it('auto bleibt Fake ohne jeden Zugang — die Testumgebung macht kein echtes HTTP', function () {
    config([
        'foodalchemist.stt.provider' => 'auto',
        'services.openai.api_key' => '',
        'foodalchemist.stt.key' => '',
    ]);

    expect(app(SttServiceContract::class))->toBeInstanceOf(FakeSttService::class);
});

it('eine explizite Wahl gewinnt über die Auto-Erkennung', function () {
    config(['foodalchemist.stt.provider' => 'fake', 'services.openai.api_key' => 'sk-test']);

    expect(app(SttServiceContract::class))->toBeInstanceOf(FakeSttService::class);
});

it('schickt Modell, Sprache und Vokabular-Hinweis mit — und gibt den Text zurück', function () {
    config([
        'services.openai.api_key' => 'sk-test',
        'foodalchemist.stt.model' => 'gpt-4o-mini-transcribe',
        'foodalchemist.stt.vokabular_prompt' => 'Basisrezept, Grundprodukt',
    ]);
    Http::fake(['api.openai.com/*' => Http::response(['text' => 'Öffne das Basisrezept Tomatensauce'], 200)]);

    $text = (new OpenAiSttService())->transcribe('BINARY-OPUS', 'audio/webm;codecs=opus');

    expect($text)->toBe('Öffne das Basisrezept Tomatensauce');

    Http::assertSent(function ($request) {
        $felder = collect($request->data())->keyBy('name')->map(fn ($f) => $f['contents']);

        // Der Vokabular-Hinweis ist der Qualitätshebel für deutsche Fachbegriffe —
        // fehlt er, wird aus „Grundprodukt" ein „Grund Produkt".
        return $request->url() === 'https://api.openai.com/v1/audio/transcriptions'
            && $request->hasHeader('Authorization', 'Bearer sk-test')
            && $felder['model'] === 'gpt-4o-mini-transcribe'
            && $felder['language'] === 'de'
            && $felder['prompt'] === 'Basisrezept, Grundprodukt';
    });
});

it('leitet die Datei-Endung aus dem Mime ab — OpenAI erkennt das Format am Namen', function () {
    config(['services.openai.api_key' => 'sk-test']);
    Http::fake(['api.openai.com/*' => Http::response(['text' => 'ok'], 200)]);

    (new OpenAiSttService())->transcribe('BINARY', 'audio/mpeg');

    Http::assertSent(fn ($request) => collect($request->data())
        ->contains(fn ($f) => $f['name'] === 'file' && str_ends_with((string) ($f['filename'] ?? ''), '.mp3')));
});

it('meldet Fehler verständlich statt eine Ausnahme durchzureichen', function () {
    config(['services.openai.api_key' => 'sk-test']);
    Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'model_not_found']], 404)]);

    expect(fn () => (new OpenAiSttService())->transcribe('BINARY'))
        ->toThrow(RuntimeException::class, 'model_not_found');
});

it('ohne Zugang und ohne Aufnahme: klare Meldung, kein HTTP', function () {
    Http::fake();

    config(['services.openai.api_key' => '']);
    expect(fn () => (new OpenAiSttService())->transcribe('BINARY'))
        ->toThrow(RuntimeException::class, 'services.openai.api_key');

    config(['services.openai.api_key' => 'sk-test']);
    expect(fn () => (new OpenAiSttService())->transcribe(''))
        ->toThrow(RuntimeException::class, 'leer');

    Http::assertNothingSent();
});

/*
 * LIVE gegen demo beobachtet: bei 1 s Stille gab gpt-4o-mini-transcribe wörtlich den
 * Vokabular-Hinweis als Transkript zurück (bekanntes Whisper-Verhalten ohne Sprachsignal).
 * Ungefiltert hätte der Tool-Loop die Vokabelliste als Befehl bekommen. Kein Fake-HTTP-Test
 * hätte das gefunden — nur der echte Durchlauf.
 *
 * Vier Fälle in EIGENEN Tests, nicht in einem: mehrere `Http::fake()`-Aufrufe im selben
 * Test ergänzen die Stubs, und der ERSTE passende gewinnt — alle Fälle bekämen dieselbe
 * Antwort. (Selbst hineingelaufen.)
 */
const ECHO_HINWEIS = 'Küchen-Befehle. Fachbegriffe: Basisrezept, Grundprodukt, Speiseplan.';

function sttMit(string $antwortText): string
{
    config([
        'services.openai.api_key' => 'sk-test',
        'foodalchemist.stt.vokabular_prompt' => ECHO_HINWEIS,
    ]);
    Http::fake(['api.openai.com/*' => Http::response(['text' => $antwortText], 200)]);

    return (new OpenAiSttService())->transcribe('X');
}

it('Prompt-Echo: das Transkript IST der Hinweis ⇒ leer, kein Befehl aus Stille', function () {
    expect(sttMit(ECHO_HINWEIS))->toBe('');
});

it('Prompt-Echo mit echter Sprache dahinter ⇒ nur die Sprache bleibt', function () {
    expect(sttMit(ECHO_HINWEIS . ' Öffne Rezept 42'))->toBe('Öffne Rezept 42');
});

it('abgeschnittenes Echo (Anfangsstück des Hinweises) ⇒ leer', function () {
    expect(sttMit('Küchen-Befehle. Fachbegriffe: Basisrezept, Grund'))->toBe('');
});

it('ein echter Befehl bleibt unangetastet — der Riegel darf nicht überfiltern', function () {
    expect(sttMit('Suche Basisrezept Tomatensauce'))->toBe('Suche Basisrezept Tomatensauce');
});

it('auch ein KURZER Befehl mit Fachwort aus dem Hinweis übersteht den Riegel', function () {
    // Ein Befehl darf nie als Echo gelten, nur weil er ein Vokabel-Wort enthält.
    expect(sttMit('Basisrezept anlegen'))->toBe('Basisrezept anlegen');
});
