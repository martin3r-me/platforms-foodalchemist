<?php

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Platform\FoodAlchemist\Jobs\ClassifyLaJob;
use Platform\FoodAlchemist\Jobs\NonFoodKiBatchJob;
use Platform\FoodAlchemist\Livewire\Suppliers\Index;
use Platform\FoodAlchemist\Livewire\Suppliers\ItemModal;
use Platform\FoodAlchemist\Models\FoodAlchemistLookupWarengruppe;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemNonfood;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Models\FoodAlchemistWarengruppeSubkategorie;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Services\Ai\AiProposal;
use Platform\FoodAlchemist\Services\NonFoodService;
use Platform\FoodAlchemist\Support\NonFoodRegeln;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Non-Food am Lieferantenartikel (PR 1): Regelstufe mit Gegenproben, Lauf über den Bestand (schreibt nichts im Trockenlauf,
 * lässt Mensch und KI stehen), KI-Stapel, Einrichtung der Warengruppe 16, Modal-Feld, Filter, Klassifikator überspringt Non-Food.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam, 'Einkauf');
    $this->actingAs($this->user);
    $this->svc = app(NonFoodService::class);

    $this->la = function (string $bezeichnung, string $lieferant = 'Hanos', ?string $branche = null) {
        $sup = FoodAlchemistSupplier::firstOrCreate(['team_id' => $this->rootTeam->id, 'name' => $lieferant], ['branch' => $branche]);

        return FoodAlchemistSupplierItem::create(['team_id' => $this->rootTeam->id, 'supplier_id' => $sup->id, 'designation' => $bezeichnung, 'qty' => 1, 'unit_code' => 'kg']);
    };
    $this->allergen = fn (FoodAlchemistSupplierItem $la) => DB::table('foodalchemist_item_allergens')->insert([
        'uuid' => (string) UuidV7::generate(), 'team_id' => $la->team_id, 'supplier_item_id' => $la->id, 'created_at' => now(), 'updated_at' => now()]);
});

it('Regel: starke Wörter sind Non-Food mit Unterkategorie; ganze Wörter, keine Teilstücke', function () {
    $b = fn (string $t, array $extra = []) => NonFoodRegeln::bewerte(['bezeichnung' => $t, 'hat_food_daten' => false] + $extra);

    expect($b('SERVIETTE 2-LAGIG 39X39CM')['klasse'])->toBe('nonfood')->and($b('SERVIETTE 2-LAGIG 39X39CM')['subkategorie'])->toBe(NonFoodRegeln::SUB_DEKO)
        ->and($b('Backofenreiniger 5 l')['subkategorie'])->toBe(NonFoodRegeln::SUB_REINIGUNG)
        ->and($b('KOCHJACKE BUJUTSU GRÜN XS')['subkategorie'])->toBe(NonFoodRegeln::SUB_KLEIDUNG)
        ->and($b('Einweghandschuhe Nitril L')['klasse'])->toBe('nonfood')
        ->and($b('GEFRIERBEUTEL 24X30CM 2,5 LITER')['subkategorie'])->toBe(NonFoodRegeln::SUB_EINWEG);

    // Gegenprobe: dasselbe Teilstück in einem Lebensmittel ist KEIN Treffer
    expect($b('Serviettenknödel 1 kg')['klasse'])->toBe('food')
        ->and($b('Schwammerl Mix TK 1 kg')['klasse'])->toBe('food')
        ->and($b('Tablettenzucker Süßstoff')['klasse'])->toBe('food');
});

it('Regel: mehrdeutige Gebinde-Wörter sind nur unklar, nie Non-Food; Food-Daten kippen ein starkes Wort auf unklar', function () {
    $b = fn (string $t, bool $food, array $extra = []) => NonFoodRegeln::bewerte(['bezeichnung' => $t, 'hat_food_daten' => $food] + $extra);

    expect($b('Rinderknochen TK a 5 kg/Karton', false)['klasse'])->toBe('unklar')
        ->and($b('Rinderknochen TK a 5 kg/Karton', true)['klasse'])->toBe('food')         // mit Allergen-/Nährwertdaten kein Zweifel mehr
        ->and($b('Nudelsalat Hausfrauen Art 1 kg Schale', false)['klasse'])->toBe('unklar')
        ->and($b('Handschuhe Latex', true)['klasse'])->toBe('unklar')                      // starkes Wort, aber Food-Daten → nie Non-Food
        ->and($b('Mineralwasser still 12x0,75', false)['klasse'])->toBe('food');           // fehlende Allergene allein heißen NICHT Non-Food
});

