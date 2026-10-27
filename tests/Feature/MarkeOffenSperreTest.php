<?php

use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Livewire\Suppliers\MarkeOffen;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierMarke;
use Platform\FoodAlchemist\Services\Marken\MarkeAusArtikel;
use Platform\FoodAlchemist\Services\Marken\MarkenPflegeService;
use Platform\FoodAlchemist\Services\Marken\MarkeOffenService;
use Platform\FoodAlchemist\Support\MasterTeam;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Marken-Nachlauf, Rückmeldung io (Dominique 27.10.2026): „Kein Kürzel“ sperrt ein Wort dauerhaft (Liste + MarkeAusArtikel), Vorfilter ohne Raten —
 * Hauptzutat-Wörter (Käse, Jus, Mix; abgekürzt mit Punkt als Präfix eines Worts des eigenen GP) und Füllwörter (de, la, met …) sind keine Kandidaten.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    config(['foodalchemist.bearbeitungssperre' => false, 'foodalchemist.master_team_id' => $this->rootTeam->id]);
    MasterTeam::flush();
    $this->admin = $this->makeUser($this->rootTeam, 'Plattform Admin');
    config(['platform-shell.admins' => [strtolower((string) $this->admin->email)]]);
    $this->mitglied = $this->makeUser($this->rootTeam, 'Koch Mitglied', 'member');   // im Master-Team, aber kein Admin
    $this->mandant = $this->makeUser($this->childA, 'Mandant Inhaber');               // anderes Team
    $this->cc = FoodAlchemistSupplier::create(['team_id' => null, 'name' => 'Chefs Culinar West Weeze', 'rolle' => 'haendler']);
    $this->hh = FoodAlchemistSupplier::create(['team_id' => null, 'name' => 'Handelshof Mönchengladbach', 'rolle' => 'haendler']);
    FoodAlchemistSupplierMarke::create(['supplier_id' => $this->hh->id, 'kuerzel' => 'heng', 'marke' => 'Henglein', 'typ' => 'herstellermarke', 'position' => 'ende']);
    FoodAlchemistSupplierMarke::create(['supplier_id' => null, 'kuerzel' => 'kn', 'marke' => 'Knorr', 'typ' => 'herstellermarke']);
    app(MarkeAusArtikel::class)->vergessen();
    $this->nr = 0;
    $this->gp = fn (string $name) => FoodAlchemistGp::create(['team_id' => null, 'gp_key' => 'mn-' . (++$this->nr) . '||', 'name' => $name,
        'main_ingredient_display' => trim(strstr($name . ':', ':', true)), 'condition' => 'TK', 'commodity_group_code' => '13', 'rezepturware' => true, 'marke_offen' => true]);
    $this->la = function (FoodAlchemistSupplier $s, string $bez, ?FoodAlchemistGp $gp = null) {
        $la = FoodAlchemistSupplierItem::create(['team_id' => null, 'supplier_id' => $s->id, 'designation' => $bez, 'article_number' => 'MN' . (++$this->nr), 'qty' => 1, 'unit_code' => 'kg']);
        if ($gp !== null) {
            FoodAlchemistSupplierItemStructure::create(['team_id' => null, 'supplier_item_id' => $la->id, 'gp_id' => $gp->id]);
        }

        return $la;
    };
    $this->tool = fn (string $name, array $args, $user) => app(ToolRegistry::class)->get($name)->execute($args, new ToolContext($user, $this->rootTeam));
});

it('Kein Kürzel: Sperre nimmt den Kandidaten dauerhaft aus der Liste, MarkeAusArtikel nutzt das Wort nie; Löschen der Sperre bringt ihn zurück', function () {
    $gp = ($this->gp)('Schnitzel Wiener Art: TK');
    ($this->la)($this->cc, 'TK KK Schnitz.Wien. Aca120g3kg', $gp);
    $kandidat = fn () => collect(app(MarkeOffenService::class)->liste()['gruppen'])->contains(fn ($g) => $g['kuerzel'] === 'kk' && $g['supplier_id'] === $this->cc->id);
    expect($kandidat())->toBeTrue();

    $r = app(MarkeOffenService::class)->sperren($this->admin, $this->cc->id, 'KK');
    expect($r['gps'])->toBe(1)->and($kandidat())->toBeFalse()
        ->and(FoodAlchemistSupplierMarke::where('supplier_id', $this->cc->id)->where('kuerzel', 'kk')->value('typ'))->toBe('sperre');

    // ein globales Kürzel gleichen Namens greift bei diesem Lieferanten nicht mehr
    FoodAlchemistSupplierMarke::create(['supplier_id' => null, 'kuerzel' => 'kk', 'marke' => 'Knusperkönig', 'typ' => 'herstellermarke']);
    app(MarkeAusArtikel::class)->vergessen();
    expect(app(MarkeAusArtikel::class)->kuerzelAus('TK KK Schnitz.Wien. Aca120g3kg', $this->cc->id))->toBeNull()
        ->and(app(MarkeAusArtikel::class)->kuerzelAus('TK KK Schnitz.Wien. Aca120g3kg', $this->hh->id)[0] ?? null)->toBe('Knusperkönig');

    FoodAlchemistSupplierMarke::where('kuerzel', 'kk')->whereNull('supplier_id')->forceDelete();
    app(MarkenPflegeService::class)->kuerzelEntfernen($this->admin, $this->cc->id, 'kk');
    expect($kandidat())->toBeTrue();
});

