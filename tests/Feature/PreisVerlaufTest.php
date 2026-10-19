<?php

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\FoodAlchemist\Livewire\Preise\Verlauf;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Services\PreisVerlaufService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Platform\FoodAlchemist\Tools\PreiseVerlaufGetTool;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Preisverlauf als Stufenlinie aus foodalchemist_prices UND foodalchemist_price_history, Preis je Basiseinheit (PriceService::vergleichspreis),
 * zeitgewichteter linearer Trend, EINE Linie je LA am Grundprodukt, gebündelte Abfragen, MCP preise_verlauf.GET.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    Carbon::setTestNow('2026-06-30 12:00:00');
    PreisVerlaufService::flush();
    $this->svc = app(PreisVerlaufService::class);
    $this->user = $this->makeUser($this->rootTeam, 'Einkauf');
    $this->actingAs($this->user);
    $this->nr = 0;

    $this->la = function (string $lieferant, float $qty = 1.0, string $einheit = 'kg', ?int $teamId = null) {
        $sup = FoodAlchemistSupplier::firstOrCreate(['team_id' => $teamId ?? $this->rootTeam->id, 'name' => $lieferant]);

        return FoodAlchemistSupplierItem::create(['team_id' => $teamId ?? $this->rootTeam->id, 'supplier_id' => $sup->id, 'designation' => 'Artikel '.$lieferant,
            'article_number' => 'A'.(++$this->nr), 'qty' => $qty, 'unit_code' => $einheit, 'packaging_unit' => 'Gebinde']);
    };
    $this->preis = function (FoodAlchemistSupplierItem $la, float $preis, string $ab, ?string $bis = null, string $status = '0', ?int $teamId = null) {
        DB::table('foodalchemist_prices')->insert(['uuid' => (string) UuidV7::generate(), 'team_id' => $teamId ?? $la->team_id, 'supplier_item_id' => $la->id, 'status' => $status, 'price' => $preis,
            'status_valid_from' => $ab, 'valid_to' => $bis, 'is_blocked' => 0, 'created_at' => now(), 'updated_at' => now()]);
    };
    $this->historie = function (FoodAlchemistSupplierItem $la, float $preis, string $ab, ?string $bis = null) {
        DB::table('foodalchemist_price_history')->insert(['uuid' => (string) UuidV7::generate(), 'price_id' => 1, 'supplier_item_id' => $la->id, 'team_id' => $la->team_id, 'status' => '0',
            'price' => $preis, 'status_valid_from' => $ab, 'valid_to' => $bis, 'is_blocked' => 0, 'quelle' => 'necta_abgleich', 'abgeloest_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    };
    $this->fenster = fn (string $z = 'alles', ?string $v = null, ?string $b = null) => $this->svc->fenster($z, $v, $b);
});

afterEach(fn () => Carbon::setTestNow());

it('Stufenlinie aus Historie UND Preiszeile, Preis je Basiseinheit (Gebinde 2 kg), Rohpreis je Stufe, Quelle je Stufe', function () {
    $la = ($this->la)('Hanos', 2.0, 'kg');
    ($this->historie)($la, 8.00, '2026-01-01');
    ($this->historie)($la, 9.00, '2026-03-01');
    ($this->preis)($la, 10.00, '2026-05-01');

    [$von, $bis] = ($this->fenster)('alles');
    $r = $this->svc->verlauf($this->rootTeam, [$la->id], $von, $bis, $la->id);

    expect($r['einheit'])->toBe('€/kg')->and($r['linien'])->toHaveCount(1);
    $s = $r['linien'][0]['segmente'];
    expect(array_column($s, 'wert'))->toBe([4.0, 4.5, 5.0])->and(array_column($s, 'preis_gebinde'))->toBe([8.0, 9.0, 10.0])
        ->and(array_column($s, 'quelle'))->toBe(['historie', 'historie', 'preis'])
        ->and([$s[0]['von'], $s[0]['bis'], $s[1]['von'], $s[2]['von'], $s[2]['bis']])->toBe(['2026-01-01', '2026-03-01', '2026-03-01', '2026-05-01', '2026-06-30'])   // gilt bis zum nächsten Stand
        ->and($r['linien'][0]['lead'])->toBeTrue()->and($r['linien'][0]['staende'])->toBe(3);
});

it('derselbe Stand aus Zeile und Historie wird nicht doppelt gezählt; abgelöste Zeilen mit valid_to bilden die Historie', function () {
    $la = ($this->la)('Chefs', 1.0, 'kg');
    ($this->preis)($la, 3.00, '2026-02-01', '2026-04-01');        // abgelöste Zeile
    ($this->preis)($la, 3.50, '2026-04-01');                       // aktuelle Zeile
    ($this->historie)($la, 3.00, '2026-02-01');                    // dieselbe Stufe nochmals als Historie

    $r = $this->svc->verlauf($this->rootTeam, [$la->id], ...($this->fenster)('alles'));

    expect(array_column($r['linien'][0]['segmente'], 'wert'))->toBe([3.0, 3.5]);
});

it('Zeitraum: 3 M schneidet zu, der am Anfang geltende Stand wird mitgenommen; „von–bis“ und „Alles“', function () {
    $la = ($this->la)('Hanos');
    ($this->preis)($la, 4.00, '2026-01-01', '2026-03-01');
    ($this->preis)($la, 4.50, '2026-03-01', '2026-05-01');
    ($this->preis)($la, 5.00, '2026-05-01');

    $drei = $this->svc->verlauf($this->rootTeam, [$la->id], ...($this->fenster)('3m'));
    $s = $drei['linien'][0]['segmente'];
    expect(array_column($s, 'wert'))->toBe([4.5, 5.0])->and($s[0]['von'])->toBe('2026-03-30')->and($drei['von'])->toBe('2026-03-30');   // 4,50 galt schon vor dem Fenster

    $frei = $this->svc->verlauf($this->rootTeam, [$la->id], ...($this->fenster)('frei', '2026-02-01', '2026-04-15'));
    expect(array_column($frei['linien'][0]['segmente'], 'wert'))->toBe([4.0, 4.5]);

    $alles = $this->svc->verlauf($this->rootTeam, [$la->id], ...($this->fenster)('alles'));
    expect($alles['linien'][0]['staende'])->toBe(3)->and($alles['von'])->toBe('2026-01-01');
});

it('Trend mit bekannter Steigung: tägliche Stände +0,01 €/Tag = +0,30 €/kg pro Monat, positive %/Jahr; Gegenprobe: ohne Zeitgewicht läge er woanders', function () {
    $la = ($this->la)('Hanos');
    foreach (range(0, 99) as $i) {
        $ab = Carbon::parse('2026-01-01')->addDays($i);
        ($this->preis)($la, round(1.00 + 0.01 * $i, 2), $ab->toDateString(), $i < 99 ? $ab->copy()->addDay()->toDateString() : null);
    }

    $r = $this->svc->verlauf($this->rootTeam, [$la->id], ...($this->fenster)('frei', '2026-01-01', '2026-04-10'));
    $t = $r['linien'][0]['trend'];

    expect($t['steigung_pro_monat'])->toBeGreaterThan(0.01 * 30.4375 * 0.98)->toBeLessThan(0.01 * 30.4375 * 1.02)
        ->and($t['prozent_pro_jahr'])->toBeGreaterThan(0)->and($t['start_wert'])->toBeLessThan($t['ende_wert']);
    expect(Verlauf::trendText($t, '€/kg'))->toStartWith('+0,30 €/kg pro Monat · +')->toContain('% pro Jahr');
});

it('Trend ist zeitgewichtet: ein kurzer Preisabschlag verzerrt ihn nicht (Referenz: tägliche Stützpunkte); über die Änderungspunkte allein läge er weit daneben', function () {
    $la = ($this->la)('Hanos');
    ($this->preis)($la, 10.00, '2025-08-01', '2026-06-25');
    ($this->preis)($la, 5.00, '2026-06-25');                         // 5 Tage kurzer Preisabschlag am Ende (Normalpreis-Stand)
    $r = $this->svc->verlauf($this->rootTeam, [$la->id], ...($this->fenster)('frei', '2025-08-01', '2026-06-30'));
    $dienst = $r['linien'][0]['trend']['steigung_pro_monat'];

    // Referenz: gewöhnliche kleinste Quadrate über TÄGLICHE Stützpunkte der Stufenlinie
    $tage = (int) Carbon::parse('2025-08-01')->diffInDays(Carbon::parse('2026-06-30'));
    $xs = range(0, $tage - 1);
    $ys = array_map(fn ($d) => Carbon::parse('2025-08-01')->addDays($d)->lt(Carbon::parse('2026-06-25')) ? 10.0 : 5.0, $xs);
    $n = count($xs);
    $mx = array_sum($xs) / $n;
    $my = array_sum($ys) / $n;
    $b = array_sum(array_map(fn ($x, $y) => ($x - $mx) * ($y - $my), $xs, $ys)) / array_sum(array_map(fn ($x) => ($x - $mx) ** 2, $xs));
    $referenz = $b * PreisVerlaufService::TAGE_PRO_MONAT;

    // nur Änderungspunkte (zwei Punkte: 10 € am Anfang, 5 € am Ende) — die Verzerrung, vor der die Gewichtung schützt
    $naiv = (5.0 - 10.0) / $tage * PreisVerlaufService::TAGE_PRO_MONAT;

    expect($dienst)->toEqualWithDelta($referenz, 0.01)->and(abs($dienst - $naiv))->toBeGreaterThan(0.3);
});

it('weniger als zwei Stände im Zeitraum: keine Trendlinie (auch wenn die Reihe insgesamt mehr hat)', function () {
    $la = ($this->la)('Hanos');
    ($this->preis)($la, 4.00, '2026-01-01', '2026-03-01');
    ($this->preis)($la, 5.00, '2026-03-01');

    $eins = $this->svc->verlauf($this->rootTeam, [$la->id], ...($this->fenster)('3m'));
    expect($eins['linien'][0]['staende'])->toBe(1)->and($eins['linien'][0]['trend'])->toBeNull();

    $zwei = $this->svc->verlauf($this->rootTeam, [$la->id], ...($this->fenster)('alles'));
    expect($zwei['linien'][0]['trend'])->not->toBeNull()->and($zwei['linien'][0]['trend']['steigung_pro_monat'])->toBeGreaterThan(0);
});

it('Basiseinheit: Lead bestimmt sie, Artikel mit fremder Einheit oder ohne Menge werden weggelassen und gezählt, nie gemischt', function () {
    $gp = FoodAlchemistGp::create(['team_id' => $this->rootTeam->id, 'gp_key' => 'pv|test', 'name' => 'Butter', 'lead_la_supplier_item_id' => null]);
    $kg1 = ($this->la)('Hanos', 0.25, 'kg');
    $kg2 = ($this->la)('Chefs', 1.0, 'kg');
    $liter = ($this->la)('Metro', 1.0, 'l');
    $ohneMenge = ($this->la)('Gilde', 1.0, 'kg');
    $ohneMenge->update(['qty' => null]);
    foreach ([$kg1, $kg2, $liter, $ohneMenge] as $i => $la) {
        ($this->preis)($la, 4.0 + $i, '2026-01-01');
        FoodAlchemistSupplierItemStructure::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'gp_id' => $gp->id]);
    }
    $gp->update(['lead_la_supplier_item_id' => $kg2->id]);

    $r = $this->svc->verlaufFuerGp($this->rootTeam, $gp->fresh(), ...($this->fenster)('alles'));

    expect($r['einheit'])->toBe('€/kg')->and($r['la_gesamt'])->toBe(4)->and($r['weggelassen'])->toBe(2)                // Liter und ohne Menge
        ->and(array_column($r['linien'], 'la_id'))->toBe([$kg2->id, $kg1->id])                                          // Lead zuerst
        ->and($r['linien'][0]['lead'])->toBeTrue()->and($r['linien'][1]['lead'])->toBeFalse()
        ->and($r['linien'][1]['segmente'][0]['wert'])->toBe(16.0);                                                      // 4 € je 0,25 kg = 16 €/kg

    // Lead in Litern: dann ist Liter die Einheit und kg weggelassen
    $gp->update(['lead_la_supplier_item_id' => $liter->id]);
    $l = $this->svc->verlaufFuerGp($this->rootTeam, $gp->fresh(), ...($this->fenster)('alles'));
    expect($l['einheit'])->toBe('€/l')->and(array_column($l['linien'], 'la_id'))->toBe([$liter->id])->and($l['weggelassen'])->toBe(3);
});

