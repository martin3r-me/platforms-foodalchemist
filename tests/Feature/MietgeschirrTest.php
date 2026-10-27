<?php

use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\FoodAlchemist\Livewire\Gps\GpModal;
use Platform\FoodAlchemist\Livewire\Suppliers\ItemModal;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistPrice;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Services\GpMasseService;
use Platform\FoodAlchemist\Services\NonFoodService;
use Platform\FoodAlchemist\Services\PriceService;
use Platform\FoodAlchemist\Services\SupplierItemService;
use Platform\FoodAlchemist\Support\NonFoodMasse;
use Platform\FoodAlchemist\Support\NonFoodRegeln;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Platform\FoodAlchemist\Tools\GpsGetTool;
use Platform\FoodAlchemist\Tools\GpsPostTool;
use Platform\FoodAlchemist\Tools\GpsPutTool;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Mietgeschirr als normaler Artikel (PR B): Pfand (netto, nur Beleg) und Mietdauer am LIEFERANTENARTIKEL, Maße am GRUNDPRODUKT (nur WG 16;
 * gespeichert in mm, gesagt in cm/ml/l), Größe im Namen „Produkt: Eigenschaft, Größe“, Unterkategorie „16.6 Geschirr & Ausstattung“.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam, 'Einkauf');
    $this->actingAs($this->user);
    config(['foodalchemist.ai.provider' => 'fake']);
    $this->ctx = new ToolContext($this->user, $this->rootTeam);
    app(NonFoodService::class)->einrichten(true);   // Plattform-Basis 18.10.: WG 16 global
    $this->gp = fn (string $name, string $wg) => tap($this->makeGp($this->rootTeam, $name), fn ($g) => $g->update(['commodity_group_code' => $wg, 'status' => 'approved']))->fresh();
});

it('Größe: Ø in cm mit Komma, L x B x H, Volumen in ml bzw. l; Reihenfolge Ø vor L×B×H vor Volumen; ohne Maß null', function () {
    $g = fn (array $m) => NonFoodMasse::groesse($m);

    expect($g(['diameter_mm' => 270]))->toBe('Ø 27 cm')
        ->and($g(['diameter_mm' => 75]))->toBe('Ø 7,5 cm')
        ->and($g(['length_mm' => 300, 'width_mm' => 200, 'height_mm' => 50]))->toBe('30 x 20 x 5 cm')
        ->and($g(['length_mm' => 325, 'width_mm' => 265]))->toBe('32,5 x 26,5 cm')
        ->and($g(['volume_ml' => 212]))->toBe('212 ml')
        ->and($g(['volume_ml' => 1500]))->toBe('1,5 l')
        ->and($g(['volume_ml' => 1000]))->toBe('1 l')
        ->and($g(['diameter_mm' => 150, 'volume_ml' => 500]))->toBe('Ø 15 cm')                       // mehrere Maße: Ø zuerst
        ->and($g(['length_mm' => 300, 'volume_ml' => 500]))->toBe('30 cm')
        ->and($g([]))->toBeNull()->and($g(['diameter_mm' => 0, 'volume_ml' => null]))->toBeNull();
});

it('Maße setzen: Eingabe in cm/ml (Komma erlaubt), gespeichert in mm; leer löscht; fehlender Schlüssel lässt unverändert; Lebensmittel hat keine Maße', function () {
    $teller = ($this->gp)('Speiseteller: Porzellan', '16');
    $svc = app(GpMasseService::class);

    $svc->setze($teller, ['diameter_cm' => '27', 'height_cm' => '2,5', 'volume_ml' => '']);
    $teller->refresh();
    expect((float) $teller->diameter_mm)->toBe(270.0)->and((float) $teller->height_mm)->toBe(25.0)->and($teller->volume_ml)->toBeNull()
        ->and($teller->masseText())->toBe('Ø 27 cm');

    $svc->setze($teller, ['height_cm' => '']);                                                       // nur die Höhe löschen
    expect($teller->refresh()->height_mm)->toBeNull()->and((float) $teller->diameter_mm)->toBe(270.0);
    expect(fn () => $svc->setze($teller, ['diameter_cm' => 'viel']))->toThrow(\RuntimeException::class, 'Zahl');

    $butter = ($this->gp)('Butter: frisch', '06');
    expect(fn () => $svc->setze($butter, ['diameter_cm' => '10']))->toThrow(\RuntimeException::class, 'Non-Food')
        ->and($butter->masseText())->toBeNull();
});

