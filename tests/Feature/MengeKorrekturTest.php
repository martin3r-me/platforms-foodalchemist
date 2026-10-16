<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\Necta\NectaAbgleichService;
use Platform\FoodAlchemist\Services\Necta\NectaNachholService;
use Platform\FoodAlchemist\Services\Necta\NectaQuelle;
use Platform\FoodAlchemist\Services\PriceService;
use Platform\FoodAlchemist\Support\StammPaket;
use Platform\FoodAlchemist\Tests\Support\NectaAttrappe;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Mengen-Korrektur aus der Bezeichnung (Entscheid Dominique 16.10.2026): `qty_korrigiert` + Quelle, Necta-`qty` bleibt,
 * Mengen-Rechnungen lesen `qty_korrigiert ?? qty`. Fall io: „0,2 APFELSAFT KLAR MW“ qty 0,008 × 24 zeigte 131 €/l.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    config(['foodalchemist.master_team_id' => $this->rootTeam->id]);
    $this->lieferant = FoodAlchemistSupplier::create(['team_id' => null, 'legacy_id' => 25080, 'name' => 'Handelshof EDI', 'stamm_class' => StammPaket::PAKET]);
    $this->artikel = fn (string $bez, float $qty, float $n, string $einheit = 'l', ?int $legacy = null) => FoodAlchemistSupplierItem::create([
        'team_id' => null, 'legacy_id' => $legacy, 'supplier_id' => $this->lieferant->id, 'designation' => $bez, 'qty' => $qty,
        'qty_ordering_per_packaging' => $n, 'unit_code' => $einheit, 'stamm_class' => StammPaket::PAKET]);
});

it('Modell: korrigiert beim Anlegen, leitet bei Änderung neu ab, Necta-qty bleibt; Vergleichspreis nimmt die korrigierte Menge', function () {
    $saft = ($this->artikel)('0,2 APFELSAFT KLAR MW', 0.008, 24);
    expect((float) $saft->fresh()->qty_korrigiert)->toBe(0.2)->and($saft->fresh()->qty_korrektur_quelle)->toBe('bezeichnung_edi')
        ->and((float) $saft->fresh()->qty)->toBe(0.008);                                             // Necta-Rohwert bleibt

    $preis = app(PriceService::class)->vergleichspreis($saft->fresh(), 1.049);
    expect(round($preis['value'], 3))->toBe(5.245)->and($preis['unit'])->toBe('€/l');                 // statt 131,125 €/l

    $saft->update(['qty' => 0.2]);                                                                     // Necta liefert später korrekt
    expect($saft->fresh()->qty_korrigiert)->toBeNull()->and($saft->fresh()->qty_korrektur_quelle)->toBeNull();

    $kiste = ($this->artikel)('2KG KISTE KENIABOHEN LOSE', 1.0, 2, 'kg');                            // Broscheit: korrekt
    $majoran = ($this->artikel)('7,5G MAJORAN GEREBELT OST', 0.008, 1, 'kg');                          // nur gerundet
    expect($kiste->fresh()->qty_korrigiert)->toBeNull()->and($majoran->fresh()->qty_korrigiert)->toBeNull();
});

it('rohe Abfrage-Zeile: mengeSql und mengeVon nehmen die korrigierte Menge', function () {
    $saft = ($this->artikel)('0,33 FLENSBURGER ALKOHOLFREI MW', 0.017, 20);
    $zeile = DB::table('foodalchemist_supplier_items AS i')->where('i.id', $saft->id)
        ->selectRaw(FoodAlchemistSupplierItem::mengeSql('i').' AS menge')->addSelect('i.qty', 'i.qty_korrigiert')->first();
    expect((float) $zeile->menge)->toBe(0.33)->and(FoodAlchemistSupplierItem::mengeVon($zeile))->toBe(0.33)
        ->and(FoodAlchemistSupplierItem::mengeVon((object) ['qty' => '1.5']))->toBe(1.5);
});

