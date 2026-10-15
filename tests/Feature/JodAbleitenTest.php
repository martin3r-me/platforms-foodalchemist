<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Models\FoodAlchemistItemDeclaration;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Services\GpAggregateService;
use Platform\FoodAlchemist\Services\Necta\NectaAbgleichService;
use Platform\FoodAlchemist\Services\Necta\NectaNachholService;
use Platform\FoodAlchemist\Services\Necta\NectaQuelle;
use Platform\FoodAlchemist\Support\StammPaket;
use Platform\FoodAlchemist\Tests\Support\NectaAttrappe;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Jod aus der Zutatenliste (Teil c, 15.10.2026): eigene Spalte `supplier_items.iodized_abgeleitet`, Necta-Rohwert
 * `item_declarations.iodized` bleibt unberührt, Aggregation = max(Necta-Flag, abgeleitet).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    config(['foodalchemist.master_team_id' => $this->rootTeam->id]);
    $this->lieferant = FoodAlchemistSupplier::create(['team_id' => null, 'legacy_id' => 19491, 'name' => 'Bäckerei', 'stamm_class' => StammPaket::PAKET]);
    $this->gp = $this->makeGp($this->rootTeam, 'Brezel');
    $this->la = function (?string $zutaten, ?int $nectaJod = null, bool $mitDeklaration = true, ?int $legacy = null) {
        $item = FoodAlchemistSupplierItem::create(['team_id' => null, 'legacy_id' => $legacy, 'supplier_id' => $this->lieferant->id,
            'designation' => 'LA '.uniqid(), 'ingredients_supplier' => $zutaten, 'stamm_class' => StammPaket::PAKET]);
        FoodAlchemistSupplierItemStructure::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $item->id, 'gp_id' => $this->gp->id]);
        if ($mitDeklaration) {
            FoodAlchemistItemDeclaration::create(['team_id' => null, 'legacy_id' => $legacy, 'supplier_item_id' => $item->id, 'iodized' => $nectaJod]);
        }

        return $item;
    };
});

it('Modell: Ableitung beim Anlegen und wenn sich die Zutatenliste ändert, sonst nicht', function () {
    $item = ($this->la)('Weizenmehl, jodiertes Speisesalz');
    expect($item->fresh()->iodized_abgeleitet)->toBe(3);

    $item->update(['ingredients_supplier' => 'Weizenmehl, Speisesalz unjodiert']);
    expect($item->fresh()->iodized_abgeleitet)->toBe(1);

    DB::table('foodalchemist_supplier_items')->where('id', $item->id)->update(['iodized_abgeleitet' => 3]);   // fremder Stand
    $item->fresh()->update(['designation' => 'Neu']);                                                          // Zutaten unverändert
    expect($item->fresh()->iodized_abgeleitet)->toBe(3);
});

it('Aggregation: max(Necta-Flag, abgeleitet), auch ohne Deklarationszeile; Necta-Rohwert bleibt unberührt', function () {
    $mitFlag0 = ($this->la)('Wasser, jodiertes Speisesalz', 0);                       // Necta k.A., Zutaten jodiert
    ($this->la)('Speisesalz unjodiert', null, mitDeklaration: false);                   // keine Deklarationszeile
    $agg = app(GpAggregateService::class);

    expect($agg->freiwillig($this->gp))->toBe(['iodized' => 3])
        ->and($agg->zusatzstoffeUndFreiwillig($this->gp)['iodized'])->toBe(3)
        ->and(DB::table('foodalchemist_item_declarations')->where('supplier_item_id', $mitFlag0->id)->value('iodized'))->toBe(0);

    DB::table('foodalchemist_supplier_items')->where('id', $mitFlag0->id)->update(['ingredients_supplier' => 'Wasser', 'iodized_abgeleitet' => null]);
    expect($agg->freiwillig($this->gp))->toBe(['iodized' => 1]);                       // nur noch „unjodiert“ (1) > k.A. (0)
});

