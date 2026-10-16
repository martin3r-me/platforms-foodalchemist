<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Enums\FaRolle;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Jobs\ProduktpassLesenJob;
use Platform\FoodAlchemist\Livewire\Suppliers\ItemModal;
use Platform\FoodAlchemist\Livewire\Suppliers\ProduktpassPanel;
use Platform\FoodAlchemist\Models\FoodAlchemistItemAllergen;
use Platform\FoodAlchemist\Models\FoodAlchemistProduktpass;
use Platform\FoodAlchemist\Models\FoodAlchemistProduktpassFeld;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Services\Ai\AiProposal;
use Platform\FoodAlchemist\Services\Produktpass\ProduktdatenQuelle;
use Platform\FoodAlchemist\Services\Produktpass\ProduktpassNormalisierer;
use Platform\FoodAlchemist\Services\Produktpass\ProduktpassService;
use Platform\FoodAlchemist\Support\StammPaket;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Produktpass am Lieferantenartikel (Bauauftrag Kurator 16.10.2026). Fake-LLM: das Gateway liefert, was der Test in
 * `$this->kiWerte` ablegt. Geprüft wird die Verdrahtung und jede Regel mit einer Gegenprobe:
 * Allergene nie ohne Bestätigung · nur ≥ 0,8 direkt · Gebinde nur in leere Felder · Mandant nicht am Stamm-Artikel ·
 * nichts raten (fehlt im Pass → leer).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    config(['foodalchemist.master_team_id' => $this->rootTeam->id]);
    Storage::fake('public');
    Storage::fake('local');
    Queue::fake();

    $this->admin = $this->makeUser($this->rootTeam, 'Admin M', 'owner');
    $this->kurator = $this->makeUser($this->rootTeam, 'Kurator M', 'member');
    $this->leser = $this->makeUser($this->rootTeam, 'Leser M', 'viewer');
    $this->ownerA = $this->makeUser($this->childA, 'Owner A', 'owner');

    $stammLieferant = FoodAlchemistSupplier::create(['team_id' => null, 'name' => 'Stamm-Lieferant', 'stamm_class' => StammPaket::PAKET]);
    $this->stamm = FoodAlchemistSupplierItem::create(['team_id' => null, 'supplier_id' => $stammLieferant->id, 'designation' => 'Weizenmehl 405',
        'unit_code' => null, 'stamm_class' => StammPaket::PAKET]);
    $lieferantA = FoodAlchemistSupplier::create(['team_id' => $this->childA->id, 'name' => 'Eigener Lieferant']);
    $this->eigen = FoodAlchemistSupplierItem::create(['team_id' => $this->childA->id, 'supplier_id' => $lieferantA->id, 'designation' => 'Hausmarmelade']);

    $this->kiWerte = [];
    $this->mock(AiGatewayService::class, fn ($m) => $m->shouldReceive('propose')
        ->andReturnUsing(fn () => new AiProposal($this->kiWerte, 0.9, 'Mock', [], 'fake-ki')));

    $this->datei = fn (string $name = 'pass.pdf') => UploadedFile::fake()->create($name, 120, 'application/pdf');
    $this->svc = app(ProduktpassService::class);
    /** Hochladen (Master am Stamm-Artikel) und sofort lesen — der Job läuft in Tests nicht von selbst. */
    $this->liesPass = function (array $werte, $item = null) {
        $this->kiWerte = $werte;
        $item ??= $this->stamm;
        $pass = $this->svc->hochladen($this->rootTeam, $item, ($this->datei)(), $this->kurator->id);

        return $this->svc->lesen($pass);
    };
});

it('Hochladen legt eine Version samt Datei an und stellt das Lesen auf die Anreicherungs-Schlange; die nächste Version zählt weiter', function () {
    config(['foodalchemist.queue.anreichern' => 'anreichern-test']);
    $v1 = $this->svc->hochladen($this->rootTeam, $this->stamm, ($this->datei)(), $this->kurator->id);
    $v2 = $this->svc->hochladen($this->rootTeam, $this->stamm, ($this->datei)('neu.pdf'), $this->kurator->id);

    expect($v1->version)->toBe(1)->and($v2->version)->toBe(2)
        ->and($v1->status)->toBe('hochgeladen')->and($v1->context_file_id)->not->toBeNull()
        ->and($v1->team_id)->toBe($this->rootTeam->id)                              // Stamm-Artikel (team_id NULL) → Master-Team
        ->and($this->svc->downloadUrl($v1))->not->toBeNull();
    Queue::assertPushed(ProduktpassLesenJob::class, 2);
    Queue::assertPushedOn('anreichern-test', ProduktpassLesenJob::class);
    expect($this->svc->versionen($this->stamm)->pluck('version')->all())->toBe([2, 1]);
});

