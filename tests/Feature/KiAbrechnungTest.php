<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Jobs\ClassifyLaJob;
use Platform\FoodAlchemist\Livewire\Settings\Ki;
use Platform\FoodAlchemist\Models\FoodAlchemistMandant;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Services\Ai\FakeAiProvider;
use Platform\FoodAlchemist\Services\Ai\KiAbrechnungService;
use Platform\FoodAlchemist\Support\KiVerursacher;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * KI-Abrechnung je Mandant (16.10.2026): Verursacher je Aufruf (Kunden-Hauptteam / plattform / unbekannt), Preis und Kurs je Aufruf eingefroren,
 * Auswertung je Mandant und Zeitraum für das Master-Team. Keine Rückfüllung, keine Monatstabelle.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->master = Team::create(['name' => 'BHG.DIGITAL (Master)', 'user_id' => 1, 'personal_team' => false]);
    config(['foodalchemist.master_team_id' => $this->master->id, 'foodalchemist.ai.provider' => 'fake', 'foodalchemist.ai.usd_eur' => 0.9]);
    \Platform\FoodAlchemist\Support\MasterTeam::flush();
    $this->broich = Team::create(['name' => 'Broich', 'user_id' => 1, 'personal_team' => false]);
    $this->kita = Team::create(['name' => 'Broich Kita', 'user_id' => 1, 'personal_team' => false, 'parent_team_id' => $this->broich->id]);
    $this->soul = Team::create(['name' => 'Soulkitchen', 'user_id' => 1, 'personal_team' => false]);
    FoodAlchemistMandant::create(['team_id' => $this->broich->id, 'status' => 'aktiv']);
    FoodAlchemistMandant::create(['team_id' => $this->soul->id, 'status' => 'projekt']);

    // Provider, der Modell und Verbrauch ausgibt wie die echte API
    $this->modell = 'gpt-5.6-luna';
    $this->usage = ['input_tokens' => 1_000_000, 'output_tokens' => 1_000_000, 'input_tokens_details' => ['cached_tokens' => 0]];
    $this->stub = function (string $modell, array $usage) {
        app()->singleton(FakeAiProvider::class, fn () => new class($modell, $usage) extends FakeAiProvider
        {
            public function __construct(private string $m, private array $u) {}

            public function chat(array $messages, array $options = []): array
            {
                $r = parent::chat($messages, $options);

                return ['model' => $this->m, 'usage' => $this->u] + $r;
            }
        });
    };
    ($this->stub)($this->modell, $this->usage);
    $this->letzte = fn () => DB::table('foodalchemist_ai_call_log')->orderByDesc('id')->first();
});

it('Verursacher: Unter-Team zahlt über sein Kunden-Hauptteam', function () {
    $this->actingAs($this->makeUser($this->kita, 'Kita Koch'));

    expect(KiVerursacher::aufloesen())->toBe(['abrechnung_team_id' => $this->broich->id, 'verursacher' => 'mandant']);
});

it('Verursacher: Master-Team und Läufe ohne Nutzer sind Plattform, nicht abrechenbar', function () {
    $this->actingAs($this->makeUser($this->master, 'Kurator'));
    expect(KiVerursacher::aufloesen())->toBe(['abrechnung_team_id' => null, 'verursacher' => 'plattform']);

    Auth::logout();
    expect(KiVerursacher::aufloesen())->toBe(['abrechnung_team_id' => null, 'verursacher' => 'plattform']);

    // Master wird über MasterTeam::id() erkannt, nicht über eine feste Zahl: anderes Master-Team → das alte ist ein normaler Mandant
    config(['foodalchemist.master_team_id' => $this->soul->id]);
    \Platform\FoodAlchemist\Support\MasterTeam::flush();
    $this->actingAs($this->makeUser($this->master, 'Ex Kurator'));
    expect(KiVerursacher::aufloesen()['verursacher'])->toBe('mandant');
});

