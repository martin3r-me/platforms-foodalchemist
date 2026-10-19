<?php

use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class);

/**
 * Spec 85 D-1 PR-A/B · Wächter: niemand liest den VK (`recipes.sales_net`/`sales_gross`) oder die Aufschlagsklasse
 * (`recipes.markup_class_id`, `->markupClass`) mehr aus der Rezept-Spalte. Die Klasse eines Gerichts ist die seiner
 * Standard-Darreichung ({@see \Platform\FoodAlchemist\Services\DarreichungResolver::klasseFuer()}, `$rezept->vk_klasse`).
 *
 * Preis-Wahrheit ist die Standard-Darreichung. Gelesen wird über die Preisleiter
 * ({@see \Platform\FoodAlchemist\Services\DarreichungResolver::vkNetto()}), den Accessor `$rezept->vk_netto`,
 * den Scope `mitStandardVk()` oder SQL an `standardPresentation`. Die Altspalten werden in PR-C gedroppt.
 *
 * Heuristik über `src/` und `resources/views/`: Rezept-Variablen (`$r`, `$recipe`, `$dish`, `->dish`, `$k`, …)
 * mit `->sales_net`/`->sales_gross` bzw. `->markupClass`/`->markup_class_id`. Erlaubt sind nur die Ausnahmen unten
 * und Zeilen mit der Markierung „Rückfall Altspalte bis PR-C“ — beides fällt mit PR-C weg.
 * Eager-Spaltenlisten `'…dish:…,sales_net'` sind in PR-A noch erlaubt: sie speisen nur den markierten Rückfall
 * der Preisleiter („Rückfall Altspalte bis PR-C“). PR-C nimmt sie heraus und ergänzt hier das Muster dafür.
 */
const SPEC85_VK_LESER_MUSTER = [
    '/(\$(r|rec|recipe|rezept|dish|gericht|k|g|gr|kand|eps)|->dish|->gericht|\[.gericht.\])\??->sales_(net|gross)\b/',
    '/(\$(r|rec|recipe|rezept|dish|gericht)|->dish|->recipe|->gericht)\??->(markupClass|markup_class_id)\b/',   // PR-B
];

const SPEC85_VK_LESER_MARKE = 'Rückfall Altspalte bis PR-C';

/** Pfad (relativ zum Modul) → Grund. */
const SPEC85_VK_LESER_AUSNAHMEN = [
    'src/Services/DarreichungResolver.php' => 'die Preisleiter selbst: Rückfall auf die Altspalte bis PR-C',
    'src/Services/MoneyTruthReportService.php' => 'Block B vergleicht Darreichung und Altspalte bis PR-C',
];

/** @return list<string> „pfad:zeile: code“ */
function spec85_vk_leser(?array $dateien = null): array
{
    $modul = dirname(__DIR__, 2);
    if ($dateien === null) {
        $dateien = [];
        foreach (['src', 'resources/views'] as $ordner) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($modul . '/' . $ordner, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $datei) {
                if ($datei->isFile() && $datei->getExtension() === 'php') {
                    $dateien[substr($datei->getPathname(), strlen($modul) + 1)] = file_get_contents($datei->getPathname());
                }
            }
        }
    }
    $treffer = [];
    foreach ($dateien as $pfad => $code) {
        if (isset(SPEC85_VK_LESER_AUSNAHMEN[$pfad])) {
            continue;
        }
        foreach (explode("\n", $code) as $i => $zeile) {
            $t = ltrim($zeile);
            if (str_starts_with($t, '*') || str_starts_with($t, '//') || str_starts_with($t, '/*') || str_contains($zeile, SPEC85_VK_LESER_MARKE)) {
                continue;   // Kommentare dürfen die Spalte erwähnen; markierte Rückfälle fallen mit PR-C weg
            }
            foreach (SPEC85_VK_LESER_MUSTER as $muster) {
                if (preg_match($muster, $zeile)) {
                    $treffer[] = $pfad . ':' . ($i + 1) . ': ' . trim($zeile);
                    break;
                }
            }
        }
    }

    return $treffer;
}

it('Spec 85 D-1 · kein Leser der VK-Altspalte recipes.sales_net außer den Ausnahmen', function () {
    $treffer = spec85_vk_leser();

    expect($treffer)->toBe([], 'VK aus der Standard-Darreichung lesen (DarreichungResolver::vkNetto, ->vk_netto, mitStandardVk()):'
        . PHP_EOL . implode(PHP_EOL, $treffer));
});

it('Spec 85 D-1 · der Wächter erkennt Rezept-Leser und lässt Darreichungen und Kommentare durch (Selbsttest)', function () {
    $treffer = spec85_vk_leser([
        'a.php' => '$vk = $recipe->sales_net;',
        'b.php' => '$preis = $gericht->sales_gross;',
        'c.blade.php' => '{{ $block->dish?->sales_net }}',
        'd.php' => '$vk = $dar->sales_net; $p = $standard->sales_net;',
        'kommentar.php' => '// früher $recipe->sales_net',
        'klasse.php' => '$code = $rezept->markupClass?->code;',
        'markiert.php' => '$alt = $recipe->markup_class_id;   // Spec 85 D-1: Rückfall Altspalte bis PR-C',
        'darreichung.php' => '$k = $standard->markupClass;',
        'src/Services/DarreichungResolver.php' => '$recipe->sales_net',
    ]);

    expect($treffer)->toHaveCount(4)
        ->and(implode("\n", $treffer))->toContain('a.php:1')->toContain('b.php:1')->toContain('c.blade.php:1')->toContain('klasse.php:1')
        ->not->toContain('d.php')->not->toContain('kommentar.php')->not->toContain('DarreichungResolver')
        ->not->toContain('markiert.php')->not->toContain('darreichung.php');
});
