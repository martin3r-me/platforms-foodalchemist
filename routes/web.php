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
 * Spec 32 — Controlling-Zentrum. Eine Fläche, an der Befund und Hebel nebeneinander liegen:
 * Lage · Preise · Wareneinsatz · Simulation · Erfolg · Geld-Signale · Kennzahlen.
 *
 * Die Seite ist das Lagebild, der Sidebar-Klick öffnet sofort den Voll-Editor (`?editor=0`
 * unterdrückt das, z. B. für Deep-Links auf das Lagebild). Tab-Vorwahl über `?tab=`.
 */
Route::get('/controlling', \Platform\FoodAlchemist\Livewire\Controlling\Cockpit::class)
    ->name('foodalchemist.controlling.index');


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
 * 06·H2 Favoriten-GPs — Kuratierungs-Screen (Auto-Score + Pin/Exclude).
 */
Route::get('/favoriten', \Platform\FoodAlchemist\Livewire\Favorites\Index::class)
    ->name('foodalchemist.favorites.index');
// Alt-Pfad → Redirect (Bookmarks/Deeplinks aus der Convenience-Ära).
Route::get('/convenience-highlights', fn () => redirect()->route('foodalchemist.favorites.index'));

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
 * Spec 32: Die beiden Einkaufs-Auswertungen sind ins Controlling-Zentrum gewandert
 * (Preisvergleich → Tab „Preise", Optimierung → Tab „Wareneinsatz"). Die Routen bleiben
 * als Redirects bestehen — Präzedenz `/kalkulator` weiter unten.
 *
 * Der Query-String wird MITGENOMMEN: die Panels tragen unverändert `q`/`wg`/`sup`/`rv`,
 * also treffen alte Deep-Links wie `/einkauf?q=Lachs&rv=1` weiterhin ihr Ziel.
 */
Route::get('/einkauf', fn () => redirect()->route(
    'foodalchemist.controlling.index',
    ['tab' => 'preise'] + request()->query(),
))->name('foodalchemist.einkauf.index');

Route::get('/einkauf/optimierung', fn () => redirect()->route(
    'foodalchemist.controlling.index',
    ['tab' => 'wareneinsatz'] + request()->query(),
))->name('foodalchemist.einkauf.optimierung');

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

// Trendradar (#FA-Trendradar): kuratierte Sicht auf die geclusterten Trend-Wissens-Docs.
Route::get('/trendradar', \Platform\FoodAlchemist\Livewire\Trendradar\Index::class)
    ->name('foodalchemist.trendradar.index');

// Planungs-/Kreativ-Ebene (Doppel-Diamant): Trend/Brief → Analyse/Skizzen/Planung → Go.
Route::get('/planung', \Platform\FoodAlchemist\Livewire\Planung\Index::class)
    ->name('foodalchemist.planung.index');

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

// Format-Modul: Marken-/Themen-Container über den Concepts (bündelt Editionen + Marketing-Bildwelt).
Route::get('/formate', \Platform\FoodAlchemist\Livewire\Formate\Browser::class)
    ->name('foodalchemist.formate.index');

/**
 * F3: Format-Druck — schöne Kunden-Ausgabe (Foodbook-styled, NICHT der technische Report).
 * Druck-HTML; ?pdf=1 = PDF (DomPDF, guarded). Spiegelt die Foodbook-Dokument-Closure.
 */
Route::get('/formate/{id}/dokument', function (int $id, \Platform\FoodAlchemist\Services\FormatService $svc) {
    $team = \Illuminate\Support\Facades\Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
    // ?intern=1 → interne Projektion (Parität zum Foodbook-Dokument); Default = Kundensicht.
    $intern = request()->boolean('intern');
    try {
        $data = $svc->dokumentDaten($team, $id, $intern);
    } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
        abort(404);
    }

    if (request()->boolean('pdf')) {
        if (! class_exists(\Barryvdh\DomPDF\Facade\Pdf::class)) {
            \Illuminate\Support\Facades\Log::warning('Format-PDF angefordert, aber DomPDF ist nicht installiert.', ['format_id' => $id]);
            abort(500, 'PDF-Export nicht verfügbar: DomPDF ist auf diesem Server nicht installiert.');
        }

        return \Barryvdh\DomPDF\Facade\Pdf::loadView('foodalchemist::dokumente.format', $data + ['istPdf' => true])
            ->setOption('isPhpEnabled', true)
            ->download('Format-' . $id . '.pdf');
    }

    return view('foodalchemist::dokumente.format', $data + ['istPdf' => false]);
})->whereNumber('id')->name('foodalchemist.formate.dokument');

/**
 * Rezept-/Gericht-/Concept-Reports — Druck-HTML mit Profilen; ?pdf=1 rendert DomPDF.
 * Profile: kurz | produktion | kalkulation | voll. Filter als Query-Booleans:
 * preise, lieferanten, steps, sensorik, produktion, notizen, kaskade.
 */