it('Verursacher: ausdrückliches Team (Massenlauf eines Mandanten) schlägt den angemeldeten Nutzer; "plattform" erzwingt Plattform', function () {
    $this->actingAs($this->makeUser($this->master, 'Kurator'));

    expect(KiVerursacher::aufloesen(['verursacher_team_id' => $this->kita->id]))->toBe(['abrechnung_team_id' => $this->broich->id, 'verursacher' => 'mandant']);

    $this->actingAs($this->makeUser($this->broich, 'Broich Koch'));
    expect(KiVerursacher::aufloesen(['verursacher' => 'plattform']))->toBe(['abrechnung_team_id' => null, 'verursacher' => 'plattform']);
});

it('Verursacher: Job-Kontext schlägt einen alten Auth-Login des Workers und wird danach zurückgesetzt', function () {
    $this->actingAs($this->makeUser($this->soul, 'Alter Login'));

    $im = KiVerursacher::imKontext($this->broich->id, fn () => KiVerursacher::aufloesen());
    expect($im)->toBe(['abrechnung_team_id' => $this->broich->id, 'verursacher' => 'mandant'])
        ->and(KiVerursacher::aufloesen()['abrechnung_team_id'])->toBe($this->soul->id);   // Kontext weg, wieder der Nutzer

    // auch bei einer Ausnahme im Job
    try {
        KiVerursacher::imKontext($this->broich->id, fn () => throw new RuntimeException('x'));
    } catch (RuntimeException) {
    }
    expect(KiVerursacher::aufloesen()['abrechnung_team_id'])->toBe($this->soul->id);
});

it('Jobs ohne Auth tragen ihr Team als Verursacher (Middleware der Job-Klassen)', function () {
    Auth::logout();
    $job = new ClassifyLaJob(1, $this->kita->id);
    $erfasst = null;
    $job->middleware()[0]->handle($job, function () use (&$erfasst) {
        $erfasst = KiVerursacher::aufloesen();
    });

    expect($erfasst)->toBe(['abrechnung_team_id' => $this->broich->id, 'verursacher' => 'mandant'])
        ->and(KiVerursacher::aufloesen()['verursacher'])->toBe('plattform');   // danach wieder ohne Kontext
});

it('Gateway schreibt Verursacher, Kosten, Preisstand und Kurs je Aufruf', function () {
    $this->actingAs($this->makeUser($this->kita, 'Kita Koch'));
    app(AiGatewayService::class)->propose('vk.speisen_klasse', ['name' => 'X']);

    $z = ($this->letzte)();
    expect((int) $z->abrechnung_team_id)->toBe($this->broich->id)->and($z->verursacher)->toBe('mandant')
        ->and((float) $z->kosten_usd)->toBe(1.4)                       // luna: 0,20 $ ein + 1,20 $ aus je Mio
        ->and((float) $z->preis_in_mio)->toBe(0.2)->and((float) $z->preis_out_mio)->toBe(1.2)->and((float) $z->usd_eur)->toBe(0.9);
});

it('Preise sind eingefroren: eine spätere Preis- oder Kursänderung betrifft nur neue Aufrufe', function () {
    $this->actingAs($this->makeUser($this->broich, 'Broich Koch'));
    $gw = app(AiGatewayService::class);
    $gw->propose('vk.speisen_klasse', ['name' => 'X']);
    $alt = ($this->letzte)();

    config(['foodalchemist.ai.modellkosten_pro_mio_usd' => ['gpt-5.6-luna' => ['in' => 1.0, 'cached_in' => 0.1, 'out' => 5.0]] + config('foodalchemist.ai.modellkosten_pro_mio_usd'),
        'foodalchemist.ai.usd_eur' => 0.5]);   // Schlüssel mit Punkten: nicht per Dot-Notation setzen
    $gw->propose('vk.speisen_klasse', ['name' => 'X']);
    $neu = ($this->letzte)();

    $alt2 = DB::table('foodalchemist_ai_call_log')->where('id', $alt->id)->first();
    expect((float) $alt2->kosten_usd)->toBe(1.4)->and((float) $alt2->usd_eur)->toBe(0.9)      // alte Zeile unverändert
        ->and((float) $neu->kosten_usd)->toBe(6.0)->and((float) $neu->usd_eur)->toBe(0.5);

    // und die Auswertung rechnet je Zeile mit dem eigenen Kurs: 1,4 × 0,9 + 6,0 × 0,5 = 4,26 €
    $a = app(KiAbrechnungService::class)->auswertung(now()->subDay(), now()->addDay());
    expect($a['mandanten']->firstWhere('team_id', $this->broich->id)['summe']['eur'])->toBe(4.26)
        ->and($a['mandanten']->firstWhere('team_id', $this->broich->id)['summe']['usd'])->toBe(7.4);
});