it('Grundprodukt mit 20 Artikeln: eine Linie je LA, gebündelt abgefragt — Zahl der Abfragen hängt nicht von der Zahl der Artikel ab (kein N+1)', function () {
    $gp = FoodAlchemistGp::create(['team_id' => $this->rootTeam->id, 'gp_key' => 'pv|zwanzig', 'name' => 'Mehl']);
    foreach (range(1, 20) as $i) {
        $la = ($this->la)('Lieferant '.$i, 1.0, 'kg');
        ($this->historie)($la, 1.0 + $i / 10, '2026-01-01');
        ($this->preis)($la, 1.5 + $i / 10, '2026-04-01');
        FoodAlchemistSupplierItemStructure::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'gp_id' => $gp->id]);
    }
    $gp = $gp->fresh();
    [$von, $bis] = ($this->fenster)('alles');
    $this->svc->verlaufFuerGp($this->rootTeam, $gp, $von, $bis);   // Aufwärmen (Schema-Prüfung, Caches)

    DB::flushQueryLog();
    DB::enableQueryLog();
    $r = $this->svc->verlaufFuerGp($this->rootTeam, $gp, $von, $bis);
    $abfragen = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($r['linien'])->toHaveCount(20)->and(array_sum(array_column($r['linien'], 'staende')))->toBe(40)
        ->and($abfragen)->toBeLessThanOrEqual(8)                          // Struktur, Artikel, Lieferanten, Preise, Historie (+ Kleinkram), nicht 20+
        ->and(collect($r['linien'])->every(fn ($l) => $l['trend'] !== null))->toBeTrue();
});

