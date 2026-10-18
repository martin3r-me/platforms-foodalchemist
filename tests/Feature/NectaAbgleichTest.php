<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Enums\SignalTyp;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\Necta\NectaAbgleichService;
use Platform\FoodAlchemist\Services\Necta\NectaQuelle;
use Platform\FoodAlchemist\Support\StammPaket;
use Platform\FoodAlchemist\Tests\Support\NectaAttrappe;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Necta-Abgleich PR 2: nachts Preis-Delta + neue Artikel, wöchentlich (--voll) Vollvergleich mit Übernahme bzw. Prüffällen.
 *
 * Bestand: Lieferant 19491 mit Artikel A (36674788, Preis 900 zu 0,99 €, Allergene: Gluten enthalten) und Artikel B (36674790,
 * den Necta nicht mehr liefert). Lieferant 16555 ist bei uns inaktiv (auslaufender Kanal).
 * Necta: A mit geänderter Bezeichnung + EAN, Preis 900 jetzt 1,05 €, neue Preiszeile 901 (1,10 € ab 15.10.), neuer Artikel C
 * (36674799, nur über das Delta bekannt) mit Preis 902; Allergene A: zusätzlich Milch enthalten.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    config(['foodalchemist.master_team_id' => $this->rootTeam->id]);

    $this->lieferant = FoodAlchemistSupplier::create(['team_id' => null, 'legacy_id' => 19491, 'name' => 'Bäckerei', 'stamm_class' => StammPaket::PAKET]);
    $this->alt = FoodAlchemistSupplier::create(['team_id' => null, 'legacy_id' => 16555, 'name' => 'Handelshof alt', 'is_inactive' => true, 'stamm_class' => StammPaket::PAKET]);
    $this->a = FoodAlchemistSupplierItem::create(['team_id' => null, 'legacy_id' => 36674788, 'supplier_id' => $this->lieferant->id,
        'designation' => 'Schoko Splitterbrötchen', 'article_number' => ' 99', 'unit_code' => 'Stk', 'stamm_class' => StammPaket::PAKET]);
    $this->b = FoodAlchemistSupplierItem::create(['team_id' => null, 'legacy_id' => 36674790, 'supplier_id' => $this->lieferant->id,
        'designation' => 'Rosinenschnecke', 'stamm_class' => StammPaket::PAKET]);
    $this->preis = DB::table('foodalchemist_prices')->insertGetId(['uuid' => (string) Symfony\Component\Uid\UuidV7::generate(), 'team_id' => $this->rootTeam->id,
        'legacy_id' => 900, 'supplier_item_id' => $this->a->id, 'status' => '0', 'price' => 0.99, 'price_partial' => 0.99,
        'status_valid_from' => '2026-09-30 22:00:00', 'is_blocked' => false, 'change_date' => '2026-10-01 08:00:00', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('foodalchemist_item_allergens')->insert(['uuid' => (string) Symfony\Component\Uid\UuidV7::generate(), 'team_id' => null,
        'legacy_id' => 36674788, 'supplier_item_id' => $this->a->id, 'allergen_gluten' => 'enthalten', 'created_at' => now(), 'updated_at' => now()]);

    $this->attrappe = new NectaAttrappe([
        'suppliers' => [['id' => 19491, 'name' => 'Bäckerei'], ['id' => 16555, 'name' => 'Handelshof alt']],
        'supplier-items' => [
            NectaAttrappe::artikel(36674788, 19491, 'Schoko-Splitterbrötchen 80 g', ['brand' => 'Hausbäcker']),
            NectaAttrappe::artikel(36674799, 19491, 'Dinkelstange'),
            NectaAttrappe::artikel(55500001, 16555, 'Handelshof-Artikel'),
        ],
        'supplier-item-prices' => [
            NectaAttrappe::preis(900, 36674788, 1.05, ['statusValidFrom' => '2026-10-01T00:00:00']),
            NectaAttrappe::preis(901, 36674788, 1.10),
            NectaAttrappe::preis(902, 36674799, 2.40),
            NectaAttrappe::preis(990, 55500001, 9.99),   // inaktiver Lieferant: darf nicht ankommen
        ],
        'supplier-item-allergens' => [
            ['supplierItemId' => 36674788, 'gluten' => 3, 'milk' => 3],
            ['supplierItemId' => 36674799, 'gluten' => 3],
        ],
        'supplier-item-nutritional-values' => [],
        'supplier-item-declaration-substances' => [],
    ], [36674788 => 19491, 36674799 => 19491, 55500001 => 16555]);
    $this->app->instance(NectaQuelle::class, $this->attrappe);
    $this->lauf = fn (array $opt = []) => app(NectaAbgleichService::class)->lauf(1, $opt + ['pause_ms' => 0]);
});

