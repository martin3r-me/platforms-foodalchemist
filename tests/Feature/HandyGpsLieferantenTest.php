<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Gps\Browser as GpBrowser;
use Platform\FoodAlchemist\Livewire\Gps\DetailPanel as GpDetail;
use Platform\FoodAlchemist\Livewire\Suppliers\Index as LieferantenIndex;
use Platform\FoodAlchemist\Models\FoodAlchemistItemAllergen;
use Platform\FoodAlchemist\Models\FoodAlchemistPrice;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierContact;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Support\HandyLesemodus;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * #1003 Handy-Ansicht PR3 · Grundprodukte und Lieferanten: am Handy Karten und Lese-Karten, am Computer unverändert.
 * Lieferanten in drei Stufen (Liste → Kontakt-Karte + Artikel → Artikel-Karte), nur über die team-sichtbaren Wege.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));

    $this->gp = $this->makeGp($this->rootTeam, 'Butter: Süßrahm HANDY');

    // Katalog-Lieferant (Root) mit Artikel, Preis, Allergenen und Zuordnung — für alle Kinder sichtbar
    $this->metro = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Metro HANDY', 'postal_code' => '40235', 'city' => 'Düsseldorf',
        'email_order' => 'bestellung@metro.test', 'order_cutoff_time' => '14:00:00', 'order_lead_days' => 1, 'delivery_days' => '1,3,5',
        'min_order_value' => 150, 'rebate_pct' => 3.5, 'payment_term_days' => 30]);
    $this->butter = FoodAlchemistSupplierItem::create(['team_id' => $this->rootTeam->id, 'supplier_id' => $this->metro->id, 'designation' => 'Butter 1 kg HANDY',
        'article_number' => '778899', 'ean_packaging' => '4012345678901', 'qty' => 1.0, 'unit_code' => 'kg']);
    FoodAlchemistPrice::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $this->butter->id, 'price' => 5.00, 'status' => '0']);
    FoodAlchemistItemAllergen::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $this->butter->id, 'allergen_milk' => 'enthalten']);
    FoodAlchemistSupplierItemStructure::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $this->butter->id, 'gp_id' => $this->gp->id]);
    FoodAlchemistSupplierContact::create(['team_id' => $this->rootTeam->id, 'supplier_id' => $this->metro->id, 'name' => 'Frau Katalog', 'phone' => '0211 123 45']);

    $this->alt = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Altlieferant HANDY', 'is_inactive' => true]);
});

function amHandy(string $klasse, array $params = [])
{
    return Livewire::withCookie(HandyLesemodus::COOKIE, '1')->test($klasse, $params);
}

it('GP-Liste am Handy: Karten mit Warengruppe, Status und Preis, kein Anlegen; am Computer die Tabelle', function () {
    amHandy(GpBrowser::class)
        ->assertSeeHtml('data-fa-handy-karte="' . $this->gp->id . '"')
        ->assertSee('Butter: Süßrahm HANDY')
        ->assertDontSeeHtml('data-gp-anlegen')->assertDontSeeHtml('<table class="fa-table">');

    Livewire::withCookie(HandyLesemodus::COOKIE, '0')->test(GpBrowser::class)
        ->assertSeeHtml('data-gp-anlegen')->assertDontSeeHtml('data-fa-handy-liste');
});