it('Regel: Lieferanten-Branche Non-Food zählt nur ohne Food-Daten', function () {
    $b = fn (bool $food) => NonFoodRegeln::bewerte(['bezeichnung' => 'Artikel ohne Stichwort', 'branche' => 'Hygiene', 'lieferant' => 'Franz Mensch GmbH', 'hat_food_daten' => $food]);

    expect($b(false)['klasse'])->toBe('nonfood')->and($b(false)['subkategorie'])->toBe(NonFoodRegeln::SUB_REINIGUNG)->and($b(true)['klasse'])->toBe('unklar');
});

it('Lauf: Trockenlauf schreibt nichts, --apply schreibt Non-Food und Prüfliste, Food bekommt keine Zeile', function () {
    $nf = ($this->la)('SERVIETTE 2-LAGIG');
    $unklar = ($this->la)('Flammkuchen-Karton 7 kg');
    $food = ($this->la)('Butter 250 g');
    $leereZeile = ($this->la)('Kochjacke Weiß');
    ($this->allergen)($leereZeile);                                // Import-Zeile OHNE Werte: kein Food-Beleg → Non-Food
    $mitDaten = ($this->la)('Handschuhe Latex');
    DB::table('foodalchemist_item_allergens')->insert(['uuid' => (string) UuidV7::generate(), 'team_id' => $mitDaten->team_id, 'supplier_item_id' => $mitDaten->id,
        'allergen_milk' => 'enthalten', 'created_at' => now(), 'updated_at' => now()]);   // echtes Allergen → unklar, nie Non-Food

    $r = $this->svc->regelLauf(null, false);
    expect($r['nonfood'])->toBe(2)->and($r['unklar'])->toBe(2)->and($r['food'])->toBe(1)->and(FoodAlchemistSupplierItemNonfood::count())->toBe(0);

    $this->svc->regelLauf(null, true);
    $z = FoodAlchemistSupplierItemNonfood::where('supplier_item_id', $nf->id)->first();
    expect($z->ist_nonfood)->toBeTrue()->and($z->quelle)->toBe('regel')->and($z->subkategorie)->toBe(NonFoodRegeln::SUB_DEKO)->and($z->pruefung_offen)->toBeFalse()
        ->and(FoodAlchemistSupplierItemNonfood::where('supplier_item_id', $unklar->id)->first())->toMatchArray(['ist_nonfood' => null, 'pruefung_offen' => true])
        ->and(FoodAlchemistSupplierItemNonfood::where('supplier_item_id', $leereZeile->id)->first())->toMatchArray(['ist_nonfood' => true, 'pruefung_offen' => false])
        ->and(FoodAlchemistSupplierItemNonfood::where('supplier_item_id', $mitDaten->id)->value('pruefung_offen'))->toBeTrue()
        ->and(FoodAlchemistSupplierItemNonfood::where('supplier_item_id', $food->id)->exists())->toBeFalse();

    // idempotent
    $this->svc->regelLauf(null, true);
    expect(FoodAlchemistSupplierItemNonfood::count())->toBe(4);
});

