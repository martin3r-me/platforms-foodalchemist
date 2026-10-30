<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistTrendBeleg;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * SSRF-Loch in `datei.url` (fundstuecke.POST, trend_belege.POST, trends.POST, produktpass.POST über
 * DateiAusArgumenten): eine URL ins interne Netz wird abgelehnt, ohne dass eine Anfrage rausgeht und ohne dass
 * etwas angelegt wird. Öffentliche Adressen laden weiter.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->inhaber = $this->makeUser($this->rootTeam, 'Inhaber SSRF');
    $this->actingAs($this->inhaber);
    $this->ctx = new ToolContext($this->inhaber, $this->rootTeam);
    $this->reg = app(ToolRegistry::class);
    Http::preventStrayRequests();
});

it('fundstuecke.POST mit datei.url auf eine private Adresse wird abgelehnt', function (string $url) {
    Http::fake();

    $r = $this->reg->get('foodalchemist.fundstuecke.POST')->execute(['titel' => 'TEST-SSRF', 'datei' => ['url' => $url]], $this->ctx);

    expect($r->success)->toBeFalse()->and($r->errorCode)->toBe('VALIDATION_ERROR')
        ->and($r->error)->toContain('interne Netz');
    Http::assertNothingSent();
    expect(FoodAlchemistTrendBeleg::where('titel', 'TEST-SSRF')->exists())->toBeFalse();
})->with([
    'http://127.0.0.1/bild.png',
    'http://localhost/bild.png',
    'http://169.254.169.254/latest/meta-data/iam/',
    'http://10.0.0.5/bild.png',
    'http://[::1]/bild.png',
]);

it('fundstuecke.POST folgt keiner Weiterleitung ins interne Netz', function () {
    Http::fake([
        'https://93.184.216.34/*' => Http::response('', 302, ['Location' => 'http://127.0.0.1/geheim.png']),
        '*' => Http::response('geheim', 200, ['Content-Type' => 'image/png']),
    ]);

    $r = $this->reg->get('foodalchemist.fundstuecke.POST')->execute(['titel' => 'TEST-SSRF', 'datei' => ['url' => 'https://93.184.216.34/bild.png']], $this->ctx);

    expect($r->success)->toBeFalse()->and($r->errorCode)->toBe('VALIDATION_ERROR');
    Http::assertSentCount(1);
    expect(FoodAlchemistTrendBeleg::where('titel', 'TEST-SSRF')->exists())->toBeFalse();
});

it('trend_belege.POST und trends.POST lehnen dieselbe URL ab', function () {
    Http::fake();
    $trend = $this->reg->get('foodalchemist.trends.POST')->execute(['name' => 'TEST-SSRF Trend'], $this->ctx);
    expect($trend->success)->toBeTrue();

    $beleg = $this->reg->get('foodalchemist.trend_belege.POST')->execute(['trend_id' => $trend->data['id'], 'quelle' => 'presse',
        'datei' => ['url' => 'http://127.0.0.1/x.pdf']], $this->ctx);
    $neu = $this->reg->get('foodalchemist.trends.POST')->execute(['name' => 'TEST-SSRF Zwei', 'datei' => ['url' => 'http://10.0.0.1/x.png']], $this->ctx);

    expect($beleg->errorCode)->toBe('VALIDATION_ERROR')->and($neu->errorCode)->toBe('VALIDATION_ERROR');
    Http::assertNothingSent();
});

it('öffentliche datei.url lädt weiter', function () {
    Storage::fake();
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
    Http::fake(['https://93.184.216.34/*' => Http::response($png, 200, ['Content-Type' => 'image/png'])]);

    $r = $this->reg->get('foodalchemist.fundstuecke.POST')->execute(['titel' => 'TEST-SSRF ok',
        'datei' => ['url' => 'https://93.184.216.34/matcha.png']], $this->ctx);

    expect($r->success)->toBeTrue()->and($r->data['datei'])->toBe('matcha.png');
});
