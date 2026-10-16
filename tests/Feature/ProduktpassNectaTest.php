<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\Necta\NectaAbgleichService;
use Platform\FoodAlchemist\Services\Necta\NectaQuelle;
use Platform\FoodAlchemist\Support\ProduktpassFelder;
use Platform\FoodAlchemist\Support\ProduktpassSchutz;
use Platform\FoodAlchemist\Support\StammPaket;
use Platform\FoodAlchemist\Tests\Support\NectaAttrappe;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Ein Produktpass-Wert gilt wie Handpflege: der Necta-Abgleich überschreibt ihn nicht, sondern meldet eine Abweichung
 * (`produktpass_abweichung`). „FA ≠ necta_stand" allein reicht nicht — die beiden Lücken stehen hier als Fälle:
 * (a) es gibt noch keinen Stand (erster Abgleich) · (b) der Pass-Wert entspricht zufällig dem letzten Necta-Stand.
 * Ohne Marker bleibt die Kennzeichnung bei der Strenge-Regel; MIT Marker hat der Pass Vorrang (ProduktpassVorrangTest).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    config(['foodalchemist.master_team_id' => $this->rootTeam->id]);
    ProduktpassSchutz::zuruecksetzen();

    $lieferant = FoodAlchemistSupplier::create(['team_id' => null, 'legacy_id' => 19491, 'name' => 'Bäckerei', 'stamm_class' => StammPaket::PAKET]);
    $this->a = FoodAlchemistSupplierItem::create(['team_id' => null, 'legacy_id' => 36674788, 'supplier_id' => $lieferant->id,
        'designation' => 'Schoko Splitterbrötchen', 'unit_code' => 'Stk', 'stamm_class' => StammPaket::PAKET,
        'ingredients_supplier' => 'Weizenmehl, Wasser']);                                   // so steht es aus dem Produktpass am Artikel
    $this->b = FoodAlchemistSupplierItem::create(['team_id' => null, 'legacy_id' => 36674790, 'supplier_id' => $lieferant->id,
        'designation' => 'Rosinenschnecke', 'ingredients_supplier' => 'Weizenmehl, Wasser', 'stamm_class' => StammPaket::PAKET]);   // gleicher Wert OHNE Marker
    DB::table('foodalchemist_item_allergens')->insert(['uuid' => (string) Symfony\Component\Uid\UuidV7::generate(), 'team_id' => null,
        'legacy_id' => 36674788, 'supplier_item_id' => $this->a->id, 'allergen_milk' => 'nicht_enthalten', 'source' => 'produktpass_ki',
        'created_at' => now(), 'updated_at' => now()]);

    // Marker: ingredients_supplier von A stammt aus dem Produktpass
    $pass = \Platform\FoodAlchemist\Models\FoodAlchemistProduktpass::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $this->a->id, 'version' => 1,
        'status' => 'gelesen', 'quelle' => ProduktpassFelder::QUELLE_PASS, 'extrakt' => []]);
    \Platform\FoodAlchemist\Models\FoodAlchemistProduktpassFeld::create(['supplier_item_id' => $this->a->id, 'produktpass_id' => $pass->id,
        'objekt' => 'supplier_item', 'feld' => 'ingredients_supplier', 'wert_norm' => 'Weizenmehl, Wasser', 'quelle' => 'produktpass_ki']);

    $this->necta = function (array $a, array $b, array $allergene = []) {
        $attrappe = new NectaAttrappe([
            'suppliers' => [['id' => 19491, 'name' => 'Bäckerei']],
            'supplier-items' => [NectaAttrappe::artikel(36674788, 19491, 'Schoko Splitterbrötchen', $a), NectaAttrappe::artikel(36674790, 19491, 'Rosinenschnecke', $b)],
            'supplier-item-prices' => [],
            'supplier-item-allergens' => $allergene,
            'supplier-item-nutritional-values' => [], 'supplier-item-declaration-substances' => [],
        ], [36674788 => 19491, 36674790 => 19491]);
        $this->app->instance(NectaQuelle::class, $attrappe);
        $this->travel(5)->minutes();   // Läufe nie in derselben Sekunde (Vollabgleich vergleicht Startzeiten)
        app(NectaAbgleichService::class)->lauf(1, ['pause_ms' => 0]);   // Voraussetzung jedes Vollabgleichs: ein erfolgreicher nächtlicher Lauf seit dem letzten
        $this->travel(5)->minutes();

        return app(NectaAbgleichService::class)->lauf(1, ['voll' => true, 'pause_ms' => 0]);
    };
});