it('Lauf: Urteil von Hand und KI-Urteil bleiben stehen, eine Regel-Zeile ohne Signal wird entfernt', function () {
    $hand = ($this->la)('SERVIETTE 2-LAGIG');
    $ki = ($this->la)('Backofenreiniger');
    $alt = ($this->la)('Butter 250 g');
    $this->svc->setzeManuell($hand, false);                                                   // Dominique: das ist Food
    FoodAlchemistSupplierItemNonfood::create(['team_id' => $ki->team_id, 'supplier_item_id' => $ki->id, 'ist_nonfood' => false, 'quelle' => 'ki', 'konfidenz' => 0.9]);
    FoodAlchemistSupplierItemNonfood::create(['team_id' => $alt->team_id, 'supplier_item_id' => $alt->id, 'ist_nonfood' => true, 'quelle' => 'regel']);

    $r = $this->svc->regelLauf(null, true);

    expect(FoodAlchemistSupplierItemNonfood::where('supplier_item_id', $hand->id)->first())->toMatchArray(['ist_nonfood' => false, 'quelle' => 'manuell'])
        ->and(FoodAlchemistSupplierItemNonfood::where('supplier_item_id', $ki->id)->first())->toMatchArray(['ist_nonfood' => false, 'quelle' => 'ki'])
        ->and(FoodAlchemistSupplierItemNonfood::where('supplier_item_id', $alt->id)->exists())->toBeFalse()
        ->and($r['geloescht'])->toBe(1);
});

it('KI-Stapel: sicheres Urteil wird gespeichert, unsicheres bleibt in der Prüfliste, fehlender Call ist ein No-op', function () {
    $a = ($this->la)('Becher 180 ml');
    $b = ($this->la)('Schale eckig');
    $c = ($this->la)('Korb rund');
    $this->svc->regelLauf(null, true);
    expect(FoodAlchemistSupplierItemNonfood::where('pruefung_offen', true)->count())->toBe(3);

    $this->mock(AiGatewayService::class)->shouldReceive('propose')->once()->with('la.nonfood', Mockery::on(fn ($ctx) => count($ctx['artikel']) === 3), [])
        ->andReturn(new AiProposal(['urteile' => [
            ['id' => $a->id, 'nonfood' => true, 'unterkategorie' => NonFoodRegeln::SUB_DEKO, 'konfidenz' => 0.95, 'grund' => 'Trinkbecher'],
            ['id' => $b->id, 'nonfood' => false, 'unterkategorie' => null, 'konfidenz' => 0.9, 'grund' => 'Salatschale'],
            ['id' => $c->id, 'nonfood' => true, 'unterkategorie' => NonFoodRegeln::SUB_DEKO, 'konfidenz' => 0.5, 'grund' => 'unsicher'],
        ]], 0.9));

    app()->call([new NonFoodKiBatchJob([$a->id, $b->id, $c->id]), 'handle']);

    $za = FoodAlchemistSupplierItemNonfood::where('supplier_item_id', $a->id)->first();
    $zb = FoodAlchemistSupplierItemNonfood::where('supplier_item_id', $b->id)->first();
    $zc = FoodAlchemistSupplierItemNonfood::where('supplier_item_id', $c->id)->first();
    expect($za)->toMatchArray(['ist_nonfood' => true, 'quelle' => 'ki', 'pruefung_offen' => false, 'subkategorie' => NonFoodRegeln::SUB_DEKO])
        ->and($zb)->toMatchArray(['ist_nonfood' => false, 'quelle' => 'ki', 'pruefung_offen' => false])
        ->and($zc)->toMatchArray(['ist_nonfood' => null, 'quelle' => 'regel', 'pruefung_offen' => true])        // unter der Schwelle: Mensch entscheidet
        ->and($zc->ki_versucht_at)->not->toBeNull();
});

it('KI-Stapel: ein roter Call lässt die Prüfliste unverändert', function () {
    $a = ($this->la)('Becher 180 ml');
    $this->svc->regelLauf(null, true);
    $this->mock(AiGatewayService::class)->shouldReceive('propose')->once()->andThrow(new RuntimeException('Provider down'));

    app()->call([new NonFoodKiBatchJob([$a->id]), 'handle']);

    expect(FoodAlchemistSupplierItemNonfood::where('supplier_item_id', $a->id)->first())->toMatchArray(['ist_nonfood' => null, 'pruefung_offen' => true]);
});

