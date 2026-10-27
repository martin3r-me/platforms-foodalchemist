<?php

use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Livewire\Suppliers\Marken;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierMarke;
use Platform\FoodAlchemist\Services\Marken\MarkeAusArtikel;
use Platform\FoodAlchemist\Services\Marken\MarkenPflegeService;
use Platform\FoodAlchemist\Services\Marken\MarkeOffenService;
use Platform\FoodAlchemist\Services\Marken\MarkenUebersichtService;
use Platform\FoodAlchemist\Support\MasterTeam;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Lieferanten → Marken (Dominique 27.10.2026): zentrale Übersicht aller supplier_marken-Einträge mit GPs (Marke in der Klammer) und Artikeln (auf Knopfdruck),
 * Filter, Pflege nur Plattform-/Master-Admin, lesend für alle; nach dem Setzen „passende Marke-offen-GPs neu benennen“ (streng). MCP GET alle + NACHRENDERN.
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

it('Liste: alle Einträge mit Typ, Geltung und GPs mit der Marke in der Klammer; Filter Typ, nur global, Suche', function () {
    FoodAlchemistSupplierMarke::create(['supplier_id' => $this->cc->id, 'kuerzel' => 'bc', 'marke' => 'Base Culinar', 'typ' => 'eigenmarke']);
    app(MarkenPflegeService::class)->sperren($this->admin, $this->cc->id, 'KK');
    foreach (['Jus: konserviert / (Knorr)', 'Fond: konserviert / (knorr)'] as $n) {
        FoodAlchemistGp::create(['team_id' => null, 'gp_key' => 'mk-' . (++$this->nr) . '||', 'name' => $n, 'zusatz' => str_contains($n, 'knorr') ? 'knorr' : 'Knorr']);
    }
    $svc = app(MarkenUebersichtService::class);
    $nach = collect($svc->liste()['zeilen'])->keyBy('kuerzel');

    expect($nach['kn']['gps'])->toBe(2)->and($nach['kn']['supplier_id'])->toBeNull()
        ->and($nach['bc']['typ'])->toBe('eigenmarke')->and($nach['bc']['lieferant'])->toBe('Chefs Culinar West Weeze')
        ->and($nach['kk']['typ'])->toBe('sperre')->and($nach['kk']['gps'])->toBeNull()
        ->and(collect($svc->liste(['typ' => 'sperre'])['zeilen'])->pluck('kuerzel')->all())->toBe(['kk'])
        ->and(collect($svc->liste(['nur_global' => true])['zeilen'])->pluck('kuerzel')->all())->toBe(['kn'])
        ->and(collect($svc->liste(['suche' => 'base'])['zeilen'])->pluck('kuerzel')->all())->toBe(['bc']);
});

it('Artikel-Zählung folgt der Auflösungsregel (Vorrang, Sperre, Lieferant)', function () {
    ($this->la)($this->cc, 'Kn.Bratenjus pastös 1kg');
    ($this->la)($this->cc, 'TK Kn.Rinderfond 1kg');
    ($this->la)($this->cc, 'Knödel Halbweiß 1kg');               // „Knödel“ ist kein Kürzel
    ($this->la)($this->cc, 'TK KK Schnitzel 3kg');
    app(MarkenPflegeService::class)->sperren($this->admin, $this->cc->id, 'KK');
    $svc = app(MarkenUebersichtService::class);
    $id = fn (string $k) => FoodAlchemistSupplierMarke::where('kuerzel', $k)->value('id');

    expect($svc->artikelZahl($id('kn')))->toBe(2)->and($svc->artikelZahl($id('kk')))->toBe(1);
});