it('GP am Handy: Server-Sperre lässt Auswahl und Filter laufen, Status, Bearbeiten und Katalog-Aktionen nicht; ?edit=1 öffnet keinen Editor', function () {
    request()->cookies->set(HandyLesemodus::COOKIE, '1');
    expect(HandyLesemodus::abgewiesen(new GpBrowser, 'waehleGp'))->toBeFalse()
        ->and(HandyLesemodus::abgewiesen(new GpBrowser, 'waehleWg'))->toBeFalse()
        ->and(HandyLesemodus::abgewiesen(new GpBrowser, 'nextPage'))->toBeFalse()
        ->and(HandyLesemodus::abgewiesen(new GpBrowser, 'statusSetzen'))->toBeTrue()
        ->and(HandyLesemodus::abgewiesen(new GpBrowser, 'bearbeite'))->toBeTrue()
        ->and(HandyLesemodus::abgewiesen(new GpDetail, 'zeige'))->toBeFalse()
        ->and(HandyLesemodus::abgewiesen(new GpDetail, '__dispatch', ['gp-selected', ['id' => 1]]))->toBeFalse()
        ->and(HandyLesemodus::abgewiesen(new GpDetail, 'leadSetzen'))->toBeTrue()
        ->and(HandyLesemodus::abgewiesen(new GpDetail, 'gpLoeschen'))->toBeTrue()
        ->and(HandyLesemodus::abgewiesen(new GpDetail, 'verknuepfe'))->toBeTrue();
    request()->cookies->set(HandyLesemodus::COOKIE, '0');

    Livewire::withCookie(HandyLesemodus::COOKIE, '1')->withQueryParams(['gp' => $this->gp->id, 'edit' => 1])->test(GpBrowser::class)
        ->assertDispatched('gp-selected')->assertNotDispatched('gp-modal.oeffnen');
    Livewire::withCookie(HandyLesemodus::COOKIE, '0')->withQueryParams(['gp' => $this->gp->id, 'edit' => 1])->test(GpBrowser::class)
        ->assertDispatched('gp-modal.oeffnen');
});

it('Lieferanten am Handy, Stufe 1: Karten ohne inaktive, Artikelsuche über alle; keine Leiste, keine Tabelle, kein Anlegen', function () {
    amHandy(LieferantenIndex::class)
        ->assertSeeHtml('data-fa-handy-lieferanten')
        ->assertSeeHtml('data-fa-handy-lieferant="' . $this->metro->id . '"')->assertSee('Düsseldorf')
        ->assertDontSee('Altlieferant HANDY')                              // inaktiv: ausgeblendet wie am Computer
        ->assertDontSeeHtml('data-artikel-tabelle')->assertDontSeeHtml('data-neuer-lieferant-btn')->assertDontSeeHtml('data-supplier-liste')
        ->assertSet('supplierId', null)                                    // am Handy keine Vorauswahl
        ->set('q', 'butter')
        ->assertSeeHtml('data-fa-handy-artikel="' . $this->butter->id . '"')->assertSee('Metro HANDY')->assertSee('5,00 €');
});

it('Stufe 2 und 3: Kontakt-Karte ohne Konditionen, Artikel mit Preis, Artikel-Karte mit Allergenen und Grundprodukt; Zurück je Stufe', function () {
    amHandy(LieferantenIndex::class)
        ->call('waehleLieferant', $this->metro->id)
        ->assertSeeHtml('data-fa-lieferant-kontakt')
        ->assertSee('Frau Katalog')->assertSeeHtml('href="tel:021112345"')
        ->assertSee('bestellung@metro.test')->assertSee('14:00 Uhr')->assertSee('1 Tag')->assertSee('Mo, Mi, Fr')->assertSee('150,00 €')
        ->assertDontSee('Rückvergütung')->assertDontSee('Zahlungsziel')->assertDontSee('3,5')
        ->assertSeeHtml('data-fa-handy-artikel="' . $this->butter->id . '"')->assertSee('Art.-Nr. 778899')
        ->call('handyArtikel', $this->butter->id)
        ->assertSeeHtml('data-fa-handy-artikel-karte="' . $this->butter->id . '"')
        ->assertSee('Enthält:')->assertSee('Milch')->assertSee('13 von 14 ohne Angabe')
        ->assertSee('Butter: Süßrahm HANDY')->assertSee('4012345678901')->assertSee('5,00 €')->assertSee('5,00 €/kg')
        ->call('handyZurueck')->assertSet('handyArtikelId', null)->assertSeeHtml('data-fa-lieferant-kontakt')
        ->call('handyZurueck')->assertSet('supplierId', null)->assertSeeHtml('data-fa-handy-lieferant="' . $this->metro->id . '"');
});

it('Inaktiver Lieferant per Link: markiert statt versteckt', function () {
    amHandy(LieferantenIndex::class)->call('waehleLieferant', $this->alt->id)
        ->assertSee('Altlieferant HANDY')->assertSeeHtml('data-fa-lieferant-inaktiv');
});