it('Rechte: Master darf am Stamm-Artikel, der Mandant NICHT (weder hochladen noch übernehmen); Mandant am eigenen Artikel ja; Lesen-Rolle nie', function () {
    // Mandant am Stamm-Artikel: gesperrt
    expect(fn () => $this->svc->hochladen($this->childA, $this->stamm, ($this->datei)(), $this->ownerA->id))
        ->toThrow(RuntimeException::class, 'übergeordneten Katalog');
    // Lesen-Rolle im Master: gesperrt
    expect(fn () => $this->svc->hochladen($this->rootTeam, $this->stamm, ($this->datei)(), $this->leser->id))
        ->toThrow(FaRechtFehltException::class);
    // Master am Mandanten-Artikel: gesperrt (gehört dem Mandanten)
    expect(fn () => $this->svc->hochladen($this->rootTeam, $this->eigen, ($this->datei)(), $this->kurator->id))
        ->toThrow(RuntimeException::class, 'übergeordneten Katalog');

    // erlaubt: Master am Stamm, Mandant am eigenen
    expect($this->svc->hochladen($this->rootTeam, $this->stamm, ($this->datei)(), $this->kurator->id)->version)->toBe(1)
        ->and($this->svc->hochladen($this->childA, $this->eigen, ($this->datei)(), $this->ownerA->id)->version)->toBe(1);

    // der Mandant kann einen Master-Vorschlag auch nicht übernehmen oder Allergene bestätigen
    $pass = ($this->liesPass)(['felder' => ['zutatenliste' => ['wert' => 'Weizenmehl', 'konfidenz' => 0.95]],
        'allergene' => ['gluten' => ['wert' => 'enthalten', 'konfidenz' => 0.9]]]);
    expect(fn () => $this->svc->feldUebernehmen($this->childA, $pass, 'zutatenliste', $this->ownerA->id))->toThrow(RuntimeException::class, 'übergeordneten Katalog')
        ->and(fn () => $this->svc->kennzeichnungBestaetigen($this->childA, $pass, $this->ownerA->id))->toThrow(RuntimeException::class, 'übergeordneten Katalog');
});

it('Lesen: Nährwerte, Zutatenliste und Haltbarkeit (mit Lagerart) ab 0,8 DIREKT mit Quelle produktpass_ki und Marker; Zusatzstoffe NIE direkt; darunter nur Vorschlag; Herkunft/Marke nie direkt', function () {
    $pass = ($this->liesPass)(['felder' => [
        'zutatenliste' => ['wert' => 'Weizenmehl, Wasser', 'konfidenz' => 0.95],
        'naehrwerte.energy_kcal' => ['wert' => 350, 'konfidenz' => 0.9],
        'naehrwerte.fat' => ['wert' => 1.2, 'konfidenz' => 0.5],                    // unsicher → nur Vorschlag
        'naehrwerte.salz' => ['wert' => 0.5, 'konfidenz' => 0.9],                   // Salz g → Natrium mg (×400), abgeleitet
        'zusatzstoffe.with_dye' => ['wert' => 'ja', 'konfidenz' => 0.9],
        'haltbarkeit.dry_days' => ['wert' => 365, 'konfidenz' => 0.9],
        'haltbarkeit.unklar_days' => ['wert' => 90, 'konfidenz' => 0.99],           // Lagerart unklar → nie direkt
        'herkunft' => ['wert' => 'Deutschland', 'konfidenz' => 0.99],
        'marke' => ['wert' => 'Hausmühle', 'konfidenz' => 0.99],
    ]]);

    $item = $this->stamm->fresh();
    $nutri = $item->nutritionals;
    expect($pass->status)->toBe('gelesen')
        ->and($item->ingredients_supplier)->toBe('Weizenmehl, Wasser')
        ->and((float) $nutri->energy_kcal)->toBe(350.0)
        ->and($nutri->fat)->toBeNull()                                              // Gegenprobe: < 0,8 nicht geschrieben
        ->and((float) $nutri->sodium)->toBe(200.0)                                  // 0,5 g Salz × 400 = 200 mg Natrium
        ->and($nutri->source)->toBe('produktpass_ki')
        ->and($item->declarations)->toBeNull()                                      // Gegenprobe: Zusatzstoff ohne Bestätigung nicht geschrieben (Entscheid 16.10.)
        ->and((int) $item->shelf_life_dry_days)->toBe(365)
        ->and($item->origin)->toBeNull()->and($item->brand)->toBeNull();             // Herkunft/Marke: Vorschlag
    $marker = FoodAlchemistProduktpassFeld::where('supplier_item_id', $item->id)->get()->keyBy(fn ($f) => $f->objekt.'.'.$f->feld);
    expect($marker->keys()->sort()->values()->all())->toBe(['item_nutritional.energy_kcal', 'item_nutritional.sodium',
        'supplier_item.ingredients_supplier', 'supplier_item.shelf_life_dry_days'])
        ->and($marker['item_nutritional.sodium']->abgeleitet)->toBeTrue()->and($marker['item_nutritional.energy_kcal']->quelle)->toBe('produktpass_ki');

    // der alte Wert bleibt nachvollziehbar
    expect($pass->uebernommen['zutatenliste'])->toMatchArray(['alt' => null, 'neu' => 'Weizenmehl, Wasser']);

    // Vorschlag per Klick: auch der unsichere Wert (die Person entscheidet), Herkunft ebenso
    $pass = $this->svc->feldUebernehmen($this->rootTeam, $pass, 'naehrwerte.fat', $this->kurator->id);
    $pass = $this->svc->feldUebernehmen($this->rootTeam, $pass, 'herkunft', $this->kurator->id);
    expect((float) $this->stamm->fresh()->nutritionals->fat)->toBe(1.2)->and($this->stamm->fresh()->origin)->toBe('Deutschland')
        ->and(fn () => $this->svc->feldUebernehmen($this->rootTeam, $pass, 'haltbarkeit.unklar_days', $this->kurator->id))
        ->toThrow(RuntimeException::class, 'Lagerart')
        ->and(fn () => $this->svc->feldUebernehmen($this->rootTeam, $pass, 'zusatzstoffe.with_dye', $this->kurator->id))
        ->toThrow(RuntimeException::class, 'Kennzeichnung bestätigen');
    // der Zusatzstoff steht als Vorschlag mit Zitat-Feld bereit, nicht in den „Feldern"
    $stand = $this->svc->stand($this->stamm->fresh(), $pass);
    expect(collect($stand['zusatzstoffe'])->firstWhere('key', 'zusatzstoffe.with_dye'))->toMatchArray(['vorschlag' => 'ja', 'status' => 'vorschlag'])
        ->and(collect($stand['felder'])->pluck('key')->all())->not->toContain('zusatzstoffe.with_dye');
});

