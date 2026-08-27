<?php

use Platform\FoodAlchemist\Services\FoodbookService;
use Platform\FoodAlchemist\Services\FormatService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Format-Buchung ins Foodbook (Dominique 2026-08-27): Format = SEKTION (Struktur-Kapitel,
 * is_struktur) + JE KONZEPT ein Unterkapitel mit einem live concept_ref-Block. header/text/
 * spacer werden Blöcke auf der Sektion. Kein `format_id`; live über die Kaskade; „Snapshot"
 * erst beim Versand. Deckt Struktur (Sektion + Unterkapitel), Live-Kaskade + die Guards ab.
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

it('bucht ein Format als Sektion (is_struktur) + je Konzept ein Unterkapitel', function () {
    $format = ($this->baueFormat)($this->rootTeam);
    $buch = $this->makeFoodbook($this->rootTeam, 'Katalog 2027');

    $sektion = $this->fb->insertFormatAlsKapitel($this->rootTeam, $buch->id, $format->id);

    // Sektion = Format-Identität, Struktur-Kapitel (kein eigenes Food), kein format_id.
    expect($sektion->title)->toBe('CHEFS.CORNER')
        ->and($sektion->consumer_title)->toBe('Chefs Corner')
        ->and($sektion->description)->toBe('Die Welt auf dem Teller.')
        ->and($sektion->format_id)->toBeNull()
        ->and($sektion->is_struktur)->toBeTrue();

    // Struktur-Slots (header/text) → Blöcke auf der Sektion; KEINE concept_ref-Blöcke auf der Sektion selbst.
    $sektionBloecke = $sektion->blocks()->orderBy('position')->get();
    expect($sektionBloecke->pluck('type')->all())->toBe(['header_frei', 'text'])
        ->and($sektionBloecke->firstWhere('type', 'header_frei')->customer_text)->toBe('Unsere Editionen')
        ->and($sektionBloecke->firstWhere('type', 'text')->customer_text)->toBe('Ein Wort zum Konzept.');

    // Je Konzept ein Unterkapitel (child der Sektion), Titel = Konzept-Name, mit genau einem concept_ref-Block.
    $unter = \Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel::where('parent_id', $sektion->id)->orderBy('position')->get();
    expect($unter->pluck('title')->all())->toBe(['Sommer-Menü', 'Winter-Menü']);
    $unter->each(function ($u) {
        $b = $u->blocks()->get();
        expect($b)->toHaveCount(1)
            ->and($b[0]->type)->toBe('concept_ref')
            ->and($b[0]->concept_id)->not->toBeNull();
    });
});

it('rendert die Editionen LIVE im Dokument (Unterkapitel je Konzept) — Concept-Edit wirkt durch', function () {
    $format = ($this->baueFormat)($this->rootTeam);
    $buch = $this->makeFoodbook($this->rootTeam, 'Katalog 2027');
    $this->fb->insertFormatAlsKapitel($this->rootTeam, $buch->id, $format->id);

    // Die Editionen sind jetzt Unterkapitel je Konzept — Gerichte über ALLE Kapitel einsammeln.
    $alleGerichte = fn ($daten) => collect($daten['kapitel'])
        ->flatMap(fn ($k) => collect($k['bloecke'])->where('type', 'concept_ref')
            ->flatMap(fn ($b) => collect($b['gerichte'])->pluck('text')));

    $vorher = $alleGerichte($this->fb->dokumentDaten($this->rootTeam, $buch->fresh()));
    expect($vorher->all())->toContain('Rinderfilet Rossini')->toContain('Zander im Speckmantel');

    // Concept-Slot-Wording live ändern → Foodbook zieht nach (Kaskade lebt, nichts eingefroren).
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
