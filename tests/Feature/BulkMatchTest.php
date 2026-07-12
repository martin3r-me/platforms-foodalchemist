<?php

use Platform\FoodAlchemist\Models\FoodAlchemistMatchProposal;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Services\MatchService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * M3-11: Bulk-Match je Lieferant — Queue (tentative), Review-Entscheidungen,
 * Idempotenz, Lead-Neuwahl beim Übernehmen (GL-03 T3).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->svc = app(MatchService::class);
    $this->supplier = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'BOS Food']);

    $this->mkLa = function (string $bez, array $extra = [], $gp = null) {
        $la = FoodAlchemistSupplierItem::create([
            'team_id' => $this->rootTeam->id, 'supplier_id' => $this->supplier->id,
            'designation' => $bez, ...$extra,
        ]);
        FoodAlchemistSupplierItemStructure::create([
            'team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'gp_id' => $gp?->id,
        ]);

        return $la;
    };
});

it('Bulk-Lauf erzeugt nachvollziehbare Vorschläge (exact via EAN, fuzzy via Stem-Index) — DoD M3-11', function () {
    $limette = $this->makeGp($this->rootTeam, 'Limettensaft: konserviert');
    $tomate = $this->makeGp($this->rootTeam, 'Tomate: frisch');
    ($this->mkLa)('Limettensaft alt 1l', ['ean_packaging' => '4001'], $limette);   // gemappte EAN-Brücke

    $eanTreffer = ($this->mkLa)('Limettensaft neu 0,75l', ['ean_ordering' => '4001']);
    $fuzzyTreffer = ($this->mkLa)('Tomaten, frisch');                               // Stem: tomaten→tomat, F1 = 1.0
    ($this->mkLa)('Schraubenzieher 5mm');                                           // kein Lebensmittel ⇒ ohne Treffer
    ($this->mkLa)('Altware', ['is_discontinued' => true]);                          // discontinued ⇒ gar nicht geprüft

    $stats = $this->svc->bulkFuerLieferant($this->rootTeam, $this->supplier->id);

    expect($stats)->toBe(['geprueft' => 3, 'exact' => 1, 'fuzzy' => 1, 'ohne_treffer' => 1, 'uebersprungen' => 0]);

    $ean = FoodAlchemistMatchProposal::where('supplier_item_id', $eanTreffer->id)->firstOrFail();
    expect($ean->gp_id)->toBe($limette->id)
        ->and($ean->methode)->toBe('exact_ean')
        ->and($ean->status)->toBe('offen');

    $fuzzy = FoodAlchemistMatchProposal::where('supplier_item_id', $fuzzyTreffer->id)->firstOrFail();
    expect($fuzzy->gp_id)->toBe($tomate->id)
        ->and($fuzzy->methode)->toBe('fuzzy_name')
        ->and((float) $fuzzy->score)->toBeGreaterThanOrEqual(0.50);

    // Idempotenz: zweiter Lauf überspringt Items mit offenen Vorschlägen
    $zweiter = $this->svc->bulkFuerLieferant($this->rootTeam, $this->supplier->id);
    expect($zweiter['uebersprungen'])->toBe(2)
        ->and(FoodAlchemistMatchProposal::count())->toBe(2);
});