it('Gebinde: 660 g im Dokument = 0,660 kg am Artikel ist GLEICHWERTIG (kein Vorschlag); g/ml werden nie geschrieben, Menge und Einheit nur als Paar', function () {
    // der Fall von io (Mousse LA 319735): Pass liest „660 g", Artikel hat 0,660 kg
    $this->stamm->update(['qty' => 0.66, 'unit_code' => 'kg']);
    $pass = ($this->liesPass)(['felder' => ['gebinde.qty' => ['wert' => 660, 'einheit' => 'g', 'konfidenz' => 0.95], 'gebinde.unit_code' => ['wert' => 'g', 'konfidenz' => 0.95]]]);

    $stand = $this->svc->stand($this->stamm->fresh(), $pass);
    $menge = collect($stand['felder'])->firstWhere('key', 'gebinde.menge');
    expect($menge['status'])->toBe('gleichwertig')->and($menge['uebernehmbar'])->toBeFalse()
        ->and($menge['wert'])->toBe(0.66)->and($menge['einheit'])->toBe('kg')                       // in der Einheit der Preisrechnung
        ->and(collect($stand['felder'])->pluck('key')->all())->not->toContain('gebinde.unit_code')    // die Einheit wird NIE allein angeboten
        ->and(collect($stand['felder'])->pluck('key')->all())->not->toContain('gebinde.qty')
        ->and(fn () => $this->svc->feldUebernehmen($this->rootTeam, $pass, 'gebinde.menge', $this->kurator->id))->toThrow(RuntimeException::class, 'gleichwertig')
        ->and(fn () => $this->svc->feldUebernehmen($this->rootTeam, $pass, 'gebinde.unit_code', $this->kurator->id))->toThrow(RuntimeException::class, 'nicht im Vorschlag')
        ->and($this->stamm->fresh()->unit_code)->toBe('kg');                                       // nie auf „g" umgestellt
    expect($this->svc->uebernahmeVorschau($this->stamm->fresh(), $pass)['gleichwertig'])->toBe(1);
});

it('Gebinde direkt nur in einen LEEREN Artikel und in der Einheit der Preisrechnung: 660 g wird 0,66 kg, 500 ml wird 0,5 l, nie g/ml', function () {
    $ml = FoodAlchemistSupplierItem::create(['team_id' => null, 'supplier_id' => $this->stamm->supplier_id, 'designation' => 'Sahne ?', 'stamm_class' => StammPaket::PAKET]);

    ($this->liesPass)(['felder' => ['gebinde.qty' => ['wert' => 660, 'einheit' => 'g', 'konfidenz' => 0.95]]]);                     // Einheit steht nur an der Menge
    ($this->liesPass)(['felder' => ['gebinde.qty' => ['wert' => 500, 'konfidenz' => 0.95], 'gebinde.unit_code' => ['wert' => 'ml', 'konfidenz' => 0.95]]], $ml);

    expect((float) $this->stamm->fresh()->qty)->toBe(0.66)->and($this->stamm->fresh()->unit_code)->toBe('kg')
        ->and((float) $ml->fresh()->qty)->toBe(0.5)->and($ml->fresh()->unit_code)->toBe('l');
    // Preisrechnung versteht die Einheit
    expect(app(\Platform\FoodAlchemist\Services\PriceService::class)->vergleichspreis($this->stamm->fresh(), 3.3))->not->toBeNull();
});

it('Gebinde: gefüllter Artikel mit anderer Menge bleibt unangetastet, die abweichende Menge ist ein PAAR-Vorschlag; ohne erkennbare Einheit keine Menge; andere Dimension nie automatisch', function () {
    $voll = FoodAlchemistSupplierItem::create(['team_id' => null, 'supplier_id' => $this->stamm->supplier_id, 'designation' => 'Mehl 25kg', 'qty' => 25, 'unit_code' => 'kg', 'stamm_class' => StammPaket::PAKET]);
    $ohneEinheit = FoodAlchemistSupplierItem::create(['team_id' => null, 'supplier_id' => $this->stamm->supplier_id, 'designation' => 'Mehl ?', 'stamm_class' => StammPaket::PAKET]);
    $liter = FoodAlchemistSupplierItem::create(['team_id' => null, 'supplier_id' => $this->stamm->supplier_id, 'designation' => 'Öl ?', 'qty' => 5, 'unit_code' => 'l', 'stamm_class' => StammPaket::PAKET]);

    $passVoll = ($this->liesPass)(['felder' => ['gebinde.qty' => ['wert' => 5, 'einheit' => 'kg', 'konfidenz' => 0.95]]], $voll);
    $passOhne = ($this->liesPass)(['felder' => ['gebinde.qty' => ['wert' => 5, 'konfidenz' => 0.95]]], $ohneEinheit);       // keine Einheit
    $passLiter = ($this->liesPass)(['felder' => ['gebinde.qty' => ['wert' => 5, 'einheit' => 'kg', 'konfidenz' => 0.95]]], $liter);   // kg gegen l

    expect((float) $voll->fresh()->qty)->toBe(25.0)                                                  // Preisbasis unangetastet
        ->and($ohneEinheit->fresh()->qty)->toBeNull()->and($passOhne->status)->toBe('gelesen')       // nie eine Menge ohne Einheit, und kein Fehler beim Lesen
        ->and((float) $liter->fresh()->qty)->toBe(5.0)->and($liter->fresh()->unit_code)->toBe('l');   // andere Dimension: nichts automatisch
    // Klick auf den Vorschlag schreibt das PAAR zusammen (Menge UND Einheit)
    $this->svc->feldUebernehmen($this->rootTeam, $passVoll, 'gebinde.menge', $this->kurator->id);
    expect((float) $voll->fresh()->qty)->toBe(5.0)->and($voll->fresh()->unit_code)->toBe('kg');
    $this->svc->feldUebernehmen($this->rootTeam, $passLiter, 'gebinde.menge', $this->kurator->id);
    expect((float) $liter->fresh()->qty)->toBe(5.0)->and($liter->fresh()->unit_code)->toBe('kg');   // das Paar wandert gemeinsam
});

