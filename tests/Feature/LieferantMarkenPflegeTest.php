<?php

use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Livewire\Suppliers\Index as LieferantenIndex;
use Platform\FoodAlchemist\Livewire\Suppliers\LieferantMarken;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierMarke;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * GP-Regelwerk v3.7.1 §5 Ausnahme 3 — Pflege von Rolle, Kurzname und Marken-Kürzel: nur Plattform-Admin/Master-Admin (UI-Block und MCP, Server-Guard),
 * Mandanten lesen; Liste „Lieferant einstufen“; MCP-Tools im Lockstep (artikel.MARKE, supplier_marken.GET/PUT, gps.* mit rezepturware).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    config(['foodalchemist.bearbeitungssperre' => false]);
    $this->mandant = $this->makeUser($this->rootTeam, 'Mandant Inhaber');
    $this->admin = $this->makeUser($this->rootTeam, 'Plattform Admin');
    config(['platform-shell.admins' => [strtolower((string) $this->admin->email)]]);
    $this->lieferant = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Bäckerei Weyers GmbH']);
    $this->tool = fn (string $name, array $args, $user) => app(ToolRegistry::class)->get($name)->execute($args, new ToolContext($user, $this->rootTeam));
});

it('Mandant sieht Rolle und Kürzel nur lesend; Schreiben wird auf dem Server abgewiesen', function () {
    $this->actingAs($this->mandant);

    Livewire::test(LieferantMarken::class, ['supplierId' => $this->lieferant->id])
        ->assertSeeHtml('data-marken-rolle-lesen')->assertDontSeeHtml('data-lm-rolle-speichern')
        ->set('rolle', 'hersteller')->set('kurzname', 'Weyers')->call('rolleSpeichern')->assertForbidden();
    expect($this->lieferant->fresh()->rolle->value)->toBe('offen');
});

it('Admin pflegt Rolle (Hersteller braucht Kurzname) und Kürzel (klein, ohne Punkt; MSC abgewiesen)', function () {
    $this->actingAs($this->admin);
    $c = Livewire::test(LieferantMarken::class, ['supplierId' => $this->lieferant->id])->assertSeeHtml('data-lm-rolle-speichern');

    $c->set('rolle', 'hersteller')->set('kurzname', '')->call('rolleSpeichern')->assertSet('fehler', fn ($f) => str_contains((string) $f, 'Kurznamen'));
    $c->set('kurzname', 'Weyers')->call('rolleSpeichern')->assertSet('fehler', null);
    expect($this->lieferant->fresh()->rolle->value)->toBe('hersteller')->and($this->lieferant->fresh()->kurzname)->toBe('Weyers');

    $c->set('neu', ['kuerzel' => 'WEY.', 'marke' => 'Weyers Backstube', 'typ' => 'herstellermarke'])->call('markeAnlegen')->assertSeeHtml('data-lm-marke="wey"');
    $c->set('neu', ['kuerzel' => 'MSC', 'marke' => 'MSC', 'typ' => 'herstellermarke'])->call('markeAnlegen')->assertSet('fehler', fn ($f) => str_contains((string) $f, 'Zertifizierung'));
    $c->call('markeLoeschen', 'wey')->assertDontSeeHtml('data-lm-marke="wey"');
    expect(FoodAlchemistSupplierMarke::count())->toBe(0);

    $c->set('rolle', 'haendler')->call('rolleSpeichern');
    expect($this->lieferant->fresh()->kurzname)->toBeNull();   // Kurzname nur beim Hersteller
});

it('Liste „Lieferant einstufen“: nur Rolle offen mit Artikeln an Rezepturware; Filter nur für Admin sichtbar', function () {
    $gp = FoodAlchemistGp::create(['team_id' => $this->rootTeam->id, 'name' => 'Brötchen: Gouda, frisch, belegt', 'gp_key' => 'broetchen_gouda', 'status' => 'approved',
        'requires_la' => true, 'commodity_group_code' => '09', 'main_ingredient_slug' => 'broetchen', 'rezepturware' => true, 'marke_offen' => true]);
    $haendler = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Großhandel Nord', 'rolle' => 'haendler']);
    foreach ([$this->lieferant, $haendler] as $i => $s) {
        $la = FoodAlchemistSupplierItem::create(['team_id' => $this->rootTeam->id, 'supplier_id' => $s->id, 'designation' => 'Brötchen Gouda', 'article_number' => 'E' . $i]);
        FoodAlchemistSupplierItemStructure::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'gp_id' => $gp->id]);
    }

    $this->actingAs($this->admin);
    $namen = Livewire::test(LieferantenIndex::class)->assertSeeHtml('data-lieferant-einstufen')->set('einstufen', true)->viewData('lieferanten')->pluck('name')->all();
    expect($namen)->toBe(['Bäckerei Weyers GmbH']);

    $this->actingAs($this->mandant);
    Livewire::test(LieferantenIndex::class)->assertDontSeeHtml('data-lieferant-einstufen');
});

