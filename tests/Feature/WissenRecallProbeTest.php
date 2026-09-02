<?php

use Platform\FoodAlchemist\Console\WissenRecallProbeCommand;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class);

/**
 * Die Anfrage-Bildung ist der Kern des Probes: nur wenn die Begriffe WIRKLICH
 * ausschliesslich hinter dem Fenster stehen, misst er Findbarkeit jenseits des Fensters
 * und nicht bloss irgendeine Suche. Und nur wenn sie deterministisch ist, sind ein Lauf
 * vor und nach der Fenster-Änderung vergleichbar.
 */
function anfrage(string $inhalt, int $fenster): ?string
{
    $rm = (new ReflectionClass(WissenRecallProbeCommand::class))->getMethod('anfrageAusSchwanz');
    $rm->setAccessible(true);

    return $rm->invoke(new WissenRecallProbeCommand(), $inhalt, $fenster);
}

it('nimmt nur Begriffe, die im Kopf NICHT vorkommen', function () {
    $kopf = str_repeat('Vorspeise Suppenbasis Gemuesebruehe ', 20);          // > 200 Zeichen
    $schwanz = 'Pfifferlingsragout Steinpilzessenz Trueffelaufschlag';
    $a = anfrage($kopf . $schwanz, 200);

    expect($a)->not->toBeNull();
    foreach (['Pfifferlingsragout', 'Steinpilzessenz', 'Trueffelaufschlag'] as $t) {
        expect($a)->toContain($t);
    }
    // Kopf-Begriffe dürfen NICHT in die Anfrage — sonst würde der Treffer vom Kopf kommen
    // und der Probe würde das Gegenteil dessen messen, was er behauptet.
    foreach (['Vorspeise', 'Suppenbasis', 'Gemuesebruehe'] as $t) {
        expect($a)->not->toContain($t);
    }
});

it('ist deterministisch — zwei Läufe stellen dieselbe Frage', function () {
    $inhalt = str_repeat('Kopfinhalt ', 40) . 'Pfifferlingsragout Steinpilzessenz Trueffelaufschlag Kalbsjus';

    expect(anfrage($inhalt, 200))->toBe(anfrage($inhalt, 200));
});

it('gibt null, wenn der Schwanz keine brauchbaren Anker hat', function () {
    // Zu kurze Tokens (< 7 Zeichen) taugen nicht als Anfrage-Anker.
    expect(anfrage(str_repeat('Kopf ', 60) . 'und der die das', 200))->toBeNull();
    // Und ohne Schwanz sowieso.
    expect(anfrage('kurz', 2000))->toBeNull();
});

it('verwirft Begriffe, die im Kopf nur in anderer Schreibung stehen — Vergleich ist kleingeschrieben', function () {
    $a = anfrage(str_repeat('pfifferlingsragout ', 20) . 'PFIFFERLINGSRAGOUT Steinpilzessenz Trueffelaufschlag Kalbsjusreduktion', 200);

    expect($a)->not->toContain('PFIFFERLINGSRAGOUT')
        ->and($a)->toContain('Steinpilzessenz');
});
