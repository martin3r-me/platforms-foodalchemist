<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Http\Controllers\LieferantenPortalController;
use Platform\FoodAlchemist\Livewire\Suppliers\SupplierDetail;
use Platform\FoodAlchemist\Models\FoodAlchemistPriceImportLine as Zeile;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierContact;
use Platform\FoodAlchemist\Services\LieferantenLinkService;
use Platform\FoodAlchemist\Services\LieferantenStammdatenblattService;
use Platform\FoodAlchemist\Services\SupplierService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Ansprechpartner sind team-eigen, auch am gemeinsamen Katalog-Lieferanten: Team B sieht im Stammblatt (Computer und
 * MCP suppliers.GET) keine Ansprechpartner, die Team A angelegt hat. Ansprechpartner des Katalog-Besitzers sieht jedes Kind.
 * Schreiben (Lieferanten-Portal, Stammdatenblatt übernehmen): nur der eigene Kontakt einer Rolle wird geändert.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->lief = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Metro KONTAKT']);
    FoodAlchemistSupplierContact::create(['team_id' => $this->rootTeam->id, 'supplier_id' => $this->lief->id, 'name' => 'Frau Katalog']);
    FoodAlchemistSupplierContact::create(['team_id' => $this->childA->id, 'supplier_id' => $this->lief->id, 'name' => 'Herr NUR-A', 'phone' => '0170 999']);
    $this->namen = fn (array $blatt) => array_column($blatt['kontakte'], 'name');
});

it('Stammblatt (Dienst): Team B sieht Katalog-Kontakt, nicht den von Team A; Team A sieht beide', function () {
    $svc = app(SupplierService::class);
    expect(($this->namen)($svc->stammblatt($this->childB, $this->lief->id)))->toBe(['Frau Katalog'])
        ->and(($this->namen)($svc->stammblatt($this->childA, $this->lief->id)))->toEqualCanonicalizing(['Frau Katalog', 'Herr NUR-A'])
        ->and(($this->namen)($svc->stammblatt($this->rootTeam, $this->lief->id)))->toBe(['Frau Katalog']);   // auch nach oben nicht
});

it('Stammblatt am Computer: Team B sieht den Ansprechpartner von Team A nicht', function () {
    $this->actingAs($this->makeUser($this->childB, 'Team B'));
    Livewire::test(SupplierDetail::class)->call('oeffnen', $this->lief->id)
        ->assertSee('Frau Katalog')->assertDontSee('Herr NUR-A')->assertDontSee('0170 999');

    $this->actingAs($this->makeUser($this->childA, 'Team A'));
    Livewire::test(SupplierDetail::class)->call('oeffnen', $this->lief->id)->assertSee('Herr NUR-A');
});

it('MCP suppliers.GET: Team B bekommt den Ansprechpartner von Team A nicht', function () {
    $registry = app(ToolRegistry::class);
    $holen = fn ($team) => $registry->get('foodalchemist.suppliers.GET')
        ->execute(['supplier_id' => $this->lief->id], new ToolContext($this->makeUser($team), $team))->data;

    expect(($this->namen)($holen($this->childB)))->toBe(['Frau Katalog'])
        ->and(($this->namen)($holen($this->childA)))->toEqualCanonicalizing(['Frau Katalog', 'Herr NUR-A']);
});

it('Portal übernehmen: Kontakt des Eltern-Teams bleibt unverändert, das übernehmende Team bekommt einen eigenen', function () {
    Storage::fake('local');
    Queue::fake();
    // Master-Team = Kind A (unter Root); Root hat am globalen Lieferanten schon einen Disposition-Kontakt
    config(['foodalchemist.master_team_id' => $this->childA->id]);
    $global = FoodAlchemistSupplier::create(['team_id' => null, 'name' => 'Global KONTAKT']);
    $eltern = FoodAlchemistSupplierContact::create(['team_id' => $this->rootTeam->id, 'supplier_id' => $global->id, 'rolle_key' => 'disposition',
        'role' => 'Disposition', 'name' => 'Frau Eltern', 'phone' => '0211 1']);
    $admin = $this->makeUser($this->childA, 'Chef A', 'owner');
    $this->actingAs($admin);
    $links = app(LieferantenLinkService::class);
    $svc = app(LieferantenStammdatenblattService::class);
    $link = $links->erzeugen($this->childA, $admin, $global);
    expect($svc->vorbelegung(\Platform\FoodAlchemist\Models\FoodAlchemistLieferantenLink::first())['kontakte']['disposition']['name'])->toBe('Frau Eltern');   // Anzeige darf erben

    app(LieferantenPortalController::class)->stammdatenblatt($link['token'], Request::create('/l/x/stammdatenblatt', 'POST',
        ['kontakt' => ['disposition' => ['name' => 'Herr Portal', 'phone' => '0211 2']]], [], [], ['REMOTE_ADDR' => '203.0.113.9']), $links, $svc);
    $zeile = Zeile::first();
    expect($zeile->aktion)->toBe(Zeile::AKTION_NEU);   // kein eigener Kontakt ⇒ „neu“, nicht „ändert“

    $svc->entscheide($this->childA, $admin, [$zeile->id], 'uebernehmen');

    $eltern->refresh();
    $eigen = FoodAlchemistSupplierContact::where('supplier_id', $global->id)->where('team_id', $this->childA->id)->get();
    expect($eltern->name)->toBe('Frau Eltern')->and($eltern->phone)->toBe('0211 1')
        ->and($eigen)->toHaveCount(1)->and($eigen->first()->name)->toBe('Herr Portal')->and($eigen->first()->rolle_key)->toBe('disposition');

    // zweite Übernahme ändert den EIGENEN, legt keinen weiteren an
    app(LieferantenPortalController::class)->stammdatenblatt($link['token'], Request::create('/l/x/stammdatenblatt', 'POST',
        ['kontakt' => ['disposition' => ['name' => 'Herr Portal Neu']]], [], [], ['REMOTE_ADDR' => '203.0.113.9']), $links, $svc);
    $svc->entscheide($this->childA, $admin, Zeile::where('entscheidung', 'offen')->pluck('id')->all(), 'uebernehmen');
    expect(FoodAlchemistSupplierContact::where('supplier_id', $global->id)->where('team_id', $this->childA->id)->pluck('name')->all())->toBe(['Herr Portal Neu'])
        ->and($eltern->fresh()->name)->toBe('Frau Eltern');
});