it('nachts: Preis geändert → History + Update, neue Preiszeile mit fortgeschriebenem Vorgänger, neuer Artikel aus dem Delta', function () {
    $r = ($this->lauf)();

    $p900 = DB::table('foodalchemist_prices')->where('legacy_id', 900)->sole();
    $p901 = DB::table('foodalchemist_prices')->where('legacy_id', 901)->sole();
    $hist = DB::table('foodalchemist_price_history')->where('legacy_id', 900)->orderBy('id')->get();
    expect((float) $p900->price)->toBe(1.05)
        ->and((float) $hist[0]->price)->toBe(0.99)                                       // alter Stand vor dem Update
        ->and((float) $p901->price)->toBe(1.1)->and((int) $p901->team_id)->toBe($this->rootTeam->id)
        ->and($p900->valid_to)->toBe('2026-10-14 22:00:00')                            // Vorgänger endet mit dem neuen statusValidFrom (UTC)
        ->and($hist)->toHaveCount(2)                                                     // Update + Fortschreibung, jeweils der alte Stand
        ->and(DB::table('foodalchemist_prices')->count())->toBe(3);                     // nie gelöscht

    $c = FoodAlchemistSupplierItem::where('legacy_id', 36674799)->sole();
    expect($c->team_id)->toBeNull()->and($c->stamm_class)->toBe(StammPaket::PAKET)
        ->and(DB::table('foodalchemist_prices')->where('legacy_id', 902)->value('supplier_item_id'))->toBe($c->id)
        ->and(DB::table('foodalchemist_item_allergens')->where('legacy_id', 36674799)->value('allergen_gluten'))->toBe('enthalten');

    // nachts kein Stammdaten-Vergleich; inaktiver Lieferant gar nicht angefasst
    expect($this->a->fresh()->designation)->toBe('Schoko Splitterbrötchen')
        ->and(DB::table('foodalchemist_prices')->where('legacy_id', 990)->exists())->toBeFalse()
        ->and(FoodAlchemistSupplierItem::where('legacy_id', 55500001)->exists())->toBeFalse()
        ->and(collect($r['zaehler']['uebersprungen'])->pluck('legacy_id')->all())->toBe([16555])
        ->and($r['zaehler'])->toMatchArray(['preise_aktualisiert' => 1, 'preise_neu' => 2, 'vorgaenger_fortgeschrieben' => 1, 'artikel_neu' => 1])
        ->and(DB::table('foodalchemist_necta_laeufe')->find($r['lauf_id'])->art)->toBe('abgleich');
});

it('changeDateFrom: ohne Vorlauf 2 Tage zurück, danach Start des letzten erfolgreichen Laufs − 1 Tag (ISO ohne Zone)', function () {
    $this->travelTo(now()->setDate(2026, 10, 16)->setTime(2, 30));
    ($this->lauf)();
    $erster = collect($this->attrappe->filter)->firstWhere(0, 'supplier-item-prices')[1]['changeDateFrom'];
    expect($erster)->toBe('2026-10-14T02:30:00');

    $this->travelTo(now()->setDate(2026, 10, 17)->setTime(2, 30));
    $this->attrappe->filter = [];
    ($this->lauf)();
    expect(collect($this->attrappe->filter)->firstWhere(0, 'supplier-item-prices')[1]['changeDateFrom'])->toBe('2026-10-15T02:30:00');
});

it('wöchentlich, erster Abgleich: Nicht-Kennzeichnung übernehmen, strengeres Allergen automatisch, in Necta entfernt → Prüffall (nicht löschen)', function () {
    ($this->lauf)();                                                  // Voraussetzung: ein erfolgreicher nächtlicher Lauf
    $r = ($this->lauf)(['voll' => true]);

    $a = $this->a->fresh();
    expect($a->designation)->toBe('Schoko-Splitterbrötchen 80 g')->and($a->brand)->toBe('Hausbäcker')->and($a->ean_ordering)->toBe('4001234567890')
        // Milch unbekannt → enthalten ist strenger: automatisch (Entscheid Dominique 15.10.), kein Prüffall
        ->and(DB::table('foodalchemist_item_allergens')->where('legacy_id', 36674788)->value('allergen_milk'))->toBe('enthalten')
        ->and($r['zaehler']['allergen_verschaerft'])->toBe(1)
        ->and(FoodAlchemistSupplierItem::where('legacy_id', 36674790)->exists())->toBeTrue()                                   // B nicht gelöscht
        ->and(DB::table('foodalchemist_necta_pruefaelle')->where('art', 'in_necta_entfernt')->value('legacy_id'))->toBe(36674790)
        ->and(DB::table('foodalchemist_necta_stand')->where('objekt', 'supplier_item')->where('legacy_id', 36674788)->exists())->toBeTrue()
        ->and(collect($r['zaehler']['pruefaelle'])->sortKeys()->all())->toBe(['in_necta_entfernt' => 1])
        ->and(DB::table('foodalchemist_necta_laeufe')->find($r['lauf_id'])->art)->toBe('vollabgleich');
});

it('wöchentlich: Deklaration wie der Bestand gemappt → keine Abweichung; geänderter Stoff → Prüffall statt Übernahme', function () {
    // Bestandszeile so, wie ImportSliceCommand sie aus der WaWi-Kopie gebaut hat (rohe Integer, Süßungsbasis in details)
    DB::table('foodalchemist_item_declarations')->insert(['uuid' => (string) Symfony\Component\Uid\UuidV7::generate(), 'team_id' => null,
        'legacy_id' => 36674788, 'supplier_item_id' => $this->a->id, 'with_dye' => 1, 'with_preservative' => 3, 'with_sweetener' => 3,
        'excessive_consumption_laxative' => 0, 'iodized' => 1,   // Jod: Stand nach necta-deklaration-nachfuellen
        'details' => json_encode(['table_sweetener_basis' => 0]), 'created_at' => now(), 'updated_at' => now()]);
    $this->attrappe->daten['supplier-item-declaration-substances'] = [
        ['supplierItemId' => 36674788, 'declarationWithDye' => 1, 'declarationWithPreservative' => 1, 'declarationWithSweetener' => 3,
            'declarationBExcessiveConsumptionCanHaveLaxativeEffect' => 0, 'declarationTableSweetenerBasedOn' => 0,
            'declarationIodhTlig' => 1, 'declarationReserve' => 0],
    ];
    ($this->lauf)();
    ($this->lauf)(['voll' => true]);

    $pf = DB::table('foodalchemist_necta_pruefaelle')->where('art', 'deklaration_aenderung')->get();
    expect($pf->pluck('feld')->all())->toBe(['with_preservative'])            // nur der echte Unterschied, nicht details/Reserve
        ->and((int) DB::table('foodalchemist_item_declarations')->where('legacy_id', 36674788)->value('with_preservative'))->toBe(3);   // ja → nein: Lockerung
});

