<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Support\StammPaket;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Einmal-Reparatur der Deklarationen aus Nachholer-Lauf 3 (15.10.2026). Zeile N so, wie der alte Mapper sie geschrieben hat
 * (alle Stoff-Spalten leer, Rohwerte als declaration_… in details), Zeile B wie der Bestand, Zeile L leer ohne Rohwert.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $l = FoodAlchemistSupplier::create(['team_id' => null, 'legacy_id' => 19491, 'name' => 'Bäckerei', 'stamm_class' => StammPaket::PAKET]);
    $artikel = fn (int $legacy) => FoodAlchemistSupplierItem::create(['team_id' => null, 'legacy_id' => $legacy, 'supplier_id' => $l->id,
        'designation' => "Artikel {$legacy}", 'stamm_class' => StammPaket::PAKET])->id;
    $zeile = fn (int $legacy, array $werte) => DB::table('foodalchemist_item_declarations')->insert($werte + ['uuid' => (string) UuidV7::generate(),
        'team_id' => null, 'legacy_id' => $legacy, 'supplier_item_id' => $artikel($legacy), 'created_at' => now(), 'updated_at' => now()]);

    $this->rohwerte = ['declaration_with_dye' => 3, 'declaration_with_preservative' => 1, 'declaration_caffeinated' => 0,
        'declaration_b_excessive_consumption_can_have_laxative_effect' => 1, 'declaration_table_sweetener_based_on' => 0,
        'declaration_iodh_tlig' => 1, 'declaration_reserve' => 0];
    $zeile(1001, ['details' => json_encode($this->rohwerte)]);
    DB::table('foodalchemist_necta_stand')->insert(['objekt' => 'item_declaration', 'legacy_id' => 1001,
        'werte' => json_encode(['details' => json_encode($this->rohwerte)]), 'created_at' => now(), 'updated_at' => now()]);
    $zeile(1002, ['with_dye' => 1, 'with_preservative' => 0, 'details' => json_encode(['table_sweetener_basis' => 0])]);
    $zeile(1003, ['details' => null]);

    $this->spalten = fn (int $legacy) => (array) DB::table('foodalchemist_item_declarations')->where('legacy_id', $legacy)
        ->first(['with_dye', 'with_preservative', 'caffeinated', 'excessive_consumption_laxative', 'with_sweetener', 'details']);
});

it('Probe zählt und zeigt Beispiele, schreibt nichts', function () {
    $vorher = ($this->spalten)(1001);

    $this->artisan('foodalchemist:necta-deklaration-reparieren', ['--probe' => true])
        ->expectsOutputToContain('PROBELAUF')->expectsOutputToContain('"with_dye":3')->assertSuccessful();

    expect(($this->spalten)(1001))->toBe($vorher);
});

it('mappt die Rohwerte wie der Bestand neu, aktualisiert den Necta-Stand und lässt Bestand + leere Zeilen in Ruhe', function () {
    $bestand = ($this->spalten)(1002);

    $this->artisan('foodalchemist:necta-deklaration-reparieren')->assertSuccessful();

    $n = ($this->spalten)(1001);
    expect((int) $n['with_dye'])->toBe(3)->and((int) $n['with_preservative'])->toBe(1)->and((int) $n['caffeinated'])->toBe(0)
        ->and((int) $n['excessive_consumption_laxative'])->toBe(1)->and($n['with_sweetener'])->toBeNull()
        ->and(json_decode($n['details'], true))->toBe(['table_sweetener_basis' => 0])                 // Jod/Reserve wie im Alt-Import weg
        ->and(json_decode(DB::table('foodalchemist_necta_stand')->where('legacy_id', 1001)->value('werte'), true)['with_dye'])->toBe(3)
        ->and(($this->spalten)(1002))->toBe($bestand)
        ->and(($this->spalten)(1003)['details'])->toBeNull();
});

it('ist idempotent: zweiter Lauf findet nichts mehr', function () {
    $this->artisan('foodalchemist:necta-deklaration-reparieren')->assertSuccessful();
    $nach = ($this->spalten)(1001);

    $this->artisan('foodalchemist:necta-deklaration-reparieren')
        ->expectsTable(['Zähler', 'Wert'], [['kandidaten', 0], ['repariert', 0], ['ohne_rohwert', 0], ['stand_aktualisiert', 0]])
        ->assertSuccessful();

    expect(($this->spalten)(1001))->toBe($nach);
});