it('(a) ohne Necta-Stand überschreibt der erste Abgleich den Pass-Wert NICHT — Prüffall; derselbe Fall ohne Marker wird übernommen (Gegenprobe)', function () {
    $r = ($this->necta)(['ingredients' => 'Mehl'], ['ingredients' => 'Mehl']);

    expect($this->a->fresh()->ingredients_supplier)->toBe('Weizenmehl, Wasser')           // geschützt
        ->and($this->b->fresh()->ingredients_supplier)->toBe('Mehl')                       // ohne Marker: wie bisher übernommen
        ->and(DB::table('foodalchemist_necta_pruefaelle')->where('art', 'produktpass_abweichung')->where('legacy_id', 36674788)->where('feld', 'ingredients_supplier')->value('necta_wert'))->toBe('Mehl')
        ->and(DB::table('foodalchemist_necta_pruefaelle')->where('art', 'produktpass_abweichung')->where('legacy_id', 36674790)->exists())->toBeFalse()
        ->and($r['zaehler']['pruefaelle']['produktpass_abweichung'])->toBe(1);
});

it('(b) Pass-Wert entspricht dem alten Necta-Stand, Necta ändert danach: geschützt statt als „nicht von Hand" überschrieben', function () {
    // 1. Abgleich: Necta liefert denselben Wert wie der Pass → Stand = Pass-Wert, keine Abweichung
    ($this->necta)(['ingredients' => 'Weizenmehl, Wasser'], ['ingredients' => 'Weizenmehl, Wasser']);
    expect(DB::table('foodalchemist_necta_pruefaelle')->where('art', 'produktpass_abweichung')->count())->toBe(0)
        ->and(DB::table('foodalchemist_necta_stand')->where('objekt', 'supplier_item')->where('legacy_id', 36674788)->exists())->toBeTrue();

    // 2. Abgleich: Necta ändert → ohne Marker würde übernommen (FA = Stand); mit Marker bleibt der Pass-Wert
    ($this->necta)(['ingredients' => 'Weizenmehl, Wasser, Salz'], ['ingredients' => 'Weizenmehl, Wasser, Salz']);

    expect($this->a->fresh()->ingredients_supplier)->toBe('Weizenmehl, Wasser')
        ->and($this->b->fresh()->ingredients_supplier)->toBe('Weizenmehl, Wasser, Salz')   // Gegenprobe: ohne Marker übernommen
        ->and(DB::table('foodalchemist_necta_pruefaelle')->where('art', 'produktpass_abweichung')->where('legacy_id', 36674788)->value('necta_wert'))->toBe('Weizenmehl, Wasser, Salz');
});

it('wird der geschützte Wert danach von Hand geändert, gilt wieder die normale Handpflege-Logik (Marker schützt nur den geschriebenen Wert)', function () {
    $this->a->update(['ingredients_supplier' => 'Dinkelmehl, Wasser']);                  // Handpflege nach dem Pass
    ProduktpassSchutz::zuruecksetzen();

    ($this->necta)(['ingredients' => 'Mehl'], ['ingredients' => 'Mehl']);

    // erster Abgleich ohne Stand: Necta-Wert wird übernommen, kein Produktpass-Prüffall
    expect($this->a->fresh()->ingredients_supplier)->toBe('Mehl')
        ->and(DB::table('foodalchemist_necta_pruefaelle')->where('art', 'produktpass_abweichung')->count())->toBe(0);
});

it('Kennzeichnung OHNE Pass-Marker: Necta STRENGER kommt automatisch, LOCKERER wird Prüffall (Strenge-Regel, kein Pass-Vorrang)', function () {
    // Allergenzeile mit Quelle produktpass_ki, aber ohne Marker (z. B. vor dem Marker-Schema geschrieben) → Strenge-Regel
    ($this->necta)([], [], [['supplierItemId' => 36674788, 'milk' => 3]]);
    expect(DB::table('foodalchemist_item_allergens')->where('legacy_id', 36674788)->value('allergen_milk'))->toBe('enthalten');

    DB::table('foodalchemist_necta_stand')->truncate();
    ($this->necta)([], [], [['supplierItemId' => 36674788, 'milk' => 1]]);
    expect(DB::table('foodalchemist_item_allergens')->where('legacy_id', 36674788)->value('allergen_milk'))->toBe('enthalten')
        ->and(DB::table('foodalchemist_necta_pruefaelle')->where('art', 'allergen_aenderung')->where('legacy_id', 36674788)->exists())->toBeTrue()
        ->and(DB::table('foodalchemist_necta_pruefaelle')->where('art', 'produktpass_abweichung')->exists())->toBeFalse();
});
