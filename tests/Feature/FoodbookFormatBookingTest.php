<?php

use Platform\FoodAlchemist\Services\FoodbookService;
use Platform\FoodAlchemist\Services\FormatService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Format-Umbau F5: ein Format ins Foodbook buchen — WIE EIN CONCEPT (kein Live-Format-
 * Sonderweg). Das Format wird sein EIGENES Kapitel; die Editionen werden live concept_ref-
 * Blöcke, die Struktur header_frei/text/spacer — alles live über die Kaskade. Kein `format_id`
 * am Kapitel. „Snapshot" erst beim Versand. Deckt Struktur, Live-Kaskade + die Guards ab.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->fb = app(FoodbookService::class);
    $this->fmt = app(FormatService::class);

    // Format mit 2 Concept-Editionen (je ein Gericht mit Kunden-Wording) + Header + Text-Struktur.
    $this->baueFormat = function ($team, array $formatAttr = []) {
        $dish1 = $this->makeRecipe($team, 'HG Rinderfilet', ['is_sales_recipe' => true, 'sales_net' => 30.00]);
        $dish2 = $this->makeRecipe($team, 'HG Zander', ['is_sales_recipe' => true, 'sales_net' => 28.00]);

        $c1 = $this->makeConcept($team, 'Sommer-Menü', ['consumer_name' => 'Sommergenuss', 'price_per_person_cache' => 42.50]);
        $this->slotC1 = $this->makeConceptSlot($c1, ['sales_recipe_id' => $dish1->id, 'wording' => 'Rinderfilet Rossini']);
        $c2 = $this->makeConcept($team, 'Winter-Menü', ['consumer_name' => 'Wintergenuss', 'price_per_person_cache' => 39.50]);
        $this->makeConceptSlot($c2, ['sales_recipe_id' => $dish2->id, 'wording' => 'Zander im Speckmantel']);

        $format = $this->fmt->create($team, array_merge([
            'name' => 'CHEFS.CORNER', 'consumer_name' => 'Chefs Corner', 'story' => 'Die Welt auf dem Teller.',
        ], $formatAttr));
        // Reihenfolge: Edition 1, Header, Edition 2, Text.
        $this->fmt->slotConceptEinfuegen($team, $format->id, $c1->id);
        $this->fmt->slotBlockEinfuegen($team, $format->id, 'header', ['title' => 'Unsere Editionen']);
        $this->fmt->slotConceptEinfuegen($team, $format->id, $c2->id);
        $this->fmt->slotBlockEinfuegen($team, $format->id, 'text', ['text_content' => 'Ein Wort zum Konzept.']);

        return $format;
    };
});

it('bucht ein Format als eigenes Kapitel mit live concept_ref/header/text-Blöcken (kein format_id)', function () {
    $format = ($this->baueFormat)($this->rootTeam);
    $buch = $this->makeFoodbook($this->rootTeam, 'Katalog 2027');

    $kapitel = $this->fb->insertFormatAlsKapitel($this->rootTeam, $buch->id, $format->id);

    // Identität aus dem Format; KEIN format_id (reines Standard-Kapitel, kein Sonderweg).
    expect($kapitel->title)->toBe('CHEFS.CORNER')
        ->and($kapitel->consumer_title)->toBe('Chefs Corner')
        ->and($kapitel->description)->toBe('Die Welt auf dem Teller.')
        ->and($kapitel->format_id)->toBeNull();

    // Blöcke in Slot-Reihenfolge: concept_ref, header_frei, concept_ref, text.
    $bloecke = $kapitel->blocks()->orderBy('position')->get();
    expect($bloecke->pluck('type')->all())->toBe(['concept_ref', 'header_frei', 'concept_ref', 'text']);

    // concept_ref-Blöcke referenzieren die Editionen (live) — kein Snapshot der Gerichte.
    $conceptRefs = $bloecke->where('type', 'concept_ref')->values();
    expect($conceptRefs)->toHaveCount(2)
        ->and($conceptRefs[0]->concept_id)->not->toBeNull();

    // Struktur-Blöcke tragen den Format-Slot-Inhalt (header/text im customer_text-Feld).
    expect($bloecke->firstWhere('type', 'header_frei')->customer_text)->toBe('Unsere Editionen')
        ->and($bloecke->firstWhere('type', 'text')->customer_text)->toBe('Ein Wort zum Konzept.');
});

it('rendert die Editionen LIVE im Dokument — Concept-Edit wirkt durch (Beweis: keine Snapshot)', function () {
    $format = ($this->baueFormat)($this->rootTeam);
    $buch = $this->makeFoodbook($this->rootTeam, 'Katalog 2027');
    $this->fb->insertFormatAlsKapitel($this->rootTeam, $buch->id, $format->id);

    // Dokument spiegelt das Gericht der ersten Edition über die Wording-Kette.
    $daten = $this->fb->dokumentDaten($this->rootTeam, $buch->fresh());
    $kap = collect($daten['kapitel'])->firstWhere('title', 'Chefs Corner');
    expect($kap)->not->toBeNull();
    $gerichte = collect($kap['bloecke'])->where('type', 'concept_ref')->flatMap(fn ($b) => collect($b['gerichte'])->pluck('text'));
    expect($gerichte->all())->toContain('Rinderfilet Rossini')->toContain('Zander im Speckmantel');

    // Concept-Slot-Wording live ändern → Foodbook zieht nach (Kaskade lebt, nichts eingefroren).
    $this->slotC1->update(['wording' => 'Rinderrücken vom Grill']);
    $danach = $this->fb->dokumentDaten($this->rootTeam, $buch->fresh());
    $kapDanach = collect($danach['kapitel'])->firstWhere('title', 'Chefs Corner');
    $gerichteDanach = collect($kapDanach['bloecke'])->where('type', 'concept_ref')->flatMap(fn ($b) => collect($b['gerichte'])->pluck('text'));
    expect($gerichteDanach->all())->toContain('Rinderrücken vom Grill')
        ->and($gerichteDanach->all())->not->toContain('Rinderfilet Rossini');
});

it('Kunden-IP-Guard: ein fremdes Kunden-Format geht nicht in ein Buch eines anderen Kunden', function () {
    $format = ($this->baueFormat)($this->rootTeam, ['origin' => 'kunde', 'customer' => 'Kunde Alpha']);
    $buch = $this->makeFoodbook($this->rootTeam, 'Buch Beta', ['customer' => 'Kunde Beta']);

    expect(fn () => $this->fb->insertFormatAlsKapitel($this->rootTeam, $buch->id, $format->id))
        ->toThrow(RuntimeException::class, 'Kunden-IP');

    // Gleicher Kunde ist erlaubt.
    $buchSelber = $this->makeFoodbook($this->rootTeam, 'Buch Alpha', ['customer' => 'Kunde Alpha']);
    $kapitel = $this->fb->insertFormatAlsKapitel($this->rootTeam, $buchSelber->id, $format->id);
    expect($kapitel->title)->toBe('CHEFS.CORNER');
});

it('Status-Guard: in ein archiviertes Buch ist kein Format mehr einfügbar', function () {
    $format = ($this->baueFormat)($this->rootTeam);
    $buch = $this->makeFoodbook($this->rootTeam, 'Altes Buch', ['status' => 'archiviert']);

    expect(fn () => $this->fb->insertFormatAlsKapitel($this->rootTeam, $buch->id, $format->id))
        ->toThrow(RuntimeException::class, 'archiviert');
});
