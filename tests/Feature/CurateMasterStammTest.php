<?php

use Livewire\Livewire;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Livewire\Suppliers\ItemModal;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistPrice;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Support\Curate;
use Platform\FoodAlchemist\Support\MasterTeam;
use Platform\FoodAlchemist\Support\StammPaket;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Curate::canCurate delegiert bei Modellen mit BelongsToTeamHierarchy an isOwnedBy(currentTeam): EINE Regel für Dienste, MCP und Web.
 * Master pflegt globale stamm_paket-Zeilen (Artikel, Lieferant, GP), Mandant mit Paket und Basis-Zeilen bleiben „Nur lesen".
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->master = Team::create(['name' => 'BHG.DIGITAL', 'user_id' => 1, 'personal_team' => false]);
    config(['foodalchemist.master_team_id' => $this->master->id]);
    MasterTeam::flush();
    StammPaket::freischalten($this->rootTeam);

    $this->sup = FoodAlchemistSupplier::create(['team_id' => null, 'name' => 'Hanos', 'stamm_class' => 'stamm_paket']);
    $this->item = FoodAlchemistSupplierItem::create(['team_id' => null, 'supplier_id' => $this->sup->id, 'designation' => 'Mehl', 'article_number' => 'M1', 'stamm_class' => 'stamm_paket', 'qty' => 1, 'unit_code' => 'kg']);
    $this->basisItem = FoodAlchemistSupplierItem::create(['team_id' => null, 'supplier_id' => $this->sup->id, 'designation' => 'Salz', 'article_number' => 'S1', 'qty' => 1, 'unit_code' => 'kg']);
    $this->gp = FoodAlchemistGp::create(['team_id' => null, 'gp_key' => 'cu|1', 'name' => 'Mehl', 'stamm_class' => 'stamm_paket']);
    $this->alleLieferantenFrei($this->rootTeam);

    $this->master_user = $this->makeUser($this->master, 'Master Admin');
    $this->mandant_user = $this->makeUser($this->rootTeam, 'Mandant Admin');
});

it('★ Curate: Master pflegt globale stamm_paket-Zeilen, Mandant mit Paket und Basis-Zeilen bleiben Nur lesen', function () {
    foreach ([$this->item, $this->sup, $this->gp] as $zeile) {
        expect(Curate::canCurate($this->master_user, $zeile))->toBeTrue(get_class($zeile))
            ->and(Curate::canCurate($this->mandant_user, $zeile))->toBeFalse(get_class($zeile));
    }
    expect(Curate::canCurate($this->master_user, $this->basisItem))->toBeFalse();      // Basis bleibt auch für den Master Nur lesen

    $eigen = FoodAlchemistSupplierItem::create(['team_id' => $this->rootTeam->id, 'supplier_id' => $this->sup->id, 'designation' => 'Eigen', 'article_number' => 'E1']);
    expect(Curate::canCurate($this->mandant_user, $eigen))->toBeTrue()->and(Curate::canCurate($this->master_user, $eigen))->toBeFalse();
    expect(Curate::canCurate($this->mandant_user, $this->rootTeam))->toBeTrue();       // Team-Ziel unverändert
});

it('★ Artikel-Modal: Master bearbeitet den globalen Stamm-Artikel und legt einen Preis an (team_id = Master)', function () {
    $this->actingAs($this->master_user);
    $c = Livewire::test(ItemModal::class)->call('oeffnen', $this->item->id);
    $c->assertSee('Artikel bearbeiten');
    $c->set('preisNeu', ['price' => '4,20', 'status' => '0'])->call('preisAnlegen')->assertSet('fehler', null);

    $p = FoodAlchemistPrice::where('supplier_item_id', $this->item->id)->first();
    expect($p)->not->toBeNull()->and((int) $p->team_id)->toBe($this->master->id)->and((float) $p->price)->toBe(4.2);
});

it('★ Artikel-Modal: Mandant mit Paket und freigeschaltetem Lieferanten bleibt Nur lesen, kein Preis, keine Änderung', function () {
    $this->actingAs($this->mandant_user);
    $c = Livewire::test(ItemModal::class)->call('oeffnen', $this->item->id);
    $c->assertDontSee('Artikel bearbeiten');
    $c->set('preisNeu', ['price' => '4,20', 'status' => '0'])->call('preisAnlegen');
    expect(FoodAlchemistPrice::where('supplier_item_id', $this->item->id)->count())->toBe(0);
    $c->call('speichern');
    expect($this->item->fresh()->designation)->toBe('Mehl');
});

it('Artikel-Modal: Basis-Artikel bleibt auch im Master Nur lesen', function () {
    $this->actingAs($this->master_user);
    Livewire::test(ItemModal::class)->call('oeffnen', $this->basisItem->id)->assertDontSee('Artikel bearbeiten');
});