it('Verpackung: eine lange Beschreibung wird KEIN packaging_unit, sondern ein Hinweis-Vorschlag; nur ein bekannter Code geht direkt; nie SQL-Text zum Nutzer', function () {
    $lang = 'Delice-Kunststoffform mit Stülpdeckel';                                                    // 37 Zeichen: der Fall von io (SQLSTATE 1406)
    $pass = ($this->liesPass)(['felder' => ['gebinde.packaging_unit' => ['wert' => $lang, 'konfidenz' => 0.95]]]);

    $stand = $this->svc->stand($this->stamm->fresh(), $pass);
    expect($this->stamm->fresh()->packaging_unit)->toBeNull()
        ->and($pass->status)->toBe('gelesen')
        ->and(collect($stand['felder'])->pluck('key')->all())->toContain('verpackungshinweis')->not->toContain('gebinde.packaging_unit');
    $this->svc->feldUebernehmen($this->rootTeam, $pass, 'verpackungshinweis', $this->kurator->id);
    expect($this->stamm->fresh()->additional_text)->toBe($lang)->and($this->stamm->fresh()->packaging_unit)->toBeNull();
    // steht schon ein Hinweis da, wird er nicht überschrieben
    expect(fn () => $this->svc->feldUebernehmen($this->rootTeam, $pass, 'verpackungshinweis', $this->kurator->id))->toThrow(RuntimeException::class, 'schon ein Hinweis');

    // bekannter Code: direkt in ein leeres Feld
    $code = FoodAlchemistSupplierItem::create(['team_id' => null, 'supplier_id' => $this->stamm->supplier_id, 'designation' => 'Karton', 'stamm_class' => StammPaket::PAKET]);
    ($this->liesPass)(['felder' => ['gebinde.packaging_unit' => ['wert' => 'KRT', 'konfidenz' => 0.95]]], $code);
    expect($code->fresh()->packaging_unit)->toBe('KRT');

    // zu langer Wert für ein kurzes Feld: verständliche Meldung, kein SQL
    $herkunft = ($this->liesPass)(['felder' => ['herkunft' => ['wert' => str_repeat('x', 300), 'konfidenz' => 0.99]]]);
    try {
        $this->svc->feldUebernehmen($this->rootTeam, $herkunft, 'herkunft', $this->kurator->id);
        $meldung = '';
    } catch (RuntimeException $e) {
        $meldung = $e->getMessage();
    }
    expect($meldung)->toContain('zu lang')->not->toContain('SQL')->not->toContain('SQLSTATE');
});

it('„Alle Vorschläge übernehmen“: sichere Nicht-Kennzeichnungs-Felder; leere sofort, Abweichungen nur mit Bestätigung; Allergene und Zusatzstoffe nie', function () {
    $this->stamm->update(['origin' => 'Frankreich', 'ingredients_supplier' => 'Alt: Weizenmehl']);           // vorhandene Werte → Abweichung
    $pass = ($this->liesPass)(['felder' => [
        'zutatenliste' => ['wert' => 'Weizenmehl, Wasser', 'konfidenz' => 0.95],                         // überschreibt (nicht automatisch? direkt-Klasse läuft schon beim Lesen)
        'herkunft' => ['wert' => 'Deutschland', 'konfidenz' => 0.95],                                    // Vorschlag, Feld belegt → Abweichung
        'marke' => ['wert' => 'Hausmühle', 'konfidenz' => 0.95],                                         // Vorschlag, Feld leer
        'naehrwerte.fat' => ['wert' => 1.2, 'konfidenz' => 0.5],                                         // unsicher → bleibt Einzel-Vorschlag
        'zusatzstoffe.with_dye' => ['wert' => 'ja', 'konfidenz' => 0.99],                                // nie hier
    ], 'allergene' => ['milk' => ['wert' => 'enthalten', 'konfidenz' => 0.99]]]);
    $vor = $this->svc->uebernahmeVorschau($this->stamm->fresh(), $pass);
    expect($vor['leer'])->toBe(['marke'])->and($vor['abweichend'])->toBe(['herkunft']);

    // ohne Bestätigung der Abweichung: nur das leere Feld
    $b = $this->svc->alleUebernehmen($this->rootTeam, $pass, $this->kurator->id, false);
    expect($b['uebernommen'])->toBe(['marke'])->and($b['abweichend_offen'])->toBe(['herkunft'])
        ->and($this->stamm->fresh()->brand)->toBe('Hausmühle')->and($this->stamm->fresh()->origin)->toBe('Frankreich');

    // mit Bestätigung: auch die Abweichung
    $b = $this->svc->alleUebernehmen($this->rootTeam, $pass->refresh(), $this->kurator->id, true);
    expect($b['uebernommen'])->toBe(['herkunft'])->and($this->stamm->fresh()->origin)->toBe('Deutschland');

    $item = $this->stamm->fresh();
    expect((float) ($item->nutritionals?->fat ?? 0))->toBe(0.0)                                          // unsicher: nie dabei
        ->and($item->declarations)->toBeNull()->and($item->allergens)->toBeNull()                         // Kennzeichnung: nie dabei
        ->and($pass->refresh()->allergene_status)->toBe('vorschlag');
    // Websuche überschreibt nie, auch nicht mit Bestätigung
    $web = FoodAlchemistProduktpass::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $item->id, 'version' => 9, 'status' => 'gelesen', 'quelle' => 'websuche',
        'extrakt' => ['quelle' => 'websuche', 'felder' => ['herkunft' => ['wert' => 'Italien', 'konfidenz' => 0.9, 'url' => 'https://x.example/d']], 'allergene' => [], 'allergene_details' => [], 'verworfen' => []]]);
    $vw = $this->svc->uebernahmeVorschau($item, $web);
    expect($vw['abweichend'])->toBe([])->and($vw['uebersprungen'])->toBe(['herkunft']);
});