it('Einrichten: Warengruppe 16 und sieben Unterkategorien, Trockenlauf schreibt nichts, zweiter Lauf ändert nichts', function () {
    $trocken = $this->svc->einrichten($this->rootTeam, false);
    expect($trocken['wg_angelegt'])->toBeTrue()->and($trocken['unterkategorien_angelegt'])->toHaveCount(7)
        ->and(FoodAlchemistLookupWarengruppe::where('code', '16')->count())->toBe(0);

    $this->svc->einrichten($this->rootTeam, true);
    $zweiter = $this->svc->einrichten($this->rootTeam, true);

    expect(FoodAlchemistLookupWarengruppe::where('team_id', $this->rootTeam->id)->where('code', '16')->count())->toBe(1)
        ->and(FoodAlchemistWarengruppeSubkategorie::where('team_id', $this->rootTeam->id)->where('commodity_group_code', '16')->count())->toBe(7)
        ->and($zweiter['wg_angelegt'])->toBeFalse()->and($zweiter['unterkategorien_angelegt'])->toBe([]);
});

it('Kommandos: einrichten und erkennen laufen, Dry-Run ist Default, --ki braucht --apply', function () {
    ($this->la)('SERVIETTE 2-LAGIG');

    $this->artisan('foodalchemist:nonfood-erkennen')->assertSuccessful();
    expect(FoodAlchemistSupplierItemNonfood::count())->toBe(0);
    $this->artisan('foodalchemist:nonfood-erkennen', ['--ki' => true])->assertFailed();
    $this->artisan('foodalchemist:nonfood-erkennen', ['--apply' => true])->assertSuccessful();
    expect(FoodAlchemistSupplierItemNonfood::count())->toBe(1);

    $this->artisan('foodalchemist:nonfood-einrichten', ['--team' => $this->rootTeam->id])->assertSuccessful();
    expect(FoodAlchemistLookupWarengruppe::where('code', '16')->count())->toBe(0);
    $this->artisan('foodalchemist:nonfood-einrichten', ['--team' => $this->rootTeam->id, '--apply' => true])->assertSuccessful();
    expect(FoodAlchemistLookupWarengruppe::where('code', '16')->count())->toBe(1);
});

it('Modal: Art des Artikels setzen, nur bei Änderung speichern, leeren nimmt das Urteil zurück; Necta-Felder bleiben unberührt', function () {
    $la = ($this->la)('Lampion Papier 35 cm');

    $c = Livewire::test(ItemModal::class)->call('oeffnen', $la->id);
    // ein Speichern ohne Änderung schreibt kein Urteil
    $c->call('speichern')->assertSet('fehler', null);
    expect(FoodAlchemistSupplierItemNonfood::count())->toBe(0);

    $c->set('nonfood.ist', '1')->set('nonfood.subkategorie', NonFoodRegeln::SUB_DEKO)->call('speichern')->assertSet('fehler', null);
    $z = FoodAlchemistSupplierItemNonfood::where('supplier_item_id', $la->id)->first();
    expect($z->ist_nonfood)->toBeTrue()->and($z->quelle)->toBe('manuell')->and($z->subkategorie)->toBe(NonFoodRegeln::SUB_DEKO);

    $d = Livewire::test(ItemModal::class)->call('oeffnen', $la->id);
    expect($d->get('nonfood'))->toBe(['ist' => '1', 'subkategorie' => NonFoodRegeln::SUB_DEKO]);
    $d->assertSeeHtml('data-nonfood-ist')->assertSeeHtml('data-nonfood-herkunft');
    $d->set('nonfood.ist', '')->set('nonfood.subkategorie', '')->call('speichern');
    expect(FoodAlchemistSupplierItemNonfood::where('supplier_item_id', $la->id)->exists())->toBeFalse();
});