it('wöchentlich, ab Lauf 2: von Hand gepflegtes Feld → Prüffall statt Überschreiben; unveränderte FA-Felder folgen Necta', function () {
    ($this->lauf)();
    ($this->lauf)(['voll' => true]);                                 // Vollvergleich 1 setzt den Necta-Stand
    $this->a->update(['designation' => 'Von Hand: Schokobrötchen']);  // Handpflege
    $this->attrappe->daten['supplier-items'][0]['designation'] = 'Schoko-Splitterbrötchen 90 g';   // Necta ändert dasselbe Feld
    $this->attrappe->daten['supplier-items'][0]['brand'] = 'Hausbäcker Plus';                      // und ein unberührtes Feld

    $this->travel(1)->minutes();
    ($this->lauf)();                                                  // nächtlicher Lauf zwischen zwei Vollvergleichen
    $this->travel(1)->minutes();
    ($this->lauf)(['voll' => true]);
    $a = $this->a->fresh();
    $pf = DB::table('foodalchemist_necta_pruefaelle')->where('art', 'manuell_gepflegt')->sole();
    expect($a->designation)->toBe('Von Hand: Schokobrötchen')->and($a->brand)->toBe('Hausbäcker Plus')
        ->and($pf->feld)->toBe('designation')->and($pf->fa_wert)->toBe('Von Hand: Schokobrötchen')->and($pf->necta_wert)->toBe('Schoko-Splitterbrötchen 90 g')
        // offener Prüffall wird beim nächsten Lauf aktualisiert, nicht verdoppelt
        ->and(DB::table('foodalchemist_necta_pruefaelle')->where('art', 'manuell_gepflegt')->count())->toBe(1)
        ->and(DB::table('foodalchemist_necta_pruefaelle')->where('art', 'allergen_aenderung')->count())->toBe(0);
});

it('Probe --voll schreibt nichts und endet mit den Prüffällen je Art', function () {
    $vorher = [DB::table('foodalchemist_prices')->count(), DB::table('foodalchemist_supplier_items')->count(), DB::table('foodalchemist_necta_pruefaelle')->count(),
        $this->a->fresh()->designation];
    $this->artisan('foodalchemist:necta-abgleich', ['--probe' => true, '--voll' => true, '--pause-ms' => 0])
        ->expectsOutputToContain('PROBELAUF')
        ->expectsOutputToContain('Prüffälle je Art: in_necta_entfernt 1')
        ->expectsOutputToContain('übersprungen: Handelshof alt (Necta 16555) — bei uns inaktiv')
        ->assertExitCode(0);
    expect([DB::table('foodalchemist_prices')->count(), DB::table('foodalchemist_supplier_items')->count(), DB::table('foodalchemist_necta_pruefaelle')->count(),
        $this->a->fresh()->designation])->toBe($vorher)
        ->and(DB::table('foodalchemist_item_allergens')->where('legacy_id', 36674788)->value('allergen_milk'))->toBeNull();   // Probe verschärft nicht
});

// ── Strenge (Entscheid Dominique 15.10.2026): Verschärfung automatisch, Lockerung als Prüffall ─────────────────────────────

it('Allergene: strenger automatisch auch über Handpflege, lockerer immer Prüffall (auch wenn FA dem Necta-Stand entspricht)', function () {
    $allergen = fn (string $spalte) => DB::table('foodalchemist_item_allergens')->where('legacy_id', 36674788)->value($spalte);
    DB::table('foodalchemist_item_allergens')->where('legacy_id', 36674788)->update(['allergen_eggs' => 'nicht_enthalten', 'allergen_soy' => 'enthalten',
        'allergen_celery' => 'nicht_enthalten']);
    $this->attrappe->daten['supplier-item-allergens'][0] = ['supplierItemId' => 36674788, 'gluten' => 3, 'milk' => 3,
        'egg' => 3,        // nicht_enthalten → enthalten: strenger → automatisch
        'soy' => 2,        // enthalten → spuren: lockerer → Prüffall
        'celery' => null]; // nicht_enthalten → unbekannt: strenger (unbekannt > nicht_enthalten) → automatisch
    ($this->lauf)();
    ($this->lauf)(['voll' => true]);
    expect($allergen('allergen_eggs'))->toBe('enthalten')->and($allergen('allergen_celery'))->toBeNull()
        ->and($allergen('allergen_soy'))->toBe('enthalten')
        ->and(DB::table('foodalchemist_necta_pruefaelle')->where('art', 'allergen_aenderung')->pluck('feld')->all())->toBe(['allergen_soy']);

    // FA = letzter Necta-Stand (nicht von Hand gepflegt) und Necta lockert → trotzdem Prüffall, nie still lockern
    $this->attrappe->daten['supplier-item-allergens'][0]['egg'] = 1;   // enthalten → nicht_enthalten
    $this->travel(1)->minutes();
    ($this->lauf)();
    $this->travel(1)->minutes();
    $r = ($this->lauf)(['voll' => true]);
    expect($allergen('allergen_eggs'))->toBe('enthalten')
        ->and(DB::table('foodalchemist_necta_pruefaelle')->where('art', 'allergen_aenderung')->where('feld', 'allergen_eggs')->value('necta_wert'))->toBe('nicht_enthalten')
        ->and($r['zaehler']['allergen_verschaerft'])->toBe(0);

    // Handpflege lockerer als Necta (FA ≠ Stand) und Necta bleibt streng → Necta gewinnt automatisch
    DB::table('foodalchemist_item_allergens')->where('legacy_id', 36674788)->update(['allergen_gluten' => 'spuren']);
    $this->travel(1)->minutes();
    ($this->lauf)();
    $this->travel(1)->minutes();
    $r = ($this->lauf)(['voll' => true]);
    expect($allergen('allergen_gluten'))->toBe('enthalten')->and($r['zaehler']['allergen_verschaerft'])->toBe(1);
});

