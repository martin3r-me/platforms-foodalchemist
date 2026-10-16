<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\Necta\NectaAbgleichService;
use Platform\FoodAlchemist\Services\Necta\NectaQuelle;
use Platform\FoodAlchemist\Support\StammPaket;
use Platform\FoodAlchemist\Tests\Support\NectaAttrappe;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Lieferstatus aus dem Necta-Preis-Lebenszyklus (16.10.2026). Fall io: Kluth LA 318750 „Paprika Mix …“: Normalpreis 5,69
 * (Historie offen ab 15.04.), ab 14.10. stellt Necta DIESELBE Preiszeile auf status 2 / price NULL um → nicht bestellbar.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    config(['foodalchemist.master_team_id' => $this->rootTeam->id]);
    $l = $this->lieferant = FoodAlchemistSupplier::create(['team_id' => null, 'legacy_id' => 25700, 'name' => 'Kluth', 'stamm_class' => StammPaket::PAKET]);
    $this->artikel = FoodAlchemistSupplierItem::create(['team_id' => null, 'legacy_id' => 28516822, 'supplier_id' => $l->id,
        'designation' => 'Paprika Mix, Chinakohl, Weißkohl, Porree, Möhre, Mungobohnensprossen', 'stamm_class' => StammPaket::PAKET]);
    $this->preis = fn (int $legacy, ?float $p, string $status, string $ab) => DB::table('foodalchemist_prices')->insertGetId([
        'uuid' => (string) UuidV7::generate(), 'team_id' => $this->rootTeam->id, 'legacy_id' => $legacy, 'supplier_item_id' => $this->artikel->id,
        'status' => $status, 'price' => $p, 'price_partial' => $p, 'status_valid_from' => $ab, 'is_blocked' => false, 'created_at' => now(), 'updated_at' => now()]);
    $this->historie = fn (float $p, string $ab, ?string $bis = null, string $status = '0') => DB::table('foodalchemist_price_history')->insertGetId([
        'uuid' => (string) UuidV7::generate(), 'necta_historie_id' => random_int(1, PHP_INT_MAX), 'supplier_item_id' => $this->artikel->id,
        'team_id' => $this->rootTeam->id, 'status' => $status, 'price' => $p, 'status_valid_from' => $ab, 'valid_to' => $bis, 'is_blocked' => false,
        'quelle' => 'necta_historie', 'created_at' => now(), 'updated_at' => now()]);
    $this->necta = function (array $preise) {
        $this->app->instance(NectaQuelle::class, new NectaAttrappe(['suppliers' => [['id' => 25700, 'name' => 'Kluth']], 'supplier-items' => [],
            'supplier-item-prices' => $preise], [28516822 => 25700]));
    };
    $this->lauf = fn () => app(NectaAbgleichService::class)->lauf(1, ['pause_ms' => 0, 'seit' => '2026-10-01T00:00:00']);
});

it('Necta stellt dieselbe Zeile auf status 2 / NULL um → eingestellt, nicht lieferbar, Historie endet am Eingestellt-Beginn', function () {
    $zeile = ($this->preis)(127646230, 5.69, '0', '2026-04-14 22:00:00');
    $h = ($this->historie)(5.69, '2026-04-14 22:00:00');
    ($this->necta)([NectaAttrappe::preis(127646230, 28516822, 0, ['status' => '2', 'price' => null, 'pricePartial' => null,
        'statusValidFrom' => '2026-10-14T00:00:00', 'changeDate' => '2026-10-15T11:34:00'])]);

    $r = ($this->lauf)();

    $a = $this->artikel->fresh();
    expect($a->lieferstatus)->toBe('eingestellt')->and((string) $a->lieferstatus_seit)->toStartWith('2026-10-13 22:00:00')   // Berlin → UTC
        ->and($a->lieferstatus_quelle)->toBe('necta')
        ->and(DB::table('foodalchemist_price_history')->where('id', $h)->value('valid_to'))->toBe('2026-10-13 22:00:00')
        ->and(DB::table('foodalchemist_prices')->where('id', $zeile)->value('status'))->toBe('2')
        ->and($r['zaehler']['lieferstatus_eingestellt'])->toBe(1)
        ->and(FoodAlchemistSupplierItem::lieferbar()->whereKey($a->id)->exists())->toBeFalse();
});

it('neue Eingestellt-Zeile schließt offene Zeilen ANDEREN Status mit Sicherung (die Lücke der Vorgänger-Regel)', function () {
    $normal = ($this->preis)(900, 5.69, '0', '2026-04-14 22:00:00');
    ($this->necta)([NectaAttrappe::preis(901, 28516822, 0, ['status' => '2', 'price' => null, 'pricePartial' => null, 'statusValidFrom' => '2026-10-14T00:00:00'])]);

    ($this->lauf)();

    expect(DB::table('foodalchemist_prices')->where('id', $normal)->value('valid_to'))->toBe('2026-10-13 22:00:00')
        ->and(DB::table('foodalchemist_price_history')->where('price_id', $normal)->where('quelle', 'necta_eingestellt')->exists())->toBeTrue()
        ->and($this->artikel->fresh()->lieferstatus)->toBe('eingestellt');
});

