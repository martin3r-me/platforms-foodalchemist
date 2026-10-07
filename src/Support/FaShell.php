<?php

namespace Platform\FoodAlchemist\Support;

/**
 * Welche Hülle der Food Alchemist trägt (Zusammenführung fa-pass → main, 2026-10-06).
 *
 *  - `plattform` (Standard): FA läuft als Modul in einer Plattform-Host-App (demo, office) —
 *    Core-Layout (`platform::layouts.app`) mit Modul-Navigation und Core-Kopfzeile. Das FA-Design
 *    (Tokens, x-fa-Bausteine, Utilities) liefert das Modul selbst als fertiges CSS aus
 *    (`resources/dist/foodalchemist.css`, über `_platform/fa-assets`), geladen nur auf FA-Seiten.
 *  - `eigenstaendig`: FA ist die Plattform (Host `food-alchemist`) — eigene Hülle
 *    (`foodalchemist::layouts.standalone`), das CSS baut der Host aus den Quellen.
 *
 * Schalter: FOODALCHEMIST_SHELL (config foodalchemist.shell).
 */
final class FaShell
{
    public static function eigenstaendig(): bool
    {
        return config('foodalchemist.shell', 'plattform') === 'eigenstaendig';
    }

    /** Layout für ganze FA-Seiten (Livewire ->layout()). */
    public static function layout(): string
    {
        return self::eigenstaendig() ? 'foodalchemist::layouts.standalone' : 'platform::layouts.app';
    }

    /** URL des vom Modul ausgelieferten CSS (nur Plattform-Modus), mit Cache-Schlüssel. */
    public static function cssUrl(): ?string
    {
        $datei = dirname(__DIR__, 2).'/resources/dist/foodalchemist.css';
        if (! is_file($datei)) {
            return null;
        }

        return '/_platform/fa-assets/foodalchemist.css?v='.substr(md5((string) filemtime($datei)), 0, 8);
    }
}
