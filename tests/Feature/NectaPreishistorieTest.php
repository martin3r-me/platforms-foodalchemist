<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\Necta\NectaApiQuelle;
use Platform\FoodAlchemist\Services\Necta\NectaPreishistorieService;
use Platform\FoodAlchemist\Services\Necta\NectaQuelle;
use Platform\FoodAlchemist\Support\StammPaket;
use Platform\FoodAlchemist\Tests\Support\NectaAttrappe;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Necta PR 2b, Stufe 1: Analyse-Probe der Preis-Historie. Feldform wie die echte Stichprobe vom 15.10.2026
 * ({"id","supplierItemId","tenantId","type","price","priceOrderingUnit","amountFrom","validFrom","validTo","source"}).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    config(['foodalchemist.master_team_id' => $this->rootTeam->id]);
    $l = FoodAlchemistSupplier::create(['team_id' => null, 'legacy_id' => 19491, 'name' => 'Bäckerei', 'stamm_class' => StammPaket::PAKET]);
    $alt = FoodAlchemistSupplier::create(['team_id' => null, 'legacy_id' => 16555, 'name' => 'Handelshof alt', 'is_inactive' => true, 'stamm_class' => StammPaket::PAKET]);
    foreach ([[36674788, $l], [36674789, $l], [55500001, $alt]] as [$legacy, $lief]) {
        FoodAlchemistSupplierItem::create(['team_id' => null, 'legacy_id' => $legacy, 'supplier_id' => $lief->id, 'designation' => "Artikel {$legacy}",
            'stamm_class' => StammPaket::PAKET]);
    }
    $h = fn (int $id, int $artikel, string $type, float $preis, float $ab, string $von, string $bis) => ['id' => $id, 'supplierItemId' => $artikel,
        'tenantId' => 1494, 'type' => $type, 'price' => $preis, 'priceOrderingUnit' => $preis, 'amountFrom' => $ab, 'validFrom' => $von,
        'validTo' => $bis, 'source' => 175492];
    $this->attrappe = new NectaAttrappe(['supplier-item-price-historys' => [
        $h(1, 36674788, 'N', 0.89, 0, '2026-01-01T00:00:00', '2026-05-31T23:59:59'),
        $h(2, 36674788, 'N', 0.95, 0, '2026-06-01T00:00:00', '2026-10-14T23:59:59'),
        $h(3, 36674788, 'N', 0.99, 0, '2026-10-15T00:00:00', '3000-12-31T23:59:59'),
        $h(4, 36674788, 'A', 0.90, 10, '2026-10-15T00:00:00', '3000-12-31T23:59:59'),
        $h(5, 36674789, 'N', 2.40, 0, '2026-10-15T00:00:00', '3000-12-31T23:59:59'),
        $h(9, 55500001, 'N', 9.99, 0, '2026-10-15T00:00:00', '3000-12-31T23:59:59'),   // inaktiver Lieferant
    ]], [36674788 => 19491, 36674789 => 19491, 55500001 => 16555]);
    $this->app->instance(NectaQuelle::class, $this->attrappe);
});

it('Probe misst type, amountFrom, source und Zeilen je Artikel, schreibt nur die Laufzeile, lässt inaktive aus', function () {
    $vorher = [DB::table('foodalchemist_prices')->count(), DB::table('foodalchemist_price_history')->count()];

    $this->artisan('foodalchemist:necta-preishistorie-nachholen', ['--analyse' => true, '--pause-ms' => 0])
        ->expectsOutputToContain('type: A 1 · N 4')
        ->expectsOutputToContain('amountFrom: 0 4 · 10 1')
        ->expectsOutputToContain('source (Top 10): 175492 5')
        ->expectsOutputToContain('übersprungen: Handelshof alt (Necta 16555) — bei uns inaktiv')
        ->assertSuccessful();

    $lauf = DB::table('foodalchemist_necta_laeufe')->where('art', 'preishistorie')->sole();
    $z = json_decode($lauf->zaehler, true);
    $b = json_decode($lauf->beispiele, true);
    expect([DB::table('foodalchemist_prices')->count(), DB::table('foodalchemist_price_history')->count()])->toBe($vorher)
        ->and((bool) $lauf->probe)->toBeTrue()
        ->and($z['zeilen_bekannte_artikel'])->toBe(5)->and($z['artikel_mit_historie'])->toBe(2)
        ->and($z['max_zeilen_je_artikel'])->toBe(4)->and($z['offen_bis_3000'])->toBe(3)
        ->and(array_column($b['folge']['zeilen'], 'id'))->toBe([1, 2, 3, 4])            // chronologisch, Staffel nach Grundpreis
        ->and($b['amount_from_gross'][0]['id'])->toBe(4)
        ->and(collect($this->attrappe->filter)->pluck(1)->pluck('supplierId')->unique()->values()->all())->toBe([19491]);
});

