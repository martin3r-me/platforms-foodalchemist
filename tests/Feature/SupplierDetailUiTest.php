<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Enums\SupplierStatus;
use Platform\FoodAlchemist\Livewire\Gps\DetailPanel;
use Platform\FoodAlchemist\Livewire\Suppliers\SupplierDetail;
use Platform\FoodAlchemist\Models\FoodAlchemistGpLaPreference;
use Platform\FoodAlchemist\Models\FoodAlchemistPrice;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierAgreement;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierContact;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Services\SupplierService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * R9.1/R9.2 UI-Slice — SupplierDetail-Modal (Stammblatt/Konditionen/Absprachen/
 * Dokumente/Bündelung) + GP-DetailPanel-Lead-Override mit Begründung. Prüft, dass
 * die UI die bereits getestete Engine korrekt bedient und D1 wahrt.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->svc = app(SupplierService::class);
    $this->lief = $this->svc->create($this->rootTeam, ['name' => 'Hanos', 'city' => 'Köln']);
});

it('öffnet das Stammblatt und zeigt Status, Kontakt, Absprache und Dokument', function () {
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->svc->setStatus($this->rootTeam, $this->lief->id, 'zweitquelle');
    $this->svc->addContact($this->rootTeam, $this->lief->id, ['name' => 'Frau Meier', 'role' => 'KAM']);
    app(\Platform\FoodAlchemist\Services\SupplierAgreementService::class)
        ->create($this->rootTeam, $this->lief->id, ['type' => 'zusage', 'note' => '3 % Bonus ab 500 €']);

    Livewire::test(SupplierDetail::class)
        ->call('oeffnen', $this->lief->id)
        ->assertSet('supplierId', $this->lief->id)
        ->assertSet('status', 'zweitquelle')
        ->assertSee('Frau Meier')
        ->assertSee('3 % Bonus ab 500 €')
        ->assertDispatched('modal.open');
});

it('schreibt Status, Konditionen und Kontakt über die Component (Besitzer-Team)', function () {
    $this->actingAs($this->makeUser($this->rootTeam));

    $c = Livewire::test(SupplierDetail::class)->call('oeffnen', $this->lief->id);

    $c->set('status', 'gesperrt')->call('statusSetzen');
    $c->set('konditionen.rebate_pct', '3.5')->set('konditionen.payment_term_days', '30')->call('konditionenSpeichern');
    $c->set('neuKontakt.name', 'Herr Schulz')->set('neuKontakt.email', 'schulz@hanos.test')->call('kontaktAnlegen');

    $lief = $this->lief->refresh();
    expect($lief->status)->toBe(SupplierStatus::Gesperrt)
        ->and((float) $lief->rebate_pct)->toBe(3.5)
        ->and($lief->payment_term_days)->toBe(30)
        ->and(FoodAlchemistSupplierContact::where('supplier_id', $this->lief->id)->where('name', 'Herr Schulz')->exists())->toBeTrue();

    // Kontakt-Formular ist nach Anlage geleert (kein State-Leak).
    $c->assertSet('neuKontakt.name', '');
});

it('erfasst Absprache und Dokument über die Component', function () {
    $this->actingAs($this->makeUser($this->rootTeam));

    Livewire::test(SupplierDetail::class)
        ->call('oeffnen', $this->lief->id)
        ->set('neueAbsprache.note', 'Palette Öl reserviert')
        ->set('neueAbsprache.follow_up_at', now()->addDays(7)->toDateString())
        ->call('abspracheAnlegen')
        ->set('neuesDokument.kind', 'vertrag')
        ->set('neuesDokument.term_end', now()->addDays(200)->toDateString())
        ->set('neuesDokument.notice_period_days', '90')
        ->call('dokumentAnlegen');

    expect(FoodAlchemistSupplierAgreement::where('supplier_id', $this->lief->id)->where('note', 'Palette Öl reserviert')->exists())->toBeTrue()
        ->and(\Platform\FoodAlchemist\Models\FoodAlchemistSupplierDocument::where('supplier_id', $this->lief->id)->where('notice_period_days', 90)->exists())->toBeTrue();
});

it('wahrt D1: geerbter Lieferant ist nur lesbar, Schreiben wirft in $fehler', function () {
    // childA sieht den root-eigenen Lieferanten (Kette), darf ihn aber nicht pflegen.
    $this->actingAs($this->makeUser($this->childA));

    $c = Livewire::test(SupplierDetail::class)->call('oeffnen', $this->lief->id);
    $c->assertSet('supplierId', $this->lief->id);          // lesen erlaubt
    expect($c->viewData('darfEdit'))->toBeFalse();

    $c->set('status', 'gesperrt')->call('statusSetzen');
    expect($c->get('fehler'))->toContain('Besitzer-Team')
        ->and($this->lief->refresh()->status)->toBe(SupplierStatus::Aktiv);  // unverändert
});

it('setzt Lead-Override mit Begründung über das GP-DetailPanel (R9.2)', function () {
    $this->actingAs($this->makeUser($this->rootTeam));

    $gp = $this->makeGp($this->rootTeam, 'Zwiebel');
    $mkLa = function (string $name, float $preis) use ($gp) {
        $sup = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => $name]);
        $la = FoodAlchemistSupplierItem::create([
            'team_id' => $this->rootTeam->id, 'supplier_id' => $sup->id,
            'designation' => 'Zwiebel ' . $name, 'qty' => 1.0, 'unit_code' => 'kg',
        ]);
        FoodAlchemistSupplierItemStructure::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'gp_id' => $gp->id]);
        FoodAlchemistPrice::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'price' => $preis, 'status' => '0']);

        return $la;
    };
    $laA = $mkLa('Aldi', 1.00);
    $laB = $mkLa('Baldur', 2.00);
    $gp->update(['n_las_total' => 2, 'lead_la_supplier_item_id' => $laA->id]);

    Livewire::test(DetailPanel::class, ['gpId' => $gp->id])
        ->set('leadReason', 'bessere Liefertreue')
        ->call('leadSetzen', $laB->id)
        ->assertSet('leadReason', '');   // Grund-Feld nach Setzen geleert

    expect((int) $gp->refresh()->lead_la_supplier_item_id)->toBe($laB->id);
    $pref = FoodAlchemistGpLaPreference::where('team_id', $this->rootTeam->id)
        ->where('gp_id', $gp->id)->where('supplier_item_id', $laB->id)->first();
    expect($pref)->not->toBeNull()
        ->and($pref->reason)->toBe('bessere Liefertreue');
});