it('Zwei Teams: Team B sieht am Handy weder Lieferanten, Artikel, Preise noch Ansprechpartner von Team A', function () {
    // Team A: eigener Lieferant mit Artikel und Preis, eigener Preis und eigener Ansprechpartner am Katalog-Lieferanten
    $eigen = FoodAlchemistSupplier::create(['team_id' => $this->childA->id, 'name' => 'Hofladen NUR-A']);
    $eigenArtikel = FoodAlchemistSupplierItem::create(['team_id' => $this->childA->id, 'supplier_id' => $eigen->id, 'designation' => 'Eier NUR-A', 'qty' => 10, 'unit_code' => 'Stk']);
    FoodAlchemistPrice::create(['team_id' => $this->childA->id, 'supplier_item_id' => $eigenArtikel->id, 'price' => 3.33, 'status' => '0']);
    FoodAlchemistPrice::create(['team_id' => $this->childA->id, 'supplier_item_id' => $this->butter->id, 'price' => 4.00, 'status' => '0']);
    FoodAlchemistSupplierContact::create(['team_id' => $this->childA->id, 'supplier_id' => $this->metro->id, 'name' => 'Herr NUR-A', 'phone' => '0170 999']);

    $this->actingAs($this->makeUser($this->childB, 'Team B'));
    amHandy(LieferantenIndex::class)
        ->assertDontSee('Hofladen NUR-A')
        ->set('q', 'NUR-A')->assertDontSee('Eier NUR-A')
        ->set('q', '')->call('waehleLieferant', $eigen->id)->assertDontSeeHtml('data-fa-lieferant-kontakt')   // fremder Lieferant: zurück zur Liste
        ->call('waehleLieferant', $this->metro->id)
        ->assertSee('Frau Katalog')->assertDontSee('Herr NUR-A')
        ->assertSee('5,00 €')->assertDontSee('4,00 €')
        ->call('handyArtikel', $eigenArtikel->id)->assertSee('Artikel nicht gefunden')->assertDontSee('3,33')
        ->call('handyZurueck')->call('handyArtikel', $this->butter->id)->assertSee('5,00 €')->assertDontSee('4,00 €');

    // Gegenprobe: Team A sieht seinen Preis und seinen Ansprechpartner
    $this->actingAs($this->makeUser($this->childA, 'Team A'));
    amHandy(LieferantenIndex::class)
        ->assertSee('Hofladen NUR-A')
        ->call('waehleLieferant', $this->metro->id)->assertSee('Herr NUR-A')->assertSee('4,00 €')
        ->call('handyArtikel', $eigenArtikel->id)->assertSee('Eier NUR-A')->assertSee('3,33 €');
});

it('Lieferanten am Handy: Server-Sperre lässt nur Auswahl, Zurück, Artikel-Karte und Blättern laufen; am Computer unverändert', function () {
    request()->cookies->set(HandyLesemodus::COOKIE, '1');
    $index = new LieferantenIndex;
    foreach (['waehleLieferant', 'handyArtikel', 'handyZurueck', 'nextPage'] as $erlaubt) {
        expect(HandyLesemodus::abgewiesen($index, $erlaubt))->toBeFalse();
    }
    foreach (['leadSetzen', 'lieferantAnlegen', 'artikelAnlegen', 'bulkLoeschen', 'bulkGpZuweisen', 'lieferantDeaktivieren', 'bulkMatchStarten', 'anomalienAnzeigen'] as $gesperrt) {
        expect(HandyLesemodus::abgewiesen($index, $gesperrt))->toBeTrue();
    }
    request()->cookies->set(HandyLesemodus::COOKIE, '0');
    expect(HandyLesemodus::abgewiesen($index, 'leadSetzen'))->toBeFalse();

    Livewire::withCookie(HandyLesemodus::COOKIE, '0')->test(LieferantenIndex::class)
        ->assertSeeHtml('data-artikel-tabelle')->assertSeeHtml('data-supplier-liste')->assertDontSeeHtml('data-fa-handy-lieferanten')
        ->assertSet('supplierId', $this->metro->id);                       // Computer: erster Lieferant vorgewählt wie bisher
});
