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

    $this->artisan('foodalchemist:necta-preishistorie-nachholen', ['--probe' => true, '--pause-ms' => 0])
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

it('ohne --probe verweigert der Befehl (Import erst nach belegter Zuordnung)', function () {
    $this->artisan('foodalchemist:necta-preishistorie-nachholen', ['--pause-ms' => 0])->assertFailed();
    expect($this->attrappe->aufrufe)->toBe([]);
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
