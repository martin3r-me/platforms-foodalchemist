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

it('Trend ist zeitgewichtet: ein kurzer Aktionspreis verzerrt ihn nicht (Referenz: tägliche Stützpunkte); über die Änderungspunkte allein läge er weit daneben', function () {
    $la = ($this->la)('Hanos');
    ($this->preis)($la, 10.00, '2025-08-01', '2026-06-25');
    ($this->preis)($la, 5.00, '2026-06-25', null, '2');              // 5 Tage Aktion am Ende
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
        ->and(Verlauf::trendText(null, '€/kg'))->toBeNull();
});

it('Diagramm: Stufenpfad, gestrichelte Trendlinie in derselben Farbe, Lead dicker, Achsen, Tooltip mit Rohpreis je Gebinde', function () {
    $la = ($this->la)('Hanos', 2.0, 'kg');
    ($this->preis)($la, 8.0, '2026-01-01', '2026-04-01');
    ($this->preis)($la, 10.0, '2026-04-01');

    $daten = $this->svc->verlauf($this->rootTeam, [$la->id], ...($this->fenster)('alles'), ...[$la->id]);
    $d = Verlauf::diagramm($daten);
    $l = $d['linien'][0];

    expect($l['pfad'])->toStartWith('M')->toContain('V')->toContain('H')->and($l['breite'])->toBe(3)->and($l['lead'])->toBeTrue()
        ->and($l['trend'])->not->toBeNull()->and($l['punkte'][0]['tip'])->toContain('Gebinde 8,00 €')->toContain('4,00 €/kg')->toContain('01.01.2026')
        ->and($d['achse_y'])->toHaveCount(5)->and($d['achse_x'])->toHaveCount(4)->and($d['einheit'])->toBe('€/kg')
        ->and($l['trend_text'])->toContain('€/kg pro Monat')->toContain('% pro Jahr');
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
