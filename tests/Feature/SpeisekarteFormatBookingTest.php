<?php

use Platform\FoodAlchemist\Services\FormatService;
use Platform\FoodAlchemist\Services\SpeisekarteService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Format-Umbau F5: ein Format in eine Speisekarte buchen — WIE EIN CONCEPT (kein Live-Format-
 * Sonderweg). Das Format wird seine EIGENE Rubrik; die Editionen werden live menue_ref-
 * Positionen, die Struktur header/text/spacer — native Positions-Typen. Kein `format_id` an
 * der Rubrik. Deckt Struktur, die Slot→Positions-Abbildung + die Live-Referenz ab.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->sk = app(SpeisekarteService::class);
    $this->fmt = app(FormatService::class);

    $this->baueFormat = function ($team) {
        $dish1 = $this->makeRecipe($team, 'Menü A HG', ['is_sales_recipe' => true, 'sales_net' => 30.00]);
        $dish2 = $this->makeRecipe($team, 'Menü B HG', ['is_sales_recipe' => true, 'sales_net' => 28.00]);
        $c1 = $this->makeConcept($team, 'Menü A', ['consumer_name' => 'Genuss A', 'price_per_person_cache' => 42.50]);
        $this->makeConceptSlot($c1, ['sales_recipe_id' => $dish1->id, 'wording' => 'Rinderfilet']);
        $c2 = $this->makeConcept($team, 'Menü B', ['consumer_name' => 'Genuss B', 'price_per_person_cache' => 39.50]);
        $this->makeConceptSlot($c2, ['sales_recipe_id' => $dish2->id, 'wording' => 'Zanderfilet']);

        $format = $this->fmt->create($team, ['name' => 'CHEFS.CORNER', 'consumer_name' => 'Chefs Corner', 'claim' => 'World on a plate', 'story' => 'Die Welt auf dem Teller.']);
        $this->fmt->slotConceptEinfuegen($team, $format->id, $c1->id);
        $this->fmt->slotBlockEinfuegen($team, $format->id, 'header', ['title' => 'Unsere Menüs']);
        $this->fmt->slotConceptEinfuegen($team, $format->id, $c2->id);
        $this->fmt->slotBlockEinfuegen($team, $format->id, 'text', ['text_content' => 'Saisonal & regional.']);
        $this->fmt->slotBlockEinfuegen($team, $format->id, 'spacer', ['height' => 'gross']);

        return $format;
    };
});

it('bucht ein Format als eigene Rubrik mit menue_ref-Positionen je Edition (kein format_id)', function () {
    $format = ($this->baueFormat)($this->rootTeam);
    $karte = $this->sk->create($this->rootTeam, ['name' => 'Abendkarte']);

    $rubrik = $this->sk->insertFormatAlsRubrik($this->rootTeam, $karte->id, $format->id);

    // Identität aus dem Format; KEIN format_id (reine Standard-Rubrik).
    expect($rubrik->title)->toBe('CHEFS.CORNER')
        ->and($rubrik->consumer_title)->toBe('Chefs Corner')
        ->and($rubrik->claim)->toBe('World on a plate')
        ->and($rubrik->description)->toBe('Die Welt auf dem Teller.')
        ->and($rubrik->art)->toBe('menue')
        ->and($rubrik->format_id)->toBeNull();

    // Positionen in Slot-Reihenfolge: menue_ref, header, menue_ref, text, spacer.
    $positionen = $rubrik->items()->orderBy('position')->get();
    expect($positionen->pluck('type')->all())->toBe(['menue_ref', 'header', 'menue_ref', 'text', 'spacer']);

    // menue_ref-Positionen referenzieren die Editionen (live) — kein Snapshot.
    $menues = $positionen->where('type', 'menue_ref')->values();
    expect($menues)->toHaveCount(2)
        ->and($menues[0]->concept_id)->not->toBeNull();

    // Struktur-Positionen tragen den Format-Slot-Inhalt.
    expect($positionen->firstWhere('type', 'header')->label)->toBe('Unsere Menüs')
        ->and($positionen->firstWhere('type', 'text')->consumer_text)->toBe('Saisonal & regional.')
        ->and($positionen->firstWhere('type', 'spacer')->height)->toBe('gross');
});

it('die gebuchte Rubrik rendert die Editionen live im Dokument', function () {
    $format = ($this->baueFormat)($this->rootTeam);
    $karte = $this->sk->create($this->rootTeam, ['name' => 'Abendkarte']);
    $this->sk->insertFormatAlsRubrik($this->rootTeam, $karte->id, $format->id);

    $daten = $this->sk->dokumentDaten($this->rootTeam, $karte->fresh());
    $rubrik = collect($daten['rubriken'])->firstWhere('title', 'Chefs Corner');
    expect($rubrik)->not->toBeNull();
    $namen = collect($rubrik['positionen'])->where('typ', 'menue_ref')->pluck('name');
    expect($namen->all())->toContain('Menü A')->toContain('Menü B');
});

it('Status-Guard: in eine archivierte Karte ist kein Format mehr einfügbar', function () {
    $format = ($this->baueFormat)($this->rootTeam);
    $karte = $this->sk->create($this->rootTeam, ['name' => 'Alte Karte', 'status' => 'archiviert']);

    expect(fn () => $this->sk->insertFormatAlsRubrik($this->rootTeam, $karte->id, $format->id))
        ->toThrow(RuntimeException::class, 'archiviert');
});