it('Deklaration: 3 (ja) > 0/NULL (k.A.) > 1 (nein) — nein → ja automatisch, ja → nein Prüffall, k.A. 0 ⇄ NULL angleichen', function () {
    DB::table('foodalchemist_item_declarations')->insert(['uuid' => (string) Symfony\Component\Uid\UuidV7::generate(), 'team_id' => null,
        'legacy_id' => 36674788, 'supplier_item_id' => $this->a->id, 'with_dye' => 1, 'with_preservative' => 3, 'with_sweetener' => null,
        'created_at' => now(), 'updated_at' => now()]);
    $this->attrappe->daten['supplier-item-declaration-substances'] = [['supplierItemId' => 36674788,
        'declarationWithDye' => 3, 'declarationWithPreservative' => 1, 'declarationWithSweetener' => 0]];
    ($this->lauf)();
    $r = ($this->lauf)(['voll' => true]);
    $dk = DB::table('foodalchemist_item_declarations')->where('legacy_id', 36674788)->sole();
    expect((int) $dk->with_dye)->toBe(3)->and((int) $dk->with_preservative)->toBe(3)->and((int) $dk->with_sweetener)->toBe(0)
        ->and(DB::table('foodalchemist_necta_pruefaelle')->where('art', 'deklaration_aenderung')->pluck('feld')->all())->toBe(['with_preservative'])
        ->and($r['zaehler']['deklaration_verschaerft'])->toBe(1);
});

it('Kunst-IDs ab 90.000.000 ohne Necta-Stand kamen nie aus Necta: nicht „entfernt“; mit Stand weiter gemeldet', function () {
    FoodAlchemistSupplierItem::create(['team_id' => null, 'legacy_id' => 90000003, 'supplier_id' => $this->lieferant->id,
        'designation' => 'Udon-Nudeln (HI TASTE)', 'stamm_class' => StammPaket::PAKET]);
    FoodAlchemistSupplierItem::create(['team_id' => null, 'legacy_id' => 90000077, 'supplier_id' => $this->lieferant->id,
        'designation' => 'Echter Necta-Artikel im hohen Bereich', 'stamm_class' => StammPaket::PAKET]);
    DB::table('foodalchemist_necta_stand')->insert(['objekt' => 'supplier_item', 'legacy_id' => 90000077, 'werte' => '{}',
        'created_at' => now(), 'updated_at' => now()]);
    ($this->lauf)();
    $r = ($this->lauf)(['voll' => true]);

    expect(DB::table('foodalchemist_necta_pruefaelle')->where('art', 'in_necta_entfernt')->orderBy('legacy_id')->pluck('legacy_id')->all())
        ->toBe([36674790, 90000077])
        ->and($r['zaehler']['nicht_aus_necta'])->toBe(1);
});

it('Fehlschlag: Lauf steht auf fehler und das Master-Team bekommt ein Signal (Probe nicht)', function () {
    $this->attrappe->fehler = new RuntimeException('Necta nicht erreichbar');
    expect(fn () => ($this->lauf)())->toThrow(RuntimeException::class, 'Necta nicht erreichbar');
    $lauf = DB::table('foodalchemist_necta_laeufe')->latest('id')->first();
    $signal = DB::table('foodalchemist_signals')->where('type', SignalTyp::NectaAbgleichFehler->value)->sole();
    expect($lauf->status)->toBe('fehler')->and($lauf->fehler)->toContain('nicht erreichbar')
        ->and((int) $signal->team_id)->toBe($this->rootTeam->id);

    expect(fn () => ($this->lauf)(['probe' => true]))->toThrow(RuntimeException::class);
    expect(DB::table('foodalchemist_signals')->where('type', SignalTyp::NectaAbgleichFehler->value)->count())->toBe(1);
});

it('Vollvergleich nur nach erfolgreichem nächtlichem Lauf seit dem letzten Vollvergleich; --erzwingen übergeht das', function () {
    $r = ($this->lauf)(['voll' => true]);
    expect($r['status'])->toBe('ausgesetzt')->and($r['zaehler']['grund'])->toBe('noch kein erfolgreicher nächtlicher Abgleich')
        ->and($this->a->fresh()->designation)->toBe('Schoko Splitterbrötchen')
        ->and(DB::table('foodalchemist_necta_laeufe')->find($r['lauf_id'])->status)->toBe('ausgesetzt');

    $this->artisan('foodalchemist:necta-abgleich', ['--voll' => true, '--pause-ms' => 0])
        ->expectsOutputToContain('Vollvergleich ausgesetzt')->assertExitCode(0);

    expect(($this->lauf)(['voll' => true, 'erzwingen' => true])['status'])->toBe('fertig')
        ->and($this->a->fresh()->designation)->toBe('Schoko-Splitterbrötchen 80 g');
    // direkt danach: seit diesem Vollvergleich noch kein Nachtlauf → wieder ausgesetzt
    $this->travel(1)->minutes();
    expect(($this->lauf)(['voll' => true])['zaehler']['grund'] ?? null)->toBe('seit dem letzten Vollvergleich kein erfolgreicher nächtlicher Abgleich');
});