it('Sichtbarkeit: ein Artikel eines Geschwister-Teams ist nicht sichtbar', function () {
    $fremd = ($this->la)('Fremdlieferant', 1.0, 'kg', $this->childB->id);
    ($this->preis)($fremd, 9.0, '2026-01-01');

    $r = $this->svc->verlauf($this->childA, [$fremd->id], ...($this->fenster)('alles'));

    expect($r['linien'])->toBe([]);
});

it('Sichtbarkeit: Preise fremder Teams am eigenen Artikel fließen nicht in die Linie', function () {
    $eigen = ($this->la)('Hanos', 1.0, 'kg', $this->childA->id);
    ($this->preis)($eigen, 4.0, '2026-01-01');
    ($this->preis)($eigen, 99.0, '2026-02-01', null, '0', $this->childB->id);   // Preis des Geschwister-Teams

    $r = $this->svc->verlauf($this->childA, [$eigen->id], ...($this->fenster)('alles'));

    expect(array_column($r['linien'][0]['segmente'], 'wert'))->toBe([4.0]);
});

it('Mandanten-Preis schlägt den Listenpreis: je Artikel gilt die beste Stufe, nie ein Mix', function () {
    $la = ($this->la)('Hanos', 1.0, 'kg');
    ($this->preis)($la, 4.0, '2026-01-01');                                      // Listenpreis (Besitzer = rootTeam)
    ($this->preis)($la, 3.0, '2026-03-01', null, '0', $this->childA->id);        // Preis des eigenen Teams

    $r = $this->svc->verlauf($this->childA, [$la->id], ...($this->fenster)('alles'));

    expect(array_column($r['linien'][0]['segmente'], 'wert'))->toBe([3.0]);
});

it('MCP preise_verlauf.GET: la_id und gp_id, Zeitraum, Punkte je LA, Trend/Steigung; Fehlerfälle', function () {
    $gp = FoodAlchemistGp::create(['team_id' => $this->rootTeam->id, 'gp_key' => 'pv|mcp', 'name' => 'Sahne']);
    $a = ($this->la)('Hanos');
    $b = ($this->la)('Chefs');
    foreach ([$a, $b] as $la) {
        ($this->preis)($la, 4.0, '2026-01-01', '2026-04-01');
        ($this->preis)($la, 5.0, '2026-04-01');
        FoodAlchemistSupplierItemStructure::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'gp_id' => $gp->id]);
    }
    $gp->update(['lead_la_supplier_item_id' => $a->id]);
    $ctx = new ToolContext($this->user, $this->rootTeam);
    $tool = new PreiseVerlaufGetTool;

    $la = $tool->execute(['la_id' => $a->id, 'zeitraum' => 'alles'], $ctx);
    expect($la->success)->toBeTrue(json_encode([$la->errorCode, $la->error ?? null]))->and($la->data['linien'])->toHaveCount(1)
        ->and($la->data['linien'][0]['punkte'])->toHaveCount(2)->and($la->data['linien'][0]['punkte'][1]['preis_je_basis'])->toBe(5.0)
        ->and($la->data['linien'][0]['trend']['steigung_pro_monat'])->toBeGreaterThan(0)->and($la->data['einheit'])->toBe('€/kg');

    $g = $tool->execute(['gp_id' => $gp->id, 'von' => '2026-02-01', 'bis' => '2026-06-30'], $ctx);
    expect($g->data['linien'])->toHaveCount(2)->and($g->data['linien'][0]['lead'])->toBeTrue()->and($g->data['la_gesamt'])->toBe(2);

    expect($tool->execute([], $ctx)->errorCode)->toBe('VALIDATION_ERROR')->and($tool->execute(['la_id' => 1, 'gp_id' => 2], $ctx)->errorCode)->toBe('VALIDATION_ERROR')
        ->and($tool->execute(['la_id' => 999999], $ctx)->errorCode)->toBe('NOT_FOUND')->and($tool->execute(['gp_id' => 999999], $ctx)->errorCode)->toBe('NOT_FOUND')
        ->and($tool->execute(['la_id' => $a->id, 'von' => 'kein-datum'], $ctx)->errorCode)->toBe('VALIDATION_ERROR');
});

it('Trendtext: Minuszeichen bei fallendem Preis, ohne Trend null', function () {
    expect(Verlauf::trendText(['steigung_pro_monat' => -0.1234, 'prozent_pro_jahr' => -2.5], '€/l'))->toBe('−0,12 €/l pro Monat · −2,5 % pro Jahr')
        ->and(Verlauf::trendText(null, '€/kg'))->toBeNull()
        ->and(Verlauf::trendText(['steigung_pro_monat' => 0.0042, 'prozent_pro_jahr' => 0.7], '€/kg'))->toBe('+0,004 €/kg pro Monat · +0,7 % pro Jahr');   // nicht „+0,00“
});

