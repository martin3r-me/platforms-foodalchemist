<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Support\LangsameAbfragen;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 84 E-1 · Messung langsamer Abfragen in der Anwendung.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->logDir = sys_get_temp_dir() . '/fa-langsam-test-' . uniqid();
    mkdir($this->logDir);
    config(['logging.channels.' . LangsameAbfragen::KANAL . '.path' => $this->logDir . '/fa-langsam.log']);
    Log::forgetChannel(LangsameAbfragen::KANAL);
    LangsameAbfragen::abschliessen();   // Zähler aus dem Seeding verwerfen
    $this->zeilen = function (): array {
        $out = [];
        foreach (glob($this->logDir . '/fa-langsam-*.log') ?: [] as $datei) {
            foreach (file($datei) as $z) {
                $j = json_decode($z, true);
                if (is_array($j)) {
                    $out[] = $j['context'];
                }
            }
        }

        return $out;
    };
});

afterEach(function () {
    array_map('unlink', glob($this->logDir . '/*') ?: []);
    @rmdir($this->logDir);
    config(['foodalchemist.leistung.langsam_ms' => null]);
});

it('Spec 84 E-1 · Schwelle aus (Standard): keine Zeile, keine Zusammenfassung', function () {
    config(['foodalchemist.leistung.langsam_ms' => null, 'foodalchemist.leistung.zusammenfassung_anzahl' => 0]);
    for ($i = 0; $i < 30; $i++) {
        DB::table('foodalchemist_gps')->where('name', 'x')->count();
    }
    LangsameAbfragen::abschliessen();

    expect(($this->zeilen)())->toBe([]);
});

it('Spec 84 E-1 · über der Schwelle: Zeile mit Eingang, Team, Nutzer und Aufrufer — ohne Bindings und Literale', function () {
    config(['foodalchemist.leistung.langsam_ms' => 0]);
    $user = $this->makeUser($this->childA, 'Messer');
    $this->actingAs($user);

    $tool = app(ToolRegistry::class)->get('foodalchemist.gps.SEARCH');
    $tool->execute(['q' => 'GEHEIMNAME123'], new ToolContext($user, $this->childA));

    $alle = array_values(array_filter(($this->zeilen)(), fn ($z) => $z['art'] === 'langsam'));
    $zeilen = array_values(array_filter($alle, fn ($z) => $z['eingang'] === 'mcp:foodalchemist.gps.SEARCH'));
    expect($zeilen)->not->toBe([]);
    $json = json_encode($alle);
    expect(mb_strtolower($json))->not->toContain('geheimname')                                  // Bindings nie im Log
        ->and(collect($zeilen)->pluck('eingang')->unique()->all())->toContain('mcp:foodalchemist.gps.SEARCH')
        ->and(collect($zeilen)->pluck('team_id')->unique()->all())->toBe([$this->childA->id])
        ->and(collect($zeilen)->pluck('user_id')->unique()->all())->toBe([$user->id])
        ->and(collect($zeilen)->pluck('aufrufer')->filter()->first())->toMatch('/^[A-Za-z\/]+\.php:\d+$/')
        ->and($zeilen[0])->toHaveKeys(['ms', 'sql', 'verbindung']);
});

it('Spec 84 E-1 · Muster: Literale und IN-Listen werden ersetzt', function () {
    expect(LangsameAbfragen::muster("select * from t where a = 'Max Mustermann' and id in (?, ?, ?) and b = 42"))
        ->toBe("select * from t where a = '?' and id in (?…) and b = ?");
});

