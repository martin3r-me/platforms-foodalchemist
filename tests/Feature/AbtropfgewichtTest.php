<?php

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Livewire\Suppliers\ItemModal;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistPrice;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Services\PriceService;
use Platform\FoodAlchemist\Support\AbtropfAusText;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Platform\FoodAlchemist\Tools\GpsListTool;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Abtropfgewicht am Lieferantenartikel (Entscheid Dominique 12.11.2026): zwei Felder Füll-/Abtropfgewicht in der Kalkulationseinheit;
 * ein gesetztes Abtropfgewicht ist die Menge für den Preis je kg (eine Stelle: FoodAlchemistSupplierItem::preisMengeVon → PriceService::vergleichspreis).
 * Text-Erkennung deterministisch, Backfill per Befehl (Standard Trockenlauf).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->lieferant = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Chefs Culinar']);
    $this->artikel = fn (array $a) => FoodAlchemistSupplierItem::create(array_merge([
        'team_id' => $this->rootTeam->id, 'supplier_id' => $this->lieferant->id, 'unit_code' => 'kg'], $a));
    $this->erkenne = fn (string $bez, ?string $zusatz, ?float $menge, ?float $ve = null, ?string $einheit = 'kg') => AbtropfAusText::erkenne([$bez, $zusatz], $menge, $ve, $einheit);
});

it('Parser: eindeutige Treffer in allen Schreibweisen, g → kg, (x kg) nur mit Abtropf-Angabe als Füllgewicht', function () {
    $pfirsich = ($this->erkenne)('Pfirsich Würfel', 'Pfirsich Würfel 10 x 10 mm (4,6 kg), Abtropfgewicht 2,8 kg', 2.8);
    expect($pfirsich)->toMatchArray(['status' => 'gesetzt', 'abtropfgewicht' => 2.8, 'fuellgewicht' => 4.6, 'abweichung' => false]);

    $krebse = ($this->erkenne)('Flusskrebse in Lake 1500g Abtropfgewicht 900g', null, 1.0);
    expect($krebse)->toMatchArray(['status' => 'gesetzt', 'abtropfgewicht' => 0.9, 'fuellgewicht' => null, 'abweichung' => true]);

    expect(($this->erkenne)('Champignons Abtropf. 900g', null, 0.9)['abtropfgewicht'])->toBe(0.9)
        ->and(($this->erkenne)('Mais ATG 1,9 kg Füllmenge 2,65 kg', null, 2.65))->toMatchArray(['abtropfgewicht' => 1.9, 'fuellgewicht' => 2.65, 'abweichung' => true])
        ->and(($this->erkenne)('Oliven', 'Füllgewicht: 4,2 kg / Abtropfgewicht: 2,5 kg', 4.2)['abtropfgewicht'])->toBe(2.5);

    // Mehrfachgebinde mit passendem Faktor: je Dose im Text, Menge je Bestelleinheit (6 Dosen)
    expect(($this->erkenne)('Ananas 6 x 850 g', 'Abtropfgewicht 490 g', 5.1))->toMatchArray(['status' => 'gesetzt', 'abtropfgewicht' => 2.94]);
});

it('Parser: negativ und mehrdeutig setzt nichts', function () {
    expect(($this->erkenne)('Pfirsich Würfel 10 x 10 mm (4,6 kg)', null, 4.6)['status'])->toBe('kein_treffer')   // Klammer ohne Abtropf-Angabe
        ->and(($this->erkenne)('Gurken abgetropft', null, 1.0)['status'])->toBe('kein_treffer')
        ->and(($this->erkenne)('Mais Abtropfgewicht siehe Etikett', null, 2.0))->toMatchArray(['status' => 'mehrdeutig', 'grund' => 'abtropf_zahl_nicht_lesbar'])
        ->and(($this->erkenne)('Mais ATG 1,9 kg', 'Abtropfgewicht 2,1 kg', 2.65))->toMatchArray(['status' => 'mehrdeutig', 'grund' => 'mehrere_abtropfwerte'])
        ->and(($this->erkenne)('Mais ATG 3 kg Füllgewicht 2,5 kg', null, 2.5)['grund'])->toBe('abtropf_groesser_als_fuell')
        ->and(($this->erkenne)('Mais ATG 1,9 kg', null, 1.0)['grund'])->toBe('abtropf_groesser_als_menge')
        ->and(($this->erkenne)('Mais ATG 1,9 kg', null, 2.65, null, 'l')['grund'])->toBe('einheit_l')
        ->and(($this->erkenne)('Mais ATG 1,9 kg', null, 3.0, null, 'Stk')['grund'])->toBe('einheit_Stk')
        ->and(($this->erkenne)('Mais ATG 1,9 kg', null, null)['grund'])->toBe('menge_fehlt')
        ->and(($this->erkenne)('Ananas 6 x 850 g', 'Abtropfgewicht 490 g', 12.0)['grund'])->toBe('mehrfachgebinde_ohne_passenden_faktor');
});

