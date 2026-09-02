<?php

use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Services\IngredientMatchService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * DER 300er-KAPPUNGS-FEHLER — reproduziert.
 *
 * `gpPool()` holt `->orderBy('id')->limit(300)`: gekappt wird also nach ID, nicht nach
 * Relevanz. Solange die Anfrage schmal ist, fällt das nicht auf. Sobald Alias-/
 * Decompound-Erweiterung den Treffer-Raum über 300 aufbläht, verschwinden die HOHEN IDs —
 * und damit ausgerechnet die zuletzt kuratierten Grundprodukte. Je mehr Bestand gepflegt
 * wird, desto unsichtbarer wird das Neue.
 *
 * Gemessen auf demo (2026-09-03) über 28 Zutaten mit Erweiterung: 17 liefen ins Limit,
 * 15 verloren dabei exakte Kandidaten. »Sherryessig« verlor genau das eine richtige GP
 * («Sherryessig: konserviert», seit Juni im Bestand) und blieb im erzeugten Rezept
 * ungemappt. »Pflanzenöl« und »Rapsöl« verloren «Rapsoel / Pflanzenoel».
 *
 * Der Fix ist additiv: der breite Pool wird mit engen Sonden vereinigt (Original-Tokens +
 * längstes Einzel-Token). Scoring und Ranking bleiben unberührt.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->matcher = app(IngredientMatchService::class);

    // 320 Füll-GPs, die alle auf das Decompound-Token »essig« passen. Sie bekommen die
    // NIEDRIGEN IDs und würden das Limit allein ausschöpfen.
    $zeilen = [];
    for ($i = 1; $i <= 320; $i++) {
        $zeilen[] = [
            'uuid' => (string) \Symfony\Component\Uid\UuidV7::generate(),
            'team_id' => $this->rootTeam->id,
            'gp_key' => 'fuell|essig-' . $i,
            'name' => 'Fuellessig ' . $i . ': konserviert',
            'status' => 'approved', 'is_platzhalter' => false,
            'created_at' => now(), 'updated_at' => now(),
        ];
    }
    FoodAlchemistGp::insert($zeilen);

    // Das RICHTIGE GP zuletzt → höchste ID → fällt bei orderBy('id')->limit(300) heraus.
    $this->treffer = FoodAlchemistGp::create([
        'team_id' => $this->rootTeam->id, 'gp_key' => 'exakt|sherryessig',
        'name' => 'Sherryessig: konserviert', 'status' => 'approved', 'is_platzhalter' => false,
    ]);
});

it('der exakte Treffer überlebt die Pool-Kappung — auch mit der höchsten ID', function () {
    $k = $this->matcher->candidatesFor($this->rootTeam, 'Sherryessig', null, 5);
    $ids = array_map(static fn ($c) => $c['id'] ?? null, $k);

    // Ohne die engen Sonden ist der Kandidat hier NICHT dabei: das Decompounding
    // (»sherryessig« → »sherry essig«) zieht 320 Füll-GPs in den Pool, und die Kappung
    // nach id schneidet den einzigen richtigen ab.
    expect($ids)->toContain($this->treffer->id);
});

/*
 * EHRLICHKEIT ZUM AUSSAGEWERT: dieser Test ist auch OHNE den Fix grün — `matchIngredient`
 * nimmt in diesem Fixture einen anderen Weg als `candidatesFor` und findet den Treffer
 * trotzdem. Er bleibt als Verhaltens-Zusicherung stehen, aber der Test DARÜBER ist der,
 * der den Defekt nachweist (ohne Fix: „Failed asserting that an array contains 321").
 *
 * Auf demo verhielt sich `matchIngredient('Sherryessig')` anders — es lieferte target=none.
 * Der Unterschied zum Fixture ist nicht reproduziert; deshalb steht hier keine Behauptung
 * darüber, sondern nur die geprüfte Zusicherung.
 */
it('und er gewinnt auch die Entscheidung — nicht nur die Kandidatenliste', function () {
    $m = $this->matcher->matchIngredient($this->rootTeam, 'Sherryessig', null, 'hybrid');

    expect($m['target'])->toBe('gp')
        ->and($m['gp_id'])->toBe($this->treffer->id);
});

it('Gegenprobe: ohne Erweiterung bleibt alles wie bisher', function () {
    // »Fuellessig 7« hat keine Alias-/Decompound-Erweiterung, die den Pool aufbläht —
    // der Weg muss unverändert funktionieren, sonst hätte der Fix etwas verschoben.
    $k = $this->matcher->candidatesFor($this->rootTeam, 'Fuellessig 7', null, 5);

    expect($k)->not->toBeEmpty();
});
