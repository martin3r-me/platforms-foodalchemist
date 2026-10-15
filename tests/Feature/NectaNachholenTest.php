<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Enums\SignalTyp;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\Necta\NectaApiQuelle;
use Platform\FoodAlchemist\Services\Necta\NectaMapper;
use Platform\FoodAlchemist\Services\Necta\NectaNachholService;
use Platform\FoodAlchemist\Services\Necta\NectaQuelle;
use Platform\FoodAlchemist\Support\StammPaket;
use Platform\FoodAlchemist\Tests\Support\NectaAttrappe;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Necta-Abgleich · Einmal-Nachholer. Necta wird durch eine Attrappe in der echten Raw-API-Antwortform ersetzt
 * ({items,totalCount,pageNumber,pageSize,totalPages}, Felder aus der Stichprobe vom 15.10.2026).
 *
 * Bestand: Lieferant 19491 mit Artikel 36674788 (in FA vorhanden). Necta liefert zusätzlich Lieferant 25345 (neu), bei 19491
 * den neuen Artikel 36674789 und bei 25345 den neuen Artikel 50000001, dazu Details und Preise.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    config(['foodalchemist.master_team_id' => $this->rootTeam->id]);

    $this->lieferant = FoodAlchemistSupplier::create(['team_id' => null, 'legacy_id' => 19491, 'name' => 'Bäckerei Bestand', 'stamm_class' => StammPaket::PAKET]);
    $this->bestand = FoodAlchemistSupplierItem::create(['team_id' => null, 'legacy_id' => 36674788, 'supplier_id' => $this->lieferant->id,
        'designation' => 'Von Hand gepflegt', 'stamm_class' => StammPaket::PAKET]);

    $preis = fn (int $id, int $artikel, float $p) => ['id' => $id, 'supplierItemId' => $artikel, 'tenantId' => 1494, 'status' => '0', 'price' => $p,
        'pricePartial' => $p, 'validTo' => null, 'statusValidFrom' => '2026-10-15T00:00:00', 'isBlocked' => false, 'note' => null,
        'changeDate' => '2026-10-15T12:21:15', 'creationDate' => '2026-10-15T12:21:15'];
    $this->attrappe = new NectaAttrappe([
        'suppliers' => [
            ['id' => 19491, 'name' => 'Bäckerei Necta-Name', 'isInactive' => false],
            ['id' => 25345, 'name' => 'edelrausch® Handels- und Service GmbH', 'branch' => 'Wein', 'postalCode' => '01099', 'city' => 'Dresden',
                'address' => 'Bautzner Straße 21', 'emailOrder' => 'info@edelrausch.de', 'isInactive' => false],
        ],
        'supplier-items' => [
            NectaAttrappe::artikel(36674788, 19491, 'Schoko Splitterbrötchen (Necta neu)'),
            NectaAttrappe::artikel(36674789, 19491, 'Laugenbrezel'),
            NectaAttrappe::artikel(50000001, 25345, 'Riesling trocken', ['unitId' => 1004, 'originCountryId' => 99, 'isOrganic' => false]),
        ],
        'supplier-item-allergens' => [
            ['supplierItemId' => 36674789, 'gluten' => 3, 'crustaceans' => 1, 'egg' => 2, 'fish' => 0, 'peanut' => 1, 'soy' => 1, 'milk' => 3,
                'nuts' => 1, 'celery' => 1, 'mustard' => 1, 'sesame' => 2, 'sulfites' => 1, 'lupin' => 0, 'molluscs' => 1,
                'allergeneWheat' => 3, 'allergeneRye' => 1, 'allergeneOats' => 0],
        ],
        'supplier-item-declaration-substances' => [
            // Feldnamen wie die Raw-API sie liefert (Nachholer-Lauf 3 auf foodalchemist.io, 15.10.2026)
            ['supplierItemId' => 36674789, 'declarationWithDye' => 3, 'declarationWithPreservative' => 1, 'declarationCaffeinated' => 0,
                'declarationBExcessiveConsumptionCanHaveLaxativeEffect' => 1, 'declarationTableSweetenerBasedOn' => 0,
                'declarationIodhTlig' => 1, 'declarationReserve' => 0, 'isGanzNeuesFeld' => 3],
        ],
        'supplier-item-nutritional-values' => [
            ['supplierItemId' => 50000001, 'energyKilocalories' => 72, 'energyKilojoules' => 301, 'protein' => 0.1, 'fat' => 0,
                'saturatedFattyAcids' => 0, 'totalSugar' => 0.6, 'sodium' => null, 'totalTableSalt' => 0.18, 'omega3FatAcids' => 0.01],
        ],
        'supplier-item-prices' => [
            $preis(138432829, 36674789, 0.99),
            $preis(138432830, 50000001, 7.85),
            $preis(138432831, 36674788, 1.10),   // Preis eines BESTEHENDEN Artikels: gehört in den Abgleich (PR 2), nicht in den Nachholer
        ],
    ], [36674788 => 19491, 36674789 => 19491, 50000001 => 25345]);
    $this->app->instance(NectaQuelle::class, $this->attrappe);
    $this->dienst = app(NectaNachholService::class);
    $this->zaehlen = fn () => [
        'lieferanten' => DB::table('foodalchemist_suppliers')->count(), 'artikel' => DB::table('foodalchemist_supplier_items')->count(),
        'allergene' => DB::table('foodalchemist_item_allergens')->count(), 'deklaration' => DB::table('foodalchemist_item_declarations')->count(),
        'naehrwerte' => DB::table('foodalchemist_item_nutritionals')->count(), 'preise' => DB::table('foodalchemist_prices')->count(),
        'stand' => DB::table('foodalchemist_necta_stand')->count(), 'signale' => DB::table('foodalchemist_signals')->count(),
    ];
});