it('Seite: lesend für alle Teams; Pflege nur Admin; nach dem Setzen werden passende Marke-offen-GPs streng neu benannt', function () {
    $einMarke = ($this->gp)('Torte Apfel-Pecan: TK');
    ($this->la)($this->cc, 'TK B&B Apfel-Pecan.Torte 12er2500g', $einMarke);
    $zweiMarken = ($this->gp)('Bagel Sesam: TK');
    ($this->la)($this->cc, 'TK B&B Sesam Bagel 44x85g', $zweiMarken);
    ($this->la)($this->cc, 'TK Kn.Sesam Bagel 44x85g', $zweiMarken);

    $this->actingAs($this->mandant);
    Livewire::test(Marken::class)->assertSeeHtml('data-marken-seite')->assertSee('Knorr')->assertDontSeeHtml('data-mk-bearbeiten')
        ->call('bearbeiten', FoodAlchemistSupplierMarke::where('kuerzel', 'kn')->value('id'))->assertForbidden();

    $this->actingAs($this->admin);
    $c = Livewire::test(Marken::class)->set('neuOffen', true)->set('neuForm.supplier_id', (string) $this->cc->id)->set('neuForm.kuerzel', 'B&B')
        ->set('neuForm.marke', 'Bake & Bite')->call('neuSpeichern')->assertSet('fehler', null)->assertSeeHtml('data-nachrendern-angebot')
        ->call('nachrendernVorschau')->assertSeeHtml('data-nachrendern-vorschau');
    expect($einMarke->fresh()->marke_offen)->toBeTrue();   // Vorschau schreibt nichts
    $c->call('nachrendernAnwenden');
    expect($einMarke->fresh()->name)->toBe('Torte Apfel-Pecan: TK / (Bake & Bite)')
        ->and($zweiMarken->fresh()->marke_offen)->toBeTrue();   // zwei Marken: teilen, bleibt offen

    $id = FoodAlchemistSupplierMarke::where('kuerzel', 'b&b')->value('id');
    Livewire::test(Marken::class)->call('bearbeiten', $id)->set('editForm.vorrang', 3)->call('speichern')->assertSet('fehler', null);
    expect((int) FoodAlchemistSupplierMarke::find($id)->vorrang)->toBe(3);
    Livewire::test(Marken::class)->call('loeschen', $id);
    expect(FoodAlchemistSupplierMarke::find($id))->toBeNull();
});

it('MCP: supplier_marken.GET alle=true listet; NACHRENDERN ist Trockenlauf-Standard und nur für Admins', function () {
    $gp = ($this->gp)('Torte Apfel-Pecan: TK');
    ($this->la)($this->cc, 'TK B&B Apfel-Pecan.Torte 12er2500g', $gp);
    app(MarkenPflegeService::class)->kuerzelSetzen($this->admin, $this->cc->id, 'B&B', 'Bake & Bite', 'eigenmarke');

    $get = ($this->tool)('foodalchemist.supplier_marken.GET', ['alle' => true, 'artikel_zaehlen' => true], $this->mandant);
    expect($get->success)->toBeTrue()->and(collect($get->data['zeilen'])->firstWhere('kuerzel', 'b&b')['artikel'])->toBe(1);

    $vorschau = ($this->tool)('foodalchemist.supplier_marken.NACHRENDERN', ['kuerzel' => 'B&B', 'supplier_id' => $this->cc->id], $this->admin);
    expect($vorschau->success)->toBeTrue()->and($vorschau->data['trockenlauf'])->toBeTrue()->and($gp->fresh()->marke_offen)->toBeTrue();
    $nein = ($this->tool)('foodalchemist.supplier_marken.NACHRENDERN', ['kuerzel' => 'B&B', 'supplier_id' => $this->cc->id, 'dry_run' => false], $this->mitglied);
    expect($nein->success)->toBeFalse()->and($gp->fresh()->marke_offen)->toBeTrue();
    $echt = ($this->tool)('foodalchemist.supplier_marken.NACHRENDERN', ['kuerzel' => 'B&B', 'supplier_id' => $this->cc->id, 'dry_run' => false], $this->admin);
    expect($echt->success)->toBeTrue()->and($gp->fresh()->name)->toBe('Torte Apfel-Pecan: TK / (Bake & Bite)');
});