it('KENNZEICHNUNG (Allergene UND Zusatzstoffe) wird ohne Bestätigung nicht geschrieben; erst die Bestätigung trägt beides zusammen ein, bis dahin gilt der alte Stand', function () {
    DB::table('foodalchemist_item_allergens')->insert(['uuid' => (string) Symfony\Component\Uid\UuidV7::generate(), 'team_id' => null,
        'supplier_item_id' => $this->stamm->id, 'allergen_gluten' => 'enthalten', 'allergen_milk' => null, 'created_at' => now(), 'updated_at' => now()]);

    $pass = ($this->liesPass)(['allergene' => [
        'milk' => ['wert' => 'enthalten', 'konfidenz' => 0.95],
        'gluten' => ['wert' => 'nicht_enthalten', 'konfidenz' => 0.6],
    ], 'allergene_details' => ['wheat' => ['wert' => 'enthalten', 'konfidenz' => 0.9]],
        'felder' => ['zusatzstoffe.with_dye' => ['wert' => 'ja', 'konfidenz' => 0.95, 'zitat' => 'enthält Farbstoff E 150']]]);

    $zeile = FoodAlchemistItemAllergen::where('supplier_item_id', $this->stamm->id)->first();
    expect($pass->allergene_status)->toBe('vorschlag')
        ->and($zeile->allergen_milk)->toBeNull()                                   // Gegenprobe: nichts geschrieben
        ->and($zeile->allergen_gluten)->toBe('enthalten')
        ->and($zeile->source)->toBeNull()
        ->and($this->stamm->fresh()->declarations)->toBeNull();                    // Gegenprobe: Zusatzstoff ohne Bestätigung nicht geschrieben
    $stand = $this->svc->stand($this->stamm->fresh(), $pass);
    expect(collect($stand['allergene'])->firstWhere('key', 'milk'))->toMatchArray(['vorschlag' => 'enthalten', 'aktuell' => 'unbekannt']);

    $pass = $this->svc->kennzeichnungBestaetigen($this->rootTeam, $pass, $this->kurator->id);

    $zeile = $zeile->fresh();
    expect($pass->allergene_status)->toBe('bestaetigt')->and($pass->allergene_bestaetigt_von)->toBe($this->kurator->id)
        ->and($zeile->allergen_milk)->toBe('enthalten')
        ->and($zeile->allergen_gluten)->toBe('nicht_enthalten')                    // die Person bestätigt auch eine Lockerung
        ->and($zeile->source)->toBe('produktpass_ki')
        ->and($zeile->details)->toBe(['wheat' => 'enthalten'])
        ->and((int) $this->stamm->fresh()->declarations->with_dye)->toBe(3)->and($this->stamm->fresh()->declarations->source)->toBe('produktpass_ki')
        ->and(fn () => $this->svc->kennzeichnungBestaetigen($this->rootTeam, $pass, $this->kurator->id))->toThrow(RuntimeException::class, 'offenen');
});

