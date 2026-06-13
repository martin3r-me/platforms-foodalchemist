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
use Platform\FoodAlchemist\Livewire\Sidebar;

/**
 * Dashboard Route
 * 
 * Hauptübersicht des Moduls
 */
Route::get('/', Dashboard::class)->name('foodalchemist.dashboard');


/**
 * Grundprodukte (Vertical Slice, D-3-Teil) — Model-Binding-Parameter = Modelname in camelCase
 * (Planner-Konvention).
 */
Route::get('/gps', \Platform\FoodAlchemist\Livewire\Gps\Browser::class)
    ->name('foodalchemist.gps.index');

/** M3-12: Alt-Routen der Vertical-Slice-Ära → Redirect in den Browser (Kontext via ?gp=). */
Route::get('/gps/liste', fn () => redirect()->route('foodalchemist.gps.index'))
    ->name('foodalchemist.gps.liste');

Route::get('/gps/{foodAlchemistGp}', fn (\Platform\FoodAlchemist\Models\FoodAlchemistGp $foodAlchemistGp) => redirect()
    ->route('foodalchemist.gps.index', ['gp' => $foodAlchemistGp->id]))
    ->name('foodalchemist.gps.show');


/**
 * Basisrezepte (M4-04, P-1) — Auswahl/Filter in der URL (Kontext-Erhalt).
 */
Route::get('/rezepte', \Platform\FoodAlchemist\Livewire\Recipes\Browser::class)
    ->name('foodalchemist.recipes.index');

/**
 * Lieferanten-Browser (M2-01, P-7) — Auswahl + Suche in der URL (V-17/Kontext-Erhalt).
 */
Route::get('/lieferanten', \Platform\FoodAlchemist\Livewire\Suppliers\Index::class)
    ->name('foodalchemist.suppliers.index');

/**
 * Einstellungen (M1-01, D-1 §4) — Sektion in der URL (V-17: kein Tab-State-Verlust).
 */
Route::get('/einstellungen/{sektion?}', \Platform\FoodAlchemist\Livewire\Settings\Index::class)
    ->name('foodalchemist.einstellungen');

/**
 * Verkaufsrezepte (M6-03, D-6 §4.1) — VK-Sicht aufs geteilte Rezept-Modell,
 * Auswahl/Filter in der URL (V-17/Kontext-Erhalt).
 */
Route::get('/gerichte', \Platform\FoodAlchemist\Livewire\Verkauf\Browser::class)
    ->name('foodalchemist.verkauf.index');

/**
 * M10 / Doc 15 §M10: Concepter — Pakete (bepreiste Bündel mehrerer Gerichte)
 * und Concepts (Slot-Gerüst über mehrere Rollen). Kontext/Auswahl in der URL (V-17).
 */
/**
 * M10R-2 / Doc 15 §10.2: vereinheitlichter Concepter-Browser (Concepts | Pakete
 * in EINEM Screen, 3-Panel im VK-Stil). /concepts + /pakete bleiben transitorisch
 * (Editor), bis das Voll-Editor-Modal (M10R-3) steht.
 */
Route::get('/concepter', \Platform\FoodAlchemist\Livewire\Concepter\Browser::class)
    ->name('foodalchemist.concepter.index');

Route::get('/concepts', \Platform\FoodAlchemist\Livewire\Concepts\Index::class)
    ->name('foodalchemist.concepts.index');

Route::get('/pakete', \Platform\FoodAlchemist\Livewire\Pakete\Index::class)
    ->name('foodalchemist.pakete.index');

/**
 * M11: Foodbook / Portfolio — stellt Concepts zu Kunden-Angeboten zusammen.
 */
Route::get('/foodbooks', \Platform\FoodAlchemist\Livewire\Foodbooks\Index::class)
    ->name('foodalchemist.foodbooks.index');

/**
 * M12: Kalkulations-Übersicht (HK1/HK2/Vollkosten-DB).
 */
Route::get('/kalkulation', \Platform\FoodAlchemist\Livewire\Kalkulation\Index::class)
    ->name('foodalchemist.kalkulation.index');

/**
 * M-K10 / Doc 16 §11: Kalkulator — standalone Composer (Positionen aus Gericht/
 * Basisrezept/GP/frei → HK1/HK2/VK), entkoppelt vom Concepter (Prüfung).
 */
Route::get('/kalkulator', \Platform\FoodAlchemist\Livewire\Kalkulator\Index::class)
    ->name('foodalchemist.kalkulator.index');

/**
 * M14: Speiseplan — Bausteine über die Zeitachse (Tag × Mahlzeit, Wochen-Zyklus).
 */
Route::get('/speiseplan', \Platform\FoodAlchemist\Livewire\Speiseplan\Index::class)
    ->name('foodalchemist.speiseplan.index');

/**
 * R7: «In Planung» — Vorschau der Phase-2-Domänen (14_ROADMAP_PHASE2).
 */
Route::get('/demnaechst', \Platform\FoodAlchemist\Livewire\Demnaechst::class)
    ->name('foodalchemist.demnaechst');

/**
 * M9-03 / V-10: Review-Queue — zentrale «Zu prüfen»-Seite.
 */
Route::get('/zu-pruefen', \Platform\FoodAlchemist\Livewire\ReviewQueue::class)
    ->name('foodalchemist.review');