Route::get('/rezepte/{id}/dokument', function (int $id, \Platform\FoodAlchemist\Services\ReportExportService $svc) {
    $team = \Illuminate\Support\Facades\Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
    $optionen = $svc->optionen(request()->query(), 'recipe');
    $data = $svc->rezeptDaten($team, $id, $optionen);

    if (request()->boolean('pdf')) {
        if (! class_exists(\Barryvdh\DomPDF\Facade\Pdf::class)) {
            \Illuminate\Support\Facades\Log::warning('Rezept-Report-PDF angefordert, aber DomPDF ist nicht installiert.', ['recipe_id' => $id]);
            abort(500, 'PDF-Export nicht verfügbar: DomPDF ist auf diesem Server nicht installiert.');
        }

        return \Barryvdh\DomPDF\Facade\Pdf::loadView('foodalchemist::dokumente.report', $data + ['istPdf' => true])
            ->download(($data['typ'] === 'gericht' ? 'Gericht-' : 'Basisrezept-') . $id . '.pdf');
    }

    return view('foodalchemist::dokumente.report', $data + ['istPdf' => false]);
})->whereNumber('id')->name('foodalchemist.rezepte.dokument');

Route::get('/concepts/{id}/dokument', function (int $id, \Platform\FoodAlchemist\Services\ReportExportService $svc) {
    $team = \Illuminate\Support\Facades\Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
    $optionen = $svc->optionen(request()->query(), 'concept');
    $data = $svc->conceptDaten($team, $id, $optionen);

    if (request()->boolean('pdf')) {
        if (! class_exists(\Barryvdh\DomPDF\Facade\Pdf::class)) {
            \Illuminate\Support\Facades\Log::warning('Concept-Report-PDF angefordert, aber DomPDF ist nicht installiert.', ['concept_id' => $id]);
            abort(500, 'PDF-Export nicht verfügbar: DomPDF ist auf diesem Server nicht installiert.');
        }

        return \Barryvdh\DomPDF\Facade\Pdf::loadView('foodalchemist::dokumente.report', $data + ['istPdf' => true])
            ->download('Concept-' . $id . '.pdf');
    }

    return view('foodalchemist::dokumente.report', $data + ['istPdf' => false]);
})->whereNumber('id')->name('foodalchemist.concepts.dokument');

/**
 * F3: Concept-„Karte" — die schöne Einzel-Concept-Ausgabe (Foodbook-styled). ZWEITE
 * Ausgabe NEBEN dem technischen Report (foodalchemist.concepts.dokument bleibt unangetastet).
 * Druck-HTML; ?pdf=1 = PDF (DomPDF, guarded). Spiegelt die Foodbook-Dokument-Closure.
 */
Route::get('/concepts/{id}/karte', function (int $id, \Platform\FoodAlchemist\Services\FoodbookService $svc) {
    $team = \Illuminate\Support\Facades\Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
    try {
        $data = $svc->conceptKarteDaten($team, $id);
    } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
        abort(404);
    }

    if (request()->boolean('pdf')) {
        if (! class_exists(\Barryvdh\DomPDF\Facade\Pdf::class)) {
            \Illuminate\Support\Facades\Log::warning('Concept-Karte-PDF angefordert, aber DomPDF ist nicht installiert.', ['concept_id' => $id]);
            abort(500, 'PDF-Export nicht verfügbar: DomPDF ist auf diesem Server nicht installiert.');
        }

        return \Barryvdh\DomPDF\Facade\Pdf::loadView('foodalchemist::dokumente.concept-karte', $data + ['istPdf' => true])
            ->setOption('isPhpEnabled', true)
            ->download('Karte-' . $id . '.pdf');
    }

    return view('foodalchemist::dokumente.concept-karte', $data + ['istPdf' => false]);
})->whereNumber('id')->name('foodalchemist.concepts.karte');

Route::get('/gps/{id}/dokument', function (int $id, \Platform\FoodAlchemist\Services\ReportExportService $svc) {
    $team = \Illuminate\Support\Facades\Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
    $data = $svc->gpDaten($team, $id, $svc->optionen(request()->query(), 'gp'));

    if (request()->boolean('pdf')) {
        if (! class_exists(\Barryvdh\DomPDF\Facade\Pdf::class)) {
            \Illuminate\Support\Facades\Log::warning('GP-Report-PDF angefordert, aber DomPDF ist nicht installiert.', ['gp_id' => $id]);
            abort(500, 'PDF-Export nicht verfügbar: DomPDF ist auf diesem Server nicht installiert.');
        }

        return \Barryvdh\DomPDF\Facade\Pdf::loadView('foodalchemist::dokumente.report', $data + ['istPdf' => true])
            ->download('Grundprodukt-' . $id . '.pdf');
    }

    return view('foodalchemist::dokumente.report', $data + ['istPdf' => false]);
})->whereNumber('id')->name('foodalchemist.gps.dokument');

Route::get('/lieferanten/{id}/dokument', function (int $id, \Platform\FoodAlchemist\Services\ReportExportService $svc) {
    $team = \Illuminate\Support\Facades\Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
    $data = $svc->supplierDaten($team, $id, $svc->optionen(request()->query(), 'supplier'));

    if (request()->boolean('pdf')) {
        if (! class_exists(\Barryvdh\DomPDF\Facade\Pdf::class)) {
            \Illuminate\Support\Facades\Log::warning('Lieferanten-Report-PDF angefordert, aber DomPDF ist nicht installiert.', ['supplier_id' => $id]);
            abort(500, 'PDF-Export nicht verfügbar: DomPDF ist auf diesem Server nicht installiert.');
        }

        return \Barryvdh\DomPDF\Facade\Pdf::loadView('foodalchemist::dokumente.report', $data + ['istPdf' => true])
            ->download('Lieferant-' . $id . '.pdf');
    }

    return view('foodalchemist::dokumente.report', $data + ['istPdf' => false]);
})->whereNumber('id')->name('foodalchemist.suppliers.dokument');

