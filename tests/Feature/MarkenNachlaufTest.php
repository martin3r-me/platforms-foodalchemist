<?php

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Enums\SignalTyp;
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
use Platform\FoodAlchemist\Services\SignalDetektorService;
use Platform\FoodAlchemist\Support\MasterTeam;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Marken-Nachlauf (Plan GP-Stamm 3g, Dominique 26.10.2026): Liste „Marke offen“ nach unbekannten Kürzeln, „Kürzel zuordnen“ mit Neu-Rendern nur bei
 * genau einer Marke über ALLE Artikel (sonst „teilen“ bzw. „Fall B“), Kürzel mit „&“ (B&B), Signal in der Leitstelle, MCP im Lockstep.
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

it('Wortregel: „&“ innerhalb eines Kürzels zählt (B&B, C&F); bekannte Fälle unverändert', function () {
    $w = fn (string $bez) => array_map(fn ($x) => $x['kuerzel'] . '@' . $x['position'], MarkeAusArtikel::kuerzelWoerter($bez));

    expect($w('TK B&B Apfel-Pecan.Torte 12er2500g'))->toContain('b&b@anfang')
        ->and($w('C&F 3.00 KG BOHNENSALAT'))->toContain('c&f@anfang')
        ->and($w('100G MUFFIN HAPPY BLUEBERRY  B&B'))->toContain('b&b@ende')
        ->and($w('Kn.Bratenjus pastös'))->toContain('kn@anfang')
        ->and(app(MarkeAusArtikel::class)->kuerzelAus('Kn.Bratenjus pastös', null)[0] ?? null)->toBe('Knorr')
        ->and($w('&B Brühe'))->not->toContain('&b@anfang')   // „&“ nur zwischen Buchstaben
        ->and($w('MSC Lachs'))->not->toContain('msc@anfang');

    app(MarkenPflegeService::class)->kuerzelSetzen($this->admin, $this->cc->id, 'B&B', 'Bake & Bite');
    expect(app(MarkeAusArtikel::class)->kuerzelAus('TK B&B Sesam Bagel 44x85g', $this->cc->id))->toBe(['Bake & Bite', 'B&B']);
});

it('Liste: unbekannte Kürzel je Lieferant mit Anzahl und Beispielen; bekannte, Stoppwörter und „ende“ ohne Ende-Kürzel fallen raus', function () {
    $torte = ($this->gp)('Torte Apfel-Pecan: TK');
    $bagel = ($this->gp)('Bagel Sesam: TK');
    ($this->la)($this->cc, 'TK B&B Apfel-Pecan.Torte 12er2500g', $torte);
    ($this->la)($this->cc, 'TK B&B Sesam Bagel 44x85g', $bagel);
    ($this->la)($this->cc, 'TK Kn.Bratenjus 1kg', ($this->gp)('Bratenjus: TK'));        // bekanntes Kürzel → Marke steht, kein offener Artikel
    ($this->la)($this->cc, 'Kartoffelsalat Pfälzer Art XYZ', ($this->gp)('Kartoffelsalat: frisch'));   // „XYZ“ am Ende, CC führt keine Ende-Kürzel
    ($this->la)($this->hh, '5KG BUTTERMILCH DESS FRI', ($this->gp)('Buttermilch-Dessert: frisch'));   // HH führt Ende-Kürzel

    $liste = app(MarkeOffenService::class)->liste();
    $nach = collect($liste['gruppen'])->keyBy(fn ($g) => $g['kuerzel'] . '@' . $g['position']);

    expect($nach->has('b&b@anfang'))->toBeTrue()
        ->and($nach['b&b@anfang']['gps'])->toBe(2)->and($nach['b&b@anfang']['artikel'])->toBe(2)->and($nach['b&b@anfang']['beispiele'])->toHaveCount(2)
        ->and($nach['b&b@anfang']['lieferant'])->toBe('Chefs Culinar West Weeze')
        ->and($nach->has('fri@ende'))->toBeTrue()
        ->and($nach->has('xyz@ende'))->toBeFalse()
        ->and($nach->has('tk@anfang'))->toBeFalse()
        ->and($nach->has('kn@anfang'))->toBeFalse()
        ->and($liste['gps_offen'])->toBe(4);
});

