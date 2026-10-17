<?php

use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class);

/**
 * Spec 84 · Wächter „Sichtbar ist nicht eigen" (Leitplanke D9, Fortsetzung von §4.0/B2).
 *
 * Sucht öffentliche Service-Methoden, die mit `visibleToTeam`/`applyVisible` laden, danach schreiben und dabei
 * KEINEN erkannten Eigentums-Riegel haben (isOwnedBy, GeerbtNurLesenException, canCurate, guard-, owned-, eigene-Helfer,
 * assertOwner, FaRechte, team_id-Vergleich …) — auch nicht in einer privaten/geschützten Hilfsmethode derselben
 * Klasse, die die Methode aufruft. Das ist eine Heuristik: Viele Treffer sind gewollt (Kopie ins eigene Team, eigene
 * Zeile mit Referenz auf eine sichtbare Zeile). Darum gibt es eine Bestandsliste mit Grund je Methode
 * (`tests/Support/Spec84ServiceEigentumAusnahmen.php`, Urteile aus docs/PLANUNG/84_B2_Pruefliste.md §4):
 *
 *   · Neue Methode, die nicht auf der Liste steht → rot. Entweder einen Riegel einbauen
 *     (`GeerbtNurLesenException::wennNichtEigen($zeile, $team, '…')`) oder mit fachlichem Grund in die Liste.
 *   · Steht eine Methode auf der Liste, ist aber kein Treffer mehr → grün (die Liste darf nur schrumpfen;
 *     Eintrag dann löschen).
 */
const SPEC84_EIGENTUM_LADEN = '/visibleToTeam|applyVisible/';
const SPEC84_EIGENTUM_SCHREIBEN = '/->update\(|->save\(|->delete\(|->forceDelete\(|::create\(|->create\(|updateOrInsert\(|updateOrCreate\(|firstOrCreate\(|->insert\(|->insertGetId\(|->upsert\(|->sync\(|->attach\(|->detach\(|->increment\(|->decrement\(|->fill\(|->restore\(/';
const SPEC84_EIGENTUM_RIEGEL = '/isOwnedBy|canCurate|GeerbtNurLesen|guard\w*\(|owned\w*\(|eigene\w*\(|assertOwner|darfAnkerPflegen|pruefeAnkerPflege|mayWrite|owns\(|where\(\s*\'team_id\'\s*,\s*\$(team|t)->id|team_id\s*(===|!==|==|!=)|FaRechte|rechte->pruefe|->pruefe\(|abort_unless|darfSchreiben|darfGlobalSchreiben|istMaster/';

/** @return list<string> „Services/Datei.php::methode" je Treffer */
function spec84_eigentum_treffer(): array
{
    $src = dirname(__DIR__, 2) . '/src/';
    $treffer = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src . 'Services'));
    foreach ($it as $datei) {
        if (! $datei->isFile() || $datei->getExtension() !== 'php') {
            continue;
        }
        // Kommentare und Docblocks entfernen — sonst zählt z. B. „{@see visibleToTeam}" im Docblock der nächsten Methode
        $code = '';
        foreach (token_get_all(file_get_contents($datei->getPathname())) as $t) {
            $code .= is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? str_repeat("\n", substr_count($t[1], "\n")) : $t[1]) : $t;
        }
        preg_match_all('/\n    (public|protected|private)(?: static)? function (\w+)\s*\(/', $code, $m, PREG_OFFSET_CAPTURE);
        $methoden = [];
        foreach ($m[2] as $i => [$name, $pos]) {
            $ende = $m[0][$i + 1][1] ?? strlen($code);
            $methoden[$name] = ['sicht' => $m[1][$i][0], 'rumpf' => substr($code, $m[0][$i][1], $ende - $m[0][$i][1])];
        }
        // Hilfsmethoden derselben Klasse mit Riegel (z. B. DarreichungService::find)
        $riegelHelfer = array_keys(array_filter($methoden, fn ($x) => $x['sicht'] !== 'public' && preg_match(SPEC84_EIGENTUM_RIEGEL, $x['rumpf'])));
        foreach ($methoden as $name => $x) {
            if ($x['sicht'] !== 'public' || ! preg_match(SPEC84_EIGENTUM_LADEN, $x['rumpf']) || ! preg_match(SPEC84_EIGENTUM_SCHREIBEN, $x['rumpf'])
                || preg_match(SPEC84_EIGENTUM_RIEGEL, $x['rumpf'])) {
                continue;
            }
            foreach ($riegelHelfer as $h) {
                if (str_contains($x['rumpf'], '$this->' . $h . '(')) {
                    continue 2;
                }
            }
            $treffer[] = substr($datei->getPathname(), strlen($src)) . '::' . $name;
        }
    }
    sort($treffer);

    return $treffer;
}

it('Spec 84 · Wächter: keine neue Service-Methode schreibt nach visibleToTeam ohne Eigentums-Riegel', function () {
    $ist = spec84_eigentum_treffer();
    $bestand = require dirname(__DIR__) . '/Support/Spec84ServiceEigentumAusnahmen.php';
    $neu = array_values(array_diff($ist, array_keys($bestand)));

    expect($neu)->toBe([], 'Schreibt nach visibleToTeam ohne Eigentums-Riegel — Riegel einbauen oder mit Grund in die Bestandsliste:' . PHP_EOL . implode(PHP_EOL, $neu));
});

it('Spec 84 · Wächter erkennt ein Leck, einen Riegel im Rumpf und einen Riegel in der Hilfsmethode (Selbsttest)', function () {
    $leck = "\$r = Rezept::visibleToTeam(\$team)->find(\$id);\n\$r->update(['x' => 1]);";
    $direkt = $leck . "\nGeerbtNurLesenException::wennNichtEigen(\$r, \$team, 'x');";

    expect((bool) preg_match(SPEC84_EIGENTUM_LADEN, $leck) && (bool) preg_match(SPEC84_EIGENTUM_SCHREIBEN, $leck))->toBeTrue()
        ->and((bool) preg_match(SPEC84_EIGENTUM_RIEGEL, $leck))->toBeFalse()
        ->and((bool) preg_match(SPEC84_EIGENTUM_RIEGEL, $direkt))->toBeTrue()
        ->and(spec84_eigentum_treffer())->not->toContain('Services/DarreichungService.php::aktualisieren')   // Riegel in find()
        ->and(count(spec84_eigentum_treffer()))->toBeGreaterThan(0);                                      // der Scan findet überhaupt etwas
});

it('Spec 84 · Bestandsliste: jeder Eintrag trägt einen fachlichen Grund', function () {
    $bestand = require dirname(__DIR__) . '/Support/Spec84ServiceEigentumAusnahmen.php';

    expect(array_filter($bestand, fn ($grund) => ! is_string($grund) || mb_strlen(trim($grund)) < 15))->toBe([]);
});