it('Befehl: Probe schreibt nichts, echter Lauf setzt und entfernt, zweiter Lauf ändert nichts', function () {
    $saft = DB::table('foodalchemist_supplier_items')->insertGetId(['uuid' => (string) UuidV7::generate(), 'team_id' => null,
        'supplier_id' => $this->lieferant->id, 'designation' => '0,2 APFELSAFT KLAR MW', 'qty' => 0.008, 'qty_ordering_per_packaging' => 24,
        'unit_code' => 'l', 'stamm_class' => StammPaket::PAKET, 'created_at' => now(), 'updated_at' => now()]);
    $alt = DB::table('foodalchemist_supplier_items')->insertGetId(['uuid' => (string) UuidV7::generate(), 'team_id' => null,
        'supplier_id' => $this->lieferant->id, 'designation' => '40G GEWUERZ', 'qty' => 0.04, 'qty_ordering_per_packaging' => 10,
        'unit_code' => 'kg', 'qty_korrigiert' => 0.4, 'qty_korrektur_quelle' => 'bezeichnung_edi', 'stamm_class' => StammPaket::PAKET,
        'created_at' => now(), 'updated_at' => now()]);   // veraltete Korrektur: Regel greift nicht → entfernen

    $this->artisan('foodalchemist:menge-korrigieren', ['--probe' => true])->expectsOutputToContain('PROBELAUF')->assertSuccessful();
    expect(DB::table('foodalchemist_supplier_items')->where('id', $saft)->value('qty_korrigiert'))->toBeNull();

    $this->artisan('foodalchemist:menge-korrigieren')->assertSuccessful();
    expect((float) DB::table('foodalchemist_supplier_items')->where('id', $saft)->value('qty_korrigiert'))->toBe(0.2)
        ->and(DB::table('foodalchemist_supplier_items')->where('id', $alt)->value('qty_korrigiert'))->toBeNull();

    $this->artisan('foodalchemist:menge-korrigieren')
        ->expectsTable(['Zähler', 'Wert'], [['geprueft', 2], ['korrigiert', 1], ['geaendert', 0], ['korrektur_entfernt', 0]])
        ->assertSuccessful();
});

it('Necta: Nachholer korrigiert neue Artikel; Vollvergleich leitet bei Änderung neu ab, ohne Prüffall und ohne Stand-Feld', function () {
    $bestand = ($this->artikel)('0,5 BOLTEN URWEIZEN MW', 0.025, 20, 'l', 36360416);
    $attrappe = new NectaAttrappe([
        'suppliers' => [['id' => 25080, 'name' => 'Handelshof EDI']],
        'supplier-items' => [
            NectaAttrappe::artikel(36360416, 25080, '0,33 BOLTEN URWEIZEN MW', ['quantity' => 0.017, 'quantityOrderingUnitsPerPackagingUnit' => 20, 'unitId' => 1004]),
            NectaAttrappe::artikel(36361143, 25080, '0,2 APFELSAFT KLAR MW', ['quantity' => 0.008, 'quantityOrderingUnitsPerPackagingUnit' => 24, 'unitId' => 1004]),
        ],
        'supplier-item-prices' => [], 'supplier-item-allergens' => [], 'supplier-item-nutritional-values' => [], 'supplier-item-declaration-substances' => [],
    ], [36360416 => 25080, 36361143 => 25080]);
    $this->app->instance(NectaQuelle::class, $attrappe);

    app(NectaNachholService::class)->lauf(1, ['pause_ms' => 0]);
    expect((float) FoodAlchemistSupplierItem::where('legacy_id', 36361143)->value('qty_korrigiert'))->toBe(0.2);

    app(NectaAbgleichService::class)->lauf(1, ['voll' => true, 'erzwingen' => true, 'pause_ms' => 0]);
    $stand = json_decode(DB::table('foodalchemist_necta_stand')->where('objekt', 'supplier_item')->where('legacy_id', 36360416)->value('werte'), true);
    expect($bestand->fresh()->designation)->toBe('0,33 BOLTEN URWEIZEN MW')
        ->and((float) $bestand->fresh()->qty_korrigiert)->toBe(0.33)->and((float) $bestand->fresh()->qty)->toBe(0.017)
        ->and($stand)->not->toHaveKey('qty_korrigiert')
        ->and(DB::table('foodalchemist_necta_pruefaelle')->where('feld', 'like', 'qty%')->count())->toBe(0);
});