Route::get('/geschirr/{id}/dokument', function (int $id, \Platform\FoodAlchemist\Services\ReportExportService $svc) {
    $team = \Illuminate\Support\Facades\Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
    $data = $svc->geschirrDaten($team, $id, $svc->optionen(request()->query(), 'geschirr'));

    if (request()->boolean('pdf')) {
        if (! class_exists(\Barryvdh\DomPDF\Facade\Pdf::class)) {
            \Illuminate\Support\Facades\Log::warning('Geschirr-Report-PDF angefordert, aber DomPDF ist nicht installiert.', ['tableware_supplier_id' => $id]);
            abort(500, 'PDF-Export nicht verfügbar: DomPDF ist auf diesem Server nicht installiert.');
        }

        return \Barryvdh\DomPDF\Facade\Pdf::loadView('foodalchemist::dokumente.report', $data + ['istPdf' => true])
            ->download('Geschirr-' . $id . '.pdf');
    }

    return view('foodalchemist::dokumente.report', $data + ['istPdf' => false]);
})->whereNumber('id')->name('foodalchemist.geschirr.dokument');

Route::get('/favoriten/dokument', function (\Platform\FoodAlchemist\Services\ReportExportService $svc) {
    $team = \Illuminate\Support\Facades\Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
    $data = $svc->favoritenDaten($team, $svc->optionen(request()->query(), 'favoriten'), request()->integer('limit') ?: 300);

    if (request()->boolean('pdf')) {
        if (! class_exists(\Barryvdh\DomPDF\Facade\Pdf::class)) {
            \Illuminate\Support\Facades\Log::warning('Favoriten-Report-PDF angefordert, aber DomPDF ist nicht installiert.');
            abort(500, 'PDF-Export nicht verfügbar: DomPDF ist auf diesem Server nicht installiert.');
        }

        return \Barryvdh\DomPDF\Facade\Pdf::loadView('foodalchemist::dokumente.report', $data + ['istPdf' => true])
            ->download('Favoriten.pdf');
    }

    return view('foodalchemist::dokumente.report', $data + ['istPdf' => false]);
})->name('foodalchemist.favorites.dokument');

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
    // #3: ?kaskade=1 → Produktions-Kaskaden-Anhang je Gericht · ?kapitel=… → nur diese Kapitel.
    // kapitel akzeptiert Checkbox-Array (kapitel[]=1&kapitel[]=2) UND CSV (kapitel=1,2).
    $mitKaskade = request()->boolean('kaskade');
    $kapRaw = request()->query('kapitel', '');
    $kapitelFilter = is_array($kapRaw)
        ? array_values(array_filter(array_map('intval', $kapRaw)))
        : array_values(array_filter(array_map('intval', explode(',', (string) $kapRaw))));
    $data = $svc->dokumentDaten($team, $fb, $intern, $kapitelFilter, $mitKaskade);

    if (request()->boolean('pdf')) {
        // Härten: kein stiller HTML-Fallback mehr — wenn PDF verlangt, aber die Engine fehlt,
        // lieber lauter Fehler + Log als lautlos die HTML-Seite ausliefern (sah aus wie „PDF kaputt").
        if (! class_exists(\Barryvdh\DomPDF\Facade\Pdf::class)) {
            \Illuminate\Support\Facades\Log::warning('Foodbook-PDF angefordert, aber DomPDF (barryvdh/laravel-dompdf) ist nicht installiert.', ['foodbook_id' => $id]);
            abort(500, 'PDF-Export nicht verfügbar: DomPDF ist auf diesem Server nicht installiert (composer require barryvdh/laravel-dompdf).');
        }

        // isPhpEnabled: nur fürs eigene Blade (Seitenzahl-Stempel via page_text am Body-Ende).
        return \Barryvdh\DomPDF\Facade\Pdf::loadView('foodalchemist::dokumente.foodbook', $data + ['istPdf' => true])
            ->setOption('isPhpEnabled', true)
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

    if (request()->boolean('pdf')) {
        if (! class_exists(\Barryvdh\DomPDF\Facade\Pdf::class)) {
            \Illuminate\Support\Facades\Log::warning('Angebots-PDF angefordert, aber DomPDF ist nicht installiert.', ['angebot_id' => $id]);
            abort(500, 'PDF-Export nicht verfügbar: DomPDF ist auf diesem Server nicht installiert.');
        }

        return \Barryvdh\DomPDF\Facade\Pdf::loadView('foodalchemist::dokumente.angebot', $data + ['istPdf' => true])
            ->download('Angebot-' . $id . '.pdf');
    }

    return view('foodalchemist::dokumente.angebot', $data + ['istPdf' => false]);
})->whereNumber('id')->name('foodalchemist.angebote.dokument');