it('Modell ohne Preis: Kosten NULL (nie 0), Warnung im Log, in der Auswertung als „ohne Preis“ ausgewiesen', function () {
    Log::spy();
    ($this->stub)('unbekanntes-modell-x', $this->usage);
    $this->actingAs($this->makeUser($this->broich, 'Broich Koch'));
    app(AiGatewayService::class)->propose('vk.speisen_klasse', ['name' => 'X']);

    $z = ($this->letzte)();
    expect($z->kosten_usd)->toBeNull()->and($z->preis_in_mio)->toBeNull();
    Log::shouldHaveReceived('warning')->withArgs(fn ($m) => str_contains($m, 'ohne Preis'))->once();

    $m = app(KiAbrechnungService::class)->auswertung(now()->subDay(), now()->addDay())['mandanten']->firstWhere('team_id', $this->broich->id);
    expect($m['summe']['ohne_preis'])->toBe(1)->and($m['summe']['usd'])->toBe(0.0);
});

it('Auswertung: je Mandant und Klasse, Plattform und Unbekannt getrennt, nicht abrechenbare Mandanten sichtbar, Altzeilen und fremder Zeitraum zählen nicht', function () {
    $log = fn (array $e) => DB::table('foodalchemist_ai_call_log')->insert($e + [
        'uuid' => (string) Symfony\Component\Uid\UuidV7::generate(), 'team_id' => null, 'user_id' => null, 'feature' => 'x', 'tier' => 'S', 'model' => 'gpt-5.6-sol',
        'tokens_in' => 100, 'tokens_cached' => 0, 'tokens_out' => 50, 'kosten_usd' => 2.0, 'usd_eur' => 0.9, 'verursacher' => 'mandant',
        'abrechnung_team_id' => $this->broich->id, 'created_at' => now(), 'updated_at' => now()]);
    $log([]);
    $log(['tier' => 'L', 'kosten_usd' => 1.0]);
    $log(['abrechnung_team_id' => $this->soul->id, 'kosten_usd' => 4.0]);                                   // Status projekt: nicht abrechenbar
    $log(['verursacher' => 'plattform', 'abrechnung_team_id' => null, 'kosten_usd' => 7.0]);
    $log(['verursacher' => 'unbekannt', 'abrechnung_team_id' => null, 'kosten_usd' => 0.5]);
    $log(['verursacher' => null, 'abrechnung_team_id' => null, 'kosten_usd' => 99.0]);                      // Altzeile ohne Verursacher
    $log(['created_at' => now()->subMonths(2), 'kosten_usd' => 50.0]);                                      // außerhalb des Zeitraums

    $a = app(KiAbrechnungService::class)->auswertung(now()->startOfMonth(), now()->addDay());
    $b = $a['mandanten']->firstWhere('team_id', $this->broich->id);
    $s = $a['mandanten']->firstWhere('team_id', $this->soul->id);

    expect($b['abrechenbar'])->toBeTrue()->and($b['summe']['calls'])->toBe(2)->and($b['summe']['usd'])->toBe(3.0)->and($b['summe']['eur'])->toBe(2.7)
        ->and(array_column($b['klassen'], 'label'))->toBe(['Stark', 'Leicht'])
        ->and($s['abrechenbar'])->toBeFalse()->and($s['summe']['usd'])->toBe(4.0)
        ->and($a['plattform']['usd'])->toBe(7.0)->and($a['unbekannt']['usd'])->toBe(0.5)
        ->and($a['summe_abrechenbar']['usd'])->toBe(3.0);                                                   // nur Broich, nicht Soulkitchen/Plattform/Altzeile
});

