<?php

use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Services\ConcepterAggregateService;
use Platform\FoodAlchemist\Services\PaketService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Deklarationsblatt für Konzepte und Pakete (Entscheid Dominique 2026-09-04).
 *
 * Anlass: der Tab zeigte einen reinen ALL-MAXIMAL-Rollup über alle Gerichte. Ein Gericht
 * mit Gluten machte das ganze Konzept „glutenhaltig" — mathematisch richtig, fachlich
 * wertlos, und rechtlich falsch adressiert: die Deklaration ist je SPEISE geschuldet.
 *
 * Drei Zusagen sind hier festgenagelt:
 *  1. Je Gericht eine Zeile mit Kennzeichnung (Allergen-Buchstaben + Zusatzstoff-Nummern)
 *  2. Übergeordnet nur Tags — als QUOTE, nicht als Alles-oder-nichts
 *  3. Nährwerte nur, wo sie stimmen: Summe bei Menü/Paket, Spanne bei Auswahl,
 *     und bei Lücken gar keine Zahl, sondern eine namentliche Aufgabenliste
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->pakete = app(PaketService::class);
    $this->concepts = app(ConceptService::class);
    $this->agg = app(ConcepterAggregateService::class);

    $mk = fn (array $attr) => FoodAlchemistRecipe::create(array_merge([
        'team_id' => $this->rootTeam->id, 'status' => 'approved', 'is_sales_recipe' => true,
    ], $attr));

    // A: vegan, glutenfrei, saubere Deklaration (Sellerie in Spuren), 250 g / 200 kcal
    $this->a = $mk([
        'recipe_key' => 'dek-a', 'name' => 'Green Power', 'sales_net' => 2.00, 'ek_total_eur' => 0.60,
        'sales_quantity_per_unit_g' => 250, 'nutri_kcal_per_100g' => 200, 'nutri_confidence' => 'high',
        'spec_is_vegan' => true, 'spec_is_vegetarian' => true, 'spec_is_gluten_free' => true,
        'allergens_confidence' => 'high',
        'allergen_celery' => 'spuren', 'allergen_gluten' => 'nicht_enthalten',
    ]);
    // B: Schwein, Gluten + Milch enthalten, mit Konservierungsstoff, 200 g / 150 kcal
    $this->b = $mk([
        'recipe_key' => 'dek-b', 'name' => 'Pulled Pork', 'sales_net' => 3.00, 'ek_total_eur' => 0.90,
        'sales_quantity_per_unit_g' => 200, 'nutri_kcal_per_100g' => 150, 'nutri_confidence' => 'medium',
        'spec_contains_pork' => true, 'allergens_confidence' => 'medium',
        'allergen_gluten' => 'enthalten', 'allergen_milk' => 'enthalten',
        'additive_with_preservative' => 3, 'additive_with_dye' => 1,
    ]);
    // C: die Lücke — kein Portionsgramm, kein Allergen-Profil
    $this->c = $mk([
        'recipe_key' => 'dek-c', 'name' => 'Mystery Dish', 'sales_net' => 5.50, 'ek_total_eur' => 1.50,
        'sales_quantity_per_unit_g' => null, 'nutri_kcal_per_100g' => null,
        'allergens_confidence' => 'none',
    ]);

    $this->blatt = function (array $gerichte, string $priceDisplay = 'gesamt') {
        $c = $this->concepts->create($this->rootTeam, ['name' => 'Deklarations-Konzept']);
        $c->update(['price_display' => $priceDisplay]);
        foreach ($gerichte as $i => $g) {
            $slot = $this->concepts->addSlot($this->rootTeam, $c->id, ['role' => 'Gang ' . ($i + 1)]);
            $this->concepts->fillSlot($this->rootTeam, $slot->id, [
                'sales_recipe_id' => $g['id'] ?? $g->id,
                'quantity' => $g['quantity'] ?? 1,
            ]);
        }

        return $this->agg->conceptDeklaration($c->refresh());
    };
});

// ── Zone: Deklaration je Gericht ─────────────────────────────────────────────

it('macht je distinktem Gericht EINE Zeile mit seinen eigenen Codes', function () {
    $blatt = ($this->blatt)([$this->a, $this->b]);

    expect($blatt['zeilen'])->toHaveCount(2);

    $namen = array_column($blatt['zeilen'], 'name');
    expect($namen)->toContain('Green Power')->and($namen)->toContain('Pulled Pork');

    $codes = collect($blatt['zeilen'])->keyBy('name');
    // Katalog-Reihenfolge = EU-Liste: A Gluten · B Krebstiere · C Eier · D Fisch ·
    // E Erdnüsse · F Soja · G Milch · H Schalenfrüchte · I Sellerie …
    // A hat Sellerie in Spuren → „I*"; B hat Gluten + Milch → „A", „G" + Zusatzstoff 2.
    expect($codes['Green Power']['codes'])->toBe(['I*'])
        ->and($codes['Pulled Pork']['codes'])->toBe(['A', 'G', '2']);
});