it('Zeitplan: nächtlicher Lauf und Vollvergleich teilen EINE Sperre, laufen im Hintergrund und auf einem Server', function () {
    $schedule = new \Illuminate\Console\Scheduling\Schedule;
    \Platform\FoodAlchemist\Console\NectaAbgleichCommand::planen($schedule);
    [$nacht, $voll] = array_values(array_filter($schedule->events(), fn ($e) => str_contains((string) $e->command, 'necta-abgleich')));

    expect($nacht->mutexName())->toBe(\Platform\FoodAlchemist\Console\NectaAbgleichCommand::SPERRE)
        ->and($voll->mutexName())->toBe($nacht->mutexName())
        ->and($nacht->withoutOverlapping)->toBeTrue()->and($voll->withoutOverlapping)->toBeTrue()
        ->and($nacht->runInBackground)->toBeTrue()->and($voll->runInBackground)->toBeTrue()
        ->and($nacht->onOneServer)->toBeTrue()->and($voll->onOneServer)->toBeTrue()
        ->and($nacht->expression)->toBe('30 2 * * *')->and($voll->expression)->toBe('30 3 * * 0');
});

it('Zeitplan: die GERENDERTE Befehlszeile parst gegen die Optionen des Befehls (Flag --voll ohne Wert)', function () {
    // Lücke auf io (16.10.2026): ['--voll' => true] wurde zu --voll='1' → „The "--voll" option does not accept a value.“
    $schedule = new \Illuminate\Console\Scheduling\Schedule;
    \Platform\FoodAlchemist\Console\NectaAbgleichCommand::planen($schedule);
    $definition = \Illuminate\Support\Facades\Artisan::all()['foodalchemist:necta-abgleich']->getDefinition();
    $parsen = function ($event) use ($definition) {
        $zeile = (string) $event->command;
        $argumente = trim(substr($zeile, strpos($zeile, 'foodalchemist:necta-abgleich') + strlen('foodalchemist:necta-abgleich')));
        $eingabe = new \Symfony\Component\Console\Input\StringInput($argumente);
        $eingabe->bind($definition);   // wirft wie Artisan selbst, wenn eine Option nicht passt

        return $eingabe;
    };
    [$nacht, $voll] = array_values(array_filter($schedule->events(), fn ($e) => str_contains((string) $e->command, 'necta-abgleich')));

    expect($parsen($voll)->getOption('voll'))->toBeTrue()
        ->and($parsen($voll)->getOption('verbindung'))->toBe('1')
        ->and($parsen($nacht)->getOption('voll'))->toBeFalse()
        ->and($parsen($nacht)->getOption('verbindung'))->toBe('1');
});

it('Zeitplan: ohne foodalchemist.necta.abgleich_aktiv ist der Abgleich nicht eingeplant (Standard aus)', function () {
    $geplant = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
        ->filter(fn ($e) => str_contains((string) $e->command, 'necta-abgleich'));
    expect(config('foodalchemist.necta.abgleich_aktiv'))->toBeFalse()->and($geplant)->toHaveCount(0);
});

it('Allergen-Unterarten je Unterart nach Strenge: strenger automatisch, lockerer Prüffall details.<unterart>, gleich bleibt', function () {
    DB::table('foodalchemist_item_allergens')->where('legacy_id', 36674788)->update(['details' => json_encode([
        'wheat' => 'nicht_enthalten', 'oats' => 'enthalten', 'spelt' => 'spuren'])]);
    $this->attrappe->daten['supplier-item-allergens'][0] += [
        'allergeneWheat' => 3,   // nicht_enthalten → enthalten: strenger → automatisch
        'allergeneOats' => 1,    // enthalten → nicht_enthalten: lockerer → Prüffall
        'allergeneSpelt' => 2,   // gleich
        'allergeneRye' => 1];    // fehlt im FA (unbekannt) → nicht_enthalten: lockerer → Prüffall
    ($this->lauf)();
    $r = ($this->lauf)(['voll' => true]);

    $details = json_decode(DB::table('foodalchemist_item_allergens')->where('legacy_id', 36674788)->value('details'), true);
    expect($details)->toBe(['oats' => 'enthalten', 'rye' => 'nicht_enthalten', 'spelt' => 'spuren', 'wheat' => 'enthalten'])
        ->and(DB::table('foodalchemist_necta_pruefaelle')->where('art', 'allergen_aenderung')->orderBy('feld')->pluck('feld')->all())
        ->toBe(['details.oats'])
        ->and($r['zaehler']['unterart_verschaerft'])->toBe(1)
        ->and($r['zaehler']['allergen_abwesenheit_erklaert'])->toBe(1)                                   // rye: unbekannt → nicht_enthalten
        ->and($r['zaehler']['pruefaelle_je_feld']['allergen_aenderung'])->toBe(['details.oats' => 1])
        ->and($r['zaehler']['lockerung_je_wechsel'])->toBe(['allergen: enthalten→nicht_enthalten' => 1]);
});