it('Probelauf liest alles, schreibt keine Stammdaten und nennt Zahlen und Beispiele', function () {
    $vorher = ($this->zaehlen)();
    $r = $this->dienst->lauf(1, ['probe' => true, 'pause_ms' => 0]);

    expect(($this->zaehlen)())->toBe($vorher)
        ->and($r['zaehler'])->toMatchArray(['lieferanten_necta' => 2, 'lieferanten_neu' => 1, 'artikel_geprueft' => 3, 'artikel_neu' => 2,
            'allergene_neu' => 1, 'deklaration_neu' => 1, 'naehrwerte_neu' => 1, 'preise_neu' => 2])
        ->and($r['zaehler']['neue_lieferanten'][0]['name'])->toBe('edelrausch® Handels- und Service GmbH')
        ->and(collect($r['zaehler']['neue_artikel_je_lieferant'])->pluck('neu', 'legacy_id')->all())->toBe([19491 => 1, 25345 => 1]);

    $lauf = DB::table('foodalchemist_necta_laeufe')->find($r['lauf_id']);
    $beispiele = json_decode($lauf->beispiele, true);
    expect((bool) $lauf->probe)->toBeTrue()->and($lauf->status)->toBe('fertig')->and($lauf->art)->toBe('nachholen')
        ->and($beispiele['artikel'][0]['designation'])->toBe('Laugenbrezel')
        ->and($beispiele['preise'])->toHaveCount(2);
});