it('MCP: supplier_marken.PUT nur Admin (Transaktion), GET liest Lieferant und globale Kürzel, artikel.MARKE nutzt denselben Dienst', function () {
    $verboten = ($this->tool)('foodalchemist.supplier_marken.PUT', ['supplier_id' => $this->lieferant->id, 'rolle' => 'hersteller', 'kurzname' => 'Weyers'], $this->mandant);
    expect($verboten->success)->toBeFalse()->and($verboten->errorCode)->toBe('ACCESS_DENIED');

    $ok = ($this->tool)('foodalchemist.supplier_marken.PUT', ['supplier_id' => $this->lieferant->id, 'rolle' => 'hersteller', 'kurzname' => 'Weyers',
        'kuerzel_setzen' => [['kuerzel' => 'Wey.', 'marke' => 'Weyers Backstube']]], $this->admin);
    expect($ok->success)->toBeTrue()->and($ok->data['gesetzt'][0]['kuerzel'])->toBe('wey');

    $fehler = ($this->tool)('foodalchemist.supplier_marken.PUT', ['kuerzel_setzen' => [['kuerzel' => 'kn', 'marke' => 'Knorr'], ['kuerzel' => 'msc', 'marke' => 'MSC']]], $this->admin);
    expect($fehler->success)->toBeFalse()->and(FoodAlchemistSupplierMarke::where('kuerzel', 'kn')->exists())->toBeFalse();   // ganz oder gar nicht

    ($this->tool)('foodalchemist.supplier_marken.PUT', ['kuerzel_setzen' => [['kuerzel' => 'kn', 'marke' => 'Knorr']]], $this->admin);
    $global = ($this->tool)('foodalchemist.supplier_marken.GET', [], $this->mandant);
    $lief = ($this->tool)('foodalchemist.supplier_marken.GET', ['supplier_id' => $this->lieferant->id], $this->mandant);
    expect($global->data['kuerzel'][0])->toMatchArray(['kuerzel' => 'kn', 'marke' => 'Knorr'])
        ->and([$lief->data['rolle'], $lief->data['kurzname']])->toBe(['hersteller', 'Weyers']);

    $la = FoodAlchemistSupplierItem::create(['team_id' => $this->rootTeam->id, 'supplier_id' => $this->lieferant->id, 'designation' => 'Laugenbrezel belegt', 'article_number' => 'W1']);
    $marke = ($this->tool)('foodalchemist.artikel.MARKE', ['item_id' => $la->id], $this->mandant);
    expect($marke->success)->toBeTrue()->and([$marke->data['marke'], $marke->data['quelle']])->toBe(['Weyers', 'kurzname']);
});

it('MCP gps.POST/GET: rezepturware ist Eingabe, marke_offen wird abgeleitet und gelesen', function () {
    $r = ($this->tool)('foodalchemist.gps.POST', ['hauptzutat' => 'Kartoffelsalat', 'condition' => 'frisch', 'commodity_group_code' => '13', 'rezepturware' => true,
        'marke_offen' => false], $this->mandant);
    expect($r->success)->toBeTrue();
    $gp = FoodAlchemistGp::where('main_ingredient_slug', 'kartoffelsalat')->firstOrFail();
    expect($gp->marke_offen)->toBeTrue();   // Eingabe marke_offen wird ignoriert

    $get = ($this->tool)('foodalchemist.gps.GET', ['id' => $gp->id], $this->mandant);
    expect($get->data['rezepturware'])->toBeTrue()->and($get->data['marke_offen'])->toBeTrue();
});
