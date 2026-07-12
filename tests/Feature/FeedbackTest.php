<?php

use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeFeedback;
use Platform\FoodAlchemist\Services\FeedbackService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * R2.6 — Praxis-Feedback (Küche/Kunde/Event) je Gericht/Basisrezept.
 * Aggregat on-read; D1-vertikale Sichtbarkeit (Kind sieht geerbtes, Eltern sieht
 * Kinder aggregiert, Geschwister isoliert).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->svc = app(FeedbackService::class);

    // Katalog-Gericht im Root → für childA/childB lesend sichtbar (D1-Vererbung).
    $this->gericht = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'g-katalog', 'name' => 'Katalog-Gericht',
        'status' => 'approved', 'is_sales_recipe' => true, 'sales_net' => 20.0,
    ]);
});

it('3 Einträge aus Küche/Kunde/Event → korrekter Ø + Verteilung je Quelle', function () {
    $this->svc->erstelle($this->childA, $this->gericht->id, ['quelle' => 'kueche', 'machbarkeit' => 5, 'geschmack' => 5, 'gaeste_reaktion' => 5, 'comment' => 'top']);
    $this->svc->erstelle($this->childA, $this->gericht->id, ['quelle' => 'kunde', 'score' => 3]);
    $this->svc->erstelle($this->childA, $this->gericht->id, ['quelle' => 'event', 'score' => 1, 'comment' => 'blieb stehen']);

    $agg = $this->svc->aggregat($this->childA, $this->gericht->id);

    expect($agg['count'])->toBe(3)
        ->and($agg['avg'])->toBe(3.0)                    // (5 + 3 + 1) / 3
        ->and($agg['per_source']['kueche'])->toBe(1)
        ->and($agg['per_source']['kunde'])->toBe(1)
        ->and($agg['per_source']['event'])->toBe(1);
});

it('Küchen-Score wird aus den Achsen gemittelt, wenn kein Gesamt-Score gesetzt ist', function () {
    $f = $this->svc->erstelle($this->childA, $this->gericht->id, [
        'quelle' => 'kueche', 'machbarkeit' => 4, 'aufwand' => 2, 'geschmack' => 5, 'gaeste_reaktion' => 3,
    ]);
    // Mittel aus machbarkeit/geschmack/gaeste_reaktion = (4+5+3)/3 = 4 (Aufwand fließt NICHT in den Score)
    expect($f->score)->toBe(4);
});

it('D1-Sichtbarkeit: Geschwister isoliert, Eltern sieht Kinder aggregiert', function () {
    $this->svc->erstelle($this->childA, $this->gericht->id, ['quelle' => 'kueche', 'score' => 4]);
    $this->svc->erstelle($this->childB, $this->gericht->id, ['quelle' => 'kunde', 'score' => 2]);

    // childA sieht NUR eigenes (+ geerbtes vom Root) — nicht childB (Geschwister)
    expect($this->svc->aggregat($this->childA, $this->gericht->id)['count'])->toBe(1);
    expect($this->svc->aggregat($this->childB, $this->gericht->id)['count'])->toBe(1);

    // Root (Eltern) sieht beide Kinder aggregiert
    $rootAgg = $this->svc->aggregat($this->rootTeam, $this->gericht->id);
    expect($rootAgg['count'])->toBe(2)
        ->and($rootAgg['avg'])->toBe(3.0);               // (4 + 2) / 2
});

it('Feedback ohne Score und ohne Kommentar wird abgelehnt', function () {
    expect(fn () => $this->svc->erstelle($this->childA, $this->gericht->id, ['quelle' => 'kunde']))
        ->toThrow(InvalidArgumentException::class);
});

it('aggregatBulk liefert Ø/Count je Rezept in einem Rutsch', function () {
    $this->svc->erstelle($this->childA, $this->gericht->id, ['quelle' => 'kunde', 'score' => 5]);
    $bulk = $this->svc->aggregatBulk($this->childA, [$this->gericht->id, 999999]);
    expect($bulk[$this->gericht->id]['avg'])->toBe(5.0)
        ->and($bulk[$this->gericht->id]['count'])->toBe(1)
        ->and($bulk)->not->toHaveKey(999999);            // Rezept ohne Feedback fehlt in der Map
});

it('Weiterentwickeln erzeugt eine Draft-Iteration mit Lineage aufs Feedback', function () {
    $f = $this->svc->erstelle($this->childA, $this->gericht->id, ['quelle' => 'kueche', 'geschmack' => 5, 'comment' => 'Basis für Winter-Variante']);
    $iteration = $this->svc->weiterentwickeln($this->childA, $f->id);

    expect($iteration->status->value)->toBe('draft')
        ->and($iteration->id)->not->toBe($this->gericht->id)
        ->and(FoodAlchemistRecipeFeedback::find($f->id)->spawned_recipe_id)->toBe($iteration->id);

    // idempotent: zweiter Aufruf gibt dieselbe Iteration
    expect($this->svc->weiterentwickeln($this->childA, $f->id)->id)->toBe($iteration->id);
});

it('löschen nur durch das Besitzer-Team (D1)', function () {
    $f = $this->svc->erstelle($this->childA, $this->gericht->id, ['quelle' => 'kunde', 'score' => 4]);
    // Root SIEHT das Kind-Feedback (vertikal), besitzt es aber nicht → Owner-Guard greift
    expect(fn () => $this->svc->loeschen($this->rootTeam, $f->id))->toThrow(RuntimeException::class);
    $this->svc->loeschen($this->childA, $f->id); // Besitzer darf
    expect(FoodAlchemistRecipeFeedback::withTrashed()->find($f->id)->trashed())->toBeTrue();
});