it('Zuordnen: GP mit genau einer Marke wird neu benannt (Klammer vor den Flags, Key bleibt); zwei Marken = teilen, Artikel ohne Marke = Fall B', function () {
    $eins = ($this->gp)('Torte Apfel-Pecan: TK / (Bio)');
    ($this->la)($this->cc, 'TK B&B Apfel-Pecan.Torte 12er2500g', $eins);
    $zwei = ($this->gp)('Bagel Sesam: TK');
    ($this->la)($this->cc, 'TK B&B Sesam Bagel 44x85g', $zwei);
    ($this->la)($this->cc, 'TK Kn.Sesam Bagel 44x85g', $zwei);                 // Knorr → zwei Marken
    $fallB = ($this->gp)('Donut Schoko: TK');
    ($this->la)($this->cc, 'TK B&B Donut Choc 12x73g', $fallB);
    ($this->la)($this->cc, 'Donut Schoko TK 12x73g', $fallB);                 // ohne Marke
    $keyVorher = $eins->gp_key;

    $r = app(MarkeOffenService::class)->zuordnen($this->admin, $this->cc->id, 'B&B', 'Bake & Bite', 'herstellermarke');
    $faelle = collect($r['gps'])->keyBy('id');

    expect($r['zaehler'])->toMatchArray(['gerendert' => 1, 'teilen' => 1, 'fall_b' => 1])
        ->and($eins->fresh()->name)->toBe('Torte Apfel-Pecan: TK / (Bake & Bite) / (Bio)')
        ->and($eins->fresh()->zusatz)->toBe('Bake & Bite')->and($eins->fresh()->marke_offen)->toBeFalse()->and($eins->fresh()->gp_key)->toBe($keyVorher)
        ->and($faelle[$zwei->id]['fall'])->toBe('teilen')->and($zwei->fresh()->name)->toBe('Bagel Sesam: TK')->and($zwei->fresh()->marke_offen)->toBeTrue()
        ->and($faelle[$fallB->id]['fall'])->toBe('fall_b')->and($fallB->fresh()->marke_offen)->toBeTrue()
        ->and(FoodAlchemistSupplierMarke::where('supplier_id', $this->cc->id)->where('kuerzel', 'b&b')->value('marke'))->toBe('Bake & Bite');
});

it('Trockenlauf schreibt nichts, zeigt aber die Vorschau; ohne Recht wird abgewiesen', function () {
    $gp = ($this->gp)('Torte Apfel-Pecan: TK');
    ($this->la)($this->cc, 'TK B&B Apfel-Pecan.Torte 12er2500g', $gp);

    $r = app(MarkeOffenService::class)->zuordnen($this->admin, $this->cc->id, 'B&B', 'Bake & Bite', trockenlauf: true);
    expect($r['gps'][0]['fall'])->toBe('gerendert')->and($r['gps'][0]['name_neu'])->toBe('Torte Apfel-Pecan: TK / (Bake & Bite)')
        ->and($gp->fresh()->name)->toBe('Torte Apfel-Pecan: TK')->and(FoodAlchemistSupplierMarke::where('kuerzel', 'b&b')->exists())->toBeFalse();

    expect(fn () => app(MarkeOffenService::class)->zuordnen($this->mitglied, $this->cc->id, 'B&B', 'Bake & Bite'))->toThrow(FaRechtFehltException::class);
    expect($gp->fresh()->marke_offen)->toBeTrue();
});

