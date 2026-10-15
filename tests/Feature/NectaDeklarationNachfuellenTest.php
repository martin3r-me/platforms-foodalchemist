<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Models\FoodAlchemistItemDeclaration;
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
 * Jod (`iodized`) für den Bestand nachfüllen. Lieferant 19491 (aktiv): Zeile 1001 Jod NULL (mit Necta-Stand), 1002 Jod schon 3
 * (Necta sagt 1 → bleibt), 1003 Jod NULL, Necta ohne Jod-Wert; Necta liefert zusätzlich 1009 ohne FA-Zeile. Lieferant 20000
 * (aktiv): 2001 Jod NULL. Lieferant 16555 ist bei uns inaktiv: 5001 bleibt NULL.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    config(['foodalchemist.master_team_id' => $this->rootTeam->id]);
    $zeile = function (int $lieferant, int $legacy, array $werte) {
        $l = FoodAlchemistSupplier::firstOrCreate(['legacy_id' => $lieferant], ['team_id' => null, 'name' => "Lieferant {$lieferant}",
            'is_inactive' => $lieferant === 16555, 'stamm_class' => StammPaket::PAKET]);
        $id = FoodAlchemistSupplierItem::create(['team_id' => null, 'legacy_id' => $legacy, 'supplier_id' => $l->id, 'designation' => "Artikel {$legacy}",
            'stamm_class' => StammPaket::PAKET])->id;
        DB::table('foodalchemist_item_declarations')->insert($werte + ['uuid' => (string) UuidV7::generate(), 'team_id' => null,
            'legacy_id' => $legacy, 'supplier_item_id' => $id, 'with_dye' => 1, 'created_at' => now(), 'updated_at' => now()]);
    };
    $zeile(19491, 1001, []);
    $zeile(19491, 1002, ['iodized' => 3]);
    $zeile(19491, 1003, []);
    $zeile(20000, 2001, []);
    $zeile(16555, 5001, []);
    DB::table('foodalchemist_necta_stand')->insert(['objekt' => 'item_declaration', 'legacy_id' => 1001,
        'werte' => json_encode(['with_dye' => 1]), 'created_at' => now(), 'updated_at' => now()]);

    $dk = fn (int $legacy, ?int $jod) => ['supplierItemId' => $legacy, 'declarationWithDye' => 1, 'declarationIodhTlig' => $jod, 'declarationReserve' => 0];
    $this->attrappe = new NectaAttrappe(['supplier-item-declaration-substances' => [
        $dk(1001, 3), $dk(1002, 1), $dk(1003, null), $dk(1009, 3), $dk(2001, 1), $dk(5001, 3),
    ]], [1001 => 19491, 1002 => 19491, 1003 => 19491, 1009 => 19491, 2001 => 20000, 5001 => 16555]);
    $this->app->instance(NectaQuelle::class, $this->attrappe);
    $this->jod = fn (int $legacy) => DB::table('foodalchemist_item_declarations')->where('legacy_id', $legacy)->value('iodized');
    $this->befehl = fn (array $opt = []) => $this->artisan('foodalchemist:necta-deklaration-nachfuellen', $opt + ['--feld' => 'iodized', '--pause-ms' => 0]);
});

it('setzt nur NULL-Werte, ergänzt den Necta-Stand, lässt Gesetztes, Fehlendes und inaktive Lieferanten in Ruhe', function () {
    ($this->befehl)()->assertSuccessful();

    $stand = json_decode(DB::table('foodalchemist_necta_stand')->where('legacy_id', 1001)->value('werte'), true);
    $lauf = DB::table('foodalchemist_necta_laeufe')->where('art', 'nachfuellen')->sole();
    $z = json_decode($lauf->zaehler, true);
    expect(($this->jod)(1001))->toBe(3)->and(($this->jod)(2001))->toBe(1)
        ->and(($this->jod)(1002))->toBe(3)                                       // schon gesetzt: Necta (1) überschreibt nicht
        ->and(($this->jod)(1003))->toBeNull()->and(($this->jod)(5001))->toBeNull()
        ->and(DB::table('foodalchemist_item_declarations')->where('legacy_id', 1009)->exists())->toBeFalse()   // legt nichts an
        ->and($stand)->toBe(['with_dye' => 1, 'iodized' => 3])
        ->and($lauf->status)->toBe('fertig')->and(json_decode($lauf->optionen, true)['feld'])->toBe('iodized')
        ->and(array_intersect_key($z, array_flip(['lieferanten', 'gesetzt', 'schon_gesetzt', 'ohne_fa_zeile', 'stand_ergaenzt', 'necta_mit_wert'])))
        ->toBe(['lieferanten' => 2, 'necta_mit_wert' => 4, 'gesetzt' => 2, 'schon_gesetzt' => 1, 'ohne_fa_zeile' => 1, 'stand_ergaenzt' => 1])
        ->and($this->attrappe->filter)->not->toContain(['supplier-item-declaration-substances', ['supplierId' => 16555]]);
});