it('GP-Modal: Maße im Formular (cm), „Größe in den Namen“, leere Größe wird beim Speichern aus den Maßen gefüllt; Zustand-Block entfällt für Non-Food', function () {
    $c = Livewire::test(GpModal::class)->call('oeffnen')
        ->set('builder.commodity_group_code', '16')->set('builder.hauptzutat', 'Speiseteller')->set('builder.processing', 'Porzellan')
        ->set('defaults.diameter_cm', '27');
    $c->assertSeeHtml('data-gp-masse');

    $c->call('masseInNamen')->assertSet('builder.pflichtangabe', 'Ø 27 cm');
    $c->set('builder.pflichtangabe', '')->call('speichern')->assertSet('fehler', null);

    $gp = FoodAlchemistGp::where('name', 'Speiseteller: Porzellan, Ø 27 cm')->firstOrFail();
    expect((float) $gp->diameter_mm)->toBe(270.0)->and($gp->istNonFood())->toBeTrue();

    $offen = Livewire::test(GpModal::class)->call('oeffnen', $gp->id);
    expect($offen->get('defaults.diameter_cm'))->toBe('27');

    // Gegenprobe: ein Lebensmittel zeigt keinen Maße-Block
    expect(Livewire::test(GpModal::class)->call('oeffnen')->set('builder.commodity_group_code', '06')->html())->not->toContain('data-gp-masse');
});

it('MCP gps.POST/PUT/GET: Maße in cm/ml, Größe im Namen, GET liefert mm und Größe; Lebensmittel bekommt keine Maße', function () {
    $post = (new GpsPostTool)->execute(['hauptzutat' => 'Weinglas', 'processing' => 'Glas', 'commodity_group_code' => '16', 'volume_ml' => '650'], $this->ctx);
    expect($post->success)->toBeTrue((string) ($post->error ?? ''))->and($post->data['name'])->toBe('Weinglas: Glas, 650 ml')->and($post->data['groesse'])->toBe('650 ml');

    $id = $post->data['id'];
    $put = (new GpsPutTool)->execute(['id' => $id, 'name' => 'Weinglas: Glas, 650 ml', 'hauptzutat' => 'Weinglas', 'diameter_cm' => '9,5'], $this->ctx);
    expect($put->success)->toBeTrue((string) ($put->error ?? ''))->and($put->data['groesse'])->toBe('Ø 9,5 cm');

    $get = (new GpsGetTool)->execute(['id' => $id], $this->ctx);
    expect($get->data['masse']['diameter_mm'])->toBe(95.0)->and($get->data['masse']['volume_ml'])->toBe(650.0)->and($get->data['masse']['groesse'])->toBe('Ø 9,5 cm');

    $butter = (new GpsPostTool)->execute(['hauptzutat' => 'Butter', 'condition' => 'frisch', 'commodity_group_code' => '06', 'diameter_cm' => '5'], $this->ctx);
    expect($butter->success)->toBeFalse()->and($butter->errorCode)->toBe('VALIDATION_ERROR');
    $lm = ($this->gp)('Sahne: frisch', '06');
    expect((new GpsGetTool)->execute(['id' => $lm->id], $this->ctx)->data['masse'])->toBeNull();
});

