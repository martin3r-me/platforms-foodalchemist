<?php

use Platform\FoodAlchemist\Console\ImportMasterCommand;

/**
 * Deploy-Invariante (D1): import-master bewahrt globale Seed-Zeilen (team_id NULL)
 * — z. B. Pairing-Graph, globale Knowledge-Docs — statt sie aufs Ziel-Team zu stempeln.
 */
it('rewriteTeamId: globale (NULL) Zeile bleibt global, team-eigene → Ziel-Team', function () {
    expect(ImportMasterCommand::rewriteTeamId(null, 7))->toBeNull()   // global bleibt global
        ->and(ImportMasterCommand::rewriteTeamId(1, 7))->toBe(7)      // Sandbox-Team → Ziel
        ->and(ImportMasterCommand::rewriteTeamId(42, 7))->toBe(7);    // beliebiges Team → Ziel
});