// ── Stufe 2: Import (16.10.2026) ──────────────────────────────────────────────────────────────────────────────────────────

it('Import: N → 0, A → A, je Artikel+Start+Status eine Zeile (kleinste Staffel), offen = NULL, Zeit UTC, team_id = Team der Preise', function () {
    $this->attrappe->daten['supplier-item-price-historys'][] = ['id' => 6, 'supplierItemId' => 36674788, 'tenantId' => 1494, 'type' => 'N',
        'price' => 0.95, 'priceOrderingUnit' => 0.95, 'amountFrom' => 2, 'validFrom' => '2026-10-15T00:00:00', 'validTo' => '3000-12-31T23:59:59', 'source' => 175492];
    $this->attrappe->daten['supplier-item-price-historys'][] = ['id' => 7, 'supplierItemId' => 36674789, 'tenantId' => 1494, 'type' => 'X',
        'price' => 1.0, 'priceOrderingUnit' => 1.0, 'amountFrom' => 0, 'validFrom' => '2026-01-01T00:00:00', 'validTo' => '3000-12-31T23:59:59', 'source' => 1];

    $this->artisan('foodalchemist:necta-preishistorie-nachholen', ['--pause-ms' => 0])->assertSuccessful();

    $h = DB::table('foodalchemist_price_history')->where('quelle', 'necta_historie')->orderBy('necta_historie_id')->get()->keyBy('necta_historie_id');
    $a = FoodAlchemistSupplierItem::where('legacy_id', 36674788)->value('id');
    expect($h->keys()->all())->toBe([1, 2, 3, 4, 5])                                                   // 6 = Staffel, 7 = type X, 9 = inaktiv
        ->and($h[1]->status)->toBe('0')->and($h[4]->status)->toBe('A')   // A = Aktion; '2' heißt in prices „läuft aus“
        ->and($h[1]->status_valid_from)->toBe('2025-12-31 23:00:00')->and($h[1]->valid_to)->toBe('2026-05-31 21:59:59')   // Berlin → UTC
        ->and($h[3]->valid_to)->toBeNull()                                                                  // 3000-12-31 = offen
        // Team der Preise (Master), NICHT das des globalen Artikels (NULL): sonst verwirft die Preis-Linie die Historie
        ->and((int) $h[3]->supplier_item_id)->toBe($a)->and((int) $h[3]->team_id)->toBe($this->rootTeam->id)->and($h[3]->price_id)->toBeNull()
        ->and((float) $h[3]->price)->toBe(0.99)->and((bool) $h[3]->is_blocked)->toBeFalse()
        ->and(json_decode($h[4]->details, true))->toBe(['source' => 175492, 'amount_from' => 10, 'type' => 'A']);

    $z = json_decode(DB::table('foodalchemist_necta_laeufe')->where('art', 'preishistorie')->value('zaehler'), true);
    expect($z['staffel_verworfen'])->toBe(1)->and($z['typ_unbekannt'])->toBe(1)->and($z['geschrieben'])->toBe(5);
});

it('Import ist idempotent über die Necta-History-ID; Probe schreibt nichts', function () {
    $this->artisan('foodalchemist:necta-preishistorie-nachholen', ['--probe' => true, '--pause-ms' => 0])
        ->expectsOutputToContain('PROBELAUF')->assertSuccessful();
    expect(DB::table('foodalchemist_price_history')->count())->toBe(0);

    $this->artisan('foodalchemist:necta-preishistorie-nachholen', ['--pause-ms' => 0])->assertSuccessful();
    $this->artisan('foodalchemist:necta-preishistorie-nachholen', ['--pause-ms' => 0])->assertSuccessful();
    $z = json_decode(DB::table('foodalchemist_necta_laeufe')->where('art', 'preishistorie')->where('probe', false)->orderByDesc('id')->value('zaehler'), true);
    expect(DB::table('foodalchemist_price_history')->count())->toBe(5)
        ->and($z['schon_vorhanden'])->toBe(5)->and($z['geschrieben'])->toBe(0);
});