it('Preis je kg: mit Abtropfgewicht rechnet er damit, ohne mit der Menge; Stück bleibt beim Inhalt; rohe Zeile ebenso', function () {
    $preise = app(PriceService::class);
    $krebse = ($this->artikel)(['designation' => 'Flusskrebse in Lake 1500g Abtropfgewicht 900g', 'qty' => 1.0]);
    expect($preise->vergleichspreis($krebse, 18.0))->toBe(['value' => 18.0, 'unit' => '€/kg'])
        ->and(FoodAlchemistSupplierItem::preisAbgetropft($krebse))->toBeFalse();

    $krebse->update(['abtropfgewicht' => 0.9, 'fuellgewicht' => 1.5]);
    expect($preise->vergleichspreis($krebse->fresh(), 18.0))->toBe(['value' => 20.0, 'unit' => '€/kg'])
        ->and(FoodAlchemistSupplierItem::preisAbgetropft($krebse->fresh()))->toBeTrue()
        ->and(round($preise->preisProGramm($krebse->fresh(), 18.0), 4))->toBe(0.02)                     // Rezept-EK läuft über dieselbe Stelle
        ->and(FoodAlchemistSupplierItem::mengeVon($krebse->fresh()))->toBe(1.0);                           // Bestell-/Lagermenge bleibt der Inhalt

    $stueck = ($this->artikel)(['designation' => 'Dose Mais', 'qty' => 6, 'unit_code' => 'Stk', 'abtropfgewicht' => 2.0]);
    expect($preise->vergleichspreis($stueck, 12.0))->toBe(['value' => 2.0, 'unit' => '€/Stk']);           // Stück: Abtropfgewicht wirkt nicht

    $zeile = DB::table('foodalchemist_supplier_items')->where('id', $krebse->id)->first(['qty', 'qty_korrigiert', 'abtropfgewicht', 'unit_code']);
    expect($preise->vergleichspreis($zeile, 18.0)['value'])->toBe(20.0);
});

it('GP-Lead-Preis (gps.LIST felder=voll) rechnet mit dem Abtropfgewicht des Leads', function () {
    $la = ($this->artikel)(['designation' => 'Pfirsich Würfel', 'qty' => 4.6, 'abtropfgewicht' => 2.8]);
    $gp = FoodAlchemistGp::create(['team_id' => $this->rootTeam->id, 'name' => 'Pfirsich: konserviert, Würfel', 'gp_key' => 'gl-pfirsich||',
        'status' => 'approved', 'commodity_group_code' => '03', 'lead_la_supplier_item_id' => $la->id]);
    FoodAlchemistSupplierItemStructure::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'gp_id' => $gp->id]);
    FoodAlchemistPrice::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'price' => 14.0, 'status' => '0']);

    $zeile = collect(app(GpsListTool::class)->execute(['felder' => 'voll'], new ToolContext($this->user, $this->rootTeam))->data['gps'])->firstWhere('id', $gp->id);
    expect($zeile['lead_vergleichspreis'])->toBe(['value' => 5.0, 'unit' => '€/kg']);
});