it('Liste: Filter Art zeigt Non-Food bzw. die Prüfliste, Gegenprobe ohne Filter zeigt alles', function () {
    $nf = ($this->la)('SERVIETTE 2-LAGIG', 'Metro');
    $unklar = ($this->la)('Flammkuchen-Karton 7 kg', 'Metro');
    $food = ($this->la)('Butter 250 g', 'Metro');
    $this->svc->regelLauf(null, true);
    $sid = $nf->supplier_id;

    $namen = fn (string $art) => Livewire::withQueryParams(['lieferant' => $sid, 'art' => $art])->test(Index::class)->viewData('artikel')->pluck('designation')->all();

    expect($namen('nonfood'))->toBe(['SERVIETTE 2-LAGIG'])
        ->and($namen('pruefen'))->toBe(['Flammkuchen-Karton 7 kg'])
        ->and($namen('food'))->toEqualCanonicalizing(['Flammkuchen-Karton 7 kg', 'Butter 250 g'])
        ->and($namen(''))->toHaveCount(3);
    $html = Livewire::withQueryParams(['lieferant' => $sid])->test(Index::class)->html();
    expect($html)->toContain('data-nonfood-pill')->toContain('data-nonfood-pruefen-pill')->toContain('data-art-filter');
});

it('Klassifikator überspringt Non-Food: keine Food-Struktur, kein LLM-Call; Gegenprobe Food läuft weiter', function () {
    $nf = ($this->la)('Backofenreiniger');
    $this->svc->setzeManuell($nf, true, NonFoodRegeln::SUB_REINIGUNG);
    $food = ($this->la)('Sesampaste');

    $mock = $this->mock(AiGatewayService::class);
    $mock->shouldReceive('propose')->once()->with('gp.suggest', Mockery::on(fn ($c) => $c['label'] === 'Sesampaste'))->andThrow(new RuntimeException('kein Provider'));

    (new ClassifyLaJob($nf->id, $this->rootTeam->id))->handle($mock, app(\Platform\FoodAlchemist\Services\GpNamingService::class));
    (new ClassifyLaJob($food->id, $this->rootTeam->id))->handle($mock, app(\Platform\FoodAlchemist\Services\GpNamingService::class));

    expect(FoodAlchemistSupplierItemStructure::where('supplier_item_id', $nf->id)->exists())->toBeFalse();
});


it('Regel (16.10.2026, an den io-Daten belegt): neue starke Wörter; Ausnahmen und Gegenproben aus den echten Bezeichnungen bleiben Food bzw. unklar', function () {
    $b = fn (string $t, bool $food = false, array $extra = []) => NonFoodRegeln::bewerte(['bezeichnung' => $t, 'hat_food_daten' => $food] + $extra);

    expect($b('WINDLICHT SURI 16CM KLAR')['klasse'])->toBe('nonfood')->and($b('WINDLICHT SURI 16CM KLAR')['subkategorie'])->toBe(NonFoodRegeln::SUB_DEKO)
        ->and($b('APS Schale Pure 19x19cm 1,5l H9cm, Melamin, weiß')['subkategorie'])->toBe(NonFoodRegeln::SUB_GESCHIRR)
        ->and($b('Tablett, 18,5 x 18,5 cm 0 kg')['klasse'])->toBe('nonfood')
        ->and($b('Deckel aus Bagasse für 300 ml-Becher')['subkategorie'])->toBe(NonFoodRegeln::SUB_EINWEG)
        ->and($b('Eisportionierer, Edelstahl 1/40')['subkategorie'])->toBe(NonFoodRegeln::SUB_KUECHE)
        ->and($b('Kes. Profi-Schneidebrett 45x27x3cm FSC-zertif. Bambus')['klasse'])->toBe('nonfood')
        ->and($b('Porzellan-Schälchen weiß, ST (30 GR)')['klasse'])->toBe('nonfood');

    // Gegenproben: Schokoladen-Tablette ist Food (Wortgrenze), Koppert „Venus Vase“ ist Kresse (Ausnahme → die KI entscheidet), Teller Linsen bleibt unklar
    expect($b('Tablette Guanaja 70% Cacao 70g/St')['klasse'])->toBe('food')
        ->and($b('KOPPERT VENUS VASE STCK')['klasse'])->toBe('unklar')
        ->and($b('MM Teller Linsen 5kg')['klasse'])->toBe('unklar')
        ->and($b('Amarena-Kirschen, in Sirup, Vase, 600 g', true, ['hat_echte_food_daten' => true])['klasse'])->toBe('unklar');   // starkes Wort an Ware mit Food-Beleg
});