it('--seit lässt ältere Stände aus; --fortsetzen macht beim nächsten Lieferanten weiter', function () {
    $this->artisan('foodalchemist:necta-preishistorie-nachholen', ['--seit' => '2026-06-01T00:00:00', '--pause-ms' => 0])->assertSuccessful();
    expect(DB::table('foodalchemist_price_history')->pluck('necta_historie_id')->map(fn ($v) => (int) $v)->sort()->values()->all())->toBe([2, 3, 4, 5]);

    DB::table('foodalchemist_price_history')->delete();
    $lauf = DB::table('foodalchemist_necta_laeufe')->insertGetId(['uuid' => (string) Symfony\Component\Uid\UuidV7::generate(), 'verbindung_id' => 1,
        'art' => 'preishistorie', 'probe' => false, 'status' => 'fehler', 'cursor' => json_encode(['lieferant_index' => 1]),
        'gestartet_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    $this->artisan('foodalchemist:necta-preishistorie-nachholen', ['--fortsetzen' => $lauf, '--pause-ms' => 0])->assertSuccessful();
    expect(DB::table('foodalchemist_price_history')->count())->toBe(0)                  // einziger aktiver Lieferant galt als erledigt
        ->and(DB::table('foodalchemist_necta_laeufe')->find($lauf)->status)->toBe('fertig');
});

it('wirkt der supplierId-Filter nicht, bricht der Lieferant nach Seite 1 ab', function () {
    // Quelle ignoriert den Filter und liefert nur Zeilen fremder Artikel
    $ignorant = new class implements NectaQuelle {
        public int $seiten = 0;

        public function seite(string $ressource, int $seite, int $groesse, array $filter = []): array
        {
            $this->seiten++;
            $items = [['id' => 1, 'supplierItemId' => 99999999, 'type' => 'N', 'price' => 1, 'priceOrderingUnit' => 1, 'amountFrom' => 0,
                'validFrom' => '2026-01-01T00:00:00', 'validTo' => '3000-12-31T23:59:59', 'source' => 1]];

            return NectaApiQuelle::normalisiere(['items' => $items, 'totalCount' => 1513596, 'pageNumber' => $seite, 'pageSize' => $groesse,
                'totalPages' => 3028], $seite, $groesse);
        }
    };
    $r = app(NectaPreishistorieService::class)->analyse(1, ['pause_ms' => 0], null, $ignorant);

    expect($ignorant->seiten)->toBe(1)
        ->and($r['zaehler']['filter_wirkt_nicht'])->toBe([['legacy_id' => 19491, 'total' => 1513596]]);
});

it('Preis-Linie (PreisVerlaufService) zeigt Historie UND aktuelle Necta-Preiszeile zusammen, weil beide dem Team der Preise gehören', function () {
    // Fall LA 296294 auf io: globaler Artikel (team NULL), aktuelle Preiszeile im Master-Team wie vom Nachholer geschrieben
    $la = FoodAlchemistSupplierItem::where('legacy_id', 36674788)->first();
    $la->update(['qty' => 1, 'unit_code' => 'kg']);
    DB::table('foodalchemist_prices')->insert(['uuid' => (string) Symfony\Component\Uid\UuidV7::generate(), 'team_id' => $this->rootTeam->id,
        'legacy_id' => 138432829, 'supplier_item_id' => $la->id, 'status' => '0', 'price' => 1.05, 'status_valid_from' => '2026-10-16 00:00:00',
        'valid_to' => null, 'is_blocked' => 0, 'created_at' => now(), 'updated_at' => now()]);
    $this->artisan('foodalchemist:necta-preishistorie-nachholen', ['--pause-ms' => 0])->assertSuccessful();

    $this->actingAs($this->makeUser($this->rootTeam));
    \Platform\FoodAlchemist\Services\PreisVerlaufService::flush();
    $svc = app(\Platform\FoodAlchemist\Services\PreisVerlaufService::class);
    [$von, $bis] = $svc->fenster('alles', null, null, \Illuminate\Support\Carbon::parse('2026-10-20'));
    $r = $svc->verlauf($this->rootTeam, [$la->id], $von, $bis, $la->id);

    $s = $r['linien'][0]['segmente'];
    expect(array_column($s, 'preis_gebinde'))->toBe([0.89, 0.95, 0.99, 1.05])                     // 3 Historienstände + aktuelle Zeile
        ->and(array_column($s, 'quelle'))->toBe(['historie', 'historie', 'historie', 'preis']);
});