it('Einstellungen: nur das Master-Team sieht „Abrechnung je Mandant“', function () {
    DB::table('foodalchemist_ai_call_log')->insert(['uuid' => (string) Symfony\Component\Uid\UuidV7::generate(), 'team_id' => null, 'feature' => 'x', 'tier' => 'S',
        'model' => 'gpt-5.6-sol', 'verursacher' => 'mandant', 'abrechnung_team_id' => $this->broich->id, 'kosten_usd' => 2.0, 'usd_eur' => 0.9,
        'created_at' => now(), 'updated_at' => now()]);

    $this->actingAs($this->makeUser($this->master, 'Kurator', 'owner'));
    Livewire::test(Ki::class)->assertSeeHtml('data-ki-abrechnung')->assertSee('Broich')->assertSee('Plattform (nicht abrechenbar)');

    $this->actingAs($this->makeUser($this->broich, 'Broich Chef', 'owner'));
    Livewire::test(Ki::class)->assertDontSeeHtml('data-ki-abrechnung');
});

it('Modelle der Klassen haben einen Preis: die drei gpt-5.6-Modelle sind in der Preistabelle (rot, wenn eines fehlt)', function () {
    foreach (['gpt-5.6-sol', 'gpt-5.6-terra', 'gpt-5.6-luna'] as $m) {
        $s = app(\Platform\FoodAlchemist\Services\Ai\AiCostCalculator::class)->snapshotFuerAufruf('recipe.generator', $m, 1000, 0, 1000);
        expect($s['kosten_usd'])->not->toBeNull("Preis fehlt für {$m}");
    }
});

it('Produktpass-Lesejob: Besitzer-Team (Unter-Team → Hauptteam) trägt den Aufruf, Pass des globalen Stamms ist Plattform', function () {
    $erfasst = [];
    $svc = Mockery::mock(\Platform\FoodAlchemist\Services\Produktpass\ProduktpassService::class);
    $svc->shouldReceive('lesen')->twice()->andReturnUsing(function ($pass) use (&$erfasst) {
        $erfasst[] = KiVerursacher::aufloesen();

        return $pass;
    });
    $supplier = \Platform\FoodAlchemist\Models\FoodAlchemistSupplier::create(['team_id' => null, 'name' => 'Lieferant']);
    $item = \Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem::create(['team_id' => null, 'supplier_id' => $supplier->id, 'designation' => 'Artikel', 'qty' => 1, 'unit_code' => 'Stk']);
    $mandantPass = \Platform\FoodAlchemist\Models\FoodAlchemistProduktpass::create(['team_id' => $this->kita->id, 'supplier_item_id' => $item->id, 'version' => 1, 'status' => 'offen']);
    $stammPass = \Platform\FoodAlchemist\Models\FoodAlchemistProduktpass::create(['team_id' => null, 'supplier_item_id' => $item->id, 'version' => 2, 'status' => 'offen']);

    $this->actingAs($this->makeUser($this->soul, 'Alter Login'));   // alter Worker-Login eines anderen Mandanten darf nichts zurechnen
    (new \Platform\FoodAlchemist\Jobs\ProduktpassLesenJob($mandantPass->id))->handle($svc);
    (new \Platform\FoodAlchemist\Jobs\ProduktpassLesenJob($stammPass->id))->handle($svc);

    expect($erfasst[0])->toBe(['abrechnung_team_id' => $this->broich->id, 'verursacher' => 'mandant'])
        ->and($erfasst[1])->toBe(['abrechnung_team_id' => null, 'verursacher' => 'plattform']);
});