it('MCP: Kennzeichnung bestätigen (alter Name ALLERGENE_BESTAETIGEN als Alias) nur für FA-Admins; produktpass.GET zeigt Stand + Vorschlag; POST legt eine Version an', function () {
    $reg = app(ToolRegistry::class);
    $pass = ($this->liesPass)(['allergene' => ['milk' => ['wert' => 'enthalten', 'konfidenz' => 0.95]]]);

    $kuratorCtx = new ToolContext($this->kurator, $this->rootTeam);
    $verboten = $reg->get('foodalchemist.produktpass.KENNZEICHNUNG_BESTAETIGEN')->execute(['produktpass_id' => $pass->id], $kuratorCtx);
    expect($verboten->success)->toBeFalse()->and($verboten->errorCode)->toBe('FORBIDDEN')
        ->and($pass->fresh()->allergene_status)->toBe('vorschlag');

    $get = $reg->get('foodalchemist.produktpass.GET')->execute(['supplier_item_id' => $this->stamm->id], $kuratorCtx);
    expect($get->success)->toBeTrue()->and($get->data['angezeigt']['allergene_status'])->toBe('vorschlag')
        ->and($get->data['allergene'][0])->toMatchArray(['key' => 'milk', 'vorschlag' => 'enthalten', 'aktuell' => 'unbekannt']);

    $ok = $reg->get('foodalchemist.produktpass.KENNZEICHNUNG_BESTAETIGEN')->execute(['produktpass_id' => $pass->id], new ToolContext($this->admin, $this->rootTeam));
    expect($ok->success)->toBeTrue()->and($pass->fresh()->allergene_status)->toBe('bestaetigt');

    // der alte Name wirkt weiter (zweite Version, ebenfalls Admin)
    $pass2 = ($this->liesPass)(['allergene' => ['soy' => ['wert' => 'spuren', 'konfidenz' => 0.9]]]);
    expect($reg->get('foodalchemist.produktpass.ALLERGENE_BESTAETIGEN')->execute(['produktpass_id' => $pass2->id], $kuratorCtx)->errorCode)->toBe('FORBIDDEN');
    expect($reg->get('foodalchemist.produktpass.ALLERGENE_BESTAETIGEN')->execute(['produktpass_id' => $pass2->id], new ToolContext($this->admin, $this->rootTeam))->success)->toBeTrue();
    // POST: Datei per base64
    $post = $reg->get('foodalchemist.produktpass.POST')->execute(['supplier_item_id' => $this->stamm->id,
        'datei' => ['name' => 'pass.pdf', 'base64' => 'data:application/pdf;base64,'.base64_encode('%PDF-1.4 test')]], $kuratorCtx);
    expect($post->success)->toBeTrue()->and($post->data['version'])->toBe(3);
    // Mandant am Stamm-Artikel: der Artikel ist sichtbar, hochladen aber nicht
    $mand = $reg->get('foodalchemist.produktpass.POST')->execute(['supplier_item_id' => $this->stamm->id,
        'datei' => ['name' => 'pass.pdf', 'base64' => base64_encode('%PDF-1.4 test')]], new ToolContext($this->ownerA, $this->childA));
    expect($mand->success)->toBeFalse()->and($mand->errorCode)->toBe('VALIDATION_ERROR');
});

it('Nichts raten: Was im Pass fehlt, bleibt leer; ungültige Werte, unbekannte Schlüssel und negative Zahlen fliegen raus; leere Antwort setzt keinen Allergen-Vorschlag', function () {
    $pass = ($this->liesPass)(['felder' => [
        'naehrwerte.protein' => ['wert' => 'viel', 'konfidenz' => 0.99],            // keine Zahl
        'naehrwerte.fat' => ['wert' => -3, 'konfidenz' => 0.99],                    // negativ
        'zutatenliste' => ['wert' => '  ', 'konfidenz' => 0.99],                    // leer
        'phantasie.feld' => ['wert' => 1, 'konfidenz' => 1],                        // unbekannt
        'zusatzstoffe.with_dye' => ['wert' => 'vielleicht', 'konfidenz' => 1],      // weder ja noch nein
    ], 'allergene' => ['gluten' => ['wert' => 'weiss_nicht', 'konfidenz' => 1]]]);

    $item = $this->stamm->fresh();
    expect($pass->extrakt['felder'])->toBe([])->and($pass->extrakt['allergene'])->toBe([])
        ->and($pass->allergene_status)->toBe('keine')
        ->and($item->nutritionals)->toBeNull()->and($item->declarations)->toBeNull()->and($item->ingredients_supplier)->toBeNull()
        ->and($item->allergens)->toBeNull()                                         // nie ein erfundenes nicht_enthalten
        ->and(FoodAlchemistProduktpassFeld::count())->toBe(0)
        ->and(count($pass->extrakt['verworfen']))->toBe(6);
});

it('Fehler beim Lesen: Version bekommt Status fehler mit Meldung statt eines Absturzes; erneut lesen stellt sie wieder in die Schlange', function () {
    $this->mock(AiGatewayService::class, fn ($m) => $m->shouldReceive('propose')->andThrow(new RuntimeException('Provider 503')));
    $pass = $this->svc->hochladen($this->rootTeam, $this->stamm, ($this->datei)(), $this->kurator->id);

    $pass = $this->svc->lesen($pass);

    expect($pass->status)->toBe('fehler')->and($pass->fehler)->toBe('Provider 503')
        ->and($this->stamm->fresh()->nutritionals)->toBeNull();
    Queue::fake();
    $pass = $this->svc->erneutLesen($this->rootTeam, $pass, $this->kurator->id);
    expect($pass->status)->toBe('hochgeladen')->and($pass->fehler)->toBeNull();
    Queue::assertPushed(ProduktpassLesenJob::class);
});

it('Neue Version ersetzt Werte der älteren NICHT von selbst, nur auf ausdrücklichen Befehl (Version übernehmen)', function () {
    $v1 = ($this->liesPass)(['felder' => ['naehrwerte.energy_kcal' => ['wert' => 350, 'konfidenz' => 0.95]]]);
    $v2 = ($this->liesPass)(['felder' => ['naehrwerte.energy_kcal' => ['wert' => 360, 'konfidenz' => 0.95]]]);

    expect((float) $this->stamm->fresh()->nutritionals->energy_kcal)->toBe(350.0)     // V2 liegt nur als Vorschlag da
        ->and($v2->uebernommen)->toBeNull();

    $this->svc->versionUebernehmen($this->rootTeam, $v2, $this->kurator->id);

    expect((float) $this->stamm->fresh()->nutritionals->energy_kcal)->toBe(360.0)
        ->and(FoodAlchemistProduktpassFeld::where('supplier_item_id', $this->stamm->id)->value('produktpass_id'))->toBe($v2->id);
});