/**
 * M12: Kalkulations-Übersicht (Kennzahlen + Preissimulation).
 *
 * Spec 32: beide Hälften sind ins Controlling-Zentrum gewandert — die Kennzahlen in den
 * gleichnamigen Tab, die Simulation in einen eigenen. Der Redirect zielt auf die Simulation,
 * weil dieser Eintrag zuletzt unter dem Label „Preissimulation" in der Sidebar stand.
 */
Route::get('/kalkulation', fn () => redirect()->route(
    'foodalchemist.controlling.index',
    ['tab' => 'simulation'] + request()->query(),
))->name('foodalchemist.kalkulation.index');

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
 * Spec 31 / Stufe B: Wochenplan-Aushang (Druck-HTML; ?pdf=1 = PDF-Landscape, DomPDF guarded).
 * Query: ?mahlzeit=mittag&montag=YYYY-MM-DD (Woche+Mahlzeit aus dem Editor). Team-scoped.
 */
Route::get('/speiseplan/{id}/dokument', function (int $id, \Platform\FoodAlchemist\Services\SpeiseplanService $svc) {
    $team = \Illuminate\Support\Facades\Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
    $plan = $svc->detail($team, $id) ?? abort(404);
    // #3: ?intern=1 (EK-Kaskade) · ?kaskade=1 (Produktions-Baum je Gericht der Woche).
    $data = $svc->dokumentDaten($team, $plan, (string) request()->query('mahlzeit', 'mittag'), request()->query('montag'), request()->boolean('intern'), request()->boolean('kaskade'));

    if (request()->boolean('pdf')) {
        if (! class_exists(\Barryvdh\DomPDF\Facade\Pdf::class)) {
            \Illuminate\Support\Facades\Log::warning('Speiseplan-PDF angefordert, aber DomPDF ist nicht installiert.', ['speiseplan_id' => $id]);
            abort(500, 'PDF-Export nicht verfügbar: DomPDF ist auf diesem Server nicht installiert.');
        }

        return \Barryvdh\DomPDF\Facade\Pdf::loadView('foodalchemist::dokumente.speiseplan', $data + ['istPdf' => true])
            ->setPaper('a4', 'landscape')
            ->download('Speiseplan-' . $id . '-' . str_replace(' ', '', (string) request()->query('mahlzeit', 'mittag')) . '.pdf');
    }

    return view('foodalchemist::dokumente.speiseplan', $data + ['istPdf' => false]);
})->whereNumber('id')->name('foodalchemist.speiseplan.dokument');

/**
 * Speisekarte — dritte Ausgabeform (Gastronomie-à-la-carte-Karte). Rubriken × Positionen.
 */
Route::get('/speisekarten', \Platform\FoodAlchemist\Livewire\Speisekarte\Index::class)
    ->name('foodalchemist.speisekarte.index');

/**
 * Speisekarten-Dokument — Druck-HTML; ?pdf=1 = PDF (DomPDF, guarded). Stufe B liefert
 * `dokumentDaten` (Allergen-/Zusatzstoff-Legende + Brutto-Preise). Team-scoped.
 */
Route::get('/speisekarten/{id}/dokument', function (int $id, \Platform\FoodAlchemist\Services\SpeisekarteService $svc) {
    $team = \Illuminate\Support\Facades\Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
    $karte = $svc->detail($team, $id) ?? abort(404);
    // #3: ?intern=1 (EK-Kaskade) · ?kaskade=1 (Produktions-Baum je Gericht) · ?rubrik=… (nur diese Rubriken; Array|CSV).
    $intern = request()->boolean('intern');
    $mitKaskade = request()->boolean('kaskade');
    $rubRaw = request()->query('rubrik', '');
    $rubrikFilter = is_array($rubRaw)
        ? array_values(array_filter(array_map('intval', $rubRaw)))
        : array_values(array_filter(array_map('intval', explode(',', (string) $rubRaw))));
    $data = $svc->dokumentDaten($team, $karte, $intern, $rubrikFilter, $mitKaskade);

    if (request()->boolean('pdf')) {
        if (! class_exists(\Barryvdh\DomPDF\Facade\Pdf::class)) {
            \Illuminate\Support\Facades\Log::warning('Speisekarte-PDF angefordert, aber DomPDF ist nicht installiert.', ['speisekarte_id' => $id]);
            abort(500, 'PDF-Export nicht verfügbar: DomPDF ist auf diesem Server nicht installiert.');
        }

        return \Barryvdh\DomPDF\Facade\Pdf::loadView('foodalchemist::dokumente.speisekarte', $data + ['istPdf' => true])
            ->setOption('isPhpEnabled', true)
            ->download('Speisekarte-' . $id . '.pdf');
    }

    return view('foodalchemist::dokumente.speisekarte', $data + ['istPdf' => false]);
})->whereNumber('id')->name('foodalchemist.speisekarte.dokument');

/**
 * Speisekarten-Präsentation (Web-Ansicht, auth-gated). Kundensicht ohne EK.
 */
Route::get('/speisekarten/{id}/praesentation', \Platform\FoodAlchemist\Livewire\Speisekarte\Praesentation::class)
    ->whereNumber('id')->name('foodalchemist.speisekarte.praesentation');

/**
 * Spec 18: Produktion — absorbiert die bisherigen Planungs-Blätter (Vorschau im
 * Editor, unverändert per PlanungsblattService) + persistierte Produktionsaufträge
 * (Datum, Status, → Bestellung übergeben). /blaetter bleibt als Redirect (keine
 * toten Deep-Links, Precedent /kalkulator oben).
 */