it('Befehl: Trockenlauf (Standard) schreibt nichts, --apply setzt, manuell bleibt, zweiter Lauf unverändert', function () {
    $krebse = ($this->artikel)(['designation' => 'Flusskrebse in Lake 1500g Abtropfgewicht 900g', 'qty' => 1.0]);
    $mais = ($this->artikel)(['designation' => 'Mais ATG 1,9 kg', 'additional_text' => 'Abtropfgewicht 2,1 kg', 'qty' => 2.65]);
    $hand = ($this->artikel)(['designation' => 'Oliven ATG 2,5 kg', 'qty' => 4.2, 'abtropfgewicht' => 2.4, 'abtropf_quelle' => 'manuell']);

    $this->artisan('foodalchemist:abtropf-backfill')->expectsOutputToContain('TROCKENLAUF')->assertSuccessful();
    $this->artisan('foodalchemist:abtropf-backfill', ['--dry-run' => true, '--apply' => true])->expectsOutputToContain('TROCKENLAUF')->assertSuccessful();
    expect($krebse->fresh()->abtropfgewicht)->toBeNull()->and($mais->fresh()->abtropfgewicht)->toBeNull();

    $this->artisan('foodalchemist:abtropf-backfill', ['--apply' => true])->expectsOutputToContain('GESCHRIEBEN')->assertSuccessful();
    expect((float) $krebse->fresh()->abtropfgewicht)->toBe(0.9)->and($krebse->fresh()->abtropf_quelle)->toBe('text')
        ->and($mais->fresh()->abtropfgewicht)->toBeNull()                                               // mehrdeutig
        ->and((float) $hand->fresh()->abtropfgewicht)->toBe(2.4)->and($hand->fresh()->abtropf_quelle)->toBe('manuell');

    $this->artisan('foodalchemist:abtropf-backfill', ['--apply' => true])
        ->expectsTable(['Zähler', 'Wert'], [['geprueft', 3], ['gesetzt', 0], ['unveraendert', 1], ['mehrdeutig', 1], ['abweichung', 1], ['manuell_geschuetzt', 1]])
        ->assertSuccessful();
});

it('LA-Editor: Felder speichern (Quelle manuell), Prüfung, Kopfkachel „abgetropft“', function () {
    $la = ($this->artikel)(['designation' => 'Flusskrebse in Lake', 'qty' => 1.0]);
    FoodAlchemistPrice::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'price' => 18.0, 'status' => '0']);

    $c = Livewire::test(ItemModal::class)->call('oeffnen', $la->id);
    expect($c->html())->toContain('data-la-abtropf')->toContain('Ist ein Abtropfgewicht gesetzt, rechnet der Preis je kg damit')->not->toContain('abgetropft');

    $c->set('verpackung.fuellgewicht', '1,5')->set('verpackung.abtropfgewicht', '0,9')->call('speichern')->assertSet('fehler', null);
    expect((float) $la->fresh()->abtropfgewicht)->toBe(0.9)->and((float) $la->fresh()->fuellgewicht)->toBe(1.5)->and($la->fresh()->abtropf_quelle)->toBe('manuell');
    expect(Livewire::test(ItemModal::class)->call('oeffnen', $la->id)->html())->toContain('20,00 €/kg abgetropft');

    Livewire::test(ItemModal::class)->call('oeffnen', $la->id)->set('verpackung.abtropfgewicht', '2')->call('speichern')
        ->assertSet('fehler', 'Abtropfgewicht kann nicht größer als das Füllgewicht sein.');
    Livewire::test(ItemModal::class)->call('oeffnen', $la->id)->set('verpackung.fuellgewicht', '')->set('verpackung.abtropfgewicht', '')->call('speichern')->assertSet('fehler', null);
    expect($la->fresh()->abtropfgewicht)->toBeNull()->and($la->fresh()->abtropf_quelle)->toBeNull();
});

it('MCP: artikel.PUT schreibt Füll-/Abtropfgewicht, artikel.LIST felder=voll liefert sie', function () {
    $registry = app(ToolRegistry::class);
    $ctx = new ToolContext($this->user, $this->rootTeam);
    $la = ($this->artikel)(['designation' => 'Ananas Stücke', 'qty' => 3.0]);

    $put = $registry->get('foodalchemist.artikel.PUT')->execute(['id' => $la->id, 'felder' => ['fuellgewicht' => 3.0, 'abtropfgewicht' => '1,8']], $ctx);
    expect($put->success)->toBeTrue('put: '.($put->error ?? ''))
        ->and($put->data)->toMatchArray(['fuellgewicht' => 3.0, 'abtropfgewicht' => 1.8, 'abtropf_quelle' => 'manuell']);
    expect($registry->get('foodalchemist.artikel.PUT')->execute(['id' => $la->id, 'felder' => ['abtropfgewicht' => 'viel']], $ctx)->errorCode)->toBe('VALIDATION_ERROR');

    $liste = $registry->get('foodalchemist.artikel.LIST')->execute(['supplier_id' => $this->lieferant->id, 'felder' => 'voll'], $ctx);
    expect(collect($liste->data['artikel'])->firstWhere('id', $la->id))->toMatchArray(['fuellgewicht' => 3.0, 'abtropfgewicht' => 1.8, 'abtropf_quelle' => 'manuell']);
});