it('echter Lauf: legt Fehlendes global an, nach den Konventionen des Bestands; Bestehendes bleibt unberührt', function () {
    $r = $this->dienst->lauf(1, ['pause_ms' => 0]);

    $neu = FoodAlchemistSupplier::where('legacy_id', 25345)->sole();
    $brezel = FoodAlchemistSupplierItem::where('legacy_id', 36674789)->sole();
    $wein = FoodAlchemistSupplierItem::where('legacy_id', 50000001)->sole();
    expect($neu->team_id)->toBeNull()->and($neu->stamm_class)->toBe(StammPaket::PAKET)->and($neu->city)->toBe('Dresden')
        ->and($brezel->team_id)->toBeNull()->and($brezel->stamm_class)->toBe(StammPaket::PAKET)
        ->and((int) $brezel->supplier_id)->toBe($this->lieferant->id)->and((int) $wein->supplier_id)->toBe($neu->id)
        ->and($brezel->unit_code)->toBe('Stk')->and($wein->unit_code)->toBe('l')
        ->and($brezel->origin_country)->toBe('Deutschland')->and($wein->origin_country)->toBe('TODO_country_99')   // Bestandskonvention
        ->and($brezel->article_number)->toBe(' 99')                                                               // nicht getrimmt (Match-Schlüssel)
        ->and($brezel->ean_ordering)->toBe('4001234567890')->and((bool) $brezel->is_organic)->toBeTrue()
        // Bestand unberührt: der Nachholer überschreibt keine vorhandenen Artikel
        ->and($this->bestand->fresh()->designation)->toBe('Von Hand gepflegt')
        // ohne GP, kein Zuordnungs-Lauf
        ->and(DB::table('foodalchemist_supplier_item_structures')->count())->toBe(0);

    $al = DB::table('foodalchemist_item_allergens')->where('legacy_id', 36674789)->sole();
    expect($al->allergen_gluten)->toBe('enthalten')->and($al->allergen_eggs)->toBe('spuren')->and($al->allergen_crustaceans)->toBe('nicht_enthalten')
        ->and($al->allergen_fish)->toBeNull()->and($al->allergen_tree_nuts)->toBe('nicht_enthalten')
        ->and(json_decode($al->details, true))->toBe(['rye' => 'nicht_enthalten', 'wheat' => 'enthalten'])
        ->and($al->team_id)->toBeNull()->and((int) $al->supplier_item_id)->toBe($brezel->id);

    $nw = DB::table('foodalchemist_item_nutritionals')->where('legacy_id', 50000001)->sole();
    expect((float) $nw->energy_kcal)->toBe(72.0)->and((float) $nw->sodium)->toBe(72.0)                     // Salz 0,18 g × 400 (Skript 214)
        ->and((float) $nw->sugar)->toBe(0.6)->and((float) $nw->saturated_fat)->toBe(0.0)
        ->and(json_decode($nw->raw_json, true))->toBe(['omega3FatAcids' => 0.01, 'totalTableSalt' => 0.18]);

    $dk = DB::table('foodalchemist_item_declarations')->where('legacy_id', 36674789)->sole();
    expect((int) $dk->with_dye)->toBe(3)->and((int) $dk->with_preservative)->toBe(1)->and((int) $dk->caffeinated)->toBe(0)
        ->and((int) $dk->excessive_consumption_laxative)->toBe(1)->and($dk->with_sweetener)->toBeNull()
        // wie Bestand: Süßungsbasis in details, Jod/Reserve hat der Alt-Import nie übernommen
        ->and(json_decode($dk->details, true))->toBe(['is_ganz_neues_feld' => 3, 'table_sweetener_basis' => 0])
        ->and($r['zaehler']['deklaration_unbekannte_felder'])->toBe(['isGanzNeuesFeld']);

    $preis = DB::table('foodalchemist_prices')->where('legacy_id', 138432829)->sole();
    expect((int) $preis->team_id)->toBe($this->rootTeam->id)->and((float) $preis->price)->toBe(0.99)
        ->and($preis->change_date)->toBe('2026-10-15 10:21:15')                                           // Europe/Berlin → UTC wie Bestand
        ->and(DB::table('foodalchemist_prices')->where('legacy_id', 138432831)->exists())->toBeFalse();   // Bestandsartikel: nicht im Nachholer

    expect(DB::table('foodalchemist_necta_stand')->where('objekt', 'supplier_item')->where('legacy_id', 36674789)->exists())->toBeTrue()
        ->and(DB::table('foodalchemist_necta_stand')->where('objekt', 'price')->count())->toBe(2);

    $signal = DB::table('foodalchemist_signals')->where('type', SignalTyp::NectaLieferantNeu->value)->sole();
    expect((int) $signal->team_id)->toBe($this->rootTeam->id)->and($signal->title)->toContain('edelrausch')->toContain('freischalten')
        ->and((int) $signal->ref_id)->toBe($neu->id)
        ->and(json_decode($signal->payload, true)['sprung'])->toBe('/verwaltung?tab=freigaben');
});

it('zweiter Lauf legt nichts doppelt an (idempotent, auch das Signal)', function () {
    $this->dienst->lauf(1, ['pause_ms' => 0]);
    $nachEins = ($this->zaehlen)();
    $r = app(NectaNachholService::class)->lauf(1, ['pause_ms' => 0]);

    expect(($this->zaehlen)())->toBe($nachEins)->and($r['zaehler']['artikel_neu'])->toBe(0)->and($r['zaehler']['lieferanten_neu'])->toBe(0);
});

it('Wiederaufnahme: --fortsetzen macht beim nächsten Lieferanten weiter', function () {
    $lauf = DB::table('foodalchemist_necta_laeufe')->insertGetId(['uuid' => (string) Symfony\Component\Uid\UuidV7::generate(), 'verbindung_id' => 1,
        'art' => 'nachholen', 'probe' => false, 'status' => 'fehler', 'cursor' => json_encode(['lieferant_index' => 1]),
        'created_at' => now(), 'updated_at' => now()]);
    $r = $this->dienst->lauf(1, ['pause_ms' => 0, 'fortsetzen' => $lauf]);

    // Index 1 = Lieferant 19491 (sortiert nach Necta-ID) gilt als erledigt → nur der Wein von 25345 kommt
    expect($r['lauf_id'])->toBe($lauf)->and($r['zaehler']['artikel_neu'])->toBe(1)
        ->and(FoodAlchemistSupplierItem::where('legacy_id', 50000001)->exists())->toBeTrue()
        ->and(FoodAlchemistSupplierItem::where('legacy_id', 36674789)->exists())->toBeFalse()
        ->and(DB::table('foodalchemist_necta_laeufe')->find($lauf)->status)->toBe('fertig');
});

