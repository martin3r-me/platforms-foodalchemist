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
 * #388 Geschirr-Datenbank (non-food) — Leih-Lieferant → Geschirr-Artikel,
 * Master-Detail nach Lieferanten-Vorbild. Auswahl/Suche in der URL (V-17).
 */
Route::get('/geschirr', \Platform\FoodAlchemist\Livewire\Geschirr\Index::class)
    ->name('foodalchemist.geschirr.index');

/**
 * #389 Food DNA — Team-Canvas „Markenkern Küche" (stehende KI-Referenz für alle Generatoren).
 */
Route::get('/food-dna', \Platform\FoodAlchemist\Livewire\FoodDna\Index::class)
    ->name('foodalchemist.food-dna.index');

/**
 * Wissens-Modul (#469) — Pflege-Browser für operatives Prosa-Wissen
 * (Regelwerke/Domains/Cross-Cutting). Auswahl/Filter in der URL (V-17).
 */
Route::get('/wissen', \Platform\FoodAlchemist\Livewire\Knowledge\Browser::class)
    ->name('foodalchemist.knowledge.index');

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
 * #384-Folge: Versendbares Foodbook/Portfolio-Dokument — Druck-HTML; ?pdf=1 = PDF (DomPDF, guarded).
 */
Route::get('/foodbooks/{id}/dokument', function (int $id, \Platform\FoodAlchemist\Services\FoodbookService $svc) {
    $team = \Illuminate\Support\Facades\Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
    $fb = $svc->detail($team, $id) ?? abort(404);
    // ?intern=1 → interne Projektion (EK/VK/W% pro Person, Projektleitung/Vertrieb). Default = Kundensicht (ohne EK).
    $intern = request()->boolean('intern');
    $data = $svc->dokumentDaten($team, $fb, $intern);

    if (request()->boolean('pdf') && class_exists(\Barryvdh\DomPDF\Facade\Pdf::class)) {
        return \Barryvdh\DomPDF\Facade\Pdf::loadView('foodalchemist::dokumente.foodbook', $data + ['istPdf' => true])
            ->download('Foodbook-' . $id . ($intern ? '-intern' : '') . '.pdf');
    }

    return view('foodalchemist::dokumente.foodbook', $data + ['istPdf' => false]);
})->whereNumber('id')->name('foodalchemist.foodbooks.dokument');

/**
 * R3.2 (Block C, layout-first): Externe Kunden-Präsentation als Web-Seite (auth-gated;
 * öffentlicher Share-Link = separater Core-Auth-Entscheid Martin). EK-frei (Kunden-Projektion).
 */
Route::get('/foodbooks/{id}/praesentation', \Platform\FoodAlchemist\Livewire\Foodbooks\Praesentation::class)
    ->whereNumber('id')->name('foodalchemist.foodbooks.praesentation');

/**
 * #501 (2026-07-13): Die standalone interne R3.1-Lese-Ansicht (/foodbooks/{id}/ansicht,
 * interne Pipe-Namen + EK/VK/W%) wurde ENTFERNT — Route, Livewire\Foodbooks\Ansicht,
 * die ansicht-Blade und FoodbookService::ansichtDaten (inkl. Filter-Helfer) sind gelöscht.
 * Kunden-Wording-Vorschau lebt jetzt im Editor (Menü-Toggle), Marge im Editor-Pax-Cockpit,
 * Versand über das Foodbook-Dokument.
 */

/**
 * #380: Angebote — individuelle Anfrage → maßgeschneidertes Angebot (CRM + Concepter).
 * Eigenständig neben Foodbook (Portfolio); 3-Panel-Browser am Concepter orientiert.
 */
Route::get('/angebote', \Platform\FoodAlchemist\Livewire\Angebote\Index::class)
    ->name('foodalchemist.angebote.index');

/**
 * #384: Versendbares Angebots-Dokument — Druck-HTML; ?pdf=1 = PDF-Download (DomPDF, guarded).
 * Team-scoped via AngebotService::detail.
 */
Route::get('/angebote/{id}/dokument', function (int $id, \Platform\FoodAlchemist\Services\AngebotService $svc) {
    $team = \Illuminate\Support\Facades\Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
    $angebot = $svc->detail($team, $id) ?? abort(404);
    $data = $svc->dokumentDaten($team, $angebot);

    if (request()->boolean('pdf') && class_exists(\Barryvdh\DomPDF\Facade\Pdf::class)) {
        return \Barryvdh\DomPDF\Facade\Pdf::loadView('foodalchemist::dokumente.angebot', $data + ['istPdf' => true])
            ->download('Angebot-' . $id . '.pdf');
    }

    return view('foodalchemist::dokumente.angebot', $data + ['istPdf' => false]);
})->whereNumber('id')->name('foodalchemist.angebote.dokument');