it('Probe --voll zeigt Prüffälle je Feld und Lockerungen je Wechsel', function () {
    $this->attrappe->daten['supplier-item-allergens'][0]['gluten'] = 2;   // enthalten → spuren: echte Lockerung
    ($this->lauf)();
    $this->artisan('foodalchemist:necta-abgleich', ['--probe' => true, '--voll' => true, '--pause-ms' => 0])
        ->expectsOutputToContain('allergen_aenderung je Feld: allergen_gluten 1')
        ->expectsOutputToContain('Lockerungen je Wechsel: allergen: enthalten→spuren 1')
        ->assertExitCode(0);
});

// ── Abwesenheit erklärt (Entscheid Dominique 15.10.2026, Lauf 8: 36.057 × unbekannt → nicht_enthalten) ──────────────────

it('Abwesenheit erklärt: unbekannt → nicht_enthalten und k.A. → nein automatisch, wenn nicht von Hand gepflegt', function () {
    DB::table('foodalchemist_item_declarations')->insert(['uuid' => (string) Symfony\Component\Uid\UuidV7::generate(), 'team_id' => null,
        'legacy_id' => 36674788, 'supplier_item_id' => $this->a->id, 'with_dye' => 0, 'with_preservative' => null, 'waxed' => 3,
        'created_at' => now(), 'updated_at' => now()]);
    $this->attrappe->daten['supplier-item-allergens'][0] += ['soy' => 1, 'allergeneOats' => 1];
    $this->attrappe->daten['supplier-item-declaration-substances'] = [['supplierItemId' => 36674788,
        'declarationWithDye' => 1, 'declarationWithPreservative' => 1, 'declarationWaxed' => 1]];
    ($this->lauf)();
    $r = ($this->lauf)(['voll' => true]);

    $al = DB::table('foodalchemist_item_allergens')->where('legacy_id', 36674788)->sole();
    $dk = DB::table('foodalchemist_item_declarations')->where('legacy_id', 36674788)->sole();
    expect($al->allergen_soy)->toBe('nicht_enthalten')->and(json_decode($al->details, true))->toBe(['oats' => 'nicht_enthalten'])
        ->and((int) $dk->with_dye)->toBe(1)->and((int) $dk->with_preservative)->toBe(1)
        ->and((int) $dk->waxed)->toBe(3)                                                                 // ja → nein bleibt Prüffall
        ->and($r['zaehler']['allergen_abwesenheit_erklaert'])->toBe(2)->and($r['zaehler']['deklaration_abwesenheit_erklaert'])->toBe(2)
        ->and(DB::table('foodalchemist_necta_pruefaelle')->where('art', 'deklaration_aenderung')->pluck('feld')->all())->toBe(['waxed'])
        ->and(DB::table('foodalchemist_necta_pruefaelle')->where('art', 'allergen_aenderung')->count())->toBe(0)
        ->and(collect(DB::table('foodalchemist_necta_laeufe')->find($r['lauf_id'])->beispiele ? json_decode(DB::table('foodalchemist_necta_laeufe')->find($r['lauf_id'])->beispiele, true)['abwesenheit_erklaert'] : [])->count())->toBe(3);
});

it('Abwesenheit erklärt NICHT bei Handpflege auf „unbekannt“ und nie bei Weggang von enthalten/spuren', function () {
    $this->attrappe->daten['supplier-item-allergens'][0] += ['soy' => 2, 'allergeneOats' => 2];   // Lauf 1: soy/oats spuren (strenger, auto)
    ($this->lauf)();
    ($this->lauf)(['voll' => true]);
    // Handpflege: soy und oats von Hand auf „unbekannt“ (Stand sagt spuren)
    DB::table('foodalchemist_item_allergens')->where('legacy_id', 36674788)->update(['allergen_soy' => null, 'details' => null]);
    $this->attrappe->daten['supplier-item-allergens'][0]['soy'] = 1;
    $this->attrappe->daten['supplier-item-allergens'][0]['allergeneOats'] = 1;
    $this->attrappe->daten['supplier-item-allergens'][0]['gluten'] = 1;                            // enthalten → nicht_enthalten
    $this->travel(1)->minutes();
    ($this->lauf)();
    $this->travel(1)->minutes();
    $r = ($this->lauf)(['voll' => true]);

    $al = DB::table('foodalchemist_item_allergens')->where('legacy_id', 36674788)->sole();
    expect($al->allergen_soy)->toBeNull()->and($al->details)->toBeNull()->and($al->allergen_gluten)->toBe('enthalten')
        ->and($r['zaehler']['allergen_abwesenheit_erklaert'])->toBe(0)
        ->and(DB::table('foodalchemist_necta_pruefaelle')->where('art', 'allergen_aenderung')->orderBy('feld')->pluck('feld')->all())
        ->toBe(['allergen_gluten', 'allergen_soy', 'details.oats']);
});

// ── Absturz Vollauf 18.10. (1062 Duplicate entry auf foodalchemist_item_allergens.supplier_item_id) ──

