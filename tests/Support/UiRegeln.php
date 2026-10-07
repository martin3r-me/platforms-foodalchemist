<?php

namespace Platform\FoodAlchemist\Tests\Support;

/**
 * fa-pass Welle 0 — Design-Regeln (Sperrklinke) für UiRegelnTest. Eigene Datei, weil Test-Helfer
 * im Parallel-Lauf nicht als globale Funktion in einer Testdatei liegen dürfen (fa_test.sh).
 */
final class UiRegeln
{
    public const REGELN = [
        // Farbe gehört in Tokens (--fa-*), nicht in die View.
        'hex_farbe' => '/#[0-9a-fA-F]{6}\b/',
        // Überschreib-Kaskaden sind der Grund, warum der alte Dunkel-Editor nicht wartbar war.
        'important' => '/!important|(?<=[\s"\'])![a-z][a-z0-9-]*[\[-]/',
        // Symbole kommen aus Heroicons, nicht als Emoji.
        'emoji' => '/[\x{1F300}-\x{1FAFF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}]/u',
        // Schriftskala: 12/13/14/16/24/28 — alles darunter ist unlesbar.
        'mini_schrift' => '/text-\[(?:8|9|10|11)px\]/',
        // Eigene <style>-Blöcke in Views umgehen die Tokens.
        'style_block' => '/<style\b/',
        // Inline-Stil nur für echte Laufzeitwerte (enthält {{ … }}).
        // (x-bind:style / :style sind gebundene Laufzeitwerte und zählen nicht.)
        'style_statisch' => '/(?<![:\w-])style="(?![^"]*\{\{)[^"]+"/',
    ];

    public static function zaehlen(): array
    {
        $basis = realpath(__DIR__ . '/../../resources/views');
        $ergebnis = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($basis, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $datei) {
            if (! str_ends_with($datei->getFilename(), '.blade.php')) {
                continue;
            }
            $pfad = str_replace($basis . DIRECTORY_SEPARATOR, '', $datei->getPathname());
            $inhalt = file_get_contents($datei->getPathname());
            // Blade-Kommentare zählen nicht (Doku darf Beispiele zeigen).
            $inhalt = preg_replace('/\{\{--.*?--\}\}/s', '', $inhalt);
            foreach (self::REGELN as $regel => $muster) {
                $anzahl = preg_match_all($muster, $inhalt);
                if ($anzahl > 0) {
                    $ergebnis[$pfad][$regel] = $anzahl;
                }
            }
        }
        ksort($ergebnis);

        return $ergebnis;
    }

    public static function baselinePfad(): string
    {
        return __DIR__ . '/../Fixtures/ui_regeln_baseline.json';
    }
}