it('Pfand und Mietdauer am Artikel: speichern, Validierung, ohne Wirkung auf Preis und Vergleichspreis', function () {
    $sup = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Eventausstatter']);
    $la = FoodAlchemistSupplierItem::create(['team_id' => $this->rootTeam->id, 'supplier_id' => $sup->id, 'designation' => 'Speiseteller weiß 28 cm', 'qty' => 1, 'unit_code' => 'Stk']);
    FoodAlchemistPrice::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'price' => 0.55, 'status' => '0']);
    $preise = app(PriceService::class);
    $vorher = $preise->vergleichspreis($la->fresh(), (float) $preise->activeFor($la->id, $this->rootTeam)->price);
    $items = app(SupplierItemService::class);

    $items->update($this->rootTeam, $la, ['deposit' => '1,50', 'rental_period' => 'veranstaltung']);
    $la->refresh();
    expect((float) $la->deposit)->toBe(1.5)->and($la->rental_period)->toBe('veranstaltung');
    expect($preise->vergleichspreis($la, (float) $preise->activeFor($la->id, $this->rootTeam)->price))->toBe($vorher)       // Pfand ändert den Preis nicht
        ->and((float) $preise->activeFor($la->id, $this->rootTeam)->price)->toBe(0.55);

    expect(fn () => $items->update($this->rootTeam, $la, ['rental_period' => 'monat']))->toThrow(\RuntimeException::class, 'tag oder veranstaltung')
        ->and(fn () => $items->update($this->rootTeam, $la, ['deposit' => '-1']))->toThrow(\RuntimeException::class, 'Pfand')
        ->and(fn () => $items->update($this->rootTeam, $la, ['deposit' => 'viel']))->toThrow(\RuntimeException::class, 'Pfand');

    $items->update($this->rootTeam, $la, ['deposit' => '', 'rental_period' => '']);
    expect($la->refresh()->deposit)->toBeNull()->and($la->rental_period)->toBeNull();
});

it('Artikel-Modal: Pfand/Mietdauer erscheinen bei Non-Food-GP oder vorhandenen Werten, speichern, Maße vom GP nur zur Anzeige; Lebensmittel zeigt den Block nicht', function () {
    $sup = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Eventausstatter']);
    $mk = function (string $d, ?FoodAlchemistGp $gp) use ($sup) {
        $la = FoodAlchemistSupplierItem::create(['team_id' => $this->rootTeam->id, 'supplier_id' => $sup->id, 'designation' => $d, 'qty' => 1, 'unit_code' => 'Stk']);
        if ($gp !== null) {
            FoodAlchemistSupplierItemStructure::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'gp_id' => $gp->id]);
        }

        return $la;
    };
    $teller = ($this->gp)('Speiseteller: Porzellan, Ø 27 cm', '16');
    app(GpMasseService::class)->setze($teller, ['diameter_cm' => '27']);
    $laTeller = $mk('Speiseteller weiß', $teller);
    $laButter = $mk('Butter 250 g', ($this->gp)('Butter: frisch', '06'));

    $c = Livewire::test(ItemModal::class)->call('oeffnen', $laTeller->id);
    $c->assertSeeHtml('data-la-miete')->assertSeeHtml('data-la-masse')->assertSee('Ø 27 cm');
    $c->set('verpackung.deposit', '2,5')->set('verpackung.rental_period', 'tag')->call('speichern')->assertSet('fehler', null);
    expect((float) $laTeller->fresh()->deposit)->toBe(2.5)->and($laTeller->fresh()->rental_period)->toBe('tag');

    expect(Livewire::test(ItemModal::class)->call('oeffnen', $laButter->id)->html())->not->toContain('data-la-miete');
    // schon vorhandene Werte zeigen den Block auch ohne Non-Food-GP
    $laButter->update(['deposit' => 1.0]);
    expect(Livewire::test(ItemModal::class)->call('oeffnen', $laButter->id)->html())->toContain('data-la-miete');
});

it('Unterkategorie „16.6 Geschirr & Ausstattung“: starke Wörter sind Non-Food mit dieser Unterkategorie, mehrdeutige (Tasse, Teller) nur unklar', function () {
    $b = fn (string $t) => NonFoodRegeln::bewerte(['bezeichnung' => $t, 'hat_food_daten' => false]);

    expect($b('Speiseteller flach weiß 28 cm')['subkategorie'])->toBe(NonFoodRegeln::SUB_GESCHIRR)
        ->and($b('Kaffeetasse Blackwell')['klasse'])->toBe('nonfood')
        ->and($b('Weinglas Bordeaux 0,65 l')['subkategorie'])->toBe(NonFoodRegeln::SUB_GESCHIRR)
        ->and($b('Besteck Set 24-teilig')['subkategorie'])->toBe(NonFoodRegeln::SUB_GESCHIRR)
        ->and($b('Tasse 290 ml')['klasse'])->toBe('unklar')                                          // mehrdeutig: Tasse Joghurt?
        ->and($b('Teller flach')['klasse'])->toBe('unklar')
        ->and(in_array(NonFoodRegeln::SUB_GESCHIRR, NonFoodRegeln::unterkategorien(), true))->toBeTrue();
});
