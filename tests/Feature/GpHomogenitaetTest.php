<?php

use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Platform\FoodAlchemist\Models\FoodAlchemistItemAllergen;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Services\GpHomogenitaetService;
use Platform\FoodAlchemist\Services\LeadLaService;
use Platform\FoodAlchemist\Support\StammPaket;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 82 §2 (Dominique 13.10.): Ein GP ist allergengleich. Verglichen wird nur „enthalten“ (Spuren zählen nicht),
 * fehlende Daten sind „unbekannt“ und blockieren nicht. Gilt nur beim NEUEN Verknüpfen; der Bericht liest nur.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->gp = $this->makeGp($this->rootTeam, 'Sahne');
    $this->sup = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Necta']);

    // Artikel mit Allergenzeile ($milch = Wert oder false = keine Zeile)
    $this->la = function (string $name, string|null|false $milch) {
        $la = FoodAlchemistSupplierItem::create(['team_id' => $this->rootTeam->id, 'supplier_id' => $this->sup->id, 'designation' => $name]);
        FoodAlchemistSupplierItemStructure::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'gp_id' => null]);
        if ($milch !== false) {
            FoodAlchemistItemAllergen::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'allergen_milk' => $milch]);
        }

        return $la;
    };
    $this->link = fn ($la, $gp = null) => app(LeadLaService::class)->verknuepfen($this->rootTeam, $gp ?? $this->gp, $la->id);
});

it('Spuren zählen nicht mit: Spuren neben „nicht enthalten“ ist einheitlich und verknüpfbar', function () {
    ($this->link)(($this->la)('Sahne A', 'nicht_enthalten'));
    ($this->link)(($this->la)('Sahne B', 'spuren'));

    expect(DB::table('foodalchemist_supplier_item_structures')->where('gp_id', $this->gp->id)->count())->toBe(2);
});

it('„enthalten“ gegen Spuren ist ein Widerspruch (Spuren = nicht enthalten) und wird beim Verknüpfen abgelehnt', function () {
    ($this->link)(($this->la)('Sahne A', 'enthalten'));
    $neu = ($this->la)('Sahne B', 'spuren');

    expect(fn () => ($this->link)($neu))->toThrow(RuntimeException::class, 'neues Grundprodukt');
    expect(FoodAlchemistSupplierItemStructure::where('supplier_item_id', $neu->id)->whereNotNull('gp_id')->exists())->toBeFalse();
});

it('fehlende Daten sind unbekannt, nicht gesperrt: keine Zeile, NULL und „unbekannt“ lassen sich verknüpfen', function () {
    ($this->link)(($this->la)('Sahne A', 'enthalten'));

    foreach ([false, null, 'unbekannt'] as $i => $wert) {
        ($this->link)(($this->la)("Sahne U{$i}", $wert));
    }

    expect(DB::table('foodalchemist_supplier_item_structures')->where('gp_id', $this->gp->id)->count())->toBe(4);
});

it('Bericht: findet uneinheitliche GPs, zählt unbekannte Artikel und ändert keine Verknüpfung', function () {
    // Uneinheitliches GP (Bestand, an der Prüfung vorbei verknüpft): enthalten + nicht_enthalten + eine ohne Daten
    $bestand = [($this->la)('B1', 'enthalten'), ($this->la)('B2', 'nicht_enthalten'), ($this->la)('B3', false)];
    DB::table('foodalchemist_supplier_item_structures')->whereIn('supplier_item_id', collect($bestand)->pluck('id'))->update(['gp_id' => $this->gp->id]);

    // Einheitliches GP mit zwei Artikeln (Spuren + nicht enthalten)
    $gp2 = $this->makeGp($this->rootTeam, 'Butter');
    $ok = [($this->la)('K1', 'spuren'), ($this->la)('K2', 'nicht_enthalten')];
    DB::table('foodalchemist_supplier_item_structures')->whereIn('supplier_item_id', collect($ok)->pluck('id'))->update(['gp_id' => $gp2->id]);

    $vorher = DB::table('foodalchemist_supplier_item_structures')->orderBy('id')->get(['id', 'gp_id', 'deleted_at'])->toArray();
    $bericht = app(GpHomogenitaetService::class)->bericht();
    $nachher = DB::table('foodalchemist_supplier_item_structures')->orderBy('id')->get(['id', 'gp_id', 'deleted_at'])->toArray();

    expect($nachher)->toEqual($vorher);                       // nur lesend
    expect($bericht['zusammenfassung'])->toMatchArray([
        'artikel_verknuepft' => 5,
        'artikel_unbekannt_gesamt' => 1,
        'gps_mit_mehreren_artikeln' => 2,
        'gps_uneinheitlich' => 1,
        'artikel_in_uneinheitlichen_gps' => 3,
        'artikel_unbekannt_in_uneinheitlichen_gps' => 1,
    ]);
    expect($bericht['gps'])->toHaveCount(1)
        ->and($bericht['gps'][0]['gp_id'])->toBe($this->gp->id)
        ->and($bericht['gps'][0]['abweichungen'])->toContain('Allergen „Milch“');
});