it('Diagramm: durchgehende Treppe mit senkrechtem Strich beim Preiswechsel, gestrichelte Trendlinie, Lead dicker, Tooltip je Stand', function () {
    $la = ($this->la)('Hanos', 2.0, 'kg');
    ($this->preis)($la, 8.0, '2026-01-01', '2026-04-01');
    ($this->preis)($la, 10.0, '2026-04-01');

    $daten = $this->svc->verlauf($this->rootTeam, [$la->id], ...($this->fenster)('alles'), ...[$la->id]);
    $d = Verlauf::diagramm($daten);
    $l = $d['linien'][0];

    expect($l['pfad'])->toMatch('/^M[\d.]+ [\d.]+ H[\d.]+ V[\d.]+ H[\d.]+$/')   // erst waagerecht, dann EIN senkrechter Strich, dann waagerecht
        ->and($l['luecken'])->toBe('')                                               // keine Lücke
        ->and($l['breite'])->toBe(2.5)->and($l['lead'])->toBeTrue()->and($l['trend'])->not->toBeNull()->and($d['einheit'])->toBe('€/kg')->and($d['hoehe'])->toBe(240)
        ->and($l['punkte'][0]['tip'])->toContain('Gebinde 8,00 €')->toContain('4,00 €/kg')->toContain('01.01.2026')->toContain('Hanos')
        ->and($l['trend_text'])->toContain('€/kg pro Monat')->toContain('% pro Jahr');
});

it('Treppe reißt nicht auf: Necta beendet einen Stand knapp VOR dem nächsten (valid_to am Vortag 23:59:59) — das ist keine Lücke', function () {
    $la = ($this->la)('Hanos');
    ($this->preis)($la, 4.0, '2026-01-01', '2026-02-28 23:59:59');
    ($this->preis)($la, 5.0, '2026-03-01');

    $d = Verlauf::diagramm($this->svc->verlauf($this->rootTeam, [$la->id], ...($this->fenster)('alles')));

    expect($d['linien'][0]['pfad'])->toContain(' V')->and($d['linien'][0]['luecken'])->toBe('');
});

it('Eine echte Lücke (Artikel zeitweise ohne Preis) bleibt eine Lücke und wird gestrichelt verbunden', function () {
    $la = ($this->la)('Hanos');
    ($this->preis)($la, 4.0, '2026-01-01', '2026-02-01');        // bis 1.2., danach ein Monat ohne Preis
    ($this->preis)($la, 5.0, '2026-03-05');

    $d = Verlauf::diagramm($this->svc->verlauf($this->rootTeam, [$la->id], ...($this->fenster)('alles')));

    expect($d['linien'][0]['luecken'])->toStartWith('M')->toContain('L')->and($d['linien'][0]['pfad'])->not->toContain(' V');
});

it('Y-Achse: 4–5 sinnvoll gerundete Ticks (keine krummen Werte), die Achse reicht über die Daten; flache Reihe mittig', function () {
    [$ticks, $min, $max] = Verlauf::runderAchsenwert(1.37, 1.74);
    expect($ticks)->toBe([1.3, 1.4, 1.5, 1.6, 1.7, 1.8])->and($min)->toBe(1.3)->and($max)->toBe(1.8);   // 1,30 / 1,40 … statt 1,37 / 1,49 / 1,62

    [$t2] = Verlauf::runderAchsenwert(0.0, 100.0);
    expect($t2)->toBe([0.0, 25.0, 50.0, 75.0, 100.0]);

    [$t3, $a, $b] = Verlauf::runderAchsenwert(5.0, 5.0);   // flach: nicht am Rand
    expect($a)->toBeLessThan(5.0)->and($b)->toBeGreaterThan(5.0)->and(count($t3))->toBeBetween(3, 7);
    foreach ([[1.37, 1.74], [0.82, 0.93], [12.0, 480.0], [3.1, 3.2]] as [$lo, $hi]) {
        [$t, $tMin, $tMax] = Verlauf::runderAchsenwert($lo, $hi);
        expect(count($t))->toBeBetween(3, 7)->and($tMin)->toBeLessThanOrEqual($lo)->and($tMax)->toBeGreaterThanOrEqual($hi);
    }
});

it('X-Achse: Monats- bzw. Quartals-Ticks im Format „Jan 26“, bei kurzen Zeiträumen wöchentlich, bei langen je Jahr', function () {
    $x = fn (int $t) => 0.0;
    $labels = fn (string $von, string $bis) => array_column(Verlauf::zeitTicks(Carbon::parse($von)->getTimestamp(), Carbon::parse($bis)->getTimestamp(), $x), 'label');

    expect($labels('2026-01-01', '2026-06-30'))->toBe(['Jan 26', 'Feb 26', 'Mär 26', 'Apr 26', 'Mai 26', 'Jun 26'])                     // 6 M: monatlich
        ->and($labels('2025-07-01', '2026-06-30'))->toBe(['Jul 25', 'Okt 25', 'Jan 26', 'Apr 26'])                                      // 12 M: Quartale
        ->and($labels('2018-03-01', '2026-06-30'))->toBe(['Jan 19', 'Jan 20', 'Jan 21', 'Jan 22', 'Jan 23', 'Jan 24', 'Jan 25', 'Jan 26'])   // Alles: je Jahr
        ->and($labels('2026-06-01', '2026-06-20'))->toBe(['01.06.', '08.06.', '15.06.']);                                                // wenige Tage: wöchentlich
});

it('Ansicht: feste Höhe, Beschriftung als HTML in FA-Typografie (nicht im skalierten SVG), Einheit klein an der Achse, X-Achse mit Monaten, Hover-Bänder, Trend lesbar', function () {
    $la = ($this->la)('Hanos', 2.0, 'kg');
    ($this->preis)($la, 8.0, '2026-01-01', '2026-04-01');
    ($this->preis)($la, 10.0, '2026-04-01');

    $html = Livewire::test(Verlauf::class, ['modus' => 'la', 'objektId' => $la->id])->set('zeitraum', 'alles')->html();

    expect($html)->toContain('grid-template-rows: 240px')->toContain('data-preisverlauf-einheit')->toContain('data-preisverlauf-achse-x')
        ->toContain('Jan 26')->toContain('Apr 26')->toContain('preserveAspectRatio="none"')->toContain('data-preisverlauf-band')
        ->toContain('Trend +')->not->toContain('<text')                                             // keine Schrift im SVG: sie skaliert sonst mit
        // Tooltip: eigenes Element im (relativen) Diagramm-Container an der Mausposition, an den Rändern geklemmt — kein natives <title> (das saß oben links im Modal)
        ->toContain('data-preisverlauf-tooltip')->toContain('class="relative" x-data="{ tip: null, mx: 0, my: 0 }"')->toContain('data-tip="')->toContain('Math.min(mx + 14')
        ->not->toContain('<title>');
});

