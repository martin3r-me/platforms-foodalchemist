<?php

use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, \Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy::class);

/**
 * Spec 85 D-1 · Wächter: die VK-Altspalten am Rezept (`sales_net`, `sales_gross`, `vat_rate`, `markup_class_id`,
 * `markup_class_legacy_id`) sind seit PR-C gedroppt. Preis, MwSt-Profil und Aufschlagsklasse stehen an der
 * Standard-Darreichung: Preisleiter ({@see \Platform\FoodAlchemist\Services\DarreichungResolver::vkNetto()},
 * `klasseFuer()`), Accessoren `$rezept->vk_netto` / `$rezept->vk_klasse`, Scope `mitStandardVk()`.
 *
 * Heuristik über `src/` und `resources/views/`: Rezept-Variablen (`$r`, `$recipe`, `$dish`, `->dish`, `$k`, …) mit
 * `->sales_net`/`->sales_gross`/`->markupClass`/`->markup_class_id` sowie Eager-Spaltenlisten `'…dish:…,sales_net'`.
 * Ein Treffer heißt: Produktionscode, der auf io eine fehlende Spalte liest (SQL-Fehler bzw. still null).
 */
const SPEC85_VK_LESER_MUSTER = [
    '/(\$(r|rec|recipe|rezept|dish|gericht|k|g|gr|kand|eps)|->dish|->gericht|\[.gericht.\])\??->sales_(net|gross)\b/',
    '/(\$(r|rec|recipe|rezept|dish|gericht)|->dish|->recipe|->gericht)\??->(markupClass|markup_class_id)\b/',
    "/'[A-Za-z_.]*(dish|gericht|recipe):[^']*\\b(sales_net|sales_gross|vat_rate|markup_class_id)\\b/",
];

/** Pfad (relativ zum Modul) → Grund. Seit PR-C leer. */
const SPEC85_VK_LESER_AUSNAHMEN = [];

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
            if (str_starts_with($t, '*') || str_starts_with($t, '//') || str_starts_with($t, '/*')) {
                continue;   // Kommentare dürfen die Spalte erwähnen
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

it('Spec 85 D-1 · kein Leser der VK-Altspalten am Rezept in src/ und views', function () {
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
        'darreichung.php' => '$k = $standard->markupClass;',
        'eager.php' => "->with(['blocks.dish:id,name,sales_net'])",
        'eager_darr.php' => "->with(['standardPresentation:id,recipe_id,sales_net'])",
    ]);

    expect($treffer)->toHaveCount(5)
        ->and(implode("\n", $treffer))->toContain('a.php:1')->toContain('b.php:1')->toContain('c.blade.php:1')
        ->toContain('klasse.php:1')->toContain('eager.php:1')
        ->not->toContain('d.php')->not->toContain('kommentar.php')->not->toContain('darreichung.php')->not->toContain('eager_darr.php');
});

it('Spec 85 D-1 · die Altspalten sind aus foodalchemist_recipes gedroppt und kommen nicht zurück', function () {
    $this->seedTeamHierarchy();   // ohne Migration wäre jedes hasColumn false und die erste Hälfte wertlos grün
    expect(\Illuminate\Support\Facades\Schema::hasTable('foodalchemist_recipes'))->toBeTrue();
    foreach (['sales_net', 'sales_gross', 'vat_rate', 'markup_class_id', 'markup_class_legacy_id'] as $spalte) {
        expect(\Illuminate\Support\Facades\Schema::hasColumn('foodalchemist_recipes', $spalte))->toBeFalse($spalte);
    }
    expect(\Illuminate\Support\Facades\Schema::hasColumn('foodalchemist_recipe_presentations', 'sales_net'))->toBeTrue()
        ->and(\Illuminate\Support\Facades\Schema::hasColumn('foodalchemist_recipe_presentations', 'markup_class_id'))->toBeTrue();
});