it('Normalisierer: Websuche braucht je Feld und Allergen eine URL, sonst fliegt es raus', function () {
    $roh = ['felder' => ['zutatenliste' => ['wert' => 'Zucker', 'konfidenz' => 0.9, 'url' => 'https://hersteller.example/pass'],
        'herkunft' => ['wert' => 'Italien', 'konfidenz' => 0.9]],
        'allergene' => ['milk' => ['wert' => 'enthalten', 'konfidenz' => 0.9, 'url' => 'https://hersteller.example/pass'], 'soy' => ['wert' => 'enthalten', 'konfidenz' => 0.9]]];

    $web = ProduktpassNormalisierer::normalisiere($roh, 'websuche');
    $pass = ProduktpassNormalisierer::normalisiere($roh, 'produktpass');

    expect(array_keys($web['felder']))->toBe(['zutatenliste'])->and(array_keys($web['allergene']))->toBe(['milk'])
        ->and(array_keys($pass['felder']))->toBe(['zutatenliste', 'herkunft'])->and(array_keys($pass['allergene']))->toBe(['milk', 'soy']);
});

it('Websuche-Quelle: nie direkt; Übernahme nur per Klick und nur in LEERE Felder; Allergene nur bei „unbekannt“; Marker mit Quelle websuche', function () {
    $this->stamm->update(['origin' => 'Frankreich']);                                  // gefülltes Feld
    DB::table('foodalchemist_item_allergens')->insert(['uuid' => (string) Symfony\Component\Uid\UuidV7::generate(), 'team_id' => null,
        'supplier_item_id' => $this->stamm->id, 'allergen_gluten' => 'enthalten', 'created_at' => now(), 'updated_at' => now()]);
    $quelle = new class implements ProduktdatenQuelle {
        public function name(): string
        {
            return 'websuche';
        }

        public function liefere(FoodAlchemistSupplierItem $la, array $optionen = []): array
        {
            $u = 'https://hersteller.example/datenblatt';

            return ['felder' => ['zutatenliste' => ['wert' => 'Weizenmehl', 'konfidenz' => 0.99, 'url' => $u],
                'herkunft' => ['wert' => 'Deutschland', 'konfidenz' => 0.99, 'url' => $u],
                'zusatzstoffe.with_dye' => ['wert' => 'ja', 'konfidenz' => 0.99, 'url' => $u], 'zusatzstoffe.waxed' => ['wert' => 'nein', 'konfidenz' => 0.99, 'url' => $u]],
                'allergene' => ['gluten' => ['wert' => 'nicht_enthalten', 'konfidenz' => 0.9, 'url' => $u], 'milk' => ['wert' => 'enthalten', 'konfidenz' => 0.9, 'url' => $u]]];
        }
    };

    $pass = $this->svc->vorschlagAusQuelle($this->rootTeam, $this->stamm, $quelle, [], $this->kurator->id);

    // nie direkt: gar nichts geschrieben, trotz Konfidenz 0,99
    expect($pass->quelle)->toBe('websuche')->and($pass->context_file_id)->toBeNull()
        ->and($this->stamm->fresh()->ingredients_supplier)->toBeNull()->and(FoodAlchemistProduktpassFeld::count())->toBe(0);

    // Klick in ein leeres Feld: ok, mit Marker websuche
    $pass = $this->svc->feldUebernehmen($this->rootTeam, $pass, 'zutatenliste', $this->kurator->id);
    expect($this->stamm->fresh()->ingredients_supplier)->toBe('Weizenmehl')
        ->and(FoodAlchemistProduktpassFeld::where('feld', 'ingredients_supplier')->value('quelle'))->toBe('websuche');
    // gefülltes Feld: gesperrt
    expect(fn () => $this->svc->feldUebernehmen($this->rootTeam, $pass, 'herkunft', $this->kurator->id))->toThrow(RuntimeException::class, 'leere Felder');
    // Allergene: nur das unbekannte (milk), das bekannte (gluten) bleibt
    DB::table('foodalchemist_item_declarations')->insert(['uuid' => (string) Symfony\Component\Uid\UuidV7::generate(), 'team_id' => null,
        'supplier_item_id' => $this->stamm->id, 'waxed' => 3, 'created_at' => now(), 'updated_at' => now()]);   // „gewachst" ist am Artikel schon bekannt
    $this->svc->kennzeichnungBestaetigen($this->rootTeam, $pass, $this->kurator->id);
    $dekl = $this->stamm->fresh()->declarations;
    expect((int) $dekl->with_dye)->toBe(3)->and((int) $dekl->waxed)->toBe(3)->and($dekl->source)->toBe('websuche');   // bekannter Wert bleibt, unbekannter wird gefüllt
    $zeile = FoodAlchemistItemAllergen::where('supplier_item_id', $this->stamm->id)->first();
    expect($zeile->allergen_milk)->toBe('enthalten')->and($zeile->allergen_gluten)->toBe('enthalten')->and($zeile->source)->toBe('websuche');
});