it('Food-Belege: leere Import-Zeilen zählen bei einem starken Wort nicht — echtes Allergen, Nährwert > 0, GP-Zuordnung und Zutatenliste schon', function () {
    $b = fn (bool $echte) => NonFoodRegeln::bewerte(['bezeichnung' => 'Windlicht Glas', 'hat_food_daten' => true, 'hat_echte_food_daten' => $echte])['klasse'];
    expect($b(false))->toBe('nonfood')->and($b(true))->toBe('unklar');

    // Lauf gegen die Tabellen: je ein Artikel pro Belegart
    $leer = ($this->la)('WINDLICHT SURI 16CM'); ($this->allergen)($leer);
    DB::table('foodalchemist_item_nutritionals')->insert(['uuid' => (string) UuidV7::generate(), 'team_id' => $leer->team_id, 'supplier_item_id' => $leer->id, 'energy_kcal' => 0, 'protein' => 0, 'fat' => 0, 'created_at' => now(), 'updated_at' => now()]);
    $nae = ($this->la)('WINDLICHT NAEHRWERT');
    DB::table('foodalchemist_item_nutritionals')->insert(['uuid' => (string) UuidV7::generate(), 'team_id' => $nae->team_id, 'supplier_item_id' => $nae->id, 'energy_kcal' => 250, 'created_at' => now(), 'updated_at' => now()]);
    $gp = ($this->la)('WINDLICHT GP');
    $g = $this->makeGp($this->rootTeam, 'Irgendein Grundprodukt');
    FoodAlchemistSupplierItemStructure::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $gp->id, 'gp_id' => $g->id]);
    $zut = ($this->la)('WINDLICHT ZUTATEN');
    $zut->update(['ingredients_supplier' => 'Zucker, Wasser']);

    $this->svc->regelLauf(null, true);
    $klasse = fn ($la) => FoodAlchemistSupplierItemNonfood::where('supplier_item_id', $la->id)->first();
    expect($klasse($leer))->toMatchArray(['ist_nonfood' => true, 'pruefung_offen' => false])                // Nährwert 0 und leere Allergene: kein Beleg
        ->and($klasse($nae))->toMatchArray(['ist_nonfood' => null, 'pruefung_offen' => true])
        ->and($klasse($gp))->toMatchArray(['ist_nonfood' => null, 'pruefung_offen' => true])
        ->and($klasse($zut))->toMatchArray(['ist_nonfood' => null, 'pruefung_offen' => true]);
});

it('KI-Stapel knapp: ohne grund steht „KI: sicher“, unsicher ohne grund „KI: unsicher“, mit grund der Satz; Kontext kompakt (Lieferant einmal, Verkaufsname nur wenn abweichend)', function () {
    $a = ($this->la)('Becher 180 ml', 'Hanos');
    $b = ($this->la)('Schale eckig', 'Hanos');
    $c = ($this->la)('Korb rund', 'Edna');
    $b->update(['marketing_name' => 'SCHALE ECKIG']);          // gleich bis auf Groß/Klein → weglassen
    $c->update(['marketing_name' => 'Brotkorb Natur']);          // abweichend → mitschicken
    $this->svc->regelLauf(null, true);
    $ctx = null;
    $this->mock(AiGatewayService::class)->shouldReceive('propose')->once()->with('la.nonfood', Mockery::on(function ($c2) use (&$ctx) {
        $ctx = $c2;

        return true;
    }), [])->andReturn(new AiProposal(['urteile' => [
        ['id' => $a->id, 'nonfood' => true, 'unterkategorie' => NonFoodRegeln::SUB_DEKO, 'konfidenz' => 0.95],
        ['id' => $b->id, 'nonfood' => false, 'unterkategorie' => null, 'konfidenz' => 0.4],
        ['id' => $c->id, 'nonfood' => false, 'unterkategorie' => null, 'konfidenz' => 0.55, 'grund' => 'Brotkorb kann Zubehör sein'],
    ]], 0.9));

    app()->call([new NonFoodKiBatchJob([$a->id, $b->id, $c->id]), 'handle']);

    $z = fn ($la) => FoodAlchemistSupplierItemNonfood::where('supplier_item_id', $la->id)->first();
    expect($z($a)->begruendung)->toBe('KI: sicher')->and($z($b)->begruendung)->toBe('KI: unsicher')->and($z($c)->begruendung)->toBe('KI: Brotkorb kann Zubehör sein')
        ->and($ctx['lieferanten'])->toBe(['Hanos', 'Edna'])
        ->and(collect($ctx['artikel'])->firstWhere('id', $a->id))->toBe(['id' => $a->id, 'bezeichnung' => 'Becher 180 ml', 'l' => 0])
        ->and(collect($ctx['artikel'])->firstWhere('id', $b->id))->not->toHaveKey('verkaufsname')
        ->and(collect($ctx['artikel'])->firstWhere('id', $c->id))->toMatchArray(['verkaufsname' => 'Brotkorb Natur', 'l' => 1]);
});

