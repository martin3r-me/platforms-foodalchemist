<?php

use Platform\FoodAlchemist\Services\FormatService;
use Platform\FoodAlchemist\Services\ReportExportService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * F3b: Technischer Format-Report — die ZWEITE Format-Ausgabe neben der schönen `formate.dokument`-
 * Karte. Deckt ReportExportService::formatDaten (Identität + Editionen über die IDENTISCHE Concept-
 * Report-Auflösung + Filter-Weiterreichung) und die Route/Blade `dokumente.report`-Format-Zweig ab.
 * Spiegelt bewusst FormatDruckTest (pretty) + ConceptKarteTest (Report/Karte-Trennung).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->svc = app(FormatService::class);
    $this->report = app(ReportExportService::class);

    // Format mit einer Concept-Edition (Gericht + interne Notiz) + Struktur (Header/Text).
    $this->baueFormat = function ($team) {
        $dish = $this->makeRecipe($team, 'HG Rinderfilet', [
            'is_sales_recipe' => true,
            'sales_net' => 30.00,
            'notes_manual' => 'Sonderhinweis Mise en Place',
        ]);
        $concept = $this->makeConcept($team, 'Sommer-Menü', [
            'kind' => 'concept', 'consumer_name' => 'Sommergenuss', 'claim' => 'Leicht & frisch',
            'description' => 'Ein sommerliches Menü.', 'price_per_person_cache' => 42.50,
        ]);
        $this->makeConceptSlot($concept, ['sales_recipe_id' => $dish->id, 'wording' => 'Rinderfilet Rossini']);

        $format = $this->svc->create($team, ['name' => 'CHEFS.CORNER', 'claim' => 'WORLD ON A PLATE', 'story' => 'Die Welt auf dem Teller.']);
        $this->svc->slotConceptEinfuegen($team, $format->id, $concept->id);
        $this->svc->slotBlockEinfuegen($team, $format->id, 'header', ['title' => 'Unsere Editionen']);
        $this->svc->slotBlockEinfuegen($team, $format->id, 'text', ['text_content' => 'Ein Wort zum Konzept.']);

        return $format;
    };
});

it('formatDaten liefert Format-Identität + Editionen (über den Concept-Report) + Preisspanne', function () {
    $format = ($this->baueFormat)($this->rootTeam);

    $optionen = $this->report->optionen(['profil' => 'voll'], 'format');
    $data = $this->report->formatDaten($this->rootTeam, $format->id, $optionen);

    // Diskriminator schaltet den Format-Zweig der Report-Blade.
    expect($data['typ'])->toBe('format')
        ->and($data['recipe'])->toBeNull()
        ->and($data['concept'])->toBeNull();

    // Identität.
    expect($data['format']['name'])->toBe('CHEFS.CORNER')
        ->and($data['format']['consumer_name'])->toBeNull()
        ->and($data['format']['claim'])->toBe('WORLD ON A PLATE')
        ->and($data['format']['story'])->toBe('Die Welt auf dem Teller.');

    // Preisspanne read-only über die Editionen (nur das eine Concept).
    expect($data['format']['price_range']['min'])->toBe(42.5)
        ->and($data['format']['price_range']['max'])->toBe(42.5);

    // Positionen in Reihenfolge: erst die Edition, dann Header, dann Text.
    expect(collect($data['format']['positionen'])->pluck('kind')->all())->toBe(['edition', 'header', 'text']);

    // Die Edition trägt die volle Concept-Report-Auflösung (Übersicht + Slots + Gericht-Node).
    $edition = collect($data['format']['positionen'])->firstWhere('kind', 'edition');
    expect($edition['concept']['name'])->toBe('Sommer-Menü')
        ->and((float) $edition['concept']['price_per_person_cache'])->toBe(42.5);

    $node = $edition['concept']['slots'][0]['gerichte'][0]['recipe'];
    expect($node['name'])->toBe('HG Rinderfilet')
        ->and($node['is_sales_recipe'])->toBeTrue();
});

it('formatDaten reicht die Filter an jede Edition durch (Sensorik-Toggle wirkt auf den Gericht-Node)', function () {
    $format = ($this->baueFormat)($this->rootTeam);

    $knoten = function (array $optionen) use ($format) {
        $data = $this->report->formatDaten($this->rootTeam, $format->id, $optionen);
        $edition = collect($data['format']['positionen'])->firstWhere('kind', 'edition');

        return $edition['concept']['slots'][0]['gerichte'][0]['recipe'];
    };

    // Sensorik aus → im Node null; Sensorik an → gebaut (nicht null) — identisch zum Concept-Report.
    $aus = $knoten($this->report->optionen(['profil' => 'voll', 'sensorik' => '0'], 'format'));
    $an = $knoten($this->report->optionen(['profil' => 'voll', 'sensorik' => '1'], 'format'));

    expect($aus['sensorik'])->toBeNull()
        ->and($an['sensorik'])->not->toBeNull();
});

it('der Format-Report (Route + Blade) rendert Profil-Leiste, Filter-Chips, Edition + Struktur', function () {
    $format = ($this->baueFormat)($this->rootTeam);
    $this->actingAs($this->makeUser($this->rootTeam, 'Report User'));

    $this->get(route('foodalchemist.formate.report', ['id' => $format->id, 'profil' => 'voll']))
        ->assertOk()
        // SELBE Profil-Leiste + SELBER Filter-Satz wie der Concept-Report.
        ->assertSee('Report-Profile')
        ->assertSee('Volle Kaskade')
        ->assertSee('Sensorik')
        ->assertSee('Deklaration')
        ->assertSee('Kaskade')
        // Format-Inhalt.
        ->assertSee('CHEFS.CORNER')
        ->assertSee('Format-Übersicht')
        ->assertSee('Sommer-Menü')          // Edition (Concept-Report-Körper)
        ->assertSee('HG Rinderfilet')        // aufgelöster Gericht-Node
        ->assertSee('Unsere Editionen')      // Header-Struktur-Block
        ->assertSee('Ein Wort zum Konzept.');// Text-Struktur-Block
});

it('der Notizen-Filter greift auch im Format-Report (Notiz erscheint nur wenn an)', function () {
    $format = ($this->baueFormat)($this->rootTeam);
    $this->actingAs($this->makeUser($this->rootTeam, 'Notiz User'));

    // notizen=1 → interne Notiz des Gerichts erscheint.
    $this->get(route('foodalchemist.formate.report', ['id' => $format->id, 'profil' => 'voll', 'notizen' => 1]))
        ->assertOk()
        ->assertSee('Sonderhinweis Mise en Place');

    // notizen=0 → dieselbe Notiz ist raus (Filter identisch zum Concept-Report).
    $this->get(route('foodalchemist.formate.report', ['id' => $format->id, 'profil' => 'voll', 'notizen' => 0]))
        ->assertOk()
        ->assertDontSee('Sonderhinweis Mise en Place');
});

it('der Format-Report ist team-gescoped (fremdes Format → 404)', function () {
    $fremd = ($this->baueFormat)($this->childB);
    $this->actingAs($this->makeUser($this->childA, 'Kind A'));

    $this->get(route('foodalchemist.formate.report', ['id' => $fremd->id]))->assertNotFound();
});