it('läuft aus (status 2 mit Preis): bleibt lieferbar und aktiv, nichts wird begrenzt; späterer Normalpreis hebt den Status auf', function () {
    $zeile = ($this->preis)(900, 5.69, '0', '2026-04-14 22:00:00');
    ($this->necta)([NectaAttrappe::preis(900, 28516822, 5.69, ['status' => '2', 'statusValidFrom' => '2026-10-10T00:00:00'])]);
    ($this->lauf)();
    $a = $this->artikel->fresh();
    expect($a->lieferstatus)->toBe('auslaufend')->and(FoodAlchemistSupplierItem::lieferbar()->whereKey($a->id)->exists())->toBeTrue()
        ->and(DB::table('foodalchemist_prices')->where('id', $zeile)->value('valid_to'))->toBeNull();

    ($this->necta)([NectaAttrappe::preis(902, 28516822, 5.99, ['status' => '0', 'statusValidFrom' => '2026-10-20T00:00:00'])]);
    $this->travel(1)->minutes();
    ($this->lauf)();
    expect($this->artikel->fresh()->lieferstatus)->toBeNull()->and($this->artikel->fresh()->lieferstatus_quelle)->toBeNull();
});

it('Widerspruch: Normalpreis in der Necta-Historie NACH dem Eingestellt-Beginn → Prüffall, nichts begrenzt', function () {
    ($this->preis)(900, 5.69, '0', '2026-04-14 22:00:00');
    $spaeter = ($this->historie)(5.99, '2026-10-20 22:00:00');
    ($this->necta)([NectaAttrappe::preis(901, 28516822, 0, ['status' => '2', 'price' => null, 'pricePartial' => null, 'statusValidFrom' => '2026-10-14T00:00:00'])]);

    $r = ($this->lauf)();

    expect(DB::table('foodalchemist_necta_pruefaelle')->where('art', 'eingestellt_widerspruch')->value('legacy_id'))->toBe(901)
        ->and($r['zaehler']['pruefaelle']['eingestellt_widerspruch'])->toBe(1)
        ->and(DB::table('foodalchemist_prices')->where('legacy_id', 900)->value('valid_to'))->toBeNull()
        ->and(DB::table('foodalchemist_price_history')->where('id', $spaeter)->value('valid_to'))->toBeNull();
});

it('Handpflege gewinnt: ein von Hand gesetzter Lieferstatus wird nie überschrieben', function () {
    DB::table('foodalchemist_supplier_items')->where('id', $this->artikel->id)->update(['lieferstatus' => 'auslaufend', 'lieferstatus_quelle' => 'hand']);
    ($this->necta)([NectaAttrappe::preis(901, 28516822, 0, ['status' => '2', 'price' => null, 'pricePartial' => null, 'statusValidFrom' => '2026-10-14T00:00:00'])]);
    ($this->lauf)();
    expect($this->artikel->fresh()->lieferstatus)->toBe('auslaufend')->and($this->artikel->fresh()->lieferstatus_quelle)->toBe('hand');
});

it('Befehl: Bestand ableiten, Probe schreibt nichts, zweiter Lauf ändert nichts', function () {
    ($this->preis)(900, 5.69, '0', '2026-04-14 22:00:00');
    ($this->preis)(901, null, '2', '2026-10-13 22:00:00');
    $h = ($this->historie)(5.69, '2026-04-14 22:00:00');

    $this->artisan('foodalchemist:necta-lieferstatus', ['--probe' => true])->expectsOutputToContain('PROBELAUF')->assertSuccessful();
    expect($this->artikel->fresh()->lieferstatus)->toBeNull()->and(DB::table('foodalchemist_price_history')->where('id', $h)->value('valid_to'))->toBeNull();

    $this->artisan('foodalchemist:necta-lieferstatus')->assertSuccessful();
    expect($this->artikel->fresh()->lieferstatus)->toBe('eingestellt')
        ->and(DB::table('foodalchemist_price_history')->where('id', $h)->value('valid_to'))->toBe('2026-10-13 22:00:00')
        ->and(DB::table('foodalchemist_prices')->where('legacy_id', 900)->value('valid_to'))->toBe('2026-10-13 22:00:00');

    $this->artisan('foodalchemist:necta-lieferstatus')
        ->expectsTable(['Zähler', 'Wert'], [['geprueft', 1], ['auslaufend', 0], ['eingestellt', 1], ['aufgehoben', 0], ['geaendert', 0],
            ['preise_begrenzt', 0], ['eingestellt_widerspruch', 0]])
        ->assertSuccessful();
});

it('Artikelliste: Filter „nur lieferbare“ blendet eingestellte aus', function () {
    DB::table('foodalchemist_supplier_items')->where('id', $this->artikel->id)->update(['lieferstatus' => 'eingestellt', 'lieferstatus_seit' => '2026-10-13 22:00:00', 'lieferstatus_quelle' => 'necta']);
    $svc = app(\Platform\FoodAlchemist\Services\SupplierItemService::class);
    $nur = $svc->paginateForSupplier($this->rootTeam, $this->lieferant->id, ['onlyActive' => true], 100);
    $alle = $svc->paginateForSupplier($this->rootTeam, $this->lieferant->id, ['onlyActive' => false], 100);
    expect(collect($nur->items())->pluck('id'))->not->toContain($this->artikel->id)
        ->and(collect($alle->items())->pluck('id'))->toContain($this->artikel->id);
});
