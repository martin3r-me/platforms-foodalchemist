<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\ArtikelWebRecherche;
use Platform\FoodAlchemist\Services\Websuche\WebSucheTransport;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * ArtikelWebRecherche: nur Vorschläge, jede Angabe mit Quellen-URL aus den tatsächlich gefundenen Quellen, keine Auto-Speicherung,
 * Allergene nie abgeleitet. Fake-Transport statt echter Websuche (Core liefert sie noch nicht in chat()).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $lief = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => "D'Arta"]);
    $this->la = FoodAlchemistSupplierItem::create(['team_id' => $this->rootTeam->id, 'supplier_id' => $lief->id, 'article_number' => 'P15003F5E',
        'designation' => 'Salat, Bella Caio Tk', 'ean_packaging' => '5413408981041']);
    $this->mitTransport = function (array $antwort, array $quellen) {
        $this->kontext = null;
        $this->app->bind(WebSucheTransport::class, fn () => new class($antwort, $quellen, $this) implements WebSucheTransport {
            public function __construct(private array $a, private array $q, private $test) {}

            public function suche(string $anweisung, array $kontext): array
            {
                $this->test->kontext = [$anweisung, $kontext];

                return ['antwort' => $this->a, 'quellen' => $this->q];
            }
        });
    };
    $this->quellen = [['url' => 'https://www.darta.be/bella-ciao-caprese', 'title' => "D'Arta"], ['url' => 'https://shop.example/caprese', 'title' => 'Shop']];
});

it('★ liefert Vorschläge in der Produktpass-Form; Angaben ohne Quellen-URL, mit erfundener URL, unbekanntem Schlüssel oder ohne Wert fallen weg', function () {
    ($this->mitTransport)([
        'felder' => [
            'zutatenliste' => ['wert' => 'Nudeln, Tomate, Mozzarella, Pesto', 'konfidenz' => 0.9, 'url' => 'https://www.darta.be/bella-ciao-caprese', 'zitat' => 'Zutaten: …'],
            'herkunft' => ['wert' => 'Belgien', 'konfidenz' => 2.5, 'url' => 'https://shop.example/caprese'],       // Konfidenz wird begrenzt
            'marke' => ['wert' => 'Bella Ciao', 'url' => 'https://erfunden.example/x'],                              // URL nicht unter den Quellen
            'hersteller' => ['wert' => "D'Arta"],                                                                    // keine URL
            'unbekannter.schluessel' => ['wert' => 1, 'url' => 'https://shop.example/caprese'],                      // nicht erlaubt
            'ean' => ['wert' => '', 'url' => 'https://shop.example/caprese'],                                        // leerer Wert
        ],
        'allergene' => [
            'gluten' => ['wert' => 'enthalten', 'konfidenz' => 0.8, 'url' => 'https://www.darta.be/bella-ciao-caprese', 'zitat' => 'Enthält Weizen'],
            'milk' => ['wert' => 'nicht_enthalten', 'url' => 'https://shop.example/caprese'],                        // ohne Zitat: nie ableiten
            'eggs' => ['wert' => 'vielleicht', 'url' => 'https://shop.example/caprese', 'zitat' => 'x'],             // ungültiger Wert
            'kryptonit' => ['wert' => 'enthalten', 'url' => 'https://shop.example/caprese'],                         // kein EU-Allergen
        ],
        'deutung' => ['produkttyp' => ['wert' => 'Nudelsalat Caprese', 'url' => 'https://shop.example/caprese'], 'hauptzutat' => ['wert' => 'Nudelsalat', 'url' => 'https://www.darta.be/bella-ciao-caprese']],
    ], $this->quellen);

    $r = app(ArtikelWebRecherche::class)->liefere($this->la);

    expect($r['quelle'])->toBe('websuche')->and(array_keys($r['felder']))->toBe(['zutatenliste', 'herkunft'])
        ->and($r['felder']['herkunft']['konfidenz'])->toBe(1.0)
        ->and(array_keys($r['allergene']))->toBe(['gluten'])->and($r['allergene']['gluten']['zitat'])->toBe('Enthält Weizen')
        ->and($r['deutung']['produkttyp'])->toBe(['wert' => 'Nudelsalat Caprese', 'url' => 'https://shop.example/caprese'])
        ->and(array_column($r['quellen'], 'url'))->toBe(['https://www.darta.be/bella-ciao-caprese', 'https://shop.example/caprese']);
    foreach ([...$r['felder'], ...$r['allergene']] as $e) {
        expect($e['url'])->toStartWith('https://');           // jede Angabe zeigt ihre Quelle
    }
    expect($this->kontext[1])->toMatchArray(['bezeichnung' => 'Salat, Bella Caio Tk', 'lieferant' => "D'Arta", 'ean_gebinde' => '5413408981041'])
        ->and($this->kontext[0])->toContain('nie');           // Anweisung aus dem Prompt (rate nie)
});

it('★ speichert nichts: weder Artikel noch Allergene, keine DB-Schreibzugriffe', function () {
    ($this->mitTransport)(['felder' => ['herkunft' => ['wert' => 'Belgien', 'url' => 'https://shop.example/caprese']],
        'allergene' => ['gluten' => ['wert' => 'enthalten', 'url' => 'https://shop.example/caprese', 'zitat' => 'Weizen']]], $this->quellen);
    $vorher = $this->la->fresh()->toArray();
    $schreibt = 0;
    DB::listen(function ($q) use (&$schreibt) {
        if (preg_match('/^\s*(insert|update|delete)\b/i', $q->sql)) {
            $schreibt++;
        }
    });

    app(ArtikelWebRecherche::class)->liefere($this->la);

    expect($schreibt)->toBe(0)->and($this->la->fresh()->toArray())->toBe($vorher)
        ->and(DB::table('foodalchemist_item_allergens')->where('supplier_item_id', $this->la->id)->count())->toBe(0);
});

it('★ ohne Quellen oder ohne Belegbares: klare Meldung statt Erfindung', function () {
    ($this->mitTransport)(['felder' => ['herkunft' => ['wert' => 'Belgien', 'url' => 'https://shop.example/caprese']]], []);
    expect(fn () => app(ArtikelWebRecherche::class)->liefere($this->la))->toThrow(RuntimeException::class, 'Keine belegbaren Quellen');

    ($this->mitTransport)(['felder' => ['herkunft' => ['wert' => 'Belgien']]], $this->quellen);       // keine einzige belegte Angabe
    expect(fn () => app(ArtikelWebRecherche::class)->liefere($this->la))->toThrow(RuntimeException::class, 'nichts Belegbares');
});

it('Standard-Transport: bis der Core Websuche mit Quellen liefert, kommt eine lesbare Meldung', function () {
    expect(fn () => app(ArtikelWebRecherche::class)->liefere($this->la))->toThrow(RuntimeException::class, 'noch nicht freigeschaltet');
    expect(app(ArtikelWebRecherche::class)->name())->toBe('websuche');
});

it('Prompt artikel.web_recherche: url-Pflicht, nie raten, nicht_enthalten nur mit Zitat', function () {
    $p = config('foodalchemist.prompts')['artikel.web_recherche'];
    expect($p['task'])->toContain('url')->toContain('rate nie')->toContain('wörtlichem zitat')->and($p)->toHaveKey('tier');
});
