<?php

use Platform\FoodAlchemist\Services\FoodbookService;
use Platform\FoodAlchemist\Services\FormatService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Format-Buchung ins Foodbook (B, Dominique 2026-08-31): LEBENDES Format-Kapitel — EINE Sektion mit
 * `format_id` (Live-Referenz), KEINE Einmal-Expansion in Unterkapitel/Blöcke mehr. Identität + Editionen
 * + Struktur werden zur Render-Zeit LIVE aus dem Format aufgelöst ({@see FoodbookService::dokumentDaten});
 * Concept-Edits UND Zusammensetzungs-Änderungen wirken durch. Deckt Buchung, Live-Render + die Guards ab.
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

it('bucht ein Format als Sektion mit format_id (Live-Ref, keine Unterkapitel/Blöcke)', function () {
    $format = ($this->baueFormat)($this->rootTeam);
    $buch = $this->makeFoodbook($this->rootTeam, 'Katalog 2027');

    $sektion = $this->fb->insertFormatAlsKapitel($this->rootTeam, $buch->id, $format->id);

    // Sektion = Format-Identität, Struktur-Kapitel, MIT format_id (Live-Referenz aufs Format).
    expect($sektion->title)->toBe('CHEFS.CORNER')
        ->and($sektion->consumer_title)->toBe('Chefs Corner')
        ->and($sektion->description)->toBe('Die Welt auf dem Teller.')
        ->and((int) $sektion->format_id)->toBe((int) $format->id)
        ->and($sektion->is_struktur)->toBeTrue();

    // B: KEINE Expansion mehr — genau EIN Kapitel (die Sektion), keine Unterkapitel, keine Blöcke.
    expect(\Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel::where('foodbook_id', $buch->id)->count())->toBe(1)
        ->and($sektion->blocks()->count())->toBe(0);
});

it('rendert die Editionen LIVE aus dem Format (ist_format) — Concept-Edit UND Zusammensetzung wirken durch', function () {
    $format = ($this->baueFormat)($this->rootTeam);
    $buch = $this->makeFoodbook($this->rootTeam, 'Katalog 2027');
    $this->fb->insertFormatAlsKapitel($this->rootTeam, $buch->id, $format->id);

    // Editionen liegen im ist_format-Row unter 'editionen' (concept-Editionen tragen die Gericht-Zeilen).
    $alleGerichte = function ($daten) {
        $row = collect($daten['kapitel'])->firstWhere('ist_format', true);

        return collect($row['editionen'] ?? [])->where('typ', 'concept')->flatMap(fn ($e) => collect($e['gerichte'])->pluck('text'));
    };

    $vorher = $alleGerichte($this->fb->dokumentDaten($this->rootTeam, $buch->fresh()));
    expect($vorher->all())->toContain('Rinderfilet Rossini')->toContain('Zander im Speckmantel');

    // Concept-Slot-Wording live ändern → Foodbook zieht nach (Live-Ref, nichts eingefroren).
    $this->slotC1->update(['wording' => 'Rinderrücken vom Grill']);
    $danach = $alleGerichte($this->fb->dokumentDaten($this->rootTeam, $buch->fresh()));
    expect($danach->all())->toContain('Rinderrücken vom Grill')
        ->and($danach->all())->not->toContain('Rinderfilet Rossini');
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