it('1062: Allergenzeile ohne legacy_id (Handpflege) wird abgeglichen statt doppelt eingefügt', function () {
    DB::table('foodalchemist_item_allergens')->where('supplier_item_id', $this->a->id)->update(['legacy_id' => null, 'source' => 'manual']);
    ($this->lauf)();

    $r = ($this->lauf)(['voll' => true]);

    expect(DB::table('foodalchemist_necta_laeufe')->find($r['lauf_id'])->status)->not->toBe('fehler')
        ->and(DB::table('foodalchemist_item_allergens')->where('supplier_item_id', $this->a->id)->count())->toBe(1)
        ->and(DB::table('foodalchemist_item_allergens')->where('supplier_item_id', $this->a->id)->value('allergen_milk'))->toBe('enthalten')   // strenger: auto
        ->and(DB::table('foodalchemist_item_allergens')->where('supplier_item_id', $this->a->id)->value('legacy_id'))->toBe(36674788);
});

it('1062: gelöschte Allergenzeile, die Necta wieder liefert, wird mit den Necta-Werten neu belegt (keine zweite Zeile)', function () {
    DB::table('foodalchemist_item_allergens')->where('supplier_item_id', $this->a->id)->update(['deleted_at' => now(), 'allergen_gluten' => 'nicht_enthalten']);
    ($this->lauf)();

    $r = ($this->lauf)(['voll' => true]);

    $zeile = DB::table('foodalchemist_item_allergens')->where('supplier_item_id', $this->a->id)->sole();
    expect($zeile->deleted_at)->toBeNull()
        ->and($zeile->allergen_gluten)->toBe('enthalten')->and($zeile->allergen_milk)->toBe('enthalten')
        ->and($r['zaehler']['allergene_wiederbelebt'])->toBe(1)
        ->and(DB::table('foodalchemist_necta_stand')->where('objekt', 'item_allergen')->where('legacy_id', 36674788)->exists())->toBeTrue();
});

it('1062: Necta liefert denselben Artikel doppelt — im Vollabgleich und beim neuen Artikel je EINE Zeile, erster Datensatz gilt', function () {
    DB::table('foodalchemist_item_allergens')->where('supplier_item_id', $this->a->id)->delete();   // A ohne Zeile → Vollabgleich legt an
    $this->attrappe->daten['supplier-item-allergens'] = [
        ['supplierItemId' => 36674788, 'gluten' => 3],
        ['supplierItemId' => 36674788, 'gluten' => 3, 'milk' => 3],
        ['supplierItemId' => 36674799, 'gluten' => 3],
        ['supplierItemId' => 36674799, 'gluten' => 3],
    ];

    ($this->lauf)();                                                  // C ist neu: Detail-Anlage aus dem Delta
    $r = ($this->lauf)(['voll' => true]);

    $c = FoodAlchemistSupplierItem::where('legacy_id', 36674799)->sole();
    expect(DB::table('foodalchemist_necta_laeufe')->find($r['lauf_id'])->status)->not->toBe('fehler')
        ->and(DB::table('foodalchemist_item_allergens')->where('supplier_item_id', $this->a->id)->count())->toBe(1)
        ->and(DB::table('foodalchemist_item_allergens')->where('supplier_item_id', $c->id)->count())->toBe(1)
        ->and($r['zaehler']['detail_doppelt_in_necta'])->toBeGreaterThanOrEqual(1);
});

it('1062: dieselben Fälle auch bei Deklaration und Nährwerten (eine Zeile je Artikel, gelöschte/ohne legacy_id)', function () {
    $einfuegen = fn (string $t, array $w) => DB::table($t)->insert(['uuid' => (string) Symfony\Component\Uid\UuidV7::generate(), 'team_id' => null,
        'supplier_item_id' => $this->a->id, 'created_at' => now(), 'updated_at' => now()] + $w);
    $einfuegen('foodalchemist_item_nutritionals', ['legacy_id' => null, 'protein' => 5.0]);
    $einfuegen('foodalchemist_item_declarations', ['legacy_id' => 36674788, 'deleted_at' => now()]);
    $this->attrappe->daten['supplier-item-nutritional-values'] = [['supplierItemId' => 36674788, 'protein' => 6.0]];
    $this->attrappe->daten['supplier-item-declaration-substances'] = [['supplierItemId' => 36674788]];
    ($this->lauf)();

    $r = ($this->lauf)(['voll' => true]);

    expect(DB::table('foodalchemist_necta_laeufe')->find($r['lauf_id'])->status)->not->toBe('fehler')
        ->and(DB::table('foodalchemist_item_nutritionals')->where('supplier_item_id', $this->a->id)->count())->toBe(1)
        ->and(DB::table('foodalchemist_item_declarations')->where('supplier_item_id', $this->a->id)->count())->toBe(1)
        ->and(DB::table('foodalchemist_item_declarations')->where('supplier_item_id', $this->a->id)->value('deleted_at'))->toBeNull();
});

