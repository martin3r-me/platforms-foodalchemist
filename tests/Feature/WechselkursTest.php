<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Platform\FoodAlchemist\Enums\SignalTyp;
use Platform\FoodAlchemist\Services\Ai\AiCostCalculator;
use Platform\FoodAlchemist\Services\Ai\WechselkursService;
use Platform\FoodAlchemist\Services\FaRechte;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * EZB-Tageskurs für KI-Kosten und KI-Budget (Dominique 18.10.2026): täglich 16:30 holen, 1/Kurs speichern (Historie), neuester Kurs gilt,
 * sonst env; ein Fehler blockiert nie, nach mehr als 5 Kalendertagen ohne neuen Kurs ein Signal an den Master. Kein echter HTTP-Aufruf.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    config(['foodalchemist.master_team_id' => $this->rootTeam->id, 'foodalchemist.ai.usd_eur' => 0.95]);
    $this->xml = fn (string $datum, string $usd) => <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<gesmes:Envelope xmlns:gesmes="http://www.gesmes.org/xml/2002-08-01" xmlns="http://www.ecb.int/vocabulary/2002-08-01/eurofxref">
  <gesmes:subject>Reference rates</gesmes:subject>
  <gesmes:Sender><gesmes:name>European Central Bank</gesmes:name></gesmes:Sender>
  <Cube><Cube time="{$datum}"><Cube currency="JPY" rate="170.12"/><Cube currency="USD" rate="{$usd}"/><Cube currency="CHF" rate="0.9312"/></Cube></Cube>
</gesmes:Envelope>
XML;
    $this->svc = app(WechselkursService::class);
});

it('liest das EZB-XML: Datum und USD-Kurs, gespeichert wird 1/Kurs', function () {
    expect(WechselkursService::parse(($this->xml)('2026-10-16', '1.1253')))->toBe(['2026-10-16', 1.1253]);
    expect(fn () => WechselkursService::parse('<html>Wartung</html>'))->toThrow(RuntimeException::class);
    expect(fn () => WechselkursService::parse(str_replace('USD', 'GBP', ($this->xml)('2026-10-16', '0.86'))))->toThrow(RuntimeException::class, 'USD');
});

it('★ Kommando holt und speichert, zweiter Lauf am selben Tag ändert nichts; der Kurs gilt für Anzeige und KI-Snapshot', function () {
    Http::fake([WechselkursService::URL => Http::response(($this->xml)('2026-10-16', '1.1253'))]);

    $this->artisan('foodalchemist:wechselkurs-holen')->assertSuccessful()->expectsOutputToContain('neu gespeichert');
    $this->artisan('foodalchemist:wechselkurs-holen')->assertSuccessful()->expectsOutputToContain('war schon da');

    $z = DB::table('foodalchemist_wechselkurse')->sole();
    $rechner = app(AiCostCalculator::class);
    expect(substr((string) $z->datum, 0, 10))->toBe('2026-10-16')->and((float) $z->ezb_kurs)->toBe(1.1253)
        ->and(round((float) $z->usd_eur, 4))->toBe(0.8887)
        ->and(round($rechner->kurs(), 4))->toBe(0.8887)                                       // nicht mehr der env-Kurs 0,95
        ->and(round($rechner->displayCost(10.0), 2))->toBe(8.89)
        ->and(round($rechner->snapshotFuerAufruf('test', 'gpt-4o', 1000, 0, 1000)['usd_eur'], 4))->toBe(0.8887)
        ->and($rechner->currency())->toBe('EUR');
    Http::assertSentCount(2);
});

it('EZB nicht erreichbar: der letzte Kurs bleibt, Warnung im Log, Exit ≠ 0 — KI rechnet weiter', function () {
    DB::table('foodalchemist_wechselkurse')->insert(['datum' => now()->subDay()->toDateString(), 'waehrung' => 'USD', 'ezb_kurs' => 1.25,
        'usd_eur' => 0.8, 'quelle' => 'ezb', 'created_at' => now(), 'updated_at' => now()]);
    Http::fake([WechselkursService::URL => Http::response('Service Unavailable', 503)]);
    Log::spy();

    $this->artisan('foodalchemist:wechselkurs-holen')->assertFailed()->expectsOutputToContain('der letzte Kurs bleibt');

    Log::shouldHaveReceived('warning')->withArgs(fn ($m) => str_contains($m, 'EZB-Wechselkurs nicht geholt'))->once();
    expect(app(AiCostCalculator::class)->kurs())->toBe(0.8)->and(DB::table('foodalchemist_wechselkurse')->count())->toBe(1);
});