it('zaehlt ein doppelt eingesetztes Gericht als EINE Deklarationszeile', function () {
    // Zwei Slots, dasselbe Gericht: die Nährwert-Summe zählt doppelt (2 Portionen/Person),
    // die Deklaration nicht — sonst stünde dieselbe Speise zweimal im Aushang.
    $blatt = ($this->blatt)([$this->a, $this->a]);

    expect($blatt['zeilen'])->toHaveCount(1)
        ->and($blatt['quoten']['n'])->toBe(1);
});

it('fuehrt Zusatzstoffe mit — sie werden in Foodbook und Speisekarte deklariert', function () {
    $blatt = ($this->blatt)([$this->b]);

    // `with_preservative` ist der 2. Stoff der Liste → Code „2"; `with_dye` steht auf 1
    // (frei) und darf NICHT erscheinen.
    expect($blatt['zeilen'][0]['codes'])->toContain('2')
        ->and($blatt['zeilen'][0]['codes'])->not->toContain('1');

    $legende = array_column($blatt['legende']['zusatzstoffe'], 'label', 'code');
    expect($legende)->toHaveKey('2')
        ->and($legende['2'])->toBe('mit Konservierungsstoff')
        ->and($legende)->not->toHaveKey('1');                        // Legende nur real Vorkommendes
});

it('laedt die Kennzeichnungs-Spalten nach, die das Aggregat nicht mitbringt', function () {
    // Die eigentliche Falle: `recipeCols()` führt die 14 allergen_* und 18 additive_*
    // Spalten NICHT (sie würden jedes Concept-Aggregat verteuern). Ein nicht geladenes
    // Eloquent-Attribut liefert still `null` → `gerichtCodes()` hätte für JEDES Gericht
    // leere Codes gemeldet, ohne Fehler und ohne dass es jemandem auffällt.
    $blatt = ($this->blatt)([$this->b]);

    expect($blatt['zeilen'][0]['codes'])->not->toBe([])
        ->and($blatt['legende']['allergene'])->not->toBe([]);
});

// ── Zone: Tags als Quote ─────────────────────────────────────────────────────

it('zaehlt Eignungen als Quote statt als Alles-oder-nichts', function () {
    $blatt = ($this->blatt)([$this->a, $this->b, $this->c]);

    // Der alte Rollup meldete hier NICHTS („nicht vegan, nicht vegetarisch, nicht
    // glutenfrei"), weil er ALLE Gerichte verlangte. 1 von 3 ist die nützliche Aussage.
    expect($blatt['quoten']['n'])->toBe(3)
        ->and($blatt['quoten']['vegan'])->toBe(1)
        ->and($blatt['quoten']['vegetarisch'])->toBe(1)
        ->and($blatt['quoten']['glutenfrei'])->toBe(1)
        ->and($blatt['quoten']['halal'])->toBe(0);
});

it('nennt bei Schwein und Rind die betroffenen Gerichte namentlich', function () {
    $blatt = ($this->blatt)([$this->a, $this->b]);

    // Warnung mit Adresse: „enthält Schwein" ohne Angabe, welches Gericht, ist im
    // Kundengespräch unbrauchbar.
    expect($blatt['quoten']['schwein'])->toBe(['Pulled Pork'])
        ->and($blatt['quoten']['rind'])->toBe([]);
});

it('nennt das schwaechste Glied der Konfidenz beim Namen', function () {
    $blatt = ($this->blatt)([$this->a, $this->b]);

    expect($blatt['confidence'])->toBe('medium')                     // min(high, medium)
        ->and($blatt['schwaechstes'])->toBe('Pulled Pork');
});

// ── Zone: Nährwerte nur, wo sie stimmen ──────────────────────────────────────

it('rechnet bei Gesamtpreis den Beitrag pro Person — die Zeilen erklaeren die Summe', function () {
    $blatt = ($this->blatt)([$this->a, $this->b], 'gesamt');

    expect($blatt['modus'])->toBe('summe');

    $kcal = collect($blatt['zeilen'])->keyBy('name');
    // A: 200 kcal/100 g × 250 g = 500 · B: 150 × 200/100 = 300 → Summe 800 (= Aggregat)
    expect($kcal['Green Power']['kcal'])->toBe(500.0)
        ->and($kcal['Pulled Pork']['kcal'])->toBe(300.0);
});

it('rechnet bei Einzelpreisen je PORTION und liefert eine Spanne statt einer Summe', function () {
    // Auswahl à la carte: niemand isst alle Positionen. Eine Summe wäre sinnlos und
    // „Untergrenze" sogar falsch — es gibt keine untere Grenze, sondern eine Spanne.
    $blatt = ($this->blatt)([$this->a, $this->b], 'einzel');

    expect($blatt['modus'])->toBe('spanne')
        ->and($blatt['kcal_min'])->toBe(300.0)                       // Pulled Pork
        ->and($blatt['kcal_max'])->toBe(500.0)                       // Green Power
        ->and($blatt['kcal_schnitt'])->toBe(400.0);
});