it('Befehl gp-homogenitaet-bericht läuft und nennt die Zahl unbekannter Artikel', function () {
    ($this->link)(($this->la)('Sahne A', false));

    $this->artisan('foodalchemist:gp-homogenitaet-bericht')
        ->expectsOutputToContain('artikel_unbekannt_gesamt')
        ->assertSuccessful();
});

it('StammPaket-Cache überlebt keine Job-Grenze: Opt-in in anderem Prozess geändert, nächster Job sieht ihn', function () {
    StammPaket::freischalten($this->rootTeam);
    expect(StammPaket::istFreigeschaltet($this->rootTeam))->toBeTrue();   // gecacht

    DB::table('foodalchemist_team_stamm_optins')->where('team_id', $this->rootTeam->id)->delete(); // „anderer Prozess"
    expect(StammPaket::istFreigeschaltet($this->rootTeam))->toBeTrue();   // alter Stand im selben Prozess (Beleg für das Problem)

    Event::dispatch(new JobProcessing('sync', Mockery::mock(\Illuminate\Contracts\Queue\Job::class)->shouldIgnoreMissing()));
    expect(StammPaket::istFreigeschaltet($this->rootTeam))->toBeFalse();  // frisch
});

it('StammPaket-Cache wird am Request-Ende geleert (terminating)', function () {
    StammPaket::freischalten($this->rootTeam);
    expect(StammPaket::istFreigeschaltet($this->rootTeam))->toBeTrue();
    DB::table('foodalchemist_team_stamm_optins')->where('team_id', $this->rootTeam->id)->delete();

    $this->app->terminate();

    expect(StammPaket::istFreigeschaltet($this->rootTeam))->toBeFalse();
});

it('Detail: je GP × Merkmal die abweichenden Artikel, Anteile, Vorschlag „neues GP“ und Konfidenz LOW (Plan §0.8)', function () {
    // 2 enthalten, 1 nicht enthalten, 1 ohne Daten → Minderheit = „ohne“ (der eine nicht-enthalten-Artikel)
    $a = ($this->la)('Sahne mit Milch 1', 'enthalten');
    $b = ($this->la)('Sahne mit Milch 2', 'enthalten');
    $c = ($this->la)('Sahne laktosefrei', 'nicht_enthalten');
    $d = ($this->la)('Sahne ohne Daten', false);
    DB::table('foodalchemist_supplier_item_structures')->whereIn('supplier_item_id', [$a->id, $b->id, $c->id, $d->id])->update(['gp_id' => $this->gp->id]);

    $details = app(GpHomogenitaetService::class)->bericht()['details'];

    expect($details)->toHaveCount(1);
    $z = $details[0];
    expect($z['gp_id'])->toBe($this->gp->id)
        ->and($z['merkmal'])->toBe('Allergen „Milch“')
        ->and([$z['artikel'], $z['mit'], $z['ohne'], $z['unbekannt']])->toBe([4, 2, 1, 1])
        ->and($z['minderheit'])->toBe('ohne')
        ->and($z['abweichende_artikel'])->toHaveCount(1)
        ->and($z['abweichende_artikel'][0])->toContain('Sahne laktosefrei')
        ->and($z['vorschlag'])->toBe('neues GP: Sahne — ohne Milch')
        ->and($z['konfidenz'])->toContain('LOW');
});

it('Befehl schreibt mit --detail-csv die Detail-Zeilen und ändert keine Verknüpfung', function () {
    $bestand = [($this->la)('B1', 'enthalten'), ($this->la)('B2', 'nicht_enthalten')];
    DB::table('foodalchemist_supplier_item_structures')->whereIn('supplier_item_id', collect($bestand)->pluck('id'))->update(['gp_id' => $this->gp->id]);
    $vorher = DB::table('foodalchemist_supplier_item_structures')->orderBy('id')->get(['id', 'gp_id'])->toArray();
    $datei = sys_get_temp_dir() . '/gp_homogenitaet_detail_' . uniqid() . '.csv';

    $this->artisan('foodalchemist:gp-homogenitaet-bericht', ['--detail-csv' => $datei])->expectsOutputToContain('Detail-CSV')->assertSuccessful();

    $zeilen = array_map(fn ($l) => str_getcsv($l, ';'), file($datei, FILE_IGNORE_NEW_LINES));
    @unlink($datei);
    expect($zeilen[0])->toContain('abweichende_artikel')->toContain('vorschlag')
        ->and($zeilen)->toHaveCount(2)
        ->and($zeilen[1][4])->toBe('Allergen „Milch“')
        ->and(DB::table('foodalchemist_supplier_item_structures')->orderBy('id')->get(['id', 'gp_id'])->toArray())->toEqual($vorher);
});
