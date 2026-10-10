<?php

use Platform\FoodAlchemist\Support\BestandsPassung;
use Platform\FoodAlchemist\Support\RezeptTypVokabular;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Kuratorin 10.10.: RezeptTypVokabular::bezeichnung() schneidet ALLES vor dem Doppelpunkt ab — „Ciabatta: frisch" (GP-
 * Schreibweise) wird „frisch". In BestandsPassung fiel so die Variante weg: „Kalbsjus: dunkel" prüfte nur „dunkel" und
 * nahm ein generisches „Jus: dunkel" als passenden Bestand.
 */
beforeEach(fn () => $this->seedTeamHierarchy());

it('ohneTyp: schneidet nur einen Vokabular-Typ ab', function () {
    expect(RezeptTypVokabular::ohneTyp('Püree: Petersilienwurzel (grün)'))->toBe('Petersilienwurzel')
        ->and(RezeptTypVokabular::ohneTyp('Ciabatta: frisch'))->toBe('Ciabatta: frisch')
        ->and(RezeptTypVokabular::ohneTyp('Kalbsjus: dunkel (Haus)'))->toBe('Kalbsjus: dunkel')
        ->and(RezeptTypVokabular::ohneTyp('Toast'))->toBe('Toast');
});

it('BestandsPassung: eine Zeile in GP-Schreibweise behält ihre Variante', function () {
    expect(BestandsPassung::grund('Kalbsjus: dunkel', 'Jus: dunkel'))->not->toBeNull()
        ->and(BestandsPassung::grund('Kalbsjus: dunkel', 'Jus: Kalb, dunkel'))->toBeNull()
        ->and(BestandsPassung::grund('Püree: Petersilienwurzel', 'Püree: Petersilienwurzel'))->toBeNull();
});
