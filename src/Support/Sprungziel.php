<?php

namespace Platform\FoodAlchemist\Support;

use Illuminate\Support\Facades\Route;

/**
 * R5: Sprung-URLs auf Browser-Seiten mit #[Url]-Selektion (?gp= / ?rezept=).
 * Route-Namen existieren nur, wenn das Modul über den ModuleRouter gebootet
 * ist — in Tests (ohne `modules`-Tabelle) fällt das auf den Pfad zurück.
 */
final class Sprungziel
{
    public static function gp(int $id): string
    {
        return Route::has('foodalchemist.gps.index')
            ? route('foodalchemist.gps.index', ['gp' => $id])
            : '/foodalchemist/gps?gp=' . $id;
    }

    public static function rezept(int $id): string
    {
        return Route::has('foodalchemist.recipes.index')
            ? route('foodalchemist.recipes.index', ['rezept' => $id])
            : '/foodalchemist/rezepte?rezept=' . $id;
    }
}