// Spec 30 E3 — Tagesplan: was steht wann an welchem Posten an (über alle Aufträge).
// Eine Abfrage über ZEILEN, keine neue Auftrags-Struktur: der Auftrag bleibt ein Punkt,
// nur seine Zeilen dürfen per Vorlauf davor liegen.
Route::get('/produktion/tagesplan', \Platform\FoodAlchemist\Livewire\Produktion\Tagesplan::class)
    ->name('foodalchemist.produktion.tagesplan');

Route::get('/produktion/tagesplan/editor', \Platform\FoodAlchemist\Livewire\Produktion\Tagesplan::class)
    ->name('foodalchemist.produktion.tagesplan.editor');

Route::get('/produktion/wandmonitor', \Platform\FoodAlchemist\Livewire\Produktion\Tagesplan::class)
    ->name('foodalchemist.produktion.wandmonitor');

// Spec 30 E8 — druckbares Posten-Blatt der Tages-Ausgabe: pro Tag pro Posten eine
// Abhak-Checkliste über alle Aufträge. Aggregation wie die Tagesplan-Komponente
// (gleiches Fenster/Posten-Filter), aber als Druck-HTML statt Livewire-Seite.
Route::get('/produktion/tagesplan/blatt', function (\Platform\FoodAlchemist\Services\ProductionCapacityService $kap) {
    $team = \Illuminate\Support\Facades\Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
    $von = \Illuminate\Support\Carbon::parse(request('von') ?: now()->toDateString())->toDateString();
    $tage = max(1, min(60, request()->integer('tage') ?: 14));
    $bis = \Illuminate\Support\Carbon::parse($von)->addDays($tage - 1)->toDateString();
    $postenFilter = request()->integer('posten') ?: null;
    // Posten- oder Gerichtssicht (Spec 35): steuert die Gruppierung im Blatt-Template.
    $ansicht = request('ansicht') === 'gericht' ? 'gericht' : 'posten';

    $auslastung = $kap->auslastung($team, $von, $bis);
    // mitAnleitung=true: lädt Schritte/Zubereitung/Blocker je Zeile für den papierenen Rückfall.
    $zeilen = $kap->tagesplanZeilen($team, $von, $bis, true);
    if ($postenFilter !== null) {
        $zeilen = $zeilen->where('station_id', $postenFilter);
        $auslastung = collect($auslastung)
            ->map(fn ($b) => array_values(array_filter($b, fn ($x) => $x['station_id'] === $postenFilter)))
            ->filter(fn ($b) => $b !== [])->all();
    }

    return view('foodalchemist::dokumente.tagesplan_blatt', [
        'von' => $von,
        'bis' => $bis,
        'ansicht' => $ansicht,
        'auslastung' => $auslastung,
        'zeilenNachTag' => $zeilen->groupBy(fn ($z) => \Illuminate\Support\Carbon::parse($z->plan_date)->toDateString()),
    ]);
})->name('foodalchemist.produktion.tagesplan.blatt');

Route::get('/produktion', \Platform\FoodAlchemist\Livewire\Produktion\Browser::class)
    ->name('foodalchemist.produktion.index');

Route::get('/blaetter', fn () => redirect()->route('foodalchemist.produktion.index'))
    ->name('foodalchemist.blaetter.index');