it('Spec 84 E-1 · N+1: gleiches Muster ≥ 20× ergibt eine Zusammenfassung mit dem Muster', function () {
    config(['foodalchemist.leistung.langsam_ms' => 100000, 'foodalchemist.leistung.n_plus_1' => 20]);
    LangsameAbfragen::beginne('test:n+1', 7);
    for ($i = 0; $i < 25; $i++) {
        DB::table('foodalchemist_gps')->where('id', $i)->first();
    }
    LangsameAbfragen::abschliessen();

    $z = collect(($this->zeilen)())->firstWhere('art', 'zusammenfassung');
    expect($z)->not->toBeNull()
        ->and($z['eingang'])->toBe('test:n+1')
        ->and($z['team_id'])->toBe(7)
        ->and($z['anzahl'])->toBeGreaterThanOrEqual(25)
        ->and($z['n_plus_1'][0]['anzahl'])->toBeGreaterThanOrEqual(25)
        ->and($z['n_plus_1'][0]['sql'])->toContain('"foodalchemist_gps"');
    expect(collect(($this->zeilen)())->where('art', 'langsam')->all())->toBe([]);   // unter der Schwelle: keine Einzelzeilen
});

it('Spec 84 E-1 · unauffälliger Abschnitt: keine Zusammenfassung', function () {
    config(['foodalchemist.leistung.langsam_ms' => 100000]);
    LangsameAbfragen::beginne('test:ruhig');
    DB::table('foodalchemist_gps')->count();
    LangsameAbfragen::abschliessen();

    expect(($this->zeilen)())->toBe([]);
});

it('Spec 84 E-1 · Kommando aggregiert nach Muster und Eingang', function () {
    config(['foodalchemist.leistung.langsam_ms' => 0]);
    LangsameAbfragen::beginne('test:kommando');
    for ($i = 0; $i < 3; $i++) {
        DB::table('foodalchemist_gps')->where('name', 'x' . $i)->count();
    }
    for ($i = 0; $i < 21; $i++) {
        DB::table('foodalchemist_gps')->where('id', $i)->first();
    }
    LangsameAbfragen::abschliessen();
    config(['foodalchemist.leistung.langsam_ms' => null]);   // die Auswertung selbst nicht messen

    Artisan::call('foodalchemist:langsame-abfragen', ['--tage' => 1]);
    $out = Artisan::output();

    expect($out)->toContain('Top 20 nach Summe')
        ->and($out)->toContain('test:kommando')
        ->and($out)->toContain('N+1-Muster')
        ->and($out)->toMatch('/\|\s+21\s+\|/');   // 21 gleiche Abfragen = eine Gruppe mit Anzahl 21
});

it('Spec 84 E-1 · die Messung wirft nie, auch wenn der Log-Kanal kaputt ist', function () {
    config(['foodalchemist.leistung.langsam_ms' => 0, 'logging.channels.' . LangsameAbfragen::KANAL . '.path' => '/gibt/es/nicht/x.log']);
    Log::forgetChannel(LangsameAbfragen::KANAL);

    expect(fn () => DB::table('foodalchemist_gps')->count())->not->toThrow(\Throwable::class);
    LangsameAbfragen::abschliessen();
});

it('Spec 84 E-1 · Queue-Ereignis mit kaputtem Job-Payload: kein Absturz, an und aus (#457-Rotfall)', function () {
    $kaputt = new class extends \Illuminate\Queue\Jobs\Job {
        public function getJobId() { return 'x'; }
        public function getRawBody() { return '{}'; }   // kein 'job' im Payload → resolveName() wirft
        public function attempts() { return 1; }
    };

    foreach ([null, 0] as $schwelle) {
        config(['foodalchemist.leistung.langsam_ms' => $schwelle]);
        expect(fn () => event(new \Illuminate\Queue\Events\JobProcessing('sync', $kaputt)))->not->toThrow(\Throwable::class)
            ->and(fn () => event(new \Illuminate\Queue\Events\JobProcessed('sync', $kaputt)))->not->toThrow(\Throwable::class);
    }
    expect(LangsameAbfragen::jobName($kaputt))->toBe($kaputt::class)
        ->and(LangsameAbfragen::jobName(null))->toBe('?');
});