it('ohne gespeicherten Kurs gilt env; ohne env rechnet die Anzeige in USD', function () {
    $rechner = app(AiCostCalculator::class);
    expect($rechner->kurs())->toBe(0.95)->and($rechner->displayCost(10.0))->toBe(9.5);

    config(['foodalchemist.ai.usd_eur' => null]);
    expect($rechner->kurs())->toBeNull()->and($rechner->displayCost(10.0))->toBe(10.0)->and($rechner->currency())->toBe('USD');
});

it('★ das KI-Budget rechnet mit dem gespeicherten EZB-Kurs', function () {
    DB::table('foodalchemist_ai_call_log')->insert(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'team_id' => $this->childA->id, 'feature' => 'test',
        'model' => 'gpt-4o', 'tokens_in' => 1_000_000, 'tokens_out' => 1_000_000, 'created_at' => now(), 'updated_at' => now()]);
    $rechte = app(FaRechte::class);
    $mitEnv = $rechte->kontingentNutzung($this->rootTeam)['ki_eur_monat'];

    Http::fake([WechselkursService::URL => Http::response(($this->xml)(now()->toDateString(), '2.0'))]);   // 1 USD = 0,50 EUR
    $this->svc->holen();
    $mitEzb = $rechte->kontingentNutzung($this->rootTeam)['ki_eur_monat'];

    expect($mitEnv)->toBeGreaterThan(0)->and(round($mitEzb / $mitEnv, 3))->toBe(round(0.5 / 0.95, 3));
});

it('Signal nach mehr als 5 Kalendertagen ohne neuen Kurs (Ostern ohne Fehlalarm); frischer Kurs schließt es', function () {
    Http::fake([WechselkursService::URL => Http::response('down', 500)]);
    $kurs = fn (string $datum) => DB::table('foodalchemist_wechselkurse')->insert(['datum' => $datum, 'waehrung' => 'USD', 'ezb_kurs' => 1.1,
        'usd_eur' => 0.9091, 'quelle' => 'ezb', 'created_at' => now(), 'updated_at' => now()]);
    $offen = fn () => DB::table('foodalchemist_signals')->where('type', SignalTyp::WechselkursVeraltet->value)->where('status', 'offen');
    $this->travelTo(now()->setDate(2027, 3, 30)->setTime(16, 30));   // Dienstag nach Ostern; letzter Kurs Gründonnerstag 25.03.

    $kurs('2027-03-25');
    $this->svc->holen();
    expect($offen()->count())->toBe(0);                               // 5 Tage: noch kein Alarm

    $this->travel(1)->days();
    $this->svc->holen();
    $s = $offen()->sole();
    expect($s->severity)->toBe('warnung')->and((int) $s->team_id)->toBe($this->rootTeam->id)->and($s->title)->toContain('seit 6 Tagen');

    $kurs('2027-03-31');
    $this->svc->signalPruefen();
    expect($offen()->count())->toBe(0);
});

it('Scheduler: täglich 16:30 Europe/Berlin, ohne Überlappung, auf einem Server', function () {
    $e = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
        ->first(fn ($e) => str_contains((string) $e->command, 'foodalchemist:wechselkurs-holen'));
    expect($e)->not->toBeNull()->and($e->expression)->toBe('30 16 * * *')->and((string) $e->timezone)->toBe('Europe/Berlin')
        ->and($e->withoutOverlapping)->toBeTrue()->and($e->onOneServer)->toBeTrue();
});

it('Einstellungen KI zeigen den geltenden Kurs mit EZB-Datum', function () {
    DB::table('foodalchemist_wechselkurse')->insert(['datum' => '2026-10-16', 'waehrung' => 'USD', 'ezb_kurs' => 1.1253,
        'usd_eur' => 0.88865, 'quelle' => 'ezb', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('foodalchemist_ai_call_log')->insert(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'team_id' => $this->rootTeam->id, 'feature' => 'test',
        'model' => 'gpt-4o', 'tokens_in' => 1000, 'tokens_out' => 1000, 'created_at' => now(), 'updated_at' => now()]);
    $this->actingAs($this->makeUser($this->rootTeam, 'Admin'));

    \Livewire\Livewire::test(\Platform\FoodAlchemist\Livewire\Settings\Ki::class)->assertSee('Kurs 0,8887 € je $ (EZB 16.10.2026)');
});