/**
 * M12: Kalkulations-Übersicht (HK1/HK2/Vollkosten-DB).
 */
Route::get('/kalkulation', \Platform\FoodAlchemist\Livewire\Kalkulation\Index::class)
    ->name('foodalchemist.kalkulation.index');

/**
 * M-K10 / Doc 16 §11: Kalkulator — standalone Composer (Positionen aus Gericht/
 * Basisrezept/GP/frei → HK1/HK2/VK), entkoppelt vom Concepter (Prüfung).
 */
/**
 * #379: Kalkulator (Scratchpad) entfällt — Ad-hoc-Rechnen lebt im Angebote-Modul.
 * Route bleibt als Redirect auf die Kalkulations-Werkstatt (keine toten Deep-Links).
 */
Route::get('/kalkulator', fn () => redirect()->route('foodalchemist.kalkulation.index'))
    ->name('foodalchemist.kalkulator.index');

/**
 * M14: Speiseplan — Bausteine über die Zeitachse (Tag × Mahlzeit, Wochen-Zyklus).
 */
Route::get('/speiseplan', \Platform\FoodAlchemist\Livewire\Speiseplan\Index::class)
    ->name('foodalchemist.speiseplan.index');

/**
 * R7.1: Operative Planungs-Blätter — Ziel + Skalierung → Produktionsblatt +
 * Bestellvorschlag + Einkaufsliste (read-only, rein rechnend).
 */
Route::get('/blaetter', \Platform\FoodAlchemist\Livewire\Blaetter\Index::class)
    ->name('foodalchemist.blaetter.index');

/**
 * Versendbares/druckbares Planungs-Blatt — Druck-HTML; ?pdf=1 = PDF (DomPDF, guarded).
 * typ=produktion|bestellung, Ziel via concept_id+persons ODER recipe_id+portions.
 */
Route::get('/blaetter/dokument', function (\Platform\FoodAlchemist\Services\PlanungsblattService $svc) {
    $team = \Illuminate\Support\Facades\Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
    $typ = request('typ') === 'bestellung' ? 'bestellung' : 'produktion';
    $ziel = array_filter([
        'concept_id' => request()->integer('concept_id') ?: null,
        'recipe_id' => request()->integer('recipe_id') ?: null,
        'persons' => request()->integer('persons') ?: null,
        'portions' => (float) request('portions') ?: null,
    ]);
    if (empty($ziel['concept_id']) && empty($ziel['recipe_id'])) {
        abort(404);
    }

    $blatt = $typ === 'bestellung' ? $svc->bestellvorschlag($team, $ziel) : $svc->produktionsblatt($team, $ziel);

    $name = ! empty($ziel['concept_id'])
        ? optional(\Platform\FoodAlchemist\Models\FoodAlchemistConcept::visibleToTeam($team)->find($ziel['concept_id']))->name
        : optional(\Platform\FoodAlchemist\Models\FoodAlchemistRecipe::visibleToTeam($team)->find($ziel['recipe_id']))->name;
    $mengeTxt = ! empty($ziel['concept_id'])
        ? (($ziel['persons'] ?? 0) . ' Personen')
        : (rtrim(rtrim(number_format((float) ($ziel['portions'] ?? 0), 2, ',', '.'), '0'), ',') . ' Portionen');
    $data = [
        'blatt' => $blatt,
        'typ' => $typ,
        'titel' => $typ === 'bestellung' ? 'Bestellvorschlag' : 'Produktionsblatt',
        'untertitel' => trim(($name ?? 'Ziel') . ' · ' . $mengeTxt),
    ];

    if (request()->boolean('pdf') && class_exists(\Barryvdh\DomPDF\Facade\Pdf::class)) {
        return \Barryvdh\DomPDF\Facade\Pdf::loadView('foodalchemist::dokumente.blatt', $data + ['istPdf' => true])
            ->download($data['titel'] . '.pdf');
    }

    return view('foodalchemist::dokumente.blatt', $data + ['istPdf' => false]);
})->name('foodalchemist.blaetter.dokument');

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
