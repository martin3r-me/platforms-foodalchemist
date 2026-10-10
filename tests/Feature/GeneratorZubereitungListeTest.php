<?php

use Platform\FoodAlchemist\Services\RecipeGeneratorService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/** demo Lauf 93: Zubereitung kam als Liste → „Array to string conversion“, das Gericht scheiterte nach dem KI-Call. */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
});

it('Zubereitung als Liste wird nummerierter Text und ergibt Schritte', function () {
    $this->unitG($this->rootTeam);
    config(['foodalchemist.ai.provider' => 'fake']);
    $this->makeGp($this->rootTeam, 'Karotten: frisch, ganz');

    $r = app(RecipeGeneratorService::class)->generiere($this->rootTeam, 'Brühe: Karotte', [], [
        'name' => 'Brühe: Karotte',
        'zutaten' => [['text' => 'Karotten: frisch, ganz', 'quantity' => 1000, 'unit' => 'g']],
        'preparation' => ['Karotten waschen und grob schneiden.', ['text' => '2. Mit Wasser 40 Minuten simmern.']],
    ])['recipe'];

    expect($r->preparation)->toBe("1. Karotten waschen und grob schneiden.\n2. Mit Wasser 40 Minuten simmern.")
        ->and($r->steps()->count())->toBe(2);
});

it('zubereitungAlsText lässt Text stehen und macht Leeres zu null', function () {
    expect(RecipeGeneratorService::zubereitungAlsText('1. Kochen.'))->toBe('1. Kochen.')
        ->and(RecipeGeneratorService::zubereitungAlsText([]))->toBeNull()
        ->and(RecipeGeneratorService::zubereitungAlsText(['  ', ['foo' => 'bar']]))->toBeNull()
        ->and(RecipeGeneratorService::zubereitungAlsText(null))->toBeNull();
});