it('Übernehmen mappt die Struktur, zählt n_las_total hoch und triggert die Lead-Neuwahl (T3)', function () {
    $gp = $this->makeGp($this->rootTeam, 'Limettensaft: konserviert');
    ($this->mkLa)('Limettensaft alt', ['ean_packaging' => '4001'], $gp);
    $gp->update(['n_las_total' => 1]);
    $neu = ($this->mkLa)('Limettensaft neu', ['ean_ordering' => '4001']);

    $this->svc->bulkFuerLieferant($this->rootTeam, $this->supplier->id);
    $proposal = FoodAlchemistMatchProposal::where('supplier_item_id', $neu->id)->firstOrFail();

    $this->svc->uebernehmeVorschlag($this->rootTeam, $proposal->id);

    $gp->refresh();
    expect($proposal->fresh()->status)->toBe('akzeptiert')
        ->and(FoodAlchemistSupplierItemStructure::where('supplier_item_id', $neu->id)->value('gp_id'))->toBe($gp->id)
        ->and($gp->n_las_total)->toBe(2)
        ->and($gp->lead_la_supplier_item_id)->not->toBeNull();      // Neuwahl gelaufen

    // entschieden ⇒ dritter Lauf prüft das Item nicht mehr
    $stats = $this->svc->bulkFuerLieferant($this->rootTeam, $this->supplier->id);
    expect($stats['uebersprungen'])->toBe(0)->and($stats['geprueft'])->toBe(0);
});

it('Verwerfen lässt die Struktur unangetastet; verworfene Items werden neu geprüft', function () {
    $gp = $this->makeGp($this->rootTeam, 'Limettensaft: konserviert');
    ($this->mkLa)('Limettensaft alt', ['ean_packaging' => '4001'], $gp);
    $neu = ($this->mkLa)('Limettensaft neu', ['ean_ordering' => '4001']);

    $this->svc->bulkFuerLieferant($this->rootTeam, $this->supplier->id);
    $proposal = FoodAlchemistMatchProposal::where('supplier_item_id', $neu->id)->firstOrFail();
    $this->svc->verwerfeVorschlag($this->rootTeam, $proposal->id);

    expect($proposal->fresh()->status)->toBe('verworfen')
        ->and(FoodAlchemistSupplierItemStructure::where('supplier_item_id', $neu->id)->value('gp_id'))->toBeNull();

    $stats = $this->svc->bulkFuerLieferant($this->rootTeam, $this->supplier->id);
    expect($stats['geprueft'])->toBe(1);                            // verworfen blockiert NICHT (neuer Versuch erlaubt)
});

it('mehrdeutige EAN-Brücken (gleiche EAN → verschiedene GPs) werden NICHT als exact genutzt', function () {
    $gpA = $this->makeGp($this->rootTeam, 'Apfelsaft');
    $gpB = $this->makeGp($this->rootTeam, 'Birnensaft');
    ($this->mkLa)('Saft A', ['ean_packaging' => '4002'], $gpA);
    ($this->mkLa)('Saft B', ['ean_packaging' => '4002'], $gpB);     // gleiche EAN, anderes GP ⇒ unsicher
    ($this->mkLa)('Schraubenzieher XL', ['ean_ordering' => '4002']);

    $stats = $this->svc->bulkFuerLieferant($this->rootTeam, $this->supplier->id);

    expect($stats['exact'])->toBe(0);                               // Brücke verworfen, kein falsches Mapping
});

it('#4 Leak: fremdes Team kann einen Match-Vorschlag NICHT verwerfen (Cross-Team-Write geblockt)', function () {
    // Vorschlag gehoert Kind A; Geschwister Kind B darf ihn nicht anfassen.
    $item = FoodAlchemistSupplierItem::create([
        'team_id' => $this->childA->id, 'supplier_id' => $this->supplier->id, 'designation' => 'Fremd-LA',
    ]);
    $proposal = FoodAlchemistMatchProposal::create([
        'team_id' => $this->childA->id, 'supplier_item_id' => $item->id,
        'gp_id' => $this->makeGp($this->childA, 'Fremd-GP')->id,
        'score' => 0.9, 'band' => 'gruen', 'methode' => 'fuzzy_name', 'status' => 'offen',
    ]);

    expect(fn () => $this->svc->verwerfeVorschlag($this->childB, $proposal->id))
        ->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
    expect($proposal->fresh()->status)->toBe('offen');             // unangetastet

    $this->svc->verwerfeVorschlag($this->childA, $proposal->id);    // Eigentuemer darf
    expect($proposal->fresh()->status)->toBe('verworfen');
});
