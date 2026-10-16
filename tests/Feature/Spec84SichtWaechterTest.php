<?php

use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class);

/**
 * Spec 84 · B0a · Statischer Sicht-Wächter (bis der automatische Filter B3 greift).
 *
 * Sucht in `src/Livewire` und `src/Tools` statische Abfragen auf Modellen mit `BelongsToTeamHierarchy`
 * (`Modell::find/where/query/…`), bei denen weder die Anweisung selbst eine Sicht-/Team-Einschränkung trägt
 * noch die folgenden Zeilen eine Eigentums-/Sicht-Prüfung machen. Das ist eine Heuristik: Viele Treffer sind
 * harmlos (Kind eines geprüften Elternteils, Service prüft danach). Darum zählt der Wächter je Datei und
 * vergleicht mit dem Bestand in `tests/Support/Spec84SichtAusnahmen.php`:
 *
 *   · Mehr Treffer als im Bestand (oder eine neue Datei) → rot. Neue Abfragen bitte mit `visibleToTeam()` /
 *     `TeamScope::applyVisible()` schreiben oder direkt danach mit `isOwnedBy()` prüfen.
 *   · Weniger Treffer → grün. Den Bestand dann senken (die Liste darf nur schrumpfen):
 *       SPEC84_WAECHTER_SCHREIBEN=1 vendor/bin/pest …/tests/Feature/Spec84SichtWaechterTest.php
 */
const SPEC84_SICHT_TOKEN = '/visibleToTeam|applyVisible|TeamScope::|isOwnedBy|ownedBy|mayWrite|team_id|teamId|mitFassungen|whereKey\(\$\w*(?:ids|Ids)\)/';
const SPEC84_PRUEF_TOKEN = '/isOwnedBy|ownedBy|mayWrite|TeamScope::|->owns\(|guard\w*\(|abort_unless|abort_if|authorize|team_id|visibleToTeam/';
const SPEC84_METHODEN = 'find|findOrFail|findMany|findOrNew|first|firstWhere|firstOrFail|where\w*|query|all|get|pluck|count|exists|withTrashed|onlyTrashed|latest|oldest|orderBy\w*|with|select|limit|cursor|chunk\w*|lazy\w*';

/** @return array<string, int> Datei (relativ zu src/) → Zahl der ungeprüften Abfragen */
function spec84_zaehle(): array
{
    $src = dirname(__DIR__, 2) . '/src';

    $modelle = [];
    foreach (glob($src . '/Models/*.php') as $datei) {
        $klasse = 'Platform\\FoodAlchemist\\Models\\' . basename($datei, '.php');
        if (class_exists($klasse) && in_array(BelongsToTeamHierarchy::class, class_uses_recursive($klasse), true)) {
            $modelle[basename($datei, '.php')] = $klasse;
        }
    }

    $treffer = [];
    foreach (['Livewire', 'Tools'] as $ordner) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src . '/' . $ordner));
        foreach ($it as $datei) {
            if (! $datei->isFile() || $datei->getExtension() !== 'php') {
                continue;
            }
            $code = file_get_contents($datei->getPathname());

            // Kurznamen der Trait-Modelle in dieser Datei (inkl. `use … as Alias`)
            $namen = [];
            foreach ($modelle as $kurz => $klasse) {
                if (preg_match('/^use\s+' . preg_quote($klasse, '/') . '(?:\s+as\s+(\w+))?\s*;/m', $code, $m)) {
                    $namen[] = $m[1] ?? $kurz;
                }
                if (str_contains($code, '\\' . $klasse . '::')) {   // voll qualifiziert, auch neben einem use
                    $namen[] = preg_quote('\\' . $klasse, '/');
                }
            }
            if ($namen === []) {
                continue;
            }

            $zeilen = explode("\n", $code);
            $n = 0;
            if (preg_match_all('/(?<![\w\\\\])(' . implode('|', $namen) . ')::(?:' . SPEC84_METHODEN . ')\(/', $code, $alle, PREG_OFFSET_CAPTURE)) {
                foreach ($alle[0] as [$text, $pos]) {
                    $ende = strpos($code, ';', $pos);
                    $anweisung = substr($code, $pos, ($ende === false ? strlen($code) : $ende) - $pos);
                    if (preg_match(SPEC84_SICHT_TOKEN, $anweisung)) {
                        continue;
                    }
                    $zeile = substr_count(substr($code, 0, $ende === false ? $pos : $ende), "\n");
                    $danach = implode("\n", array_slice($zeilen, $zeile + 1, 8));
                    if (preg_match(SPEC84_PRUEF_TOKEN, $danach)) {
                        continue;
                    }
                    $n++;
                }
            }
            if ($n > 0) {
                $treffer[$ordner . '/' . substr($datei->getPathname(), strlen($src . '/' . $ordner . '/'))] = $n;
            }
        }
    }
    ksort($treffer);

    return $treffer;
}

it('Spec 84 B0a · Sicht-Wächter: keine neuen ungeprüften Abfragen auf Team-Modellen in Livewire/ und Tools/', function () {
    $ist = spec84_zaehle();
    $bestandDatei = dirname(__DIR__) . '/Support/Spec84SichtAusnahmen.php';

    if (getenv('SPEC84_WAECHTER_SCHREIBEN')) {
        $zeilen = array_map(fn ($d, $n) => '    ' . var_export($d, true) . ' => ' . $n . ',', array_keys($ist), $ist);
        file_put_contents($bestandDatei, "<?php\n\n// Spec 84 B0a · Bestand des Sicht-Wächters (Spec84SichtWaechterTest). Darf nur schrumpfen.\n// Stand " . date('Y-m-d') . ': ' . array_sum($ist) . ' Treffer in ' . count($ist) . " Dateien.\n\nreturn [\n" . implode("\n", $zeilen) . "\n];\n");
    }

    $bestand = require $bestandDatei;
    $mehr = [];
    foreach ($ist as $datei => $n) {
        if ($n > ($bestand[$datei] ?? 0)) {
            $mehr[$datei] = ($bestand[$datei] ?? 0) . ' → ' . $n;
        }
    }

    expect($mehr)->toBe([], 'Neue Abfragen ohne Sicht-/Eigentums-Prüfung (Bestand → jetzt):' . PHP_EOL . print_r($mehr, true));
});

it('Spec 84 B0a · Sicht-Wächter erkennt eine ungeprüfte Abfrage und lässt geprüfte durch (Selbsttest der Heuristik)', function () {
    $roh = 'FoodAlchemistRecipe::find($id);' . "\n" . '$x = 1;';
    $sicht = 'FoodAlchemistRecipe::visibleToTeam($team)->find($id);';
    $geprueft = '$r = FoodAlchemistRecipe::find($id);' . "\n" . 'abort_unless($r->isOwnedBy($team), 403);';
    $muster = '/(?<![\w\\\\])(FoodAlchemistRecipe)::(?:' . SPEC84_METHODEN . ')\(/';

    expect(preg_match($muster, $roh))->toBe(1)
        ->and(preg_match(SPEC84_SICHT_TOKEN, 'FoodAlchemistRecipe::find($id)'))->toBe(0)
        ->and(preg_match(SPEC84_SICHT_TOKEN, $sicht))->toBe(1)
        ->and(preg_match(SPEC84_PRUEF_TOKEN, explode("\n", $geprueft)[1]))->toBe(1)
        ->and(array_sum(spec84_zaehle()))->toBeGreaterThan(0);   // der Scan findet überhaupt Modelle und Dateien
});