it('Probe schreibt nichts außer der Laufzeile; zweiter echter Lauf setzt nichts mehr', function () {
    ($this->befehl)(['--probe' => true])->expectsOutputToContain('PROBELAUF')->assertSuccessful();
    expect(($this->jod)(1001))->toBeNull()->and(DB::table('foodalchemist_necta_laeufe')->where('probe', true)->count())->toBe(1);

    ($this->befehl)()->assertSuccessful();
    ($this->befehl)()->assertSuccessful();
    $z = json_decode(DB::table('foodalchemist_necta_laeufe')->where('probe', false)->orderByDesc('id')->value('zaehler'), true);
    expect($z['gesetzt'])->toBe(0)->and($z['schon_gesetzt'])->toBe(3)->and(($this->jod)(1001))->toBe(3);
});

it('Wiederaufnahme: --fortsetzen macht beim nächsten Lieferanten weiter', function () {
    $lauf = DB::table('foodalchemist_necta_laeufe')->insertGetId(['uuid' => (string) UuidV7::generate(), 'verbindung_id' => 1, 'art' => 'nachfuellen',
        'probe' => false, 'status' => 'fehler', 'cursor' => json_encode(['lieferant_index' => 1]), 'gestartet_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

    ($this->befehl)(['--fortsetzen' => $lauf])->assertSuccessful();

    expect(($this->jod)(1001))->toBeNull()                                         // Lieferant 1 (19491) galt als erledigt
        ->and(($this->jod)(2001))->toBe(1)
        ->and(DB::table('foodalchemist_necta_laeufe')->find($lauf)->status)->toBe('fertig');
});

it('unbekannte Spalte bricht ab, ohne zu lesen', function () {
    ($this->befehl)(['--feld' => 'gibt_es_nicht'])->assertFailed();
    expect($this->attrappe->aufrufe)->toBe([]);
});

it('Vollvergleich nach dem Nachfüllen: kein Jod-Prüffall für gefüllte Zeilen', function () {
    $this->attrappe->daten += ['suppliers' => [['id' => 19491, 'name' => 'Lieferant 19491'], ['id' => 20000, 'name' => 'Lieferant 20000']],
        'supplier-items' => [NectaAttrappe::artikel(1001, 19491, 'Artikel 1001'), NectaAttrappe::artikel(1002, 19491, 'Artikel 1002'),
            NectaAttrappe::artikel(1003, 19491, 'Artikel 1003'), NectaAttrappe::artikel(2001, 20000, 'Artikel 2001')],
        'supplier-item-prices' => [], 'supplier-item-allergens' => [], 'supplier-item-nutritional-values' => []];
    ($this->befehl)()->assertSuccessful();

    app(NectaAbgleichService::class)->lauf(1, ['voll' => true, 'erzwingen' => true, 'pause_ms' => 0]);

    $jod = DB::table('foodalchemist_necta_pruefaelle')->where('art', 'deklaration_aenderung')->where('feld', 'iodized')->pluck('legacy_id')->all();
    expect($jod)->toBe([1002]);   // nur der echte Widerspruch (FA 3, Necta 1), nicht die nachgefüllten
});

it('Jod ist freiwillig: nie in den Pflicht-Stoffen (Legende, Nummerierung, Kennzeichnung)', function () {
    expect(FoodAlchemistItemDeclaration::STOFFE)->not->toHaveKey('iodized')->toHaveCount(18)
        ->and(FoodAlchemistItemDeclaration::FREIWILLIG)->toHaveKey('iodized');
});