it('Ansicht: jedes Kind der Diagramm-Fläche ist explizit im Grid platziert — das SVG sitzt in Zeile 1 / Spalte 2 (io 19.10.: 56 × 24 px unten links)', function () {
    // Ein einzelnes explizit platziertes Kind (Marker-Labels, #427) drängte das nicht platzierte SVG per Auto-Placement in
    // Zeile 2 / Spalte 1. Darum: alle Kinder im Fluss tragen grid-column + grid-row, keine zwei teilen eine Zelle außer
    // SVG + Marker-Labels (bewusst übereinander).
    $la = ($this->la)('Hanos', 2.0, 'kg');
    ($this->preis)($la, 8.0, '2026-01-01', '2026-04-01');
    ($this->preis)($la, 10.0, '2026-04-01');
    $html = Livewire::test(Verlauf::class, ['modus' => 'la', 'objektId' => $la->id])->set('zeitraum', 'alles')->html();

    $dom = new DOMDocument;
    @$dom->loadHTML('<?xml encoding="utf-8">' . $html);
    $flaeche = (new DOMXPath($dom))->query('//*[@data-preisverlauf-flaeche]')->item(0);
    expect($flaeche)->not->toBeNull();

    $zellen = [];
    foreach ($flaeche->childNodes as $kind) {
        if (! $kind instanceof DOMElement) {
            continue;
        }
        $style = preg_replace('/\s+/', '', (string) $kind->getAttribute('style'));
        if (str_contains($style, 'position:absolute') || str_contains((string) $kind->getAttribute('class'), 'absolute')) {
            continue;   // Tooltip: außerhalb des Grid-Flusses
        }
        preg_match('/grid-column:(\d+);grid-row:(\d+)/', $style, $m);
        $name = $kind->tagName . '[' . implode(',', array_filter(array_map(fn ($a) => str_starts_with($a->name, 'data-') ? $a->name : null, iterator_to_array($kind->attributes)))) . ']';
        expect($m)->not->toBe([], "{$name} ohne explizite Platzierung");
        $zellen[$name] = $m[2] . '/' . $m[1];
    }

    expect($zellen['svg[data-preisverlauf-svg]'])->toBe('1/2')
        ->and($zellen['div[data-preisverlauf-achse-y]'])->toBe('1/1')
        ->and($zellen['div[data-preisverlauf-achse-x]'])->toBe('2/2')
        ->and(array_count_values($zellen))->toBe(['1/1' => 1, '1/2' => 2, '2/1' => 1, '2/2' => 1]);
});

it('Komponente: LA-Modal zeigt eine Linie, GP zeigt je LA eine Linie mit Legende, Zeitraum-Umschalter und Hinweis auf weggelassene Artikel', function () {
    $gp = FoodAlchemistGp::create(['team_id' => $this->rootTeam->id, 'gp_key' => 'pv|ui', 'name' => 'Butter']);
    $a = ($this->la)('Hanos');
    $b = ($this->la)('Chefs');
    $l = ($this->la)('Metro', 1.0, 'l');
    foreach ([$a, $b, $l] as $la) {
        ($this->preis)($la, 4.0, '2026-01-01', '2026-04-01');
        ($this->preis)($la, 5.0, '2026-04-01');
        FoodAlchemistSupplierItemStructure::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'gp_id' => $gp->id]);
    }
    $gp->update(['lead_la_supplier_item_id' => $a->id, 'n_las_total' => 3]);

    $c = Livewire::test(Verlauf::class, ['modus' => 'la', 'objektId' => $a->id])->assertSeeHtml('data-preisverlauf="la"')->assertSeeHtml('data-preisverlauf-svg')
        ->assertSeeHtml('data-preisverlauf-trend');
    expect(substr_count($c->html(), 'data-preisverlauf-linie='))->toBe(1);
    $c->call('zeitraumSetzen', '3m')->assertSet('zeitraum', '3m')->call('zeitraumSetzen', 'frei')->assertSeeHtml('data-preisverlauf-von')->assertSeeHtml('data-preisverlauf-bis');

    $g = Livewire::test(Verlauf::class, ['modus' => 'gp', 'objektId' => $gp->id]);
    $html = $g->html();
    expect(substr_count($html, 'data-preisverlauf-linie='))->toBe(2)->and($html)->toContain('data-lead="1"')->toContain('Hanos')->toContain('Chefs')->toContain('Lead')
        ->toContain('data-preisverlauf-weggelassen')->toContain('1 Artikel nicht gezeigt');
});

it('Komponente: ohne Stand im Zeitraum ein ruhiger Leerzustand, ungültiges Datum eine Meldung', function () {
    $la = ($this->la)('Hanos');
    ($this->preis)($la, 4.0, '2020-01-01', '2020-02-01');   // lange vorbei

    Livewire::test(Verlauf::class, ['modus' => 'la', 'objektId' => $la->id])->assertSeeHtml('data-preisverlauf-leer');
    Livewire::test(Verlauf::class, ['modus' => 'la', 'objektId' => $la->id])->set('zeitraum', 'frei')->set('von', 'kein-datum')->assertSeeHtml('data-preisverlauf-fehler');
});

it('Embeds: LA-Modal (Preise-Reiter) und GP-Detail binden den Verlauf ein', function () {
    $la = ($this->la)('Hanos');
    ($this->preis)($la, 4.0, '2026-01-01');
    $gp = FoodAlchemistGp::create(['team_id' => $this->rootTeam->id, 'gp_key' => 'pv|embed', 'name' => 'Mehl', 'n_las_total' => 1]);
    FoodAlchemistSupplierItemStructure::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'gp_id' => $gp->id]);

    Livewire::test(\Platform\FoodAlchemist\Livewire\Suppliers\ItemModal::class)->call('oeffnen', $la->id)->assertSeeHtml('data-sektion="preisverlauf"')->assertSeeHtml('data-preisverlauf="la"');
    Livewire::test(\Platform\FoodAlchemist\Livewire\Gps\DetailPanel::class, ['gpId' => $gp->id])->assertSeeHtml('data-sektion="preisverlauf"')->assertSeeHtml('data-preisverlauf="gp"');
});

