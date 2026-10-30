<?php

use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Livewire\Suppliers\SupplierDetail;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierContact;
use Platform\FoodAlchemist\Services\SupplierService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Ansprechpartner sind team-eigen, auch am gemeinsamen Katalog-Lieferanten: Team B sieht im Stammblatt (Computer und
 * MCP suppliers.GET) keine Ansprechpartner, die Team A angelegt hat. Ansprechpartner des Katalog-Besitzers sieht jedes Kind.
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
