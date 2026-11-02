<?php

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Platform\FoodAlchemist\Models\FoodAlchemistTrendBeleg;
use Platform\FoodAlchemist\Models\FoodAlchemistTrendEingang;
use Platform\FoodAlchemist\Models\FoodAlchemistTrendInspiration;
use Platform\FoodAlchemist\Services\TrendEingangService;
use Platform\FoodAlchemist\Services\TrendThemenService;
use Platform\FoodAlchemist\Support\SichererAbruf;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 93 B · Befund io 02.11.2026: drei Einträge gleichzeitig per MCP, zwei Erschließungs-Jobs sahen beide kein Thema
 * „Trendprognose 2027“ und legten es doppelt an. Ein automatisches Thema je Team und Schlüssel (eindeutiger Index über die
 * generierte Spalte auto_schluessel), der verlierende Job hängt sich an das Thema des Gewinners. Testdaten „TEST-93R“.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam, 'Inhaber TEST-93R'));
    Queue::fake();
    Http::preventStrayRequests();
    app()->instance(SichererAbruf::class, new SichererAbruf(fn () => ['93.184.216.34']));
    $this->themen = app(TrendThemenService::class);
    $this->eintrag = function (string $pfad, string $thema): FoodAlchemistTrendEingang {
        $e = app(TrendEingangService::class)->einreichen($this->rootTeam,
            ['url' => 'https://artikel.beispiel.test/'.$pfad, 'titel' => 'TEST-93R '.$pfad, 'teaser' => 'TEST-93R Teaser'], 'mcp')['eintrag'];
        $e->update(['thema' => $thema]);

        return $e->fresh();
    };
    $this->auto = fn () => FoodAlchemistTrendInspiration::where('team_id', $this->rootTeam->id)->where('herkunft', 'automatisch');
});

it('bündelt zwei Einträge mit gleichem Schlüssel nacheinander in ein Thema', function () {
    $a = $this->themen->buendeln(($this->eintrag)('a', 'Trendprognose 2027'));
    $b = $this->themen->buendeln(($this->eintrag)('b', 'trendprognose 2027!'));

    expect($b->id)->toBe($a->id)->and(($this->auto)()->count())->toBe(1)
        ->and(FoodAlchemistTrendBeleg::where('inspiration_id', $a->id)->count())->toBe(2);
});

it('legt bei verschränkter Bündelung kein zweites Thema an, der Verlierer hängt sich an das Thema des Gewinners', function () {
    $verlierer = ($this->eintrag)('verlierer', 'Trendprognose 2027');
    $gewinnerId = null;
    // Der parallele Job legt das Thema genau zwischen first() und create() des Verlierers an (wie auf io).
    DB::listen(function ($q) use (&$gewinnerId) {
        if ($gewinnerId === null && str_starts_with(strtolower(ltrim($q->sql)), 'select')
            && str_contains($q->sql, 'foodalchemist_trend_inspirationen') && str_contains($q->sql, 'thema_schluessel')) {
            $gewinnerId = 0;
            $gewinnerId = FoodAlchemistTrendInspiration::create(['team_id' => $this->rootTeam->id, 'titel' => 'Trendprognose 2027',
                'herkunft' => 'automatisch', 'thema_schluessel' => TrendThemenService::schluessel('Trendprognose 2027')])->id;
        }
    });

    $thema = $this->themen->buendeln($verlierer);

    expect($gewinnerId)->toBeGreaterThan(0)
        ->and(($this->auto)()->count())->toBe(1)
        ->and($thema->id)->toBe($gewinnerId)
        ->and($verlierer->fresh()->thema_inspiration_id)->toBe($gewinnerId)
        ->and(FoodAlchemistTrendBeleg::where('inspiration_id', $gewinnerId)->count())->toBe(1);
});

it('erzwingt den Schlüssel nur für lebende automatische Themen', function () {
    $basis = ['team_id' => $this->rootTeam->id, 'titel' => 'Trendprognose 2027', 'thema_schluessel' => 'trendprognose 2027'];
    FoodAlchemistTrendInspiration::create($basis + ['herkunft' => 'manuell']);
    FoodAlchemistTrendInspiration::create($basis + ['herkunft' => 'manuell']);
    $auto = FoodAlchemistTrendInspiration::create($basis + ['herkunft' => 'automatisch']);

    expect(fn () => FoodAlchemistTrendInspiration::create($basis + ['herkunft' => 'automatisch']))
        ->toThrow(UniqueConstraintViolationException::class);

    $auto->delete();
    FoodAlchemistTrendInspiration::create($basis + ['herkunft' => 'automatisch']);
    expect(($this->auto)()->count())->toBe(1)
        ->and(FoodAlchemistTrendInspiration::where('team_id', $this->rootTeam->id)->where('herkunft', 'manuell')->count())->toBe(2);
});

it('führt Bestands-Dubletten in der Migration auf das älteste Thema zusammen', function () {
    $migration = require dirname(__DIR__, 2).'/database/migrations/2026_12_02_100100_add_auto_thema_unique_to_foodalchemist_trend_inspirationen.php';
    $migration->down();
    $basis = ['team_id' => $this->rootTeam->id, 'titel' => 'Trendprognose 2027', 'herkunft' => 'automatisch', 'thema_schluessel' => 'trendprognose 2027'];
    $alt = FoodAlchemistTrendInspiration::create($basis);
    $neu = FoodAlchemistTrendInspiration::create($basis);
    $e = ($this->eintrag)('dublette', 'Trendprognose 2027');
    $e->update(['thema_inspiration_id' => $neu->id]);
    FoodAlchemistTrendBeleg::create(['team_id' => $this->rootTeam->id, 'inspiration_id' => $neu->id, 'quelle' => 'presse',
        'titel' => 'TEST-93R Beleg', 'url' => $e->url, 'beobachtet_am' => now()->toDateString(), 'fundstueck' => false]);

    $migration->up();

    expect(($this->auto)()->pluck('id')->all())->toBe([$alt->id])
        ->and(FoodAlchemistTrendInspiration::withTrashed()->find($neu->id)->trashed())->toBeTrue()
        ->and($e->fresh()->thema_inspiration_id)->toBe($alt->id)
        ->and(FoodAlchemistTrendBeleg::where('inspiration_id', $alt->id)->count())->toBe(1)
        ->and(fn () => FoodAlchemistTrendInspiration::create($basis))->toThrow(UniqueConstraintViolationException::class);
});

it('liefert jeden Themennamen nur einmal in den Prompt', function () {
    // Altbestand ohne Schlüssel fällt nicht unter den Index und kann gleich heißen.
    foreach ([1, 2] as $_) {
        FoodAlchemistTrendInspiration::create(['team_id' => $this->rootTeam->id, 'titel' => 'Trendprognose 2027', 'herkunft' => 'automatisch']);
    }
    FoodAlchemistTrendInspiration::create(['team_id' => $this->rootTeam->id, 'titel' => 'Fermentation', 'herkunft' => 'automatisch']);

    expect($this->themen->themenNamen($this->rootTeam->id))->toBe(['Fermentation', 'Trendprognose 2027']);
});