it('Details gezielt je Artikel und seitenweise je Lieferant führen zum selben Ergebnis', function () {
    $this->dienst->lauf(1, ['pause_ms' => 0, 'detail_schwelle' => 0, 'seite' => 1]);   // erzwingt den Weg „je Lieferant“, Seitengröße 1

    expect(DB::table('foodalchemist_item_allergens')->where('legacy_id', 36674789)->value('allergen_gluten'))->toBe('enthalten')
        ->and(DB::table('foodalchemist_prices')->whereIn('legacy_id', [138432829, 138432830])->count())->toBe(2)
        ->and(DB::table('foodalchemist_prices')->where('legacy_id', 138432831)->exists())->toBeFalse()
        ->and($this->attrappe->aufrufe['supplier-items'])->toBeGreaterThan(2);                      // Seitengröße 1 → mehrere Seiten gelesen
});

it('Befehl: --probe läuft durch und meldet PROBELAUF', function () {
    $this->artisan('foodalchemist:necta-nachholen', ['--probe' => true, '--pause-ms' => 0])
        ->expectsOutputToContain('PROBELAUF')->expectsOutputToContain('edelrausch')->assertExitCode(0);
    expect(DB::table('foodalchemist_supplier_items')->count())->toBe(1);
});

it('Mapper: Allergen-Kodierung, Zeitzone und Raw-API-Antwortform', function () {
    expect(NectaMapper::allergenWert(3))->toBe('enthalten')->and(NectaMapper::allergenWert(1))->toBe('nicht_enthalten')
        ->and(NectaMapper::allergenWert(0))->toBeNull()->and(NectaMapper::allergenWert(null))->toBeNull()
        ->and(NectaMapper::zeit('2026-01-15T12:00:00'))->toBe('2026-01-15 11:00:00')                         // Winterzeit
        ->and(NectaApiQuelle::normalisiere(['Items' => [['id' => 1]], 'TotalCount' => 1, 'TotalPages' => 1], 1, 50)['items'])->toBe([['id' => 1]]);
});

it('Ausnahmen: bei uns inaktiver Lieferant und --ohne-lieferant werden übersprungen und im Protokoll genannt', function () {
    // auslaufender Kanal (wie Handelshof 16555): bei uns stillgelegt → kein neuer Artikel, keine Details, keine Preise
    $this->lieferant->update(['is_inactive' => true]);
    $r = $this->dienst->lauf(1, ['pause_ms' => 0, 'ohne_lieferanten' => [25345]]);

    expect(collect($r['zaehler']['uebersprungen'])->pluck('grund', 'legacy_id')->all())
        ->toBe([19491 => 'bei uns inaktiv', 25345 => 'ausgenommen (--ohne-lieferant)'])
        ->and($r['zaehler']['artikel_neu'])->toBe(0)->and($r['zaehler']['lieferanten_neu'])->toBe(0)
        ->and(FoodAlchemistSupplierItem::where('legacy_id', 36674789)->exists())->toBeFalse()
        ->and(FoodAlchemistSupplier::where('legacy_id', 25345)->exists())->toBeFalse()          // ausgenommen: auch nicht angelegt
        ->and(DB::table('foodalchemist_signals')->count())->toBe(0)
        ->and($this->attrappe->aufrufe['supplier-items'] ?? 0)->toBe(0);                        // nicht einmal gelesen
});

it('Befehl: --ohne-lieferant und inaktive Lieferanten stehen in der Probe-Ausgabe', function () {
    $this->lieferant->update(['is_inactive' => true]);
    $this->artisan('foodalchemist:necta-nachholen', ['--probe' => true, '--pause-ms' => 0, '--ohne-lieferant' => [25345]])
        ->expectsOutputToContain('übersprungen: Bäckerei Bestand (Necta 19491) — bei uns inaktiv')
        ->expectsOutputToContain('(Necta 25345) — ausgenommen (--ohne-lieferant)')
        ->assertExitCode(0);
});