it('Seite: Master-Admin sieht die Gruppe und ordnet zu; Mitglied ohne Recht sieht keinen Knopf und wird abgewiesen, fremdes Team kommt nicht auf die Seite', function () {
    $gp = ($this->gp)('Torte Apfel-Pecan: TK');
    ($this->la)($this->cc, 'TK B&B Apfel-Pecan.Torte 12er2500g', $gp);
    $schluessel = $this->cc->id . '|anfang|b&b';

    $this->actingAs($this->admin);
    Livewire::test(MarkeOffen::class)->assertSeeHtml('data-marke-offen-gruppe="' . e($schluessel) . '"')->assertSeeHtml('data-kuerzel-zuordnen-oeffnen')
        ->call('oeffnen', $schluessel)->set('form.marke', 'Bake & Bite')->call('vorschau')->assertSeeHtml('data-kuerzel-vorschau-liste')
        ->call('zuordnen')->assertSet('fehler', null);
    expect($gp->fresh()->name)->toBe('Torte Apfel-Pecan: TK / (Bake & Bite)');

    $andere = ($this->gp)('Bagel Sesam: TK');
    ($this->la)($this->cc, 'TK SBS Sesam Bagel 44x85g', $andere);
    $this->actingAs($this->mitglied);
    Livewire::test(MarkeOffen::class)->assertDontSeeHtml('data-kuerzel-zuordnen-oeffnen')
        ->call('oeffnen', $this->cc->id . '|anfang|sbs')->set('form.marke', 'Steinhauer')->call('zuordnen')->assertForbidden();
    expect($andere->fresh()->marke_offen)->toBeTrue();

    $this->actingAs($this->mandant);   // anderes Team: die Seite gehört dem Master-Team
    Livewire::test(MarkeOffen::class)->assertForbidden();
});

it('Signal: Master-Team bekommt die Summen-Signale (unbekannte Kürzel, Lieferanten Rolle offen mit Rezepturware), andere Teams nicht', function () {
    $offen = FoodAlchemistSupplier::create(['team_id' => null, 'name' => 'Feinkost Sonnenschein GmbH']);
    ($this->la)($this->cc, 'TK B&B Apfel-Pecan.Torte 12er2500g', ($this->gp)('Torte Apfel-Pecan: TK'));
    ($this->la)($offen, 'Kartoffelsalat 2kg', ($this->gp)('Kartoffelsalat: frisch'));

    expect(app(SignalDetektorService::class)->markenNachlauf($this->rootTeam))->toBe(2)
        ->and(app(SignalDetektorService::class)->markenNachlauf($this->childA))->toBe(0);
    $keys = DB::table('foodalchemist_signals')->where('type', SignalTyp::MarkenNachlauf->value)->pluck('dedup_key')->all();
    expect($keys)->toContain('marken-nachlauf-kuerzel')->toContain('marken-nachlauf-lieferant-rolle')
        ->and(DB::table('foodalchemist_signals')->where('type', SignalTyp::MarkenNachlauf->value)->distinct()->pluck('team_id')->all())->toBe([$this->rootTeam->id]);
});

it('MCP: marke_offen.GET liefert die Gruppen; marke_offen.ZUORDNEN ist standardmäßig Trockenlauf und nur für Admins', function () {
    $gp = ($this->gp)('Torte Apfel-Pecan: TK');
    ($this->la)($this->cc, 'TK B&B Apfel-Pecan.Torte 12er2500g', $gp);

    $get = ($this->tool)('foodalchemist.marke_offen.GET', [], $this->admin);
    expect($get->success)->toBeTrue()->and(collect($get->data['gruppen'])->pluck('kuerzel')->all())->toContain('b&b');

    $vorschau = ($this->tool)('foodalchemist.marke_offen.ZUORDNEN', ['kuerzel' => 'B&B', 'marke' => 'Bake & Bite', 'supplier_id' => $this->cc->id], $this->admin);
    expect($vorschau->success)->toBeTrue()->and($vorschau->data['trockenlauf'])->toBeTrue()->and($gp->fresh()->marke_offen)->toBeTrue();

    $echt = ($this->tool)('foodalchemist.marke_offen.ZUORDNEN', ['kuerzel' => 'B&B', 'marke' => 'Bake & Bite', 'supplier_id' => $this->cc->id, 'dry_run' => false], $this->admin);
    expect($echt->success)->toBeTrue()->and($gp->fresh()->name)->toBe('Torte Apfel-Pecan: TK / (Bake & Bite)');

    $nein = ($this->tool)('foodalchemist.marke_offen.ZUORDNEN', ['kuerzel' => 'SBS', 'marke' => 'Steinhauer', 'dry_run' => false], $this->mitglied);
    expect($nein->success)->toBeFalse()->and($nein->errorCode)->toBeIn(['ACCESS_DENIED', 'FORBIDDEN'])   // Rechte-Hülle oder Tool selbst
        ->and(FoodAlchemistSupplierMarke::where('kuerzel', 'sbs')->exists())->toBeFalse();
});