it('Befehl: Probe schreibt nichts, echter Lauf setzt, zweiter Lauf ändert nichts', function () {
    // Bestand ohne Modell-Hook (wie Import): Ableitung fehlt
    $id = DB::table('foodalchemist_supplier_items')->insertGetId(['uuid' => (string) UuidV7::generate(), 'team_id' => null,
        'supplier_id' => $this->lieferant->id, 'designation' => 'Laugenbrezel', 'ingredients_supplier' => 'Mehl, Kaliumjodat',
        'stamm_class' => StammPaket::PAKET, 'created_at' => now(), 'updated_at' => now()]);
    $nein = DB::table('foodalchemist_supplier_items')->insertGetId(['uuid' => (string) UuidV7::generate(), 'team_id' => null,
        'supplier_id' => $this->lieferant->id, 'designation' => 'Bauernbrot', 'ingredients_supplier' => 'Mehl, Salz (unjodiert)',
        'stamm_class' => StammPaket::PAKET, 'created_at' => now(), 'updated_at' => now()]);

    $this->artisan('foodalchemist:jod-ableiten', ['--probe' => true])->expectsOutputToContain('PROBELAUF')->assertSuccessful();
    expect(DB::table('foodalchemist_supplier_items')->where('id', $id)->value('iodized_abgeleitet'))->toBeNull();

    $this->artisan('foodalchemist:jod-ableiten')->assertSuccessful();
    expect(DB::table('foodalchemist_supplier_items')->where('id', $id)->value('iodized_abgeleitet'))->toBe(3)
        ->and(DB::table('foodalchemist_supplier_items')->where('id', $nein)->value('iodized_abgeleitet'))->toBe(1);

    $this->artisan('foodalchemist:jod-ableiten')
        ->expectsTable(['Zähler', 'Wert'], [['geprueft', 2], ['ja', 1], ['nein', 1], ['keine_aussage', 0], ['geaendert', 0]])
        ->assertSuccessful();
});

it('Necta: neuer Artikel bekommt die Ableitung; geänderte Zutatenliste leitet neu ab, ohne Prüffall und ohne Stand-Feld', function () {
    $alt = ($this->la)('Mehl, Salz', 0, legacy: 36674788);
    $attrappe = new NectaAttrappe([
        'suppliers' => [['id' => 19491, 'name' => 'Bäckerei']],
        'supplier-items' => [
            NectaAttrappe::artikel(36674788, 19491, $alt->designation, ['ingredients' => 'Mehl, jodiertes Speisesalz']),
            NectaAttrappe::artikel(36674799, 19491, 'Dinkelstange', ['ingredients' => 'Dinkel, Speisesalz und Kaliumjodid']),
        ],
        'supplier-item-prices' => [], 'supplier-item-allergens' => [], 'supplier-item-nutritional-values' => [],
        'supplier-item-declaration-substances' => [['supplierItemId' => 36674788, 'declarationIodhTlig' => 0]],
    ], [36674788 => 19491, 36674799 => 19491]);
    $this->app->instance(NectaQuelle::class, $attrappe);

    app(NectaNachholService::class)->lauf(1, ['pause_ms' => 0]);
    expect(FoodAlchemistSupplierItem::where('legacy_id', 36674799)->value('iodized_abgeleitet'))->toBe(3);

    app(NectaAbgleichService::class)->lauf(1, ['voll' => true, 'erzwingen' => true, 'pause_ms' => 0]);
    $stand = json_decode(DB::table('foodalchemist_necta_stand')->where('objekt', 'supplier_item')->where('legacy_id', 36674788)->value('werte'), true);
    expect($alt->fresh()->ingredients_supplier)->toBe('Mehl, jodiertes Speisesalz')
        ->and($alt->fresh()->iodized_abgeleitet)->toBe(3)
        ->and($stand)->not->toHaveKey('iodized_abgeleitet')
        ->and(DB::table('foodalchemist_necta_pruefaelle')->where('feld', 'like', 'iodized%')->count())->toBe(0)
        ->and(DB::table('foodalchemist_item_declarations')->where('legacy_id', 36674788)->value('iodized'))->toBe(0);
});