it('UI: Panel zeigt gelesene Werte und Allergen-Vorschlag, Übernehmen/Bestätigen per Klick; Mandant sieht am Stamm-Artikel nur lesend', function () {
    $pass = ($this->liesPass)(['felder' => ['naehrwerte.fat' => ['wert' => 1.2, 'konfidenz' => 0.5], 'marke' => ['wert' => 'Hausmühle', 'konfidenz' => 0.95]],
        'allergene' => ['milk' => ['wert' => 'enthalten', 'konfidenz' => 0.95]]]);

    $this->actingAs($this->kurator);
    Livewire::test(ProduktpassPanel::class, ['itemId' => $this->stamm->id])
        ->assertSeeHtml('data-produktpass-upload')
        ->call('anzeigen', $pass->id)
        ->assertSeeHtml('data-produktpass-feld="naehrwerte.fat"')
        ->assertSeeHtml('data-produktpass-allergene-offen')
        ->call('feldUebernehmen', 'naehrwerte.fat')->assertSet('fehler', null)->assertDispatched('produktpass.geaendert')
        ->assertSeeHtml('data-produktpass-alle-uebernehmen')
        ->call('alleUebernehmen', false)->assertSet('fehler', null)->assertDontSeeHtml('data-produktpass-alle-uebernehmen')   // alles Sichere ist übernommen
        ->call('kennzeichnungBestaetigen')->assertSet('fehler', null)->assertSeeHtml('data-produktpass-allergene-bestaetigt');
    expect((float) $this->stamm->fresh()->nutritionals->fat)->toBe(1.2)->and($this->stamm->fresh()->brand)->toBe('Hausmühle')
        ->and($this->stamm->fresh()->allergens->allergen_milk)->toBe('enthalten');

    // Mandant (childA) am Master-Stamm-Artikel: sichtbar, aber ohne Schreib-Knöpfe, und Aktionen werden abgewiesen
    $this->actingAs($this->ownerA);
    Livewire::test(ProduktpassPanel::class, ['itemId' => $this->stamm->id])
        ->assertSeeHtml('data-produktpass-nur-lesen')
        ->assertDontSeeHtml('data-produktpass-upload')
        ->assertDontSeeHtml('data-produktpass-hochladen')
        ->call('verwerfen', $pass->id)->assertSet('fehler', fn ($f) => str_contains((string) $f, 'übergeordneten Katalog'));
    expect($pass->fresh()->status)->toBe('gelesen');
});


it('Bereits direkt übernommene Zusatzstoffe (vor der neuen Regel) bleiben stehen; die Bestätigung fasst sie nicht an', function () {
    $pass = ($this->liesPass)(['felder' => ['zusatzstoffe.with_dye' => ['wert' => 'ja', 'konfidenz' => 0.95], 'zusatzstoffe.waxed' => ['wert' => 'ja', 'konfidenz' => 0.95]]]);
    // Stand wie auf io (Mousse): with_dye wurde noch direkt geschrieben, samt uebernommen-Eintrag und Marker
    DB::table('foodalchemist_item_declarations')->insert(['uuid' => (string) Symfony\Component\Uid\UuidV7::generate(), 'team_id' => null,
        'supplier_item_id' => $this->stamm->id, 'with_dye' => 3, 'source' => 'produktpass_ki', 'created_at' => now(), 'updated_at' => now()]);
    $pass->update(['uebernommen' => ['zusatzstoffe.with_dye' => ['spalte' => 'with_dye', 'alt' => null, 'neu' => 3, 'abgeleitet' => false, 'konfidenz' => 0.95]]]);

    $this->svc->kennzeichnungBestaetigen($this->rootTeam, $pass->refresh(), $this->kurator->id);

    $dekl = $this->stamm->fresh()->declarations;
    expect((int) $dekl->with_dye)->toBe(3)->and((int) $dekl->waxed)->toBe(3)
        ->and($pass->refresh()->uebernommen['zusatzstoffe.with_dye']['alt'])->toBeNull();       // der alte Eintrag bleibt unverändert
});

it('Haltbarkeit im Artikel-Modal: sichtbar und editierbar, Quelle-Badge „aus dem Produktpass (KI)“, Validierung, MCP artikel.PUT und produktpass.GET führen sie mit', function () {
    ($this->liesPass)(['felder' => ['haltbarkeit.frozen_days' => ['wert' => 42, 'konfidenz' => 0.95]]]);
    $this->actingAs($this->kurator);

    $c = Livewire::test(ItemModal::class)->call('oeffnen', $this->stamm->id);
    $c->assertSet('haltbarkeit.shelf_life_frozen_days', 42)
        ->assertSeeHtml('data-sektion="haltbarkeit"')->assertSeeHtml('data-haltbarkeit-quelle')->assertSee('aus dem Produktpass (KI)');

    $c->set('haltbarkeit.shelf_life_chilled_days', '5')->set('haltbarkeit.shelf_life_frozen_days', '')->call('speichern')->assertHasNoErrors();
    $item = $this->stamm->fresh();
    expect((int) $item->shelf_life_chilled_days)->toBe(5)->and($item->shelf_life_frozen_days)->toBeNull();

    Livewire::test(ItemModal::class)->call('oeffnen', $this->stamm->id)->set('haltbarkeit.shelf_life_dry_days', 'viel')->call('speichern')->assertHasErrors(['haltbarkeit.shelf_life_dry_days']);

    // MCP: artikel.PUT schreibt die Spalten, produktpass.GET zeigt sie
    $reg = app(ToolRegistry::class);
    $put = $reg->get('foodalchemist.artikel.PUT')->execute(['id' => $this->stamm->id, 'felder' => ['shelf_life_dry_days' => 180]], new ToolContext($this->kurator, $this->rootTeam));
    expect($put->success)->toBeTrue()->and((int) $this->stamm->fresh()->shelf_life_dry_days)->toBe(180);
    $get = $reg->get('foodalchemist.produktpass.GET')->execute(['supplier_item_id' => $this->stamm->id], new ToolContext($this->kurator, $this->rootTeam));
    expect($get->data['haltbarkeit'])->toBe(['gekuehlt_tage' => 5, 'tiefgekuehlt_tage' => null, 'trocken_tage' => 180]);
});