it('Dubletten: gleiche Herstellermarke bei ≥ 2 Lieferanten erkannt; Eigenmarken und Sperren nie; abweichender Vorrang → höchster', function () {
    $delta = FoodAlchemistSupplier::create(['team_id' => null, 'name' => 'Delta Fleisch', 'rolle' => 'haendler']);
    FoodAlchemistSupplierMarke::create(['supplier_id' => $this->cc->id, 'kuerzel' => 'stei', 'marke' => 'Steinhauer', 'typ' => 'herstellermarke', 'vorrang' => 1]);
    FoodAlchemistSupplierMarke::create(['supplier_id' => $delta->id, 'kuerzel' => 'stei', 'marke' => 'steinhauer', 'typ' => 'herstellermarke', 'vorrang' => 3]);
    FoodAlchemistSupplierMarke::create(['supplier_id' => $this->cc->id, 'kuerzel' => 'hs', 'marke' => 'Hausmarke', 'typ' => 'eigenmarke']);
    FoodAlchemistSupplierMarke::create(['supplier_id' => $delta->id, 'kuerzel' => 'hs', 'marke' => 'Hausmarke', 'typ' => 'eigenmarke']);
    app(MarkenPflegeService::class)->sperren($this->admin, $this->cc->id, 'KK');
    app(MarkenPflegeService::class)->sperren($this->admin, $delta->id, 'KK');

    $d = app(MarkenUebersichtService::class)->dubletten();

    expect($d)->toHaveCount(1)->and($d[0]['kuerzel'])->toBe('stei')->and($d[0]['vorrang'])->toBe(3)->and($d[0]['vorrang_abweichend'])->toBeTrue()
        ->and($d[0]['lieferanten'])->toEqualCanonicalizing(['Chefs Culinar West Weeze', 'Delta Fleisch']);
});

it('Zusammenführen: Trockenlauf schreibt nichts; danach eine Zeile für alle, Lieferanten-Zeilen weg, Auflösung und GP-Namen unverändert (0 GPs geändert)', function () {
    $delta = FoodAlchemistSupplier::create(['team_id' => null, 'name' => 'Delta Fleisch', 'rolle' => 'haendler']);
    FoodAlchemistSupplierMarke::create(['supplier_id' => $this->cc->id, 'kuerzel' => 'stei', 'marke' => 'Steinhauer', 'typ' => 'herstellermarke', 'vorrang' => 1]);
    FoodAlchemistSupplierMarke::create(['supplier_id' => $delta->id, 'kuerzel' => 'stei', 'marke' => 'Steinhauer', 'typ' => 'herstellermarke', 'vorrang' => 2]);
    app(MarkeAusArtikel::class)->vergessen();
    $gp = FoodAlchemistGp::create(['team_id' => null, 'gp_key' => 'mk-z||', 'name' => 'Heidelbeeren heiß: konserviert / (Steinhauer)', 'zusatz' => 'Steinhauer',
        'main_ingredient_display' => 'Heidelbeeren heiß', 'condition' => 'konserviert', 'commodity_group_code' => '13', 'rezepturware' => true, 'marke_offen' => false]);
    ($this->la)($this->cc, 'Stei.Heiße Heidelbeeren 2650ml', $gp);
    ($this->la)($delta, 'Stei.Heiße Heidelbeeren 2,6l', $gp);
    $aufloesung = fn () => [app(MarkeAusArtikel::class)->kuerzelAus('Stei.Heiße Heidelbeeren', $this->cc->id)[0] ?? null,
        app(MarkeAusArtikel::class)->kuerzelAus('Stei.Heiße Heidelbeeren', $delta->id)[0] ?? null];
    $vorher = $aufloesung();
    $schluessel = app(MarkenUebersichtService::class)->dubletten()[0]['schluessel'];

    $v = app(MarkenUebersichtService::class)->zusammenfuehren($this->admin, $schluessel, true);
    expect($v['vorrang'])->toBe(2)->and(FoodAlchemistSupplierMarke::whereNull('supplier_id')->where('kuerzel', 'stei')->exists())->toBeFalse();

    $r = app(MarkenUebersichtService::class)->zusammenfuehren($this->admin, $schluessel, false);
    app(MarkeAusArtikel::class)->vergessen();
    expect(FoodAlchemistSupplierMarke::where('kuerzel', 'stei')->get(['supplier_id', 'vorrang'])->map(fn ($z) => [$z->supplier_id, (int) $z->vorrang])->all())->toBe([[null, 2]])
        ->and($aufloesung())->toBe($vorher)->and($vorher)->toBe(['Steinhauer', 'Steinhauer'])
        ->and($r['nachrendern']['zaehler']['gerendert'])->toBe(0)
        ->and($gp->fresh()->name)->toBe('Heidelbeeren heiß: konserviert / (Steinhauer)');
});

