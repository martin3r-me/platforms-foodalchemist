<?php

/**
 * 98 · 3.5 Schritt 6a C (Ausgabe): Nach Dominiques „fest 1“ (27.11.) braucht der EK je Portion (DarreichungResolver::ekProPortion,
 * PositionPreisService::blockPreis) die Spalte sales_unit_count nicht mehr. Die Eager-Loads der Ausgabe-Dienste laden sie deshalb nicht
 * mehr mit. Der Wächter hält das fest: Kommt das Spiegelfeld in einen dieser Selects zurück, ist es ein neuer Leser vor dem
 * Spalten-Drop (6c).
 */
it('Ausgabe-Dienste und Planungsblatt laden die Spiegelfelder sales_unit_count/sales_quantity_per_unit_g nicht mehr', function () {
    $modul = dirname(__DIR__, 2);
    $dateien = [
        'src/Services/Ausgabe/OfferCompositionService.php',
        'src/Services/Foodbook/FoodbookBasis.php',
        'src/Services/Foodbook/FoodbookDokument.php',
        'src/Services/Foodbook/FoodbookAggregat.php',
        'src/Services/Ausgabe/SpeisekarteService.php',
        'src/Services/Planung/LeitstelleService.php',
        'src/Services/Ausgabe/MenuEngineeringService.php',
        'src/Services/Planung/PlanungsblattService.php',   // 3.5-6a Folge: Selects nach B (Teiler fest 1) bereinigt
    ];
    $treffer = [];
    foreach ($dateien as $d) {
        foreach (explode("\n", (string) file_get_contents("{$modul}/{$d}")) as $i => $zeile) {
            $code = trim((string) preg_replace('~//.*$~', '', $zeile));   // Kommentare nennen das Feld als Herkunft, das ist erlaubt
            if (str_contains($code, 'sales_unit_count') || str_contains($code, 'sales_quantity_per_unit_g')) {
                $treffer[] = "{$d}:".($i + 1);
            }
        }
    }

    expect($treffer)->toBe([]);
});