it('meldet Luecken namentlich statt eine Summe ueber Loecher zu zeigen', function () {
    $blatt = ($this->blatt)([$this->a, $this->c]);

    expect($blatt['vollstaendig'])->toBeFalse()
        ->and($blatt['luecken'])->toHaveCount(1);

    $luecke = $blatt['luecken'][0];
    // „1 von 2 fehlt" ist eine Zahl; „Mystery Dish — Nährwerte, Portionsgramm,
    // Allergen-Profil" ist eine Arbeitsanweisung.
    expect($luecke['name'])->toBe('Mystery Dish')
        ->and($luecke['fehlt'])->toContain('Nährwerte')
        ->and($luecke['fehlt'])->toContain('Portionsgramm')
        ->and($luecke['fehlt'])->toContain('Allergen-Profil');
});

it('meldet ein vollstaendiges Blatt als vollstaendig', function () {
    $blatt = ($this->blatt)([$this->a, $this->b]);

    expect($blatt['vollstaendig'])->toBeTrue()
        ->and($blatt['luecken'])->toBe([]);
});

it('bleibt bei einem Konzept ohne Gerichte leer statt zu werfen', function () {
    $c = $this->concepts->create($this->rootTeam, ['name' => 'Leeres Konzept']);

    $blatt = $this->agg->conceptDeklaration($c->refresh());

    expect($blatt['quoten']['n'])->toBe(0)
        ->and($blatt['zeilen'])->toBe([])
        ->and($blatt['confidence'])->toBe('unknown')
        ->and($blatt['kcal_min'])->toBeNull();
});

// ── Paket ────────────────────────────────────────────────────────────────────

it('rechnet Pakete immer als Summe — ein Paket ist ein Gesamtpreis', function () {
    $p = $this->pakete->create($this->rootTeam, ['name' => 'Salad Wall', 'role' => 'Vorspeise', 'price_mode' => 'manuell']);
    $this->pakete->syncGerichte($this->rootTeam, $p->id, [
        ['sales_recipe_id' => $this->a->id], ['sales_recipe_id' => $this->b->id],
    ]);

    $blatt = $this->agg->paketDeklaration($p->refresh());

    expect($blatt['modus'])->toBe('summe')
        ->and($blatt['zeilen'])->toHaveCount(2)
        ->and($blatt['quoten']['schwein'])->toBe(['Pulled Pork']);
});

// ── Fläche ───────────────────────────────────────────────────────────────────

it('gibt Kennzeichnung und Legende auf der Concept-KARTE aus', function () {
    // Die Karte geht an den Kunden, trug aber keine Deklaration — anders als Foodbook,
    // Speisekarte und Speiseplan, die dieselbe Code-Quelle längst nutzen. Der technische
    // Report hatte sie schon (ausgeschrieben, `report-declaration`), nur die schöne
    // Kunden-Ausgabe nicht.
    $c = $this->concepts->create($this->rootTeam, ['name' => 'Karten-Konzept']);
    $slot = $this->concepts->addSlot($this->rootTeam, $c->id, ['role' => 'Hauptgang']);
    $this->concepts->fillSlot($this->rootTeam, $slot->id, ['sales_recipe_id' => $this->b->id]);

    $daten = app(\Platform\FoodAlchemist\Services\FoodbookService::class)
        ->conceptKarteDaten($this->rootTeam, $c->id);

    $gericht = collect($daten['gerichte'])->firstWhere('type', 'gericht');
    expect($gericht['codes'])->toBe(['A', 'G', '2'])                 // Gluten, Milch, Konservierungsstoff
        ->and(array_column($daten['legende']['allergene'], 'code'))->toBe(['A', 'G'])
        ->and(array_column($daten['legende']['zusatzstoffe'], 'code'))->toBe(['2']);
});

it('haelt die Karten-Blade auf derselben Kennzeichnungs-Konvention wie das Foodbook', function () {
    $karte = file_get_contents(__DIR__ . '/../../resources/views/dokumente/concept-karte.blade.php');

    expect($karte)->toContain('class="codes"')
        ->and($karte)->toContain('class="legende"')
        ->and($karte)->toContain('LMIV')
        // DomPDF-Leitplanke: kein Flex/Grid in den Druckstücken.
        ->and($karte)->not->toContain('display: flex')
        ->and($karte)->not->toContain('display:flex');
});

it('traegt die drei Zonen im Deklarations-Tab', function () {
    $editor = file_get_contents(__DIR__ . '/../../resources/views/livewire/concepter/editor.blade.php');

    expect($editor)->toContain('data-deklaration-quoten')           // Zone 1: Tags/Quoten
        ->and($editor)->toContain('data-deklaration-tabelle')       // Zone 2: je Gericht
        ->and($editor)->toContain('data-deklaration-legende')
        ->and($editor)->toContain('data-deklaration-luecken')       // Zone 3a: Aufgabenliste
        ->and($editor)->toContain('data-deklaration-spanne')        // Zone 3b: Auswahl
        ->and($editor)->toContain('data-deklaration-summe')         // Zone 3c: Menü/Paket
        // Der alte Alles-oder-nichts-Rollup ist weg — er war die Ursache der leeren Seite.
        ->and($editor)->not->toContain("aggregat['allergene']['is_vegan']")
        ->and($editor)->not->toContain('Werte sind eine Untergrenze');
});