it('Kein Kürzel: Trockenlauf schreibt nichts; ohne Recht abgewiesen; Seite und MCP', function () {
    $gp = ($this->gp)('Schnitzel Wiener Art: TK');
    ($this->la)($this->cc, 'TK KK Schnitz.Wien. Aca120g3kg', $gp);

    expect(app(MarkeOffenService::class)->sperren($this->admin, $this->cc->id, 'KK', trockenlauf: true)['gps'])->toBe(1)
        ->and(FoodAlchemistSupplierMarke::where('kuerzel', 'kk')->exists())->toBeFalse();
    expect(fn () => app(MarkeOffenService::class)->sperren($this->mitglied, $this->cc->id, 'KK'))->toThrow(FaRechtFehltException::class);

    $vorschau = ($this->tool)('foodalchemist.marke_offen.SPERREN', ['kuerzel' => 'KK', 'supplier_id' => $this->cc->id], $this->admin);
    expect($vorschau->success)->toBeTrue()->and($vorschau->data['trockenlauf'])->toBeTrue()->and(FoodAlchemistSupplierMarke::where('kuerzel', 'kk')->exists())->toBeFalse();
    $nein = ($this->tool)('foodalchemist.marke_offen.SPERREN', ['kuerzel' => 'KK', 'supplier_id' => $this->cc->id, 'dry_run' => false], $this->mitglied);
    expect($nein->success)->toBeFalse()->and(FoodAlchemistSupplierMarke::where('kuerzel', 'kk')->exists())->toBeFalse();

    $this->actingAs($this->admin);
    Livewire::test(MarkeOffen::class)->assertSeeHtml('data-kein-kuerzel')
        ->call('keinKuerzel', $this->cc->id . '|anfang|kk')->assertSet('fehler', null);
    expect(FoodAlchemistSupplierMarke::where('supplier_id', $this->cc->id)->where('kuerzel', 'kk')->value('typ'))->toBe('sperre');

    $this->actingAs($this->mitglied);
    Livewire::test(MarkeOffen::class)->assertDontSeeHtml('data-kein-kuerzel')->call('keinKuerzel', $this->cc->id . '|anfang|xy')->assertForbidden();
});

it('Vorfilter: Hauptzutat-Wörter und Füllwörter sind keine Kandidaten, echte Kürzel bleiben', function () {
    ($this->gp)('Käse: Scheiben, frisch');                     // „Käse“ ist Hauptzutat eines GP
    ($this->gp)('Mix: TK');
    $dessert = ($this->gp)('Dessert Mango: TK');
    ($this->la)($this->cc, 'TK Käse Kn.Platte 1kg', ($this->gp)('Käseplatte: TK'));
    ($this->la)($this->cc, 'Cremoso de Guacamole 1kg', ($this->gp)('Guacamole: TK'));
    ($this->la)($this->cc, 'TK Dess. Mango Becher 10x80g', $dessert);   // „Dess.“ = Präfix von „Dessert“ im eigenen GP
    ($this->la)($this->cc, 'TK Mix Gemüse 2,5kg', ($this->gp)('Gemüsemischung: TK'));
    ($this->la)($this->cc, 'TK Göt.Chia Van.pud. 60x45ml', ($this->gp)('Chiapudding: TK'));    // „Göt.“ ist kein Wortanfang im eigenen GP
    ($this->la)($this->cc, 'TK SBS Croissant 48x95g', ($this->gp)('Croissant: TK'));

    $k = collect(app(MarkeOffenService::class)->liste()['gruppen'])->pluck('kuerzel')->all();

    expect($k)->not->toContain('käse')->not->toContain('de')->not->toContain('dess')->not->toContain('mix')
        ->toContain('göt')->toContain('sbs');
});