it('Stapelgröße 50 und eigenes Non-Food-Modell: leer = kein model-Override, gesetzt = model im Aufruf', function () {
    expect(NonFoodKiBatchJob::STAPEL)->toBe(50);
    $a = ($this->la)('Becher 180 ml');
    $this->svc->regelLauf(null, true);
    $liefere = fn () => new AiProposal(['urteile' => [['id' => $a->id, 'nonfood' => true, 'unterkategorie' => NonFoodRegeln::SUB_DEKO, 'konfidenz' => 0.9]]], 0.9);

    config(['foodalchemist.ai.nonfood_model' => '']);
    $this->mock(AiGatewayService::class)->shouldReceive('propose')->once()->with('la.nonfood', Mockery::any(), [])->andReturnUsing($liefere);
    app()->call([new NonFoodKiBatchJob([$a->id]), 'handle']);

    FoodAlchemistSupplierItemNonfood::where('supplier_item_id', $a->id)->update(['ist_nonfood' => null, 'quelle' => 'regel', 'pruefung_offen' => true]);
    config(['foodalchemist.ai.nonfood_model' => 'gpt-5.6-luna']);
    $this->mock(AiGatewayService::class)->shouldReceive('propose')->once()->with('la.nonfood', Mockery::any(), ['model' => 'gpt-5.6-luna'])->andReturnUsing($liefere);
    app()->call([new NonFoodKiBatchJob([$a->id]), 'handle']);
});

it('Kommando --ki: nur Artikel, an denen die KI noch nie war; --ki-nochmal nimmt alle, --ki-limit und --stapel begrenzen', function () {
    \Illuminate\Support\Facades\Queue::fake();
    foreach (range(1, 5) as $i) {
        ($this->la)("Becher {$i}");
    }
    $this->artisan('foodalchemist:nonfood-erkennen', ['--apply' => true])->assertSuccessful();
    // zwei Artikel hat die KI schon versucht (blieben unsicher)
    FoodAlchemistSupplierItemNonfood::query()->orderBy('id')->limit(2)->update(['ki_versucht_at' => now()]);

    $this->artisan('foodalchemist:nonfood-erkennen', ['--apply' => true, '--ki' => true])->expectsOutputToContain('3 Artikel, 2 schon früher von der KI versucht')->assertSuccessful();
    \Illuminate\Support\Facades\Queue::assertPushed(NonFoodKiBatchJob::class, fn ($j) => count($j->itemIds) === 3);

    \Illuminate\Support\Facades\Queue::fake();
    $this->artisan('foodalchemist:nonfood-erkennen', ['--apply' => true, '--ki' => true, '--ki-nochmal' => true, '--stapel' => 2])->assertSuccessful();
    \Illuminate\Support\Facades\Queue::assertPushed(NonFoodKiBatchJob::class, 3);                    // 5 Artikel in Stapeln à 2

    \Illuminate\Support\Facades\Queue::fake();
    $this->artisan('foodalchemist:nonfood-erkennen', ['--apply' => true, '--ki' => true, '--ki-nochmal' => true, '--ki-limit' => 2])->assertSuccessful();
    \Illuminate\Support\Facades\Queue::assertPushed(NonFoodKiBatchJob::class, 1);
});
