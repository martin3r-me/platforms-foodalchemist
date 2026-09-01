<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Platform\Core\Services\ImageGenerationService;
use Platform\Core\Tools\GenerateImageTool;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    config()->set('services.openai.api_key', 'test-key');
});

it('sendet fuer GPT Image standardmaessig die unterstuetzte Qualitaet medium', function () {
    Http::fake([
        'api.openai.com/*' => Http::response([
            'data' => [[
                'b64_json' => base64_encode('image'),
                'revised_prompt' => 'Testbild',
            ]],
        ]),
    ]);

    (new ImageGenerationService())->generate('Ein Testbild');

    Http::assertSent(fn (Request $request): bool => $request['model'] === 'gpt-image-1.5'
        && $request['quality'] === 'medium');
});

it('weist die veraltete Qualitaet standard vor dem API-Aufruf zurueck', function () {
    Http::fake();

    expect(fn () => (new ImageGenerationService())->generate('Ein Testbild', ['quality' => 'standard']))
        ->toThrow(InvalidArgumentException::class, 'Erlaubt: low, medium, high, auto');

    Http::assertNothingSent();
});

it('veroeffentlicht im Bild-Tool nur die unterstuetzten GPT-Image-Qualitaeten', function () {
    $quality = (new GenerateImageTool())->getSchema()['properties']['quality'];

    expect($quality['enum'])->toBe(['low', 'medium', 'high', 'auto'])
        ->and($quality['description'])->toContain('Standard: medium');
});
