<?php

/**
 * Food Alchemist Web Routes
 * 
 * Diese Datei definiert alle Web-Routes für das Modul.
 * 
 * WICHTIG FÜR LLMs:
 * - Routes werden automatisch mit dem Modul-Prefix versehen (aus Config)
 * - Middleware wird automatisch hinzugefügt (web, auth, etc.)
 * - Route-Namen sollten mit dem Modul-Prefix beginnen
 * 
 * BEISPIEL:
 * Route::get('/', Dashboard::class)->name('foodalchemist.dashboard');
 * 
 * Wird zu: /foodalchemist/ (wenn prefix = 'foodalchemist')
 * 
 * @see Platform\Core\Routing\ModuleRouter für Details
 */

use Platform\FoodAlchemist\Livewire\Dashboard;
use Platform\FoodAlchemist\Livewire\Test;
use Platform\FoodAlchemist\Livewire\Sidebar;

/**
 * Dashboard Route
 * 
 * Hauptübersicht des Moduls
 */
Route::get('/', Dashboard::class)->name('foodalchemist.dashboard');

/**
 * Test Route
 * 
 * Test-Seite für Entwicklung und Demonstration
 */
Route::get('/test', Test::class)->name('foodalchemist.test');

/**
 * Grundprodukte (Vertical Slice, D-3-Teil) — Model-Binding-Parameter = Modelname in camelCase
 * (Planner-Konvention).
 */
Route::get('/gps', \Platform\FoodAlchemist\Livewire\Gps\Index::class)
    ->name('foodalchemist.gps.index');

Route::get('/gps/{foodAlchemistGp}', \Platform\FoodAlchemist\Livewire\Gps\Show::class)
    ->name('foodalchemist.gps.show');

/**
 * Einstellungen (M1-01, D-1 §4) — Sektion in der URL (V-17: kein Tab-State-Verlust).
 */
Route::get('/einstellungen/{sektion?}', \Platform\FoodAlchemist\Livewire\Settings\Index::class)
    ->name('foodalchemist.einstellungen');