it('Handzeile ohne Necta-Stand (source manual / ohne legacy_id): Nährwert nicht überschrieben → manuell_gepflegt; Gegenprobe Necta-Zeile wird übernommen', function () {
    $einfuegen = fn (int $item, array $w) => DB::table('foodalchemist_item_nutritionals')->insert(['uuid' => (string) Symfony\Component\Uid\UuidV7::generate(),
        'team_id' => null, 'supplier_item_id' => $item, 'created_at' => now(), 'updated_at' => now()] + $w);
    $einfuegen($this->a->id, ['legacy_id' => null, 'source' => 'manual', 'protein' => 5.0]);
    $c = FoodAlchemistSupplierItem::create(['team_id' => null, 'legacy_id' => 36674799, 'supplier_id' => $this->lieferant->id,
        'designation' => 'Dinkelstange', 'stamm_class' => StammPaket::PAKET]);
    $einfuegen($c->id, ['legacy_id' => 36674799, 'protein' => 5.0]);   // Gegenprobe: aus Necta importiert, noch kein Stand
    $this->attrappe->daten['supplier-item-nutritional-values'] = [
        ['supplierItemId' => 36674788, 'protein' => 6.0],
        ['supplierItemId' => 36674799, 'protein' => 6.0],
    ];
    ($this->lauf)();

    ($this->lauf)(['voll' => true]);

    $protein = fn (int $item) => (float) DB::table('foodalchemist_item_nutritionals')->where('supplier_item_id', $item)->value('protein');
    expect($protein($this->a->id))->toBe(5.0)                                                        // Handwert bleibt
        ->and(DB::table('foodalchemist_necta_pruefaelle')->where('art', 'manuell_gepflegt')->where('objekt', 'item_nutritional')
            ->where('legacy_id', 36674788)->where('feld', 'protein')->exists())->toBeTrue()
        ->and($protein($c->id))->toBe(6.0)                                                           // Necta-Zeile: erster Abgleich übernimmt
        ->and(DB::table('foodalchemist_necta_pruefaelle')->where('art', 'manuell_gepflegt')->where('legacy_id', 36674799)->exists())->toBeFalse();
});

it('Handzeile ohne Necta-Stand, Allergene: strenger übernommen, lockerer und „Abwesenheit erklärt“ werden Prüffall', function () {
    DB::table('foodalchemist_item_allergens')->where('supplier_item_id', $this->a->id)->update([
        'legacy_id' => null, 'source' => 'manual', 'allergen_gluten' => 'spuren', 'allergen_milk' => 'enthalten', 'allergen_celery' => null]);
    $this->attrappe->daten['supplier-item-allergens'] = [['supplierItemId' => 36674788, 'gluten' => 3, 'milk' => 1, 'celery' => 1]];
    ($this->lauf)();

    ($this->lauf)(['voll' => true]);

    $zeile = DB::table('foodalchemist_item_allergens')->where('supplier_item_id', $this->a->id)->sole();
    $faelle = DB::table('foodalchemist_necta_pruefaelle')->where('art', 'allergen_aenderung')->where('legacy_id', 36674788)->pluck('feld')->sort()->values()->all();
    expect($zeile->allergen_gluten)->toBe('enthalten')                // strenger: Sicherheit vor Handpflege
        ->and($zeile->allergen_milk)->toBe('enthalten')               // lockerer: bleibt
        ->and($zeile->allergen_celery)->toBeNull()                    // unbekannt → nicht_enthalten NICHT still bei Handzeile
        ->and($faelle)->toBe(['allergen_celery', 'allergen_milk']);
});

// ── Kurator 18.10. (F3): veraltete Rezept-Allergene nach Necta-Änderung sind ein Sicherheitsthema ──

it('★ Necta verschärft ein Artikel-Allergen → das Rezept über GP ist nach dem Lauf verschärft, Zähler „rezepte_nachgerechnet“', function () {
    $gp = $this->makeGp($this->rootTeam, 'Splitterbrötchen');
    \Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure::create(['team_id' => null, 'supplier_item_id' => $this->a->id, 'gp_id' => $gp->id]);
    $rezept = $this->makeRecipe($this->rootTeam, 'Schoko-Sandwich');
    $this->makeIngredient($rezept, 'Splitterbrötchen', $gp);
    app(\Platform\FoodAlchemist\Services\RecipeRecomputeService::class)->recomputePipeline($rezept->id);
    expect($rezept->fresh()->allergen_milk)->not->toBe('enthalten')   // Ausgangslage: Milch am Artikel unbekannt
        ->and($rezept->fresh()->allergen_gluten)->toBe('enthalten');

    ($this->lauf)();
    $r = ($this->lauf)(['voll' => true]);

    expect(DB::table('foodalchemist_item_allergens')->where('supplier_item_id', $this->a->id)->value('allergen_milk'))->toBe('enthalten')
        ->and($rezept->fresh()->allergen_milk)->toBe('enthalten')                  // ohne Nachrechnen bliebe das Rezept alt
        ->and($r['zaehler']['rezepte_nachgerechnet'])->toBe(1)
        ->and($r['zaehler']['artikel_kennzeichnung_geaendert'])->toBe(1);
});

it('Nachrechnen: ohne Kennzeichnungs-Änderung kein Rezept; Probe zählt nur und schreibt nichts', function () {
    $gp = $this->makeGp($this->rootTeam, 'Splitterbrötchen');
    \Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure::create(['team_id' => null, 'supplier_item_id' => $this->a->id, 'gp_id' => $gp->id]);
    $rezept = $this->makeRecipe($this->rootTeam, 'Schoko-Sandwich');
    $this->makeIngredient($rezept, 'Splitterbrötchen', $gp);
    app(\Platform\FoodAlchemist\Services\RecipeRecomputeService::class)->recomputePipeline($rezept->id);
    $vorher = $rezept->fresh()->allergen_milk;

    $nacht = ($this->lauf)();                                       // nächtlich: nur Preise, A hat keine neuen Allergene
    expect($nacht['zaehler']['rezepte_nachgerechnet'])->toBe(0);

    $probe = ($this->lauf)(['voll' => true, 'probe' => true]);
    expect($probe['zaehler']['rezepte_nachgerechnet'])->toBe(1)      // würde nachgerechnet
        ->and($rezept->fresh()->allergen_milk)->toBe($vorher);       // Probe schreibt nichts
});