it('Zusammenführen stoppt bei einem globalen Kürzel mit anderer Bedeutung; MCP ZUSAMMENFUEHREN nur für Admins, Trockenlauf-Standard', function () {
    $delta = FoodAlchemistSupplier::create(['team_id' => null, 'name' => 'Delta Fleisch', 'rolle' => 'haendler']);
    foreach ([$this->cc->id, $delta->id] as $sid) {
        FoodAlchemistSupplierMarke::create(['supplier_id' => $sid, 'kuerzel' => 'kn', 'marke' => 'Knusperkönig', 'typ' => 'herstellermarke']);
    }
    $d = app(MarkenUebersichtService::class)->dubletten();
    expect($d[0]['global_konflikt'])->toContain('Knorr');
    expect(fn () => app(MarkenUebersichtService::class)->zusammenfuehren($this->admin, $d[0]['schluessel'], false))->toThrow(RuntimeException::class);

    FoodAlchemistSupplierMarke::where('kuerzel', 'kn')->whereNull('supplier_id')->forceDelete();
    $liste = ($this->tool)('foodalchemist.supplier_marken.ZUSAMMENFUEHREN', [], $this->admin);
    expect($liste->success)->toBeTrue()->and($liste->data['dubletten'])->toHaveCount(1);
    $vorschau = ($this->tool)('foodalchemist.supplier_marken.ZUSAMMENFUEHREN', ['schluessel' => $liste->data['dubletten'][0]['schluessel']], $this->admin);
    expect($vorschau->success)->toBeTrue()->and($vorschau->data['trockenlauf'])->toBeTrue()->and(FoodAlchemistSupplierMarke::where('kuerzel', 'kn')->count())->toBe(2);
    $nein = ($this->tool)('foodalchemist.supplier_marken.ZUSAMMENFUEHREN', ['schluessel' => $liste->data['dubletten'][0]['schluessel'], 'dry_run' => false], $this->mitglied);
    expect($nein->success)->toBeFalse()->and(FoodAlchemistSupplierMarke::where('kuerzel', 'kn')->count())->toBe(2);
});

it('Marke offen: „Gilt für“ schlägt „alle“ vor, wenn die Herstellermarke schon bei einem anderen Lieferanten steht; Eigenmarke bleibt beim Lieferanten', function () {
    $hersteller = FoodAlchemistSupplier::create(['team_id' => null, 'name' => 'Feinkost Hersteller', 'rolle' => 'hersteller']);
    FoodAlchemistSupplierMarke::create(['supplier_id' => $this->hh->id, 'kuerzel' => 'stei', 'marke' => 'Steinhauer', 'typ' => 'herstellermarke']);
    ($this->la)($hersteller, 'XQZ Heidelbeeren heiß 2l', ($this->gp)('Heidelbeeren heiß: konserviert'));
    ($this->la)($this->cc, 'TK B&B Apfel-Pecan.Torte 12er2500g', ($this->gp)('Torte Apfel-Pecan: TK'));
    $this->actingAs($this->admin);

    Livewire::test(\Platform\FoodAlchemist\Livewire\Suppliers\MarkeOffen::class)->call('oeffnen', $hersteller->id . '|anfang|xqz')
        ->assertSet('form.geltung', 'lieferant')
        ->set('form.marke', 'steinhauer')->assertSet('form.geltung', 'alle')->assertSee('Marke ist schon bei Handelshof Mönchengladbach angelegt')
        ->set('form.marke', 'Etwas anderes')->assertSet('form.geltung', 'lieferant');

    // Händler → Eigenmarke-Standard: kein Vorschlag „alle“
    Livewire::test(\Platform\FoodAlchemist\Livewire\Suppliers\MarkeOffen::class)->call('oeffnen', $this->cc->id . '|anfang|b&b')
        ->set('form.marke', 'Steinhauer')->assertSet('form.typ', 'eigenmarke')->assertSet('form.geltung', 'lieferant');
});