it('Echte Aktion (Historie, status A) läuft parallel und geht nicht in die Linie ein: keine Sprünge, Trend nur über den Normalpreis', function () {
    $la = ($this->la)('Hanos');
    ($this->preis)($la, 11.00, '2026-01-01', '2026-10-05');
    ($this->preis)($la, 12.29, '2026-10-05');                       // Normalpreis ab 5.10.
    DB::table('foodalchemist_price_history')->insert(['uuid' => (string) UuidV7::generate(), 'price_id' => null, 'supplier_item_id' => $la->id, 'team_id' => $la->team_id, 'status' => 'A',
        'price' => 9.99, 'status_valid_from' => '2026-10-05', 'valid_to' => '2026-10-12', 'is_blocked' => 0, 'quelle' => 'necta_historie', 'abgeloest_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    Carbon::setTestNow('2026-10-20 12:00:00');

    $r = $this->svc->verlauf($this->rootTeam, [$la->id], ...($this->fenster)('alles'));

    expect(array_column($r['linien'][0]['segmente'], 'wert'))->toBe([11.0, 12.29])->and($r['linien'][0]['trend']['steigung_pro_monat'])->toBeGreaterThan(0);
    // Gegenprobe: ein altes status-2 in der Historie (vor der Umstellung auf A) ist ebenfalls keine Linie
    DB::table('foodalchemist_price_history')->update(['status' => '2']);
    $alt = $this->svc->verlauf($this->rootTeam, [$la->id], ...($this->fenster)('alles'));
    expect(array_column($alt['linien'][0]['segmente'], 'wert'))->toBe([11.0, 12.29]);
});

it('Preiszeile status 2 MIT Preis (läuft aus) ist Teil der Linie, derselbe Preis wie der N-Stand: keine Sprünge, kein Doppelstand; 2 ohne Preis trägt nichts', function () {
    $la = ($this->la)('Hanos');
    ($this->historie)($la, 11.0, '2026-01-01', '2026-10-05');
    ($this->preis)($la, 12.29, '2026-10-05', null, '2');            // zuletzt gelistet 12,29, jetzt „läuft aus“ (gleiche Preiszeile, anderer Status)
    Carbon::setTestNow('2026-10-20 12:00:00');
    $ohne = ($this->la)('Chefs');
    DB::table('foodalchemist_prices')->insert(['uuid' => (string) UuidV7::generate(), 'team_id' => $ohne->team_id, 'supplier_item_id' => $ohne->id, 'status' => '2', 'price' => null,
        'status_valid_from' => '2026-01-01', 'valid_to' => null, 'is_blocked' => 0, 'created_at' => now(), 'updated_at' => now()]);   // eingestellt

    $r = $this->svc->verlauf($this->rootTeam, [$la->id, $ohne->id], ...($this->fenster)('alles'));

    expect(array_column($r['linien'], 'la_id'))->toBe([$la->id])->and(array_column($r['linien'][0]['segmente'], 'wert'))->toBe([11.0, 12.29])
        ->and($r['ohne_verlauf'])->toBe(1);
});

it('Ein Artikel nur mit Preiszeilen status 2 (läuft aus) zeigt sie als Linie', function () {
    $la = ($this->la)('Hanos');
    ($this->preis)($la, 7.00, '2026-02-01', '2026-04-01', '2');
    ($this->preis)($la, 6.00, '2026-04-01', null, '2');

    $r = $this->svc->verlauf($this->rootTeam, [$la->id], ...($this->fenster)('alles'));

    expect(array_column($r['linien'][0]['segmente'], 'wert'))->toBe([7.0, 6.0]);
});

// ── Preise-Reiter: frühere Preise (Necta-Historie), Layout, Refresh ──────────

it('Frühere Preise: Necta-Historie als Liste, neueste zuerst, Aktion markiert, offener Stand gilt bis zum nächsten, schon gelistete Preiszeilen fehlen', function () {
    $la = ($this->la)('Hanos', 2.0, 'kg');
    ($this->preis)($la, 10.0, '2026-05-01');                          // aktuelle Preiszeile: steht schon in der Preisliste
    ($this->historie)($la, 8.0, '2026-01-01', '2026-02-28 23:59:59');
    ($this->historie)($la, 9.0, '2026-03-01');                        // offen → gilt bis zum nächsten Stand desselben Status
    ($this->historie)($la, 10.0, '2026-05-01');                       // dieselbe Stufe wie die Preiszeile → nicht doppelt
    DB::table('foodalchemist_price_history')->insert(['uuid' => (string) UuidV7::generate(), 'price_id' => null, 'supplier_item_id' => $la->id, 'team_id' => $la->team_id, 'status' => 'A',
        'price' => 7.0, 'status_valid_from' => '2026-02-01', 'valid_to' => '2026-02-14', 'is_blocked' => 0, 'quelle' => 'necta_historie', 'abgeloest_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

    $r = $this->svc->fruehere($this->rootTeam, $la);

    expect($r['gesamt'])->toBe(3)->and($r['gekappt'])->toBeFalse()
        ->and(array_column($r['zeilen'], 'von'))->toBe(['2026-03-01', '2026-02-01', '2026-01-01'])              // neueste zuerst
        ->and(array_column($r['zeilen'], 'preis'))->toBe([9.0, 7.0, 8.0])->and(array_column($r['zeilen'], 'je_basis'))->toBe([4.5, 3.5, 4.0])   // Gebinde 2 kg
        ->and(array_column($r['zeilen'], 'aktion'))->toBe([false, true, false])                                  // Aktion sichtbar markiert
        ->and($r['zeilen'][0]['bis'])->toBe('2026-05-01')->and($r['zeilen'][2]['bis'])->toBe('2026-02-28')      // offen → bis zum nächsten Stand; sonst valid_to
        ->and($r['zeilen'][0]['quelle'])->toBe('Necta-Historie')->and($r['zeilen'][0]['einheit'])->toBe('€/kg');

    // die Linie zeigt die Aktion weiterhin NICHT
    $linie = $this->svc->verlauf($this->rootTeam, [$la->id], ...($this->fenster)('alles'));
    expect(in_array(3.5, array_column($linie['linien'][0]['segmente'], 'wert'), true))->toBeFalse();
});

it('Frühere Preise: Sichtbarkeit wie die Linie (fremde Historie nicht, Kappung bei sehr vielen Ständen)', function () {
    $eigen = ($this->la)('Hanos', 1.0, 'kg', $this->childA->id);
    ($this->historie)($eigen, 4.0, '2026-01-01');
    DB::table('foodalchemist_price_history')->insert(['uuid' => (string) UuidV7::generate(), 'price_id' => null, 'supplier_item_id' => $eigen->id, 'team_id' => $this->childB->id, 'status' => '0',
        'price' => 99.0, 'status_valid_from' => '2026-02-01', 'is_blocked' => 0, 'quelle' => 'necta_historie', 'abgeloest_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    expect(array_column($this->svc->fruehere($this->childA, $eigen)['zeilen'], 'preis'))->toBe([4.0]);

    $viele = ($this->la)('Chefs', 1.0, 'kg');
    foreach (range(1, 30) as $i) {
        ($this->historie)($viele, 1.0 + $i / 100, Carbon::parse('2026-01-01')->addDays($i)->toDateString());
    }
    $r = $this->svc->fruehere($this->rootTeam, $viele, 10);
    expect($r['gesamt'])->toBe(30)->and(count($r['zeilen']))->toBe(10)->and($r['gekappt'])->toBeTrue();
});

it('Komponente Frühere Preise: eingeklappte Gruppe „Frühere Preise (n)“ mit Gültig ab/bis, Preis, je Basiseinheit, Quelle, Aktion markiert; ohne Historie nichts', function () {
    $la = ($this->la)('Hanos', 2.0, 'kg');
    ($this->historie)($la, 8.0, '2026-01-01', '2026-02-28 23:59:59');
    DB::table('foodalchemist_price_history')->insert(['uuid' => (string) UuidV7::generate(), 'price_id' => null, 'supplier_item_id' => $la->id, 'team_id' => $la->team_id, 'status' => 'A',
        'price' => 7.0, 'status_valid_from' => '2026-02-01', 'valid_to' => '2026-02-14', 'is_blocked' => 0, 'quelle' => 'necta_historie', 'abgeloest_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

    $html = Livewire::test(\Platform\FoodAlchemist\Livewire\Preise\Fruehere::class, ['itemId' => $la->id])->html();

    expect($html)->toContain('Frühere Preise (2)')->toContain('data-fruehere-liste')->toContain('x-show="offen"')->toContain('Necta-Historie')
        ->toContain('01.01.2026')->toContain('28.02.2026')->toContain('4,00 €/kg')->toContain('Gültig ab')->toContain('Gültig bis')->toContain('Je Basiseinheit')
        ->and(substr_count($html, 'data-aktion="1"'))->toBe(1)->and($html)->toContain('Aktion');

    $leer = ($this->la)('Chefs');
    Livewire::test(\Platform\FoodAlchemist\Livewire\Preise\Fruehere::class, ['itemId' => $leer->id])->assertDontSeeHtml('data-fruehere-preise')->assertSeeHtml('data-fruehere-wurzel');
});

it('Preise-Reiter im Artikel-Modal: zweispaltig ab lg (links Preise, rechts Verlauf sticky), Knopf „Preis manuell setzen“ oben im Preise-Block, Kinder mit Key', function () {
    $la = ($this->la)('Hanos');
    ($this->preis)($la, 4.0, '2026-01-01');
    ($this->historie)($la, 3.0, '2025-06-01', '2025-12-31');

    $html = Livewire::test(\Platform\FoodAlchemist\Livewire\Suppliers\ItemModal::class)->call('oeffnen', $la->id)->html();

    expect($html)->toContain('data-preise-reiter')->toContain('lg:grid-cols-2')->toContain('data-preise-links')->toContain('data-preise-rechts')->toContain('lg:sticky')
        ->toContain('data-preis-liste')->toContain('max-h-[28rem]')
        ->toContain('data-preis-neu-toggle')->toContain('Preis manuell setzen')
        ->toContain('data-sektion="preisverlauf"')->toContain('Frühere Preise (1)')
        ->toContain('wire:key="pv-la-'.$la->id.'"')->toContain('wire:key="pf-la-'.$la->id.'"');            // Kinder mit Key: kein Zuspringen des Modals
    expect(strpos($html, 'data-preise-links'))->toBeLessThan(strpos($html, 'data-preise-rechts'));
});

it('Ein manueller Preis erscheint sofort in Liste und Linie: Modal sendet preise-geaendert, Verlauf und frühere Preise laden daraufhin neu', function () {
    $la = ($this->la)('Hanos');
    ($this->preis)($la, 4.0, '2026-01-01');

    $modal = Livewire::test(\Platform\FoodAlchemist\Livewire\Suppliers\ItemModal::class)->call('oeffnen', $la->id)
        ->set('preisNeu.price', '5,50')->call('preisAnlegen')->assertDispatched('preise-geaendert');
    expect(DB::table('foodalchemist_prices')->where('supplier_item_id', $la->id)->where('price', 5.5)->exists())->toBeTrue()
        ->and($modal->html())->toContain('5,50');                                                         // in der Liste

    // der Verlauf (eigene Komponente) kennt den neuen Preis erst nach dem Ereignis, nicht früher
    $verlauf = Livewire::test(Verlauf::class, ['modus' => 'la', 'objektId' => $la->id])->set('zeitraum', 'alles');
    expect($verlauf->html())->toContain('5,50 €/kg');
    DB::table('foodalchemist_prices')->where('supplier_item_id', $la->id)->where('price', 5.5)->update(['price' => 6.25]);
    $verlauf->dispatch('preise-geaendert', itemId: $la->id);
    expect($verlauf->html())->toContain('6,25 €/kg');
});

it('Kein Preiswechsel im Zeitraum: statt „+0,00 €/Stk pro Monat · +0,0 %“ steht „kein Preiswechsel im Zeitraum“, ohne Trendlinie (MCP: Trend null)', function () {
    $la = ($this->la)('Hanos', 1.0, 'Stk');
    ($this->preis)($la, 1.91, '2025-10-16', '2026-03-01');
    ($this->preis)($la, 1.91, '2026-03-01');                          // zwei Stände, derselbe Preis

    $r = $this->svc->verlauf($this->rootTeam, [$la->id], ...($this->fenster)('alles'));
    $html = Livewire::test(Verlauf::class, ['modus' => 'la', 'objektId' => $la->id])->set('zeitraum', 'alles')->html();

    expect($r['linien'][0]['ohne_wechsel'])->toBeTrue()->and($r['linien'][0]['trend'])->toBeNull()->and($r['linien'][0]['staende'])->toBe(2);
    expect($html)->toContain('kein Preiswechsel im Zeitraum')->not->toContain('+0,00')->not->toContain('data-preisverlauf-trend');

    // mit Wechsel bleibt es beim Trend
    $b = ($this->la)('Chefs', 1.0, 'Stk');
    ($this->preis)($b, 1.91, '2025-10-16', '2026-03-01');
    ($this->preis)($b, 2.10, '2026-03-01');
    expect($this->svc->verlauf($this->rootTeam, [$b->id], ...($this->fenster)('alles'))['linien'][0]['trend'])->not->toBeNull();
});

it('Ein Preis, der gerade eben angelegt wurde, erscheint sofort in der Linie (Fenster reicht bis zum Ende des heutigen Tages)', function () {
    $la = ($this->la)('Hanos');
    ($this->preis)($la, 4.0, '2026-01-01', '2026-06-30 12:00:00');
    DB::table('foodalchemist_prices')->insert(['uuid' => (string) UuidV7::generate(), 'team_id' => $la->team_id, 'supplier_item_id' => $la->id, 'status' => '0', 'price' => 5.5,
        'status_valid_from' => null, 'creation_date' => now(), 'valid_to' => null, 'is_blocked' => 0, 'created_at' => now(), 'updated_at' => now()]);   // wie PriceService::createFor: ab jetzt, ohne Länge

    foreach (['12m', 'alles', 'frei'] as $z) {
        $r = $this->svc->verlauf($this->rootTeam, [$la->id], ...($this->fenster)($z));
        expect(array_column($r['linien'][0]['segmente'], 'wert'))->toBe([4.0, 5.5]);
    }
});

it('Artikel-Modal: ohne Grundprodukt eine Linie und KEIN Lead-Badge; ein Artikel mit GP, der nicht Lead ist, zeigt den Lead als zweite Linie', function () {
    $solo = ($this->la)('Kluth');
    ($this->preis)($solo, 4.0, '2026-01-01', '2026-04-01');
    ($this->preis)($solo, 5.0, '2026-04-01');
    $c = Livewire::test(Verlauf::class, ['modus' => 'la', 'objektId' => $solo->id]);
    expect(substr_count($c->html(), 'data-preisverlauf-linie='))->toBe(1)->and($c->html())->not->toContain('data-lead-badge')->not->toContain('data-preisverlauf-alle');
    $r = $this->svc->verlaufFuerLa($this->rootTeam, $solo->id, ...($this->fenster)('alles'));
    expect($r['linien'][0]['lead'])->toBeFalse()->and($r['linien'][0]['fokus'])->toBeTrue()->and($r['gp_id'])->toBeNull();

    $gp = FoodAlchemistGp::create(['team_id' => $this->rootTeam->id, 'gp_key' => 'pv|lead', 'name' => 'Sahne 30 %']);
    $lead = ($this->la)('Hanos');
    $anderer = ($this->la)('Chefs');
    $dritter = ($this->la)('Metro');
    foreach ([$lead, $anderer, $dritter] as $la) {
        ($this->preis)($la, 4.0, '2026-01-01', '2026-04-01');
        ($this->preis)($la, 5.0, '2026-04-01');
        FoodAlchemistSupplierItemStructure::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'gp_id' => $gp->id]);
    }
    $gp->update(['lead_la_supplier_item_id' => $lead->id]);

    $c = Livewire::test(Verlauf::class, ['modus' => 'la', 'objektId' => $anderer->id]);
    $html = $c->html();
    expect(substr_count($html, 'data-preisverlauf-linie='))->toBe(2)->and($html)->toContain('data-preisverlauf-alle')->toContain('dieser Artikel')->toContain('Hanos');
    $r = $this->svc->verlaufFuerLa($this->rootTeam, $anderer->id, ...($this->fenster)('alles'));
    expect(array_column($r['linien'], 'la_id'))->toBe([$anderer->id, $lead->id])->and($r['linien'][0]['fokus'])->toBeTrue()->and($r['linien'][0]['lead'])->toBeFalse()
        ->and($r['linien'][1]['lead'])->toBeTrue()->and($r['linien'][1]['fokus'])->toBeFalse();

    // Umschalter: alle drei Artikel
    $c->call('alleArtikelUmschalten');
    expect(substr_count($c->html(), 'data-preisverlauf-linie='))->toBe(3);
    $c->call('alleArtikelUmschalten');
    expect(substr_count($c->html(), 'data-preisverlauf-linie='))->toBe(2);

    // Gegenprobe: der Lead selbst zeigt eine Linie und das Badge „Lead von <GP>“ mit Tooltip
    $l = Livewire::test(Verlauf::class, ['modus' => 'la', 'objektId' => $lead->id]);
    expect(substr_count($l->html(), 'data-preisverlauf-linie='))->toBe(1)->and($l->html())->toContain('Lead von Sahne 30 %')->toContain('gilt als EK des Grundprodukts');
});

it('Artikel-Modal: Basiseinheit des Artikels gilt, fremde Einheit am Lead wird weggelassen und gezählt', function () {
    $gp = FoodAlchemistGp::create(['team_id' => $this->rootTeam->id, 'gp_key' => 'pv|einheit', 'name' => 'Milch']);
    $kg = ($this->la)('Hanos', 1.0, 'kg');
    $l = ($this->la)('Metro', 1.0, 'l');
    foreach ([$kg, $l] as $la) {
        ($this->preis)($la, 4.0, '2026-01-01');
        FoodAlchemistSupplierItemStructure::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'gp_id' => $gp->id]);
    }
    $gp->update(['lead_la_supplier_item_id' => $l->id]);
    $r = $this->svc->verlaufFuerLa($this->rootTeam, $kg->id, ...($this->fenster)('alles'));
    expect($r['einheit'])->toBe('€/kg')->and(array_column($r['linien'], 'la_id'))->toBe([$kg->id])->and($r['weggelassen'])->toBe(1);
});
