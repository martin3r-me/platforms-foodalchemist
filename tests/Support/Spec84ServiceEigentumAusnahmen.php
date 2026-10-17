<?php

// Spec 84 · Bestand des Wächters „Sichtbar ist nicht eigen" (Spec84ServiceEigentumWaechterTest).
// Je Methode der fachliche Grund, warum sie nach visibleToTeam ohne Eigentums-Riegel schreiben darf
// (Urteile aus docs/PLANUNG/84_B2_Pruefliste.md §4). Die Liste darf nur schrumpfen.
// Stand 2026-10-17: 17 Methoden.

return [
    'Services/BulkEnrichService.php::verarbeiteGp' => 'legt nur eigene KI-Vorschläge an (team_id = Team); der Riegel sitzt in uebernehmenGp',
    'Services/BulkEnrichService.php::verarbeiteRezept' => 'legt nur eigene KI-Vorschläge an (team_id = Team); der Riegel sitzt in uebernehmen',
    'Services/ConceptService.php::alsVorlageSpeichern' => 'Kopie ins eigene Team (anpassen = eigene Kopie); das Original wird nur gelesen',
    'Services/ConceptService.php::duplicate' => 'Kopie ins eigene Team (anpassen = eigene Kopie); das Original wird nur gelesen',
    'Services/ConceptService.php::forkVonVorlage' => 'Kopie einer sichtbaren Vorlage ins eigene Team; die Vorlage wird nur gelesen',
    'Services/FeedbackService.php::erstelle' => 'eigene Feedback-Zeile (team_id = Team) zu einem sichtbaren Rezept; das Rezept wird nicht verändert',
    'Services/MatchService.php::bulkFuerLieferant' => 'legt nur eigene Match-Vorschläge an; sichtbare Artikel werden nur gelesen',
    'Services/OrderService.php::addManualLine' => 'eigene Bestellzeile, die einen sichtbaren Lieferantenartikel referenziert (bestellbar = sichtbar)',
    'Services/PaketService.php::duplicate' => 'Kopie ins eigene Team (anpassen = eigene Kopie); das Original wird nur gelesen',
    'Services/PreislistenImportService.php::stage' => 'nur Staging-Zeilen des eigenen Teams; sichtbare Lieferanten werden nur gelesen',
    'Services/RecipeService.php::duplicate' => 'Kopie ins eigene Team (anpassen = eigene Kopie); das Original wird nur gelesen',
    'Services/SalesRecipeService.php::createFromBasis' => 'neues eigenes Gericht, das ein sichtbares Basisrezept referenziert (Master-Vererbung)',
    'Services/StammLieferantService.php::setStamm' => 'eigene Stamm-Zeile (team_id = Team), die einen sichtbaren Lieferanten referenziert',
    'Services/SupplierItemService.php::create' => 'eigener Artikel (team_id = Team) an einem sichtbaren Lieferanten',
    'Services/SupplierService.php::addContact' => 'eigener Kontakt (team_id = Team) an einem sichtbaren Lieferanten',
    'Services/VocabularyService.php::createEinheit' => 'eigene Einheit; exists() über die Kette ist nur Dubletten-Schutz',
    'Services/VocabularyService.php::createRecipeCategory' => 'eigene Kategorie, die eine sichtbare Hauptgruppe referenziert',
];
