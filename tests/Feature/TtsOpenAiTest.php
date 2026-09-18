<?php

use Illuminate\Support\Facades\Http;
use Platform\FoodAlchemist\Services\Tts\FakeTtsService;
use Platform\FoodAlchemist\Services\Tts\OpenAiTtsService;
use Platform\FoodAlchemist\Services\Tts\TtsServiceContract;
use Platform\FoodAlchemist\Services\Tts\UnkonfiguriertTtsService;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class);

/**
 * Spec 53 / Paket F (3): TTS-Fassade — Spiegel von {@see \Tests\Feature\SttOpenAiTest}
 * (gleiches auto/fake/Binding-Prinzip, ein Provider weniger — kein AssemblyAI-Pendant).
 */
it('auto wählt OpenAI, sobald der Plattform-Schlüssel da ist', function () {
    config(['foodalchemist.tts.provider' => 'auto', 'services.openai.api_key' => 'sk-test']);

    expect(app(TtsServiceContract::class))->toBeInstanceOf(OpenAiTtsService::class);
});

it('auto bleibt Fake ohne Zugang — die Testumgebung macht kein echtes HTTP', function () {
    config(['foodalchemist.tts.provider' => 'auto', 'services.openai.api_key' => '']);

    expect(app(TtsServiceContract::class))->toBeInstanceOf(FakeTtsService::class);
});

it('eine explizite Wahl gewinnt über die Auto-Erkennung', function () {
    config(['foodalchemist.tts.provider' => 'fake', 'services.openai.api_key' => 'sk-test']);

    expect(app(TtsServiceContract::class))->toBeInstanceOf(FakeTtsService::class);
});

it('Binding-Matrix: Fake ausserhalb testing/local ohne allow_fake bindet Unkonfiguriert', function () {
    app()['env'] = 'production';
    config(['foodalchemist.tts.provider' => 'fake', 'foodalchemist.tts.allow_fake' => false]);

    expect(app(TtsServiceContract::class))->toBeInstanceOf(UnkonfiguriertTtsService::class);

    app()['env'] = 'testing';
});

it('Binding-Matrix: allow_fake=true erlaubt Fake auch ausserhalb testing/local', function () {
    app()['env'] = 'production';
    config(['foodalchemist.tts.provider' => 'fake', 'foodalchemist.tts.allow_fake' => true]);

    expect(app(TtsServiceContract::class))->toBeInstanceOf(FakeTtsService::class);

    app()['env'] = 'testing';
});

it('Binding-Matrix: auto ohne Zugang ausserhalb testing/local bindet ebenfalls Unkonfiguriert', function () {
    app()['env'] = 'production';
    config(['foodalchemist.tts.provider' => 'auto', 'services.openai.api_key' => '', 'foodalchemist.tts.allow_fake' => false]);

    expect(app(TtsServiceContract::class))->toBeInstanceOf(UnkonfiguriertTtsService::class);

    app()['env'] = 'testing';
});

it('name() identifiziert jeden Provider', function () {
    expect((new OpenAiTtsService())->name())->toBe('openai')
        ->and((new FakeTtsService())->name())->toBe('fake')
        ->and((new UnkonfiguriertTtsService())->name())->toBe('none');
});

it('mimeType() ist audio/mpeg bei allen drei Providern', function () {
    expect((new OpenAiTtsService())->mimeType())->toBe('audio/mpeg')
        ->and((new FakeTtsService())->mimeType())->toBe('audio/mpeg')
        ->and((new UnkonfiguriertTtsService())->mimeType())->toBe('audio/mpeg');
});

it('UnkonfiguriertTtsService wirft eine klare Meldung statt zu schweigen', function () {
    expect(fn () => (new UnkonfiguriertTtsService())->synthesize('Hallo'))
        ->toThrow(RuntimeException::class, 'nicht konfiguriert');
});

it('schickt Modell, Stimme und Text — und gibt die rohen Audio-Bytes zurück', function () {
    config(['services.openai.api_key' => 'sk-test', 'foodalchemist.tts.model' => 'gpt-4o-mini-tts', 'foodalchemist.tts.voice' => 'alloy']);
    Http::fake(['api.openai.com/*' => Http::response('FAKE-MP3-BYTES', 200)]);

    $audio = (new OpenAiTtsService())->synthesize('Das Basisrezept ist gespeichert.');

    expect($audio)->toBe('FAKE-MP3-BYTES');
    Http::assertSent(fn ($request) => $request->url() === 'https://api.openai.com/v1/audio/speech'
        && $request->hasHeader('Authorization', 'Bearer sk-test')
        && $request['model'] === 'gpt-4o-mini-tts'
        && $request['voice'] === 'alloy'
        && $request['input'] === 'Das Basisrezept ist gespeichert.'
        && $request['response_format'] === 'mp3');
});

it('eine übergebene Stimme überschreibt den Config-Default', function () {
    config(['services.openai.api_key' => 'sk-test', 'foodalchemist.tts.voice' => 'alloy']);
    Http::fake(['api.openai.com/*' => Http::response('X', 200)]);

    (new OpenAiTtsService())->synthesize('Text', 'nova');

    Http::assertSent(fn ($request) => $request['voice'] === 'nova');
});

it('kappt Text über 350 Zeichen statt ihn ungekürzt vorzulesen', function () {
    config(['services.openai.api_key' => 'sk-test']);
    Http::fake(['api.openai.com/*' => Http::response('X', 200)]);

    (new OpenAiTtsService())->synthesize(str_repeat('a', 500));

    Http::assertSent(fn ($request) => mb_strlen($request['input']) === OpenAiTtsService::MAX_ZEICHEN);
});

it('ohne Zugang und ohne Text: klare Meldung, kein HTTP', function () {
    Http::fake();

    config(['services.openai.api_key' => '']);
    expect(fn () => (new OpenAiTtsService())->synthesize('Hallo'))
        ->toThrow(RuntimeException::class, 'services.openai.api_key');

    config(['services.openai.api_key' => 'sk-test']);
    expect(fn () => (new OpenAiTtsService())->synthesize('   '))
        ->toThrow(RuntimeException::class, 'Kein Text');

    Http::assertNothingSent();
});

it('meldet Fehler verständlich statt eine Ausnahme durchzureichen', function () {
    config(['services.openai.api_key' => 'sk-test']);
    Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'model_not_found']], 404)]);

    expect(fn () => (new OpenAiTtsService())->synthesize('Hallo'))
        ->toThrow(RuntimeException::class, 'model_not_found');
});