// Spec 18/S3 — filterbare Produktionsdoku: HTML-Vorschau | ?pdf=1 | ?csv=1.
Route::get('/produktion/auftraege/{order}/dokument', function (int $order, \Platform\FoodAlchemist\Services\ProductionOrderService $svc) {
    $team = \Illuminate\Support\Facades\Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
    $optionen = $svc->dokumentOptionen(request()->query());
    try {
        $dok = $svc->dokument($team, $order);
    } catch (\Throwable $e) {
        abort(404);
    }

    if (request()->boolean('csv')) {
        $dateiname = 'Produktionsschein-' . $dok['id'] . '.csv';

        return response()->streamDownload(function () use ($dok) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM
            fputcsv($out, ['Rezept', 'Ansätze', 'Portionen', 'Menge (kg)', 'Arbeitszeit (min)'], ';');
            foreach ($dok['zeilen'] as $z) {
                fputcsv($out, [
                    $z['name'] ?? '',
                    number_format($z['ansaetze'], 2, ',', ''),
                    $z['portionen'] !== null ? (string) $z['portionen'] : '',
                    $z['produzierte_menge_kg'] !== null ? number_format($z['produzierte_menge_kg'], 3, ',', '') : '',
                    $z['arbeitszeit_min'] !== null ? (string) $z['arbeitszeit_min'] : '',
                ], ';');
            }
            fclose($out);
        }, $dateiname, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    $data = [
        'dok' => $dok,
        'istPdf' => false,
        'optionen' => $optionen,
        'mitFotos' => $optionen['bilder'],
    ];

    if (request()->boolean('pdf')) {
        if (! class_exists(\Barryvdh\DomPDF\Facade\Pdf::class)) {
            \Illuminate\Support\Facades\Log::warning('Produktionsschein-PDF angefordert, aber DomPDF ist nicht installiert.', ['order' => $order]);
            abort(500, 'PDF-Export nicht verfügbar: DomPDF ist auf diesem Server nicht installiert.');
        }

        return \Barryvdh\DomPDF\Facade\Pdf::loadView('foodalchemist::dokumente.produktionsauftrag', $data + ['istPdf' => true])
            ->download('Produktionsschein-' . $dok['id'] . '.pdf');
    }

    return view('foodalchemist::dokumente.produktionsauftrag', $data);
})->name('foodalchemist.produktion.auftraege.dokument');

// Spec 17/S2 — Bestellungen (mini-WaWi Bestellschienen, N-Track)
Route::get('/bestellungen', \Platform\FoodAlchemist\Livewire\Orders\Index::class)
    ->name('foodalchemist.orders.index');

// Gebündeltes Versandprotokoll für eine bewusst ausgewählte Belegmenge.
Route::get('/bestellungen/versandprotokoll', function (\Platform\FoodAlchemist\Services\OrderService $svc) {
    $team = \Illuminate\Support\Facades\Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
    $ids = collect(explode(',', (string) request('ids')))
        ->map(fn ($id) => (int) $id)->filter()->unique()->take(100)->values();
    $dokumente = $ids->map(function (int $id) use ($svc, $team) {
        try {
            return $svc->dokument($team, $id);
        } catch (\Throwable) {
            return null;
        }
    })->filter()->values();
    abort_if($dokumente->isEmpty(), 404);

    $data = ['dokumente' => $dokumente, 'istPdf' => false, 'erstelltAm' => now()->format('d.m.Y H:i')];
    if (request()->boolean('pdf')) {
        abort_unless(class_exists(\Barryvdh\DomPDF\Facade\Pdf::class), 500, 'PDF-Export nicht verfügbar.');

        return \Barryvdh\DomPDF\Facade\Pdf::loadView('foodalchemist::dokumente.bestelllauf', $data + ['istPdf' => true])
            ->download('Versandprotokoll-' . now()->format('Ymd-Hi') . '.pdf');
    }

    return view('foodalchemist::dokumente.bestelllauf', $data);
})->name('foodalchemist.orders.versandprotokoll');

// Spec 17/S3 — Bestell-Dokument: Druck-HTML | ?pdf=1 (DomPDF) | ?csv=1 (Download).
Route::get('/bestellungen/{order}/dokument', function (int $order, \Platform\FoodAlchemist\Services\OrderService $svc) {
    $team = \Illuminate\Support\Facades\Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
    try {
        $dok = $svc->dokument($team, $order);
    } catch (\Throwable $e) {
        abort(404);
    }

    // CSV-Export (Excel-tauglich: Semikolon-getrennt, BOM für Umlaute).
    if (request()->boolean('csv')) {
        $dateiname = 'Bestellung-' . $dok['id'] . '-' . preg_replace('/[^A-Za-z0-9]+/', '_', (string) ($dok['lieferant']['name'] ?? '')) . '.csv';

        return response()->streamDownload(function () use ($dok) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM
            fputcsv($out, [
                'Bestellung', 'Status', 'Lieferant', 'Liefertag', 'AB-Nr', 'Bestaetigter Liefertag',
                'Rechnungs-Nr', 'Rechnungsdatum', 'Faellig am', 'Zahlungsziel Tage', 'Zahlungsstatus',
                'Bezahlt am', 'Zahlungsnotiz', 'Freigabe', 'Freigabe angefragt am', 'Freigegeben am',
                'Freigegeben durch', 'Freigabenotiz',
            ], ';');
            fputcsv($out, [
                'ord-' . $dok['id'], $dok['status_label'], $dok['lieferant']['name'] ?? '',
                $dok['desired_delivery_date'] ?? '', $dok['supplier_order_number'] ?? '',
                $dok['confirmed_delivery_date'] ?? '', $dok['invoice_number'] ?? '', $dok['invoice_date'] ?? '',
                $dok['invoice_due_date'] ?? '', $dok['payment_term_days'] ?? '',
                ($dok['payment']['status'] ?? null) ? ($dok['payment']['label'] ?? '') : '',
                $dok['invoice_paid_at'] ?? '', $dok['payment_note'] ?? '',
                ($dok['approval']['status'] ?? null) ? ($dok['approval']['label'] ?? '') : '',
                $dok['approval_requested_at'] ?? '', $dok['approved_at'] ?? '', $dok['approved_by'] ?? '',
                $dok['approval_note'] ?? '',
            ], ';');
            fputcsv($out, [], ';');
            fputcsv($out, [
                'Artikel-Nr', 'Bezeichnung', 'Gebinde', 'Anzahl bestellt', 'Gebinde-Inhalt', 'Einheit',
                'Preis/Gebinde EUR', 'Summe EUR', 'Bedarf kg', 'WE Anzahl', 'WE Diff.', 'WE Notiz',
                'RE Anzahl', 'RE Preis/Gebinde EUR', 'RE Summe EUR', 'RE Diff. EUR', 'RE Notiz',
                'Reklamation Status', 'Reklamation Menge', 'Gutschrift erwartet EUR', 'Reklamation Notiz',
                'Kontingent Menge', 'Kontingent verbraucht', 'Kontingent frei vorher', 'Kontingent frei nachher',
                'Kontingent verbucht durch Zeile', 'Kontingent verbucht am',
                'Kontingent gueltig von', 'Kontingent gueltig bis', 'Kontingent Notiz',
            ], ';');
            foreach ($dok['zeilen'] as $z) {
                $quota = $z['quota'] ?? null;
                fputcsv($out, [
                    $z['article_number'] ?? '',
                    $z['designation'] ?? '',
                    $z['packaging_unit'] ?? '',
                    number_format($z['qty_packs'], 2, ',', ''),
                    $z['pack_qty'] !== null ? number_format($z['pack_qty'], 3, ',', '') : '',
                    $z['unit_code'] ?? '',
                    $z['pack_price'] !== null ? number_format($z['pack_price'], 2, ',', '') : '',
                    number_format($z['line_total'], 2, ',', ''),
                    number_format($z['needed_base_g'] / 1000, 3, ',', ''),
                    $z['received_qty_packs'] !== null ? number_format($z['received_qty_packs'], 2, ',', '') : '',
                    $z['receipt_diff_packs'] !== null ? number_format($z['receipt_diff_packs'], 2, ',', '') : '',
                    $z['received_note'] ?? '',
                    $z['invoice_qty_packs'] !== null ? number_format($z['invoice_qty_packs'], 2, ',', '') : '',
                    $z['invoice_pack_price'] !== null ? number_format($z['invoice_pack_price'], 2, ',', '') : '',
                    $z['invoice_line_total'] !== null ? number_format($z['invoice_line_total'], 2, ',', '') : '',
                    $z['invoice_diff_net'] !== null ? number_format($z['invoice_diff_net'], 2, ',', '') : '',
                    $z['invoice_note'] ?? '',
                    $z['claim_status_label'] ?? '',
                    $z['claim_qty_packs'] !== null ? number_format($z['claim_qty_packs'], 2, ',', '') : '',
                    $z['credit_expected_net'] !== null ? number_format($z['credit_expected_net'], 2, ',', '') : '',
                    $z['claim_note'] ?? '',
                    $quota !== null ? number_format($quota['qty_packs'], 2, ',', '') : '',
                    $quota !== null ? number_format($quota['used_packs'], 2, ',', '') : '',
                    $quota !== null ? number_format($quota['remaining_before_packs'], 2, ',', '') : '',
                    $quota !== null ? number_format($quota['remaining_after_packs'], 2, ',', '') : '',
                    $z['quota_consumed_packs'] !== null ? number_format($z['quota_consumed_packs'], 2, ',', '') : '',
                    $z['quota_consumed_at'] ?? '',
                    $quota['valid_from'] ?? '',
                    $quota['valid_to'] ?? '',
                    $quota['note'] ?? '',
                ], ';');
            }
            fputcsv($out, [], ';');
            fputcsv($out, ['', '', '', '', '', '', 'Netto gesamt', number_format($dok['total_net'], 2, ',', ''), '', '', '', '', '', '', 'Rechnung netto', number_format($dok['invoice']['invoice_net'], 2, ',', ''), '', 'Gutschrift erwartet', '', number_format($dok['claims']['credit_expected_net'] ?? 0, 2, ',', '')], ';');
            fclose($out);
        }, $dateiname, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    $data = ['dok' => $dok, 'istPdf' => false];

    if (request()->boolean('pdf')) {
        if (! class_exists(\Barryvdh\DomPDF\Facade\Pdf::class)) {
            \Illuminate\Support\Facades\Log::warning('Bestell-PDF angefordert, aber DomPDF ist nicht installiert.', ['order' => $order]);
            abort(500, 'PDF-Export nicht verfügbar: DomPDF ist auf diesem Server nicht installiert.');
        }

        return \Barryvdh\DomPDF\Facade\Pdf::loadView('foodalchemist::dokumente.bestellung', $data + ['istPdf' => true])
            ->download('Bestellung-' . $dok['id'] . '.pdf');
    }

    return view('foodalchemist::dokumente.bestellung', $data);
})->name('foodalchemist.orders.dokument');

/**
 * Versendbares/druckbares Planungs-Blatt — Druck-HTML; ?pdf=1 = PDF (DomPDF, guarded).
 * typ=produktion|bestellung, Ziel via concept_id+persons ODER recipe_id+portions.
 */
Route::get('/blaetter/dokument', function (\Platform\FoodAlchemist\Services\PlanungsblattService $svc) {
    $team = \Illuminate\Support\Facades\Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
    $typ = in_array(request('typ'), ['bestellung', 'einkauf'], true) ? request('typ') : 'produktion';
    $ziel = array_filter([
        'concept_id' => request()->integer('concept_id') ?: null,
        'recipe_id' => request()->integer('recipe_id') ?: null,
        'persons' => request()->integer('persons') ?: null,
        'portions' => (float) request('portions') ?: null,
    ]);
    if (empty($ziel['concept_id']) && empty($ziel['recipe_id'])) {
        abort(404);
    }

    $blatt = match ($typ) {
        'bestellung' => $svc->bestellvorschlag($team, $ziel),
        'einkauf' => $svc->einkaufsliste($team, [$ziel]),
        default => $svc->produktionsblatt($team, $ziel),
    };

    $name = ! empty($ziel['concept_id'])
        ? optional(\Platform\FoodAlchemist\Models\FoodAlchemistConcept::visibleToTeam($team)->find($ziel['concept_id']))->name
        : optional(\Platform\FoodAlchemist\Models\FoodAlchemistRecipe::visibleToTeam($team)->find($ziel['recipe_id']))->name;
    $mengeTxt = ! empty($ziel['concept_id'])
        ? (($ziel['persons'] ?? 0) . ' Personen')
        : (rtrim(rtrim(number_format((float) ($ziel['portions'] ?? 0), 2, ',', '.'), '0'), ',') . ' Portionen');
    $data = [
        'blatt' => $blatt,
        'typ' => $typ,
        'titel' => match ($typ) { 'bestellung' => 'Bestellvorschlag', 'einkauf' => 'Einkaufsliste', default => 'Produktionsblatt' },
        'untertitel' => trim(($name ?? 'Ziel') . ' · ' . $mengeTxt),
        // Spec 27: ?fotos=0 druckt die Anleitung ohne Bilder (Text-Zettel), Default = mit Fotos.
        'mitFotos' => request()->query('fotos') !== '0',
    ];

    if (request()->boolean('pdf')) {
        if (! class_exists(\Barryvdh\DomPDF\Facade\Pdf::class)) {
            \Illuminate\Support\Facades\Log::warning('Planungsblatt-PDF angefordert, aber DomPDF ist nicht installiert.', ['typ' => $typ]);
            abort(500, 'PDF-Export nicht verfügbar: DomPDF ist auf diesem Server nicht installiert.');
        }

        return \Barryvdh\DomPDF\Facade\Pdf::loadView('foodalchemist::dokumente.blatt', $data + ['istPdf' => true])
            ->download($data['titel'] . '.pdf');
    }

    return view('foodalchemist::dokumente.blatt', $data + ['istPdf' => false]);
})->name('foodalchemist.blaetter.dokument');

/**
 * Spec 27 — Postenzettel „Anleitung": NUR die Schritt-für-Schritt-Zubereitung eines
 * Rezepts, groß gesetzt zum Aufhängen am Posten. `?fotos=0` = Textfassung ohne Bilder,
 * `?pdf=1` = PDF (DomPDF, guarded wie die anderen Druckansichten).
 */
Route::get('/rezepte/{recipe}/anleitung', function (int $recipe) {
    $team = \Illuminate\Support\Facades\Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
    $rezept = \Platform\FoodAlchemist\Models\FoodAlchemistRecipe::visibleToTeam($team)->find($recipe) ?? abort(404);

    $disk = \Illuminate\Support\Facades\Storage::disk('public');
    $schritte = \Platform\FoodAlchemist\Models\FoodAlchemistRecipeStep::where('recipe_id', $rezept->id)
        ->with('photos')->orderBy('position')->orderBy('id')->get()
        ->map(fn ($s) => [
            'nr' => (int) $s->position,
            'phase' => $s->phase,
            'text' => (string) $s->text,
            'fotos' => $s->photos->map(fn ($f) => [
                'url' => $f->url(),
                'pfad_abs' => $disk->exists($f->pfad) ? $disk->path($f->pfad) : null,
                'caption' => $f->caption,
            ])->values()->all(),
        ])->values()->all();

    $zutaten = $rezept->ingredients->map(fn ($z) => [
        'name' => $z->gp?->name ?? $z->referencedRecipe?->name ?? $z->display_name ?? $z->raw_text,
        'menge' => $z->quantity !== null ? rtrim(rtrim(number_format((float) $z->quantity, 2, ',', '.'), '0'), ',') : null,
        'einheit' => $z->unit?->slug,
    ])->filter(fn ($z) => trim((string) $z['name']) !== '')->values();

    // Endprodukt-Bild („so soll es fertig aussehen"). Quelle wie bei den Schritt-Fotos:
    // lokaler Pfad fürs PDF, URL fürs HTML.
    $istPdf = request()->boolean('pdf');
    $hero = app(\Platform\FoodAlchemist\Services\RecipeStepService::class)->endprodukt($rezept->id);
    $endprodukt = $hero === null ? null : [
        'quelle' => $istPdf
            ? ($disk->exists($hero->pfad) ? $disk->path($hero->pfad) : null)
            : $hero->url(),
        'caption' => $hero->caption,
    ];

    $data = [
        'rezept' => $rezept,
        'schritte' => $schritte,
        'zutaten' => $zutaten,
        'endprodukt' => $endprodukt,
        'mitFotos' => request()->query('fotos') !== '0',
    ];

    if ($istPdf) {
        if (! class_exists(\Barryvdh\DomPDF\Facade\Pdf::class)) {
            \Illuminate\Support\Facades\Log::warning('Anleitungs-PDF angefordert, aber DomPDF ist nicht installiert.', ['recipe' => $recipe]);
            abort(500, 'PDF-Export nicht verfügbar: DomPDF ist auf diesem Server nicht installiert.');
        }

        return \Barryvdh\DomPDF\Facade\Pdf::loadView('foodalchemist::dokumente.anleitung', $data + ['istPdf' => true])
            ->download('Anleitung-' . $rezept->id . '.pdf');
    }

    return view('foodalchemist::dokumente.anleitung', $data + ['istPdf' => false]);
})->name('foodalchemist.rezepte.anleitung');

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
