<?php

use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * io 23.10. (PHP 8.5): „Using null as an array offset is deprecated“ in TeamSettingsService::kuechenTyp — ein Team ohne Küchentyp
 * schlug KUECHEN_TYPEN[null] nach. Ungesetzt liefert jetzt null, ohne die Tabelle zu fragen; gesetzt wie bisher.
 */
it('Team ohne Küchentyp: null, kein Nachschlagen mit null-Schlüssel; gesetzter Typ wie bisher', function () {
    $this->seedTeamHierarchy();
    $svc = app(TeamSettingsService::class);
    $meldungen = [];
    set_error_handler(function (int $nr, string $text) use (&$meldungen) { $meldungen[] = $text; return true; }, E_DEPRECATED | E_USER_DEPRECATED);
    try {
        expect($svc->kuechenTyp($this->rootTeam))->toBeNull()->and($svc->segment($this->rootTeam))->toBeNull();
    } finally {
        restore_error_handler();
    }
    expect($meldungen)->toBe([]);

    $typ = array_key_first(TeamSettingsService::KUECHEN_TYPEN);
    $svc->update($this->rootTeam, ['kitchen_type' => $typ]);
    expect($svc->kuechenTyp($this->rootTeam))->toBe($typ);
});
