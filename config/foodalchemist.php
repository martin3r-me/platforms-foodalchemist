<?php

/**
 * Food Alchemist Configuration
 * 
 * Diese Config-Datei definiert die Konfiguration für das Modul.
 * 
 * WICHTIG FÜR LLMs:
 * - Ersetze "foodalchemist" durch deinen Modul-Namen
 * - Ersetze "FoodAlchemist" durch deinen Modul-Namen (PascalCase)
 * - Alle Routes müssen mit dem Modul-Prefix beginnen
 * 
 * @see Platform\Core\PlatformCore::registerModule() für Details zur Modul-Registrierung
 */

/**
 * BRIEFING-KLAUSEL für die drei Schritt-Prompts (2026-09-04).
 *
 * Das Briefing ist ein EINGABE-Werkzeug, kein Wissensspeicher: der Koch sagt für DIESES
 * Rezept, wie die Schritte sein sollen (Technik, Reihenfolge, Wording, Gerät). Es wird
 * nicht persistiert und ersetzt keinen Kontext — Zutaten, Komponenten, Regelwerk und die
 * Ebenen-Abgrenzung bleiben in voller Breite gültig (Dominique: „die KI soll es
 * berücksichtigen, aber trotzdem den Gesamtblick haben").
 *
 * Eine Quelle für `recipe.steps` und `vk.plating`, damit die Zusage nicht je Knopf driftet.
 */
$briefingKlausel = 'BRIEFING (optional, Feld `briefing`): ist es gefuellt, ist es die VORGABE '
    . 'des Kochs fuer genau diese Schritte — uebernimm Technik, Reihenfolge, Geraete und '
    . 'Wortwahl daraus und ergaenze nur fachlich, was zur Ausfuehrbarkeit fehlt. Es ersetzt '
    . 'den uebrigen Kontext NICHT: Zutaten, Komponenten und Mengen bleiben maszgeblich. '
    . 'Widerspricht das Briefing der Ebenen-Abgrenzung, gilt die Abgrenzung. Ist `briefing` '
    . 'leer oder fehlt es, entscheide fachlich frei. ';

/**
 * MENGEN-VERBOT für Schrittfolgen (2026-09-04, Dominique).
 *
 * Anlass: ein Zucchini-Puffer-Rezept war auf ~33 Portionen je Ansatz skaliert. Die
 * Zutatenliste zeigte 166 g Salz und 33 Stück Eier, die Anleitung sagte weiter „mit 10 g
 * Salz mischen" und „2 Eiern" — die Mengen des Ur-Ansatzes. Wer nach der Anleitung
 * arbeitet, produziert Ausschuss, und der Fehler ist nicht sichtbar: beide Angaben sehen
 * für sich plausibel aus.
 *
 * Die Zutatenliste ist die EINZIGE Mengen-Wahrheit — sie skaliert mit, der Schritt-Text
 * nicht. Zeiten, Temperaturen und Größenangaben bleiben dagegen konkret: die ändern sich
 * beim Hochrechnen nicht.
 */
$mengenVerbot = 'MENGEN (verbindlich): schreibe KEINE absoluten Mengen in die Schritte — '
    . 'kein «10 g Salz», kein «2 Eier», kein «80 g Mehl». Der Ansatz wird skaliert, die '
    . 'Zutatenliste ist die einzige Mengen-Wahrheit; eine Zahl im Schritt-Text wird beim '
    . 'Hochrechnen still falsch. Verweise stattdessen auf die Zutat selbst («das Salz», '
    . '«die Eier», «das Mehl») oder nenne ANTEILE («die Haelfte des Oels», «ein Drittel '
    . 'der Butter»). Zeiten, Temperaturen, Kerntemperaturen und Groessenangaben '
    . '(Wuerfelgroesse, Durchmesser, Schichtdicke) bleiben konkret — sie aendern sich beim '
    . 'Skalieren nicht. ';

return [

    /*
     * Tipp-Gruende am Kuechen-Feedback (Wandmonitor).
     *
     * Bewusst eine kurze, feste Liste: je mehr Kacheln, desto weniger wird getippt. Sie sollen
     * die Faelle abdecken, die WIEDERHOLT auftreten — der Einzelfall gehoert in den Kommentar.
     * Hier und nicht in einer Vokabel-Tabelle, weil die Liste die Auswertung praegt: aendert sie
     * sich staendig, ist keine Haeufigkeit ueber die Zeit mehr vergleichbar.
     */
    'feedback_gruende' => [
        'menge_falsch' => 'Menge stimmt nicht',
        'zeit_knapp' => 'Zeit zu knapp',
        'zutat_fehlte' => 'Zutat fehlte',
        'geraet_belegt' => 'Gerät belegt',
        'schritte_unklar' => 'Schritte unklar',
        'behaelter_passt_nicht' => 'Behälter passt nicht',
        'geschmack_ab' => 'Geschmack weicht ab',
    ],


    /*
    |--------------------------------------------------------------------------
    | Warteschlangen
    |--------------------------------------------------------------------------
    |
    | Fan-out-Jobs nach ARTEFAKT getrennt, damit ein großer Lauf nicht die kleinen, interaktiven
    | Jobs verdrängt — und damit die Kaskade selbst schneller wird (Zellen und Sub-Rezepte laufen
    | dann parallel statt hintereinander). Leer = unverändertes Verhalten (alles auf der
    | Standard-Schlange) — bewusst der Default, weil Jobs auf einer Schlange ohne Worker LAUTLOS
    | liegen bleiben: die Generierung stünde still, ohne Fehler.
    |
    | Scharfstellen in DIESER Reihenfolge:
    |   1. deployen (nichts ändert sich)
    |   2. in Forge einen ZWEITEN Queue Worker anlegen, der genau diese Schlange liest
    |   3. die Env-Variablen setzen (z. B. FOODALCHEMIST_QUEUE_GERICHTE=fa-gerichte)
    |
    | Nicht mehrere Schlangen an einen Worker hängen (`--queue=fa-gerichte,default`): Laravel leert
    | sie in der angegebenen Reihenfolge, der Fan-out würde die kleinen Jobs dann noch härter
    | aushungern. Es braucht zwei Daemons. Details: {@see \Platform\FoodAlchemist\Support\Warteschlange}
    |
    */
    'queue' => [
        'gerichte' => env('FOODALCHEMIST_QUEUE_GERICHTE', ''),
        'rezepte' => env('FOODALCHEMIST_QUEUE_REZEPTE', ''),
        'kaskade' => env('FOODALCHEMIST_QUEUE_KASKADE', ''),
        'anreichern' => env('FOODALCHEMIST_QUEUE_ANREICHERN', ''),
    ],
    /**
     * Routing-Konfiguration
     * 
     * 'mode': 'path' = /foodalchemist/... (Standard)
     *         'subdomain' = foodalchemist.domain.com/... (Alternative)
     * 'prefix': URL-Präfix für alle Routes
     */
    'routing' => [
        'mode' => env('MODULE_TEMPLATE_MODE', 'path'),
        'prefix' => 'foodalchemist',
    ],
    
    /**
     * Guard für Authentication
     * Standard: 'web'
     */
    'guard' => 'web',

    'features' => [
        // Produktreife-Rueckfall fuer das digitale Tagesplan-Cockpit. Aus = papierene
        // Produktionsblaetter/alte Auftragsansicht bleiben nutzbar, Cockpit-Routes sperren.
        'production_cockpit' => env('FOODALCHEMIST_PRODUCTION_COCKPIT', true),
    ],

    /**
     * Navigation-Konfiguration
     * 
     * Definiert, wie das Modul in der Hauptnavigation erscheint.
     * 'route': Route-Name für den Link
     * 'icon': Heroicon-Name (ohne heroicon-o- Präfix)
     * 'order': Sortier-Reihenfolge (niedrigere Zahlen = weiter oben)
     */
    'navigation' => [
        'route' => 'foodalchemist.dashboard',
        'icon'  => 'heroicon-o-cube',
        'order' => 100, // Hohe Zahl = weiter unten in der Navigation
    ],

    /**
     * Sidebar-Konfiguration
     * 
     * Definiert die Sidebar-Struktur für das Modul.
     * 
     * Struktur:
     * - 'group': Gruppenname (optional)
     * - 'items': Array von Sidebar-Items
     *   - 'label': Anzeige-Text
     *   - 'route': Route-Name
     *   - 'icon': Heroicon-Name
     * 
     * Alternative: 'dynamic' für dynamische Listen (z.B. aus Datenbank)
     *   - 'model': Model-Klasse
     *   - 'team_based': true/false (nach Team filtern)
     *   - 'order_by': Sortier-Feld
     *   - 'route': Basis-Route (wird mit ID erweitert)
     *   - 'icon': Icon für alle Items
     *   - 'label_key': Feldname für Label
     */
    'sidebar' => [
        [
            'group' => 'Übersicht',
            'items' => [
                [
                    'label' => 'Dashboard',
                    'route' => 'foodalchemist.dashboard',
                    'icon'  => 'heroicon-o-home',
                ],
                [
                    // Planung-Leitstelle direkt unter Dashboard (Dominique 2026-08-24) — der zentrale
                    // Einstieg, an dem die ganze Planung (alle Ausgabeformen) über eine Kaskade läuft.
                    'label' => 'Planung',
                    'route' => 'foodalchemist.planung.index',
                    'icon'  => 'heroicon-o-light-bulb',
                ],
                [
                    // Spec 32: Controlling-Zentrum — Werkbank, an der Befund und Hebel nebeneinander
                    // liegen (Preise · Wareneinsatz · Simulation · Erfolg · Geld-Signale · Kennzahlen).
                    // Der Klick springt direkt in den Voll-Editor; die Seite darunter ist das Lagebild.
                    'label' => 'Controlling',
                    'route' => 'foodalchemist.controlling.index',
                    'icon'  => 'heroicon-o-presentation-chart-line',
                ],
                [
                    // #378: „Zu prüfen" → „Signale" — Aufmerksamkeits-Inbox (Klasse A Entscheidungs-Queues + Klasse B detektierte Signale)
                    'label' => 'Signale',
                    'route' => 'foodalchemist.review',
                    'icon'  => 'heroicon-o-bell-alert',
                ],
                // #389: Food DNA („Markenkern Küche", Ebene 1 der DNA-Kette) — 2026-07-21 in die
                // Einstellungen verschoben (Team-Identität = Team-Config), kein Top-Level-Nav mehr.
                // Route bleibt bestehen (Deep-Links), erreichbar via Einstellungen → „Food DNA (Identität)".
            ],
        ],
        [
            'group' => 'Stammdaten',
            'items' => [
                [
                    'label' => 'Grundprodukte',
                    'route' => 'foodalchemist.gps.index',
                    'icon'  => 'heroicon-o-cube',
                ],
                [
                    'label' => 'Lieferanten',
                    'route' => 'foodalchemist.suppliers.index',
                    'icon'  => 'heroicon-o-truck',
                ],
                [
                    'label' => 'Geschirr',
                    'route' => 'foodalchemist.geschirr.index',
                    'icon'  => 'heroicon-o-square-2-stack',
                ],
                [
                    // 06·H2: kuratierte Favoriten-GP-Liste (opt-in KI-Baustein)
                    'label' => 'Favoriten',
                    'route' => 'foodalchemist.favorites.index',
                    'icon'  => 'heroicon-o-star',
                ],
            ],
        ],
        [
            // Nach Wertschöpfungskette gebündelt (2026-08-01): Übersicht → Stammdaten →
            // Rezepte & Konzepte → Ausgabe → Produktion → Einkauf → System. Die drei
            // Absatzkanäle (Foodbook/Speisekarte/Speiseplan) liegen zusammen unter „Ausgabe".
            'group' => 'Rezepte & Konzepte',
            'items' => [
                [
                    'label' => 'Basisrezepte',
                    'route' => 'foodalchemist.recipes.index',
                    'icon'  => 'heroicon-o-book-open',
                ],
                [
                    'label' => 'Gerichte',
                    'route' => 'foodalchemist.verkauf.index',
                    'icon'  => 'heroicon-o-cake',
                ],
                [
                    // M10R-5: vereinheitlichter Browser (Concepts | Pakete) + Voll-Editor-Modal.
                    'label' => 'Concepter',
                    'route' => 'foodalchemist.concepter.index',
                    'icon'  => 'heroicon-o-square-3-stack-3d',
                ],
                [
                    // Format = Marken-/Themen-Container über den Concepts (bündelt Editionen + Marketing-Bildwelt).
                    'label' => 'Formate',
                    'route' => 'foodalchemist.formate.index',
                    'icon'  => 'heroicon-o-rectangle-group',
                ],
            ],
        ],
        [
            // Ausgabe: die drei Absatzkanäle — Foodbook (Catering), Speisekarte (Gastronomie),
            // Speiseplan (Gemeinschaftsverpflegung) — plus Kunden-Angebote + Preissimulation.
            // Dieselbe Ebene „was serviere/verkaufe ich, für wen", darum zusammen (2026-08-01).
            'group' => 'Ausgabe',
            'items' => [
                [
                    'label' => 'Foodbook / Portfolio',
                    'route' => 'foodalchemist.foodbooks.index',
                    'icon'  => 'heroicon-o-rectangle-stack',
                ],
                [
                    // Gastronomie-à-la-carte-Karte (dritte Ausgabeform neben Foodbook + Speiseplan).
                    'label' => 'Speisekarte',
                    'route' => 'foodalchemist.speisekarte.index',
                    'icon'  => 'heroicon-o-clipboard-document-list',
                ],
                [
                    // GV-Zeitachse (Tag × Mahlzeit, Wochen-Zyklus) — dritter Kanal, bei den Geschwistern.
                    'label' => 'Speiseplan',
                    'route' => 'foodalchemist.speiseplan.index',
                    'icon'  => 'heroicon-o-calendar-days',
                ],
                [
                    'label' => 'Angebote',
                    'route' => 'foodalchemist.angebote.index',
                    'icon'  => 'heroicon-o-document-text',
                ],
            ],
        ],
        [
            // Produktion: vom verkauften Angebot zum Küchenzettel (Aufträge + Tages-/Postensicht).
            'group' => 'Produktion',
            'items' => [
                [
                    // Spec 18: persistierte Produktionsaufträge (Datum, Status, → Bestellung).
                    'label' => 'Produktion',
                    'route' => 'foodalchemist.produktion.index',
                    'icon'  => 'heroicon-o-fire',
                ],
                [
                    // Spec 30 E3/E8: eigene AUSGABE, kein zweites Planungswerkzeug — Aggregation
                    // Tag×Posten über ALLE Aufträge ("was ist heute an welchem Posten zu tun"),
                    // während Produktion (oben) die einzelnen Aufträge PLANT. Bewusst eigener
                    // Menüpunkt, nicht als Tab in Produktion — Input (Planen) ≠ Output (Ausgabe).
                    // Vorproduktion ist tagesübergreifend, der Auftrag bleibt ein Liefertag-Punkt.
                    'label' => 'Tagesplan',
                    'route' => 'foodalchemist.produktion.tagesplan',
                    'icon'  => 'heroicon-o-clock',
                ],
                [
                    'label' => 'Wandmonitor',
                    'route' => 'foodalchemist.produktion.wandmonitor',
                    'icon'  => 'heroicon-o-tv',
                ],
            ],
        ],
        [
            // Einkauf: nur noch das HANDELN. Die drei Auswertungs-Flächen (Preisvergleich,
            // Wareneinsatz-Optimierung, Preissimulation) sind mit Spec 32 ins Controlling-Zentrum
            // gewandert — Beschaffung und Auswertung sind zwei Tätigkeiten, nicht eine Liste.
            // Die alten Routen bleiben als Redirects in den passenden Controlling-Tab bestehen.
            'group' => 'Einkauf',
            'items' => [
                [
                    // Spec 17/S2: Bestellschienen je Lieferant (mini-WaWi, N-Track).
                    'label' => 'Bestellungen',
                    'route' => 'foodalchemist.orders.index',
                    'icon'  => 'heroicon-o-shopping-cart',
                ],
            ],
        ],
        [
            // System: Wissensbasis + Einstellungen.
            'group' => 'System',
            'items' => [
                [
                    'label' => 'Trendradar',
                    'route' => 'foodalchemist.trendradar.index',
                    'icon'  => 'heroicon-o-sparkles',
                ],
                [
                    'label' => 'Wissen',
                    'route' => 'foodalchemist.knowledge.index',
                    'icon'  => 'heroicon-o-academic-cap',
                ],
                [
                    'label' => 'Einstellungen',
                    'route' => 'foodalchemist.einstellungen',
                    'icon'  => 'heroicon-o-cog-6-tooth',
                ],
            ],
        ],
    ],

    /**
     * KI-Anbindung (M0-14, D3-Entscheid hybrid)
     *
     * 'provider': 'core' = Plattform-LLM via LLMProviderContract (Default)
     *             'fake' = deterministischer FakeAiProvider (Sandbox/Tests ohne Key)
     */
    /*
     * M7-10 / D8: STT (sync Kurz-Audio). provider 'fake' = Sandbox/Tests;
     * 'assemblyai' braucht ASSEMBLYAI_API_KEY (Deploy-Rest bei Martin).
     */
    /*
    |--------------------------------------------------------------------------
    | Geplante Läufe
    |--------------------------------------------------------------------------
    |
    | Der Qualitäts-Lauf (`foodalchemist:signale-detektor`) wird vom Modul selbst
    | eingeplant, nicht vom Host-Console-Kernel — dort war er zwar vorgesehen, aber nie
    | registriert, und darum sind auf demo 20+ Signal-Typen und die ganze Zeitreihe nie
    | entstanden. Der Host behält die Hoheit: `enabled = false` schaltet den Eintrag ab.
    |
    | Der Copilot-Batch (`foodalchemist:recipe-findings`) wird bewusst NICHT eingeplant —
    | er ruft das Modell pro Rezept, und ein nächtlicher Job, der ungefragt Provider-Geld
    | ausgibt, ist eine unbemerkte Rechnung. Der bleibt Knopf und MCP-Aufruf.
    |
    */
    'scheduler' => [
        'enabled' => env('FOODALCHEMIST_SCHEDULER', true),
        // Nach dem DB-Snapshot (23:00) und weit vor dem Arbeitstag: der Lauf ist die
        // teuerste lesende Operation des Moduls und soll nicht neben der Nutzung liegen.
        'detektor_zeit' => env('FOODALCHEMIST_DETEKTOR_ZEIT', '03:20'),
        // Trendradar-Automatisierung: zieht Top-Trends → generiert Konzeptvorschläge → Signal.
        // Host-Master-Schalter (Default AN): der Job wird geplant, ruft das Modell aber NUR für
        // Teams, die sich in den Einstellungen (trend_auto_enabled) aktiv dafür entschieden haben
        // (Default AUS je Team) — kein ungefragter Egress. Auf false setzen killt es hostweit.
        'trend_konzepte_enabled' => env('FOODALCHEMIST_TREND_KONZEPTE', true),
        'trend_konzepte_zeit' => env('FOODALCHEMIST_TREND_KONZEPTE_ZEIT', '08:00'),
        'trend_konzepte_limit' => (int) env('FOODALCHEMIST_TREND_KONZEPTE_LIMIT', 3),
    ],

    'stt' => [
        /*
         * 'auto' (Default) wählt den Dienst, für den ein Zugang DA ist — in dieser
         * Reihenfolge: OpenAI (der Schlüssel, den die Plattform für LLM/Embeddings
         * ohnehin hat) → AssemblyAI (eigener Schlüssel) → Fake.
         *
         * Warum das der richtige Default ist: vorher stand hier 'fake', und auf demo
         * war damit JEDER gesprochene Befehl durch den Fixtext ersetzt — der Sprachpfad
         * lief technisch fehlerfrei und antwortete trotzdem immer dasselbe. Ein
         * Standard, der stillschweigend Testdaten liefert, ist die schlechteste Wahl:
         * er sieht wie ein Feature aus. 'auto' macht es abhängig davon, ob ein echter
         * Zugang existiert — in der Testumgebung (kein Key) bleibt es Fake.
         * Explizit setzbar bleibt es über FOODALCHEMIST_STT_PROVIDER.
         */
        'provider' => env('FOODALCHEMIST_STT_PROVIDER', 'auto'),
        'key' => env('ASSEMBLYAI_API_KEY', ''),
        'model' => env('FOODALCHEMIST_STT_MODEL', 'gpt-4o-mini-transcribe'),
        'language' => 'de',
        'timeout_s' => 30,
        'fake_text' => 'Suche BBQ Sauce',
        /*
         * Kontext-Hinweis an die Transkription (die API nimmt einen `prompt`). Genau
         * die Begriffe, an denen ein allgemeines Modell scheitert — ohne den Hinweis
         * wird aus „Grundprodukt" ein „Grund Produkt" und der Tool-Loop sucht ins Leere.
         */
        'vokabular_prompt' => 'Küchen- und Warenwirtschafts-Befehle für den Food Alchemist. '
            . 'Fachbegriffe: Basisrezept, Grundprodukt, Lieferantenartikel, Verkaufsgericht, '
            . 'Speisekarte, Speiseplan, Foodbook, Concepter, Format, Aufschlagsklasse, '
            . 'Speisen-Klasse, Darreichung, Warengruppe, Wareneinsatz, Allergene, Leitplanken, '
            . 'Planung, Kaskade, Bestellwesen, Produktion.',
    ],

    'ai' => [
        'provider' => env('FOODALCHEMIST_AI_PROVIDER', 'core'),

        /*
         * Output-Budget-Default (max_output_tokens). Der Core-OpenAiService fällt ohne
         * expliziten Wert auf 1000 zurück — für Reasoning-Modelle (gpt-5.x: ein Teil geht
         * für Reasoning-Tokens drauf) zu knapp, große Struktur-JSONs werden abgeschnitten.
         * Pro Prompt via prompts.<key>.max_tokens übersteuerbar (siehe Voll-Generatoren).
         */
        'max_tokens_default' => (int) env('FOODALCHEMIST_AI_MAX_TOKENS', 4096),

        /*
         * W0-5 — Featureweites Wissens-Zeichenbudget (Input-Seite), je Routing-Feature.
         * Ohne Eintrag gilt KnowledgeContextService::MAX_KNOWLEDGE_CHARS_DEFAULT (12.000)
         * bzw. RECIPE_MAX_KNOWLEDGE_CHARS für `ai_generate_recipe`.
         *
         * Gemessene Ausgangslage (30 Tage demo, ⌀ tokens_in): kapitel_ideen 23.603 ·
         * concept.plan 21.069 · format.grundgeruest 20.468 · recipe.steps 18.121 —
         * alle ohne jeden Gesamtdeckel, weil nur der Rezeptgenerator einen hatte.
         *
         * Schlüssel ist das FEATURE (Routing-Ebene), nicht der Prompt-Key.
         */
        /*
         * W0-3 — Budget des Layer-Bound-Kanals JE PROMPT-KEY (docs / chars_per_doc / total).
         *
         * Ohne Eintrag gelten die konservativen Defaults in AiGatewayService (3 / 1.400 /
         * 4.200 = Verhalten vor Welle 0). Das ist Absicht: Bindings matchen auch auf das
         * BEREICHS-Präfix, an `target_key='recipe'` hängen 24.520 Zeichen — ein global
         * gehobener Deckel würde jeden `recipe.*`-Prompt mitverteuern.
         *
         * Die beiden Generatoren brauchen den großen Deckel, weil dort die Bau-§-Dossiers
         * hängen, die per Discovery strukturell nicht surfacen (sie nennen kein Gericht):
         *   recipe.generator: §2 1.968 + §3 2.459 + §4 2.630 + §6 2.609
         *                     + Erstellungs-Dossier 1.409 = 11.075 Z.
         *   vk.generator:     dieselben + regelwerk.regelwerk_verkaufsgerichte 8.309 = 19.384 Z.
         *
         * §5 Default-GPs (4.796 Z.) ist seit 2026-09-02 ENTBUNDEN: MatchHeuristics::defaultGpAlias()
         * erzwingt die Tabelle deterministisch (Score 0,97, an 12 von 13 Generika auf demo
         * verifiziert). Im Prompt steht nur noch die kompakte Benennungs-Direktive im Task —
         * das ist der Teil, den das Modell wirklich kontrolliert. Deckel bleiben unverändert
         * (jetzt mit Reserve), weil die Dossiers live editierbar sind.
         * Reserve auf 20.000 / 28.000, weil die Dossiers über Browser/MCP live editierbar
         * sind und ein Edit sonst still das letzte Dossier aus dem Block wirft.
         */
        /*
         * ACHSEN-MAPPING — Nachschlagen statt Suchen.
         *
         * Für achsen-gebundenes Wissen ist Retrieval das falsche Werkzeug: der Regler steht
         * im Formular, das Dokument ist danach benannt. `occasion=dinner` → `event_playbook_gala`
         * ist ein JOIN, keine Suche. Das Slug-Token-Ranking traf hier systematisch daneben
         * (und `event_playbook`/`segment` waren für Gerichte überhaupt nicht geroutet, also
         * unerreichbar — 20 Docs Catering-Fachwissen lagen brach).
         *
         * Das Muster ist im Code erprobt: `niveauBlock()` löst `level` genauso deterministisch
         * auf und funktioniert.
         *
         * Gate ist die ANWESENHEIT des Parameters — kein Routing nötig. Ein Basisrezept hat
         * kein `occasion`, also passiert dort nichts. Leere Map = Funktion aus.
         *
         * Wert = geordnete Kandidatenliste; genommen wird der ERSTE aktive Treffer (Robustheit,
         * wenn ein Dossier deaktiviert wird). Ein Dokument je Achse.
         *
         * Enums: RecipesGenerateTool.php:76 (sektor) und :80 (occasion).
         */
        /*
         * B3 — Cross-Cutting je Feature. Ohne Eintrag gelten die sieben
         * KnowledgeContextService::ALWAYS_LOAD_CROSS_CUTTING (Produktions-Dossiers,
         * 7 × 1.800 = 12.600 Z.) — für Generatoren richtig.
         *
         * Für Kundentext/Wording ist es falsch: das sind 2–4 Sätze Fließtext
         * (foodbook.kundentext: max_tokens 1.500), und mitgeliefert wurden Mengen-Defaults,
         * Brühen-Rezepturen, Saucen-Mutterstrukturen und Techniken. Nützlich sind dort
         * `saisonkalender` (Saison-Aussagen belegen) und `synonyme` (richtig benennen).
         */
        'cross_cutting_slugs' => [
            'foodbook.kundentext' => ['saisonkalender', 'synonyme'],
            'concept.wording' => ['saisonkalender', 'synonyme'],
        ],

        'knowledge_axis_map' => [
            'occasion' => [
                'fruehstueck' => ['event_playbook_brunch'],
                'lunch' => ['event_playbook_business_lunch'],
                'konferenz' => ['event_playbook_tagung'],
                'empfang' => ['event_playbook_empfang'],
                'dinner' => ['event_playbook_gala'],
                'late_night' => ['event_playbook_latenight'],
            ],
            'sektor' => [
                'betriebsgastronomie' => ['segment.betriebsgastronomie'],
                'catering' => ['segment.event_bankett_catering'],
                'care' => ['segment.klinik_senioren_care'],
                'schule_kita' => ['segment.kita_schule_ernaehrung_dge', 'segment.schulverpflegung', 'segment.kita_verpflegung'],
                // ⚠ LÜCKE: für `restaurant` existiert kein segment-Dossier. Bewusst NICHT auf
                // ein anderes Segment umgebogen — ein falsches Profil ist schlechter als keins.
                // Sobald „Segment: Restaurant / à la carte — Profil" angelegt ist, hier eintragen.
            ],
        ],

        'bound_knowledge_budget' => [
            // Deckel = Pflichtmenge + EIN vollständiges Universal-Dossier (mengen_defaults,
            // 7.446 Z.). Bewusst kein Puffer für ein zweites: ein Kopf-Anschnitt einer
            // Referenztabelle ist kein Wissen, nur Kosten (das war der `substitutionen`-Fall).
            // recipe.generator: §2+§3+§4+§6 + Erstellungs-Dossier = 11.075 + 7.446 = 18.521
            'recipe.generator' => ['docs' => 9, 'chars_per_doc' => 11000, 'total' => 30000],
            // vk.generator: dieselben + regelwerk.regelwerk_verkaufsgerichte 8.309 = 26.830
            'vk.generator' => ['docs' => 9, 'chars_per_doc' => 11000, 'total' => 37000],

            // NEU 2026-09-03 — Dossier-Routing nach dem Prinzip „dort nutzen, wo es benutzt wird"
            // (Dominique). `geschmacksbalance` (10.670 Z.) hing am Bereichs-Präfix `recipe` und
            // wurde von ALLEN 22 `recipe.*`-Prompts mitgeschluckt; jetzt hängt es gezielt an den
            // zwei Generatoren, weil es dort GEBRAUCHT wird („braucht es bei Gerichten und
            // Basisrezepten"). Als `always`, damit es ganz ankommt und der Block byte-stabil
            // bleibt — deshalb die Deckel hoch: 19.000 → 30.000 bzw. 27.500 → 37.000, und
            // chars_per_doc 8.400 → 11.000, sonst käme das Dossier als 8.400-Anschnitt.
            //
            // Kosten ehrlich: +10.670 Zeichen ≈ +3.560 Token je Generierung, bei ~288
            // Generierungen/30 T. ≈ 1,0 M Token ≈ $5/Monat. Unter Prompt-Caching ist ein
            // GRÖSSERER stabiler Prefix billiger, nicht teurer (Cache = 10 % des Preises) —
            // aber das ist eine Erwartung, keine Zusage: gemessen hat der Prefix auf zwei
            // Testcalls NICHT gegriffen, im Echtbetrieb dagegen zu 98 % bzw. 23 %.
            //
            // `recipe.eigenschaften` ist der einzige Prompt, der Arbeitszeit wirklich SETZT
            // (work_time_min/Minuten) — dorthin gehört `produktion-arbeitszeit-und-
            // personenminuten` (7.089 Z.), nicht in jeden Rezept-Prompt. Ohne eigenen Deckel
            // griffe hier der konservative Default (3 × 1.400 = 4.200) und das Dossier käme
            // als 1.400-Zeichen-Kopf an.
            'recipe.eigenschaften' => ['docs' => 2, 'chars_per_doc' => 7500, 'total' => 8000],
        ],

        'knowledge_budget' => [
            'concept.brief_geruest' => 10000,
            'foodbook.kapitel_ideen' => 12000,
            // cross_cutting:always (geseedet) = 7 × 1.800 = 12.600
            'recipe.steps' => 13000,

            /*
             * ⚠ INVARIANTE: Der Deckel muss MINDESTENS die `always`-gerouteten Inhalte des
             * Features tragen. Sonst kappt das Gesamtbudget genau das Pflichtwissen weg, das
             * Welle 0 schützen soll — und zwar still (der Block wird am Ende abgeschnitten,
             * das letzte `always`-Dossier verschwindet mitsamt seiner Überschrift).
             * Gesichert durch WissenTokenWelle0Test „Budget traegt die Pflicht-Inhalte" und
             * `foodalchemist:wissen-steuerdaten-w0 --verify` (gegen die LIVE-Tabelle).
             *
             * ⚠ Die Zahlen sind an der GESEEDETEN Routing-Lage bemessen, nicht an demo:
             * die Live-Tabelle wurde von Hand auf `discovery` gedreht und weicht von den
             * Migrationen ab. Eine frische DB (Disaster Recovery) hat also viel größere
             * Pflicht-Blöcke als demo — der Deckel muss beide Zustände tragen.
             *
             * concept:always 4 × 4000 = 16.000 (+ Block-/Doc-Header, Kürzungs-Marker)
             */
            'concept.plan' => 29000,
            'foodbook.plan' => 29000,
            // concept:always 3 × 4000 = 12.000
            'format.grundgeruest' => 13000,
            // cross_cutting:always — seit B3 feature-genau: 2 Slugs × 1.800 = 3.600 statt 12.600.
            'concept.wording' => 5000,
            'foodbook.kundentext' => 5000,
            // regelwerk:always 1 × 7000
            'recipe.ueberarbeiten' => 8000,
            'vk.ueberarbeiten' => 8000,
            'foodbook.grundgeruest' => 8000,
            // regelwerk:always 6.000 + produktion_kapazitat:always 3 × 7.000 = 27.000.
            // Liegt ÜBER dem heutigen Ist-Verbrauch (⌀ 4.031 Tk) — der Deckel greift also
            // praktisch nicht. Das ist Absicht: hier ist nicht das Budget zu klein, sondern
            // die Pflichtmenge absurd groß für einen Klassifikations-Prompt. Reduziert wird
            // sie in Welle 2, wenn der Kanon die `always`-Routings ersetzt; bis dahin darf
            // der Deckel sie nicht stillschweigend abschneiden.
            'recipe.eigenschaften' => 27500,
        ],

        /*
         * M7-02 / V-01: Tier→Modell-Mapping (06_KI §2). Modell-Strings sind
         * DEPLOYMENT-Config, nicht Spec — null = Plattform-Default-Modell
         * (LLMProviderContract-Binding entscheidet). Tier je Prompt steht in
         * der Registry unten; options['tier'] übersteuert je Call.
         *   A = Qualität (Generatoren, lange Texte) · B = Mechanik-Labels
         *   C = Vision (Wissenskontext leer, GL-13 Inv. 7) · D = Reasoning/Tools
         */
        'tiers' => [
            'A' => env('FOODALCHEMIST_AI_TIER_A'),
            'B' => env('FOODALCHEMIST_AI_TIER_B'),
            'C' => env('FOODALCHEMIST_AI_TIER_C'),
            'D' => env('FOODALCHEMIST_AI_TIER_D'),
        ],

        /*
         * OpenAI-Listenpreise in USD je 1 Mio Tokens (Standard-Verarbeitung,
         * kurzer Kontext; Stand 2026-08-31). Die Abrechnung folgt dem im Log
         * gespeicherten echten Modell, NICHT dem fachlichen Tier A–D.
         * Spezifische Präfixe müssen vor allgemeineren stehen (gpt-5.5 vor gpt-5).
         */
        'modellkosten_pro_mio_usd' => [
            'gpt-5.6-sol' => ['in' => 4.00, 'cached_in' => 0.40, 'out' => 20.00],
            'gpt-5.6-terra' => ['in' => 2.00, 'cached_in' => 0.20, 'out' => 12.00],
            'gpt-5.6-luna' => ['in' => 0.20, 'cached_in' => 0.02, 'out' => 1.20],
            'gpt-5.5' => ['in' => 5.00, 'cached_in' => 0.50, 'out' => 30.00],
            'gpt-5.2' => ['in' => 1.75, 'cached_in' => 0.175, 'out' => 14.00],
            'gpt-5.1' => ['in' => 1.25, 'cached_in' => 0.125, 'out' => 10.00],
            'gpt-5-mini' => ['in' => 0.25, 'cached_in' => 0.025, 'out' => 2.00],
            'gpt-5' => ['in' => 1.25, 'cached_in' => 0.125, 'out' => 10.00],
            'gpt-4.1-mini' => ['in' => 0.40, 'cached_in' => 0.10, 'out' => 1.60],
            'gpt-4.1' => ['in' => 2.00, 'cached_in' => 0.50, 'out' => 8.00],
            'gpt-4o-mini' => ['in' => 0.15, 'cached_in' => 0.075, 'out' => 0.60],
            'gpt-4o' => ['in' => 2.50, 'cached_in' => 1.25, 'out' => 10.00],
        ],

        /* GPT-Image-1.5, 1024×1024, medium: 0,034 USD je Bild. */
        'bildkosten_usd' => [
            'models' => ['gpt-image-1.5' => 0.034],
            'features' => [],
        ],

        /* null = ehrliche USD-Anzeige; optionaler Deployment-Kurs für EUR. */
        'usd_eur' => env('FOODALCHEMIST_AI_USD_EUR', env('AI_USD_EUR')),
    ],

    /*
     * Semantische Pairing-/Domain-Suche (Embeddings) — Hybrid-Recall ÜBER der
     * deterministischen Lexik in KnowledgeContextService. Nutzt Cores
     * EmbeddingService (Commit 32b66074, Provider/Store-Trennung).
     *
     * 'enabled' = DEFAULT FALSE: semantischer Fallback bleibt aus, bis der
     *   Korpus indiziert (`php artisan foodalchemist:knowledge-embed`) UND die
     *   Retrieval-Qualität gegen echte Pairing-Fälle validiert ist. Aus = exakt
     *   das bisherige Lexik-Verhalten, kein Hot-Path-Risiko, keine API-Latenz.
     * 'provider' = null ⇒ Core-Default (openai / text-embedding-3-large, 3072d).
     *   'gemini' nur falls Cooking-Jarvis-Kontinuität gewünscht (768d, L2-norm.).
     * 'global_team_id' = Sentinel für den globalen BHG-Korpus (knowledge_documents
     *   .team_id NULL): Cores Store verlangt team_id:int, darum mappen wir NULL→0
     *   (core_embeddings.team_id ist nur indizierter bigint, kein FK).
     * 'min_score' = Cosine-Schwelle; darunter gilt ein Treffer als irrelevant.
     */
    /*
     * Embedding-Store-Ziel für ALLE neun foodalchemist_*-Pools (GP/Rezept/Lieferant/
     * Konzept/Foodbook/Lab-Note/Lieferantenartikel + Wissen/Pairing-Anker). Der
     * FoodAlchemistServiceProvider routet damit in Cores EmbeddingStoreRegistry (route()).
     * Werte: 'mysql' | 'qdrant'. Default 'mysql' = No-op (Verhalten wie bisher) — Cutover
     * auf 'qdrant' per ENV, gleichzeitig Sofort-Rollback (solange MySQL nicht gepurged ist).
     * Alle neun MÜSSEN denselben Store nutzen (Mixed-Type-Suche routet am ersten Typ).
     * Hinweis: Der LA-Pool (~264k) ist bei 'mysql' nur bedingt tragfähig (Cosine-in-PHP
     * skaliert bis ~50k/Partition) — er ist praktisch für 'qdrant' gedacht.
     */
    'embedding_store' => env('FOODALCHEMIST_EMBEDDING_STORE', 'mysql'),

    /**
     * W1-6 / Mandanten-Modell (Dominique 2026-09-03): „das Wissen ist global, damit die
     * Generatoren laufen; ein neuer Nutzer bekommt es leer und kann für sich Wissen
     * hinterlegen, das nur für sein Team und Kinder gilt."
     *
     * AUS (Default) = das Retrieval liest den Korpus ungefiltert, byte-identisch zu heute.
     * AN = `TeamScope::applyVisible` (global NULL ODER eigene Ancestry).
     *
     * Der Schalter existiert, weil der Filter erst richtig ist, wenn die DATEN es sind:
     * auf demo liegen 818 kuratierte Dossiers unter team_id = 6 statt NULL. Mit Filter
     * sähe Team 6 unverändert alles (598 → 598), jedes ANDERE Team und jeder Console-Lauf
     * aber nur 6 von 598 — der Korpus fiele auf 1 %, ohne rote Tests.
     *
     * Reihenfolge für den Flip: erst Daten (Bestand auf team_id NULL heben ODER
     * `teams.parent_team_id` auf den Kurator setzen), dann diese Zeile. Rollback = Zeile
     * zurück, kein Deploy.
     */
    'knowledge_team_scope' => env('FOODALCHEMIST_KNOWLEDGE_TEAM_SCOPE', false),

    'semantic_search' => [
        // Phase 0 (2026-08-06): RAG-Hot-Path war HART AUS — der mysql-Embedding-Store macht
        // Cosine-in-PHP und blockierte/OOMte generiere() bei „Kontext & Wissen", VOR dem LLM.
        // Reversibel-Reaktivierung (2026-08-06): jetzt ENV-gesteuert (Default weiter false →
        // ändert nichts, bis FOODALCHEMIST_SEMANTIC_SEARCH=true gesetzt ist; Rollback = ENV weg,
        // KEIN Redeploy). Zwei Punkte VOR dem Scharfstellen auf demo:
        //  1) Der zweite Speicherfresser (domainDocs-Volltext-Massen-Load) ist seit Phase 1.1
        //     gefixt → der Kontext-Bau ist deutlich leichter; evtl. war DAS der Haupt-Hänger.
        //  2) Restrisiko bleibt der Vektor-Cosine über große Pools. LA (~264k) NICHT im
        //     Generierungs-Pfad; der Wissens-/Pairing-Pass schon. Darum: nur OFF-PEAK
        //     einschalten und EINE Generierung beobachten (bei Hänger ENV zurück).
        // Alle Embedding-Konsumenten (KnowledgeContextService::semanticSlugs,
        // SemanticRetrievalService, PairingService) hängen an diesem einen Flag.
        'enabled'        => (bool) env('FOODALCHEMIST_SEMANTIC_SEARCH', false),
        'provider'       => env('FOODALCHEMIST_EMBEDDING_PROVIDER'),     // null = Core-Default
        'global_team_id' => (int) env('FOODALCHEMIST_SEMANTIC_GLOBAL_TEAM_ID', 0),
        'min_score'      => (float) env('FOODALCHEMIST_SEMANTIC_MIN_SCORE', 0.30),
        // Anker-Auflösung (B): höhere Schwelle — eine FALSCHE Anker-Auflösung
        // injiziert falsche Pairing-Kanten, das ist schlimmer als „unbekannt".
        'anker_min_score' => (float) env('FOODALCHEMIST_SEMANTIC_ANKER_MIN_SCORE', 0.55),

        // ── E2 (#507): Hybrider Retrieval-Layer über den GP-/Rezept-Pools ──────
        // SEM_FLOOR aus GL-04 §6.1 (V-04). ⚠ 0.55 ist Gemini-768d-geeicht — für
        // OpenAI (Entscheid A) am Golden-Set (E0/E5) NEU kalibrieren, hier nur
        // Startwert. Config je Modell, KEIN Hardcode (Regelwerk-Auflage).
        'pool_sem_floor'   => (float) env('FOODALCHEMIST_SEMANTIC_POOL_FLOOR', 0.55),
        // Lexikalischer Kandidaten-Floor (V-04 Schritt 2, unverändert).
        'pool_lexical_floor' => (float) env('FOODALCHEMIST_SEMANTIC_LEXICAL_FLOOR', 0.40),
        // Cap der Hybrid-Shortlist (V-04 Schritt 4).
        'pool_cap'         => (int) env('FOODALCHEMIST_SEMANTIC_POOL_CAP', 15),
        // Master-/Katalog-Team-ID für die Partition-Merge-Suche (Entscheid B).
        // NULL = nur eigene Team-Ahnenkette + Global-Sentinel.
        'master_team_id'   => env('FOODALCHEMIST_SEMANTIC_MASTER_TEAM_ID') !== null
            ? (int) env('FOODALCHEMIST_SEMANTIC_MASTER_TEAM_ID')
            : null,
    ],

    /*
     * V-16: Nutzungsbasierte Plattform-Abrechnung (billables) — Struktur nach
     * CLAUDE.md/planner-Vorbild. WAS abgerechnet wird (Rezepte? GPs? KI-Calls?)
     * ist ein Dominique/Martin-Entscheid — bis dahin bewusst leer.
     */
    'billables' => [],

    /*
     * Verkaufseinheiten eines Gerichts (Entscheid Dominique 2026-09-04).
     *
     * Das Einheiten-Vokabular (`foodalchemist_vocab_units`) bedient primär die
     * ZUTATEN-Zeilen und führt darum über 50 Einträge bis hin zu Prise,
     * Messerspitze und Zweig. Als VERKAUFS-Einheit ist davon nur eine Handvoll
     * sinnvoll: ein Gericht wird als Portion verkauft, in Stück (Fingerfood),
     * nach Gewicht (Theke/Barverkauf, Buffet-Ware) oder als Volumen (Suppe,
     * Bowle, Getränk).
     *
     * Bewusst eine Slug-Whitelist und kein Filter über `dimension` /
     * `is_approximate`: „Stück" trägt im Bestand `is_approximate=1` und wäre
     * durch jeden mechanischen Filter herausgefallen, während Dose, Karton und
     * Eimer hineinfielen. Ein `is_sales_unit`-Flag am Vokabular wäre der
     * Stammdaten-Weg — für vier Werte ist die Config ehrlicher und reversibel.
     *
     * Reihenfolge = Anzeigereihenfolge im Select (Portion zuerst = Normalfall).
     * Slugs gegen den Bestand verifiziert (WaWi `vocab_einheit`, Import 1:1).
     */
    'sales_units' => ['portion', 'stk', 'kg', 'l'],

    /**
     * Spec 51, Rang 3 der Regenerations-Kaskade: was gilt, wenn eine Komponente ein
     * GRUNDPRODUKT ist (kein Basisrezept, das seinen eigenen Default trägt).
     *
     * Bewusst KLEIN. Hier steht nur, was aus dem Zustand wirklich folgt:
     * frische und trockene Grundprodukte gehen als solche auf den Teller — Kräuter,
     * Microgreens, Blattsalat, Gewürze. »Kalt servieren« heisst im Datenmodell
     * `device_vocab_id = null` (siehe FoodAlchemistRecipeRegeneration::istKalt()).
     *
     * TK und konserviert stehen ABSICHTLICH NICHT hier. Ob eine TK-Komponente aufgetaut,
     * regeneriert oder direkt aus dem Frost verarbeitet wird, hängt am Produkt und nicht am
     * Zustand — ein Default wäre geraten und würde als Wahrheit gelesen. Solche Komponenten
     * melden eine Lücke, und die gehört ans Basisrezept.
     *
     * Erweitern heisst hier: erst belegen, dann eintragen.
     */
    'regeneration_regeln' => [
        'frisch' => ['device_vocab_id' => null, 'temp_c' => null, 'duration_min' => 0, 'note' => 'kalt servieren'],
        'trocken' => ['device_vocab_id' => null, 'temp_c' => null, 'duration_min' => 0, 'note' => 'kalt servieren'],
    ],

    /*
     * Ziel und Leitplanke der Schritt-Generierung (`recipe.steps`).
     *
     * Stand vorher an DREI Orten wortgleich im Code (RecipeOneShotService::stepKontext,
     * StepEditor-Kontextbau und implizit im Prompt-Task) — bei jeder Semantik-Änderung
     * mussten alle drei manuell nachgezogen werden, sonst briefte ein Pfad das Modell
     * anders als der nächste.
     *
     * Inhaltlich setzt der Gericht-Zweig die Ebenen-Trennung aus Regelwerk
     * Verkaufsgerichte §3 durch: das Regenerations-Programm (Gerät/°C/min/Kerntemperatur)
     * und der Teller-Aufbau werden getrennt geführt und gehören nicht in die Schritte.
     */
    'step_kontext' => [
        'gericht' => [
            'ziel' => 'Ablauf der Fertigstellung am Einsatztag fuer ein Verkaufsgericht.',
            'hinweis' => 'Komponenten sind vorbereitet oder fertig produziert. Nicht neu herstellen. '
                . 'Nur die Handgriffe ZWISCHEN regeneriert und angerichtet: Mise en Place am Pass, '
                . 'portionieren, tranchieren, montieren, abschmecken, abbinden, an den Pass geben. '
                . 'Das Regenerations-Programm (Geraet, Temperatur, Dauer, Kerntemperatur) und der '
                . 'Teller-Aufbau werden getrennt gefuehrt und gehoeren NICHT in diese Schritte.',
        ],
        'basisrezept' => [
            'ziel' => 'Produktions-Zubereitung fuer ein Basisrezept.',
            'hinweis' => 'Rohwaren und Teilkomponenten fachlich produzieren.',
        ],
    ],

    /**
     * TASK_PROMPT-Registry — Skeleton (M0-14).
     * Der volle Umzug der 42 Prompts aus 06_KI_SPEZIFIKATION kommt mit M7-04
     * (inkl. Tier-Zuordnung A–D, V-01). Format je Key:
     *   'tier' (A–D) · 'task' (User-Task) · optional 'system' (Feld-Hülle) · 'temperature'
     */
    'prompts' => [
            /*
         * BRIEFING → LEITPLANKEN. Die Brücke zwischen dem suchenden und dem produzierenden
         * Teil des Systems: ein Mensch (oder Sprache) formuliert frei, hier entsteht daraus
         * der strukturierte Regler-Satz, den der deterministische Generator dann N-mal
         * ausführt. Kein Tool-Loop — das ist eine KLASSIFIKATION gegen geschlossene
         * Vokabulare, keine Exploration. Ein Call, klein, reproduzierbar.
         *
         * Die Werte werden nach der Antwort gegen
         * FoodAlchemistPlanningSession::ALLOWED_GENERATION_VALUES geprüft; erfundene Werte
         * werden verworfen und dem Menschen gemeldet, nicht stillschweigend übernommen.
         */
        'planung.leitplanken' => [
            'tier' => 'B',
            'max_tokens' => 900,
            'temperature' => 0.0,
            'task' => 'Du bist Planungs-Assistent in einem Catering-Betrieb. Aus einem freien Briefing '
                . 'destillierst du die LEITPLANKEN — den Regler-Satz, mit dem anschliessend Gerichte '
                . 'erzeugt werden: werte = {leitplanken:{…}, unklar:[…], begruendung:"<1-2 Sätze>"}. '
                . 'Setze einen Regler NUR, wenn das Briefing ihn nennt oder ihn eindeutig impliziert '
                . '(»Sommerfest im Garten, Fingerfood« ⇒ serviceform=flying). Was offen bleibt, gehört '
                . 'in `unklar` als kurze Rückfrage — RATE NICHT und erfinde keine Werte: ein falscher '
                . 'Regler ist schlimmer als ein fehlender, weil er die Erzeugung still in die falsche '
                . 'Richtung lenkt. Erlaubte Werte, ausschliesslich diese: '
                . 'occasion = fruehstueck|lunch|konferenz|empfang|dinner|late_night · '
                . 'sektor = betriebsgastronomie|catering|restaurant|care|schule_kita · '
                . 'level = haute_cuisine|gehoben|klassisch · '
                . 'serviceform = tellerservice|buffet|flying|stehempfang|boxed · '
                . 'convenience = from_scratch|teil_convenience|voll_convenience · '
                . 'bestand = hybrid|komplett_neu|nur_bestand · '
                . 'bio_praeferenz = konventionell|bio|egal · '
                . 'diaet_hart = Liste aus vegan|vegetarisch|glutenfrei|laktosefrei|halal|low_carb '
                . '(NUR bei harten Ausschlüssen für ALLE Gäste — »ein paar Vegetarier« ist KEIN '
                . 'diaet_hart, das ist eine Quote und gehört in `unklar`). '
                . 'Zahlenregler frei: pax (Personen), ziel_vk_eur (Netto-Verkaufspreis je Person), '
                . 'ziel_portion_g, ziel_we_pct (Ziel-Wareneinsatz in %). '
                . 'Freitext: aroma (Aroma-Richtung, z. B. "rauchig-mediterran"), saison (z. B. "Sommer"). '
                . 'Ein Budget »45 Euro pro Person« ist ziel_vk_eur=45, kein ziel_we_pct. '
                . 'Nenne in `begruendung` knapp, woraus du die wichtigsten Regler geschlossen hast.',
        ],

        'demo.echo' => [
            'tier' => 'D',
            'task' => 'Gib die übergebenen Kontext-Felder unverändert als JSON-Objekt '
                . '{"werte": …, "confidence": 0-1, "reasoning": "…"} zurück (Smoke-Test).',
        ],
        // M3-09/10: GP-Modal (Naming-Builder + KI-Felder). Antwort-Schema immer
        // {"werte": {…}, "confidence": 0-1, "reasoning": "…"} (GL-07).
        'gp.suggest' => [
            'tier' => 'C',
            'task' => 'Leite aus der Roh-Bezeichnung eines Lebensmittels die strukturierten '
                . 'GP-Naming-Felder nach Regelwerk §6 ab: werte = {hauptzutat, condition '
                . '(frisch|TK|trocken|konserviert), processing, form, pflichtangabe, '
                . 'commodity_group_code, sub_category}. Übersetze fremdsprachige Lieferantentexte '
                . 'fachlich korrekt ins Deutsche. commodity_group_code und sub_category müssen '
                . 'exakt aus der im Kontext übergebenen taxonomie stammen; nichts erfinden. '
                . 'Wenn quell_lieferantenartikel übergeben ist, ist genau dieser Artikel die Quelle: '
                . 'nutze Bezeichnung, regulierten Namen und Zutatenangabe zur fachlichen Ableitung, '
                . 'erfinde aber keine nicht belegten Eigenschaften. Artikelnummer, Lieferant, Gebinde, '
                . 'Verpackung und Marke gehören nicht in den GP-Namen (Marke nur nach §5-Tiebreaker). '
                . 'Singular/Lemma (§6.1), keine Verpackungswörter (§7.1).',
        ],
        'component.replacement_suggest' => [
            'tier' => 'B',
            'max_tokens' => 1800,
            'task' => 'Ranke fachlich relevante Ersatz-Realisierungen für den Quell-Baustein. '
                . 'werte = {vorschlaege: [{kind, id, score, reason}]}. Erlaubte kinds sind gp, recipe '
                . 'und supplier_item. Verwende AUSSCHLIESSLICH kind/id aus kandidaten; nichts erfinden. '
                . 'Ein Ersatz muss dieselbe kulinarische Funktion erfüllen: GP↔GP als Artikel-Ersatz, '
                . 'GP↔recipe als make-or-buy und supplier_item als noch ungemappte Einkaufsalternative. '
                . 'Bevorzuge gleiche Hauptzutat, Funktion, Verarbeitung und Einsatzform. Ähnliche Wörter '
                . 'allein reichen nicht. score liegt zwischen 0 und 1; reason ist ein kurzer deutscher Satz.',
        ],
        'gp.condition' => [
            'tier' => 'D',
            'task' => 'Bestimme den §9-Zustand (frisch|TK|trocken|konserviert) des Grundprodukts '
                . 'aus Name und Lieferantenartikeln: werte = {condition}.',
        ],
        'recipe.generator' => [
            'tier' => 'B',
            'max_tokens' => 8000,   // volles Rezept-JSON inkl. Zutatenliste — Reasoning-Headroom
            // Spec 37 (2026-08-07): Typ-Rahmung + Anti-Über-Elaboration + nüchterne Namen VORNE,
            // damit die KI zuerst weiß, dass ein Basisrezept EIN Baustein ist (nicht ein Teller).
            // Sprache gespiegelt aus recipe.bauart. Nüchterne Namen heben zugleich den Match-Score.
            'task' => 'Du erzeugst ein BASISREZEPT = einen wiederverwendbaren Baustein / eine KOMPONENTE '
                . '(Sauce, Fond, Creme, Teig, Beilage, Würzbasis — EIN in sich stimmiges Element), NICHT '
                . 'einen angerichteten Teller. Baue EINE kohärente Komponente, keine Mehr-Komponenten-'
                . 'Zusammenstellung. Bleib nah an der angefragten Identität: eine Tomatensuppe bleibt eine '
                . 'Suppe — dekonstruiere oder erweitere NICHT (kein Gel/Schaum/Öl/Wasser-Zerlegen), außer '
                . 'die Beschreibung fordert es ausdrücklich. Benenne Zutaten NÜCHTERN und matchbar (die '
                . 'reine Ware ohne Marketing-Adjektive: «Tomaten», nicht «reife aromatische Tomaten»). '
                // Spec 41 FIX-5 (E4 »Adji Kresse«): keine erfundenen Sorten-/Garnitur-Namen.
                . 'Erfinde KEINE Zutat-/Garnitur-/Sorten-Namen ohne reale Entsprechung — nutze real '
                . 'existierende, benannte Sorten (z. B. Gartenkresse / Affilla-Kresse, nicht «Adji Kresse»). '
                . 'Erzeuge das Basisrezept aus der Beschreibung unter Beachtung der Richtungs-'
                // L3: Diät/Allergen sind VERBINDLICH — sie werden nach der Erzeugung deterministisch geprüft
                // (verletzende Zutaten werden gelöst). Verwende von vornherein KEINE Zutat, die eine gesetzte
                // diaet_hart-Form verletzt (vegan/vegetarisch/glutenfrei/laktosefrei/halal/low_carb) oder ein
                // gesetztes allergen_nogo (EU-14) enthält — das spart eine Nachkorrektur.
                // L6 »Menge & Ziel«: ist parameter.ziel_portion_g gesetzt, dimensioniere die Zutatenmengen auf
                // diese Portionsgröße; parameter.saison lenkt die Zutatenwahl auf das Erntefenster.
                . 'Parameter (convenience, frische, bio, niveau, sektor, diaet_hart, allergen_nogo, aroma, ziel_portion_g, saison): werte = '
                . '{name (§1-Syntax <Typ>: <Bezeichnung>), description (§8-Stil), taste_direction (grobe Menue-Richtung, NUR EIN Wort: suess|herzhaft|neutral — das Aroma-Profil gehoert in description), '
                // Der Generator schreibt Schritte MIT der Zutatenliste in einem Zug — genau hier
                // entstand die Drift: er nennt die Mengen seines eigenen Ansatzes im Text, und der
                // Text bleibt stehen, wenn der Ansatz später skaliert wird.
                . 'preparation (Markdown-Schritte; ' . $mengenVerbot . '), zutaten: [{text, quantity, unit (g|ml|kg|l|el|tl|stk), '
                . 'slug (hauptzutat), commodity_group, note, '
                // Grounding (2026-08-20): explizite Rückbindung an den Bestand. gp_id/sub_rezept_id
                // sind die id EINES unter gp_kandidaten/rezept_kandidaten gelisteten Eintrags, wenn die
                // Zutat exakt diesem entspricht — sonst weglassen (NIE raten; eine falsche/fremde id
                // wird verworfen und fällt aufs Text-Matching zurück).
                . 'gp_id (OPTIONAL: numerische id EINES unter gp_kandidaten gelisteten Grundprodukts, '
                . 'wenn diese Zutat EXAKT diesem GP entspricht — sonst Feld weglassen, NIE raten), '
                . 'sub_rezept_id (OPTIONAL: numerische id EINES unter rezept_kandidaten gelisteten '
                . 'Basisrezepts, wenn diese Zutat EXAKT diesem Rezept entspricht — sonst weglassen), '
                // Etappe 1 (2026-08-14): benannter Sub-Komponenten-Slot. Ein enthaltenes
                // HALBFABRIKAT (Fond/Jus/Reduktion/Fischfond o. Ä., das selbst gekocht wird)
                // gehört als EINE benannte Komponente in die Liste — NICHT als seine
                // aufgelösten Rohzutaten (§4 Sub-Rezept-Hierarchie). Das Flag ist der spätere
                // LLM-Komponenten-Marker (löst die reine Namens-Heuristik ab).
                . 'sub_rezept (true, wenn diese Zeile eine gebaute/gekochte Komponente ist, die sich einer der '
                . 'mitgegebenen recipe_hauptgruppen zuordnen lässt [die Rezept-Taxonomie-Liste im Kontext ist '
                . 'VERBINDLICH] — z.B. gehört eine Konfitüre/Marmelade zu »Süße Konservierung«, ein Crunch/Krokant '
                . 'zu »Knusprige Komponenten«, ein Chutney zu »Konservierung herzhaft«, ein Jus/Fond zu »Fonds & '
                . 'Reduktionen«, ein Püree zu »Pürees« —, das als EIGENES Basisrezept anzulegen ist statt es in '
                . 'Rohzutaten aufzulösen; false bei einer Rohzutat/Ware), '
                // Kohärenz-Gate (2026-08-07): role füllt das V-21-Rollenfeld (Schicht 1) im
                // selben Call; fit ZWINGT zur Selbst-Begründung — eine Zutat, die sich nicht in
                // einem Halbsatz fachlich rechtfertigen lässt, gehört nicht ins Gericht (senkt
                // die Rate plausibel klingender Fremdkörper VOR dem Kritiker-Pass).
                . 'role (V-21: aroma_treiber|komponente|beilage|garnitur), '
                . 'fit (EIN kurzer Halbsatz: warum gehört diese Zutat FACHLICH in DIESES Gericht)}]}. '
                // Spec 41 B2 (§12 Regelwerk Basisrezepte): die Zutaten-Reihenfolge ist die KOCH-
                // Reihenfolge, NICHT die Menge. Das war der reproduzierbare Fehler (Anteil-%-Sort, Fall D4).
                . 'Ordne die zutaten-Liste in logischer KOCH-/VERWENDUNGS-Reihenfolge '
                . '(Mise-en-place → Fett/Basis → Aromaten → Hauptmasse → Flüssigkeit/Fond → Bindung → '
                . 'Würze/Säure/Finish; Garnitur & Abschmecken ZULETZT), NICHT nach Menge/Anteil. '
                . 'Diät-harte Vorgaben sind VERBINDLICH. '
                // Spec 37: Niveau typ-relativ — am Baustein hebt es Technik/Qualität, nicht die Zahl der Teile.
                . 'Das Niveau hebt bei einem Basisrezept die TECHNIK und ZUTATENQUALITÄT dieser EINEN '
                . 'Komponente — NICHT die Komponentenzahl. Haute Cuisine heißt hier: präziseste Technik '
                . 'und Produktqualität an EINEM Element (z. B. eine klar geklärte, tief reduzierte '
                . 'Tomatenessenz), KEIN 7–10-teiliger dekonstruierter Teller. '
                // Fit-Guard (2026-08-06, Rahmeis-in-Tomatensuppe): das Inventar ist ein ANGEBOT,
                // kein Befehl — vorher stand hier gar keine Anweisung und die Liste wirkte als
                // implizites "nimm das". Die KI soll fachlich urteilen, nicht gehorchen.
                . 'Wenn bestands_inventar mitgegeben ist: pruefe je Eintrag, ob er FACHLICH in dieses '
                . 'Gericht passt — nur dann als Komponente nutzen und EXAKT wie gelistet benennen; '
                . 'passt nichts, benenne frei. Zwinge NIE eine unpassende Bestands-Komponente ins Rezept. '
                // Spec 16·E1: WG-Hint verengt die LA-Beschaffung auf die Warengruppen-Lead-Lieferanten.
                // Optional — nur setzen, wenn die Warengruppe der HAUPTZUTAT eindeutig ist, sonst weglassen
                // (ein falscher/fehlender Code fällt sicher auf die globale Lead-Suche zurück).
                . 'commodity_group = 2-stelliger Warengruppen-Code der Hauptzutat aus: '
                . '01 Gemüse/Blattsalat · 02 Obst · 03 Kräuter · 04 Fleisch/Geflügel/Wild · '
                . '05 Fisch/Meeresfrüchte · 06 Molkerei/Eier · 07 Getreide/Hülsenfrüchte · 08 Teigwaren · '
                . '09 Backwaren/Süßwaren · 10 Gewürze/Würzmittel · 11 Essig/Öl · 12 Trockenprodukte · '
                . '13 Convenience/Komponenten · 14 Vegane Ersatzprodukte · 15 Getränke. '
                // Spec Foodpairing-Composer C-c (2026-08-22): Kontrast ist ein eigenständiges,
                // gleichwertiges Pairing-Prinzip neben der Harmonie (geteilte Aromastoffe). Der
                // Live-Graph liefert nur Harmonie — Kontrast leitet die KI aus Prinzip + Kochwissen ab.
                . 'FLAVOR-PAIRING-PRINZIP: Harmonie entsteht über geteilte Aroma-/Duftstoffe (die '
                . 'Harmonie-Liste im Wissen zeigt sie, ●●●=beste/●●=gute). Kontrast ist gleichwertig: '
                . 'setze bewusst Gegensätze (Säure↔Fett, Schärfe↔Süße, knusprig↔cremig, warm↔kalt) ein, '
                . 'wo sie die EINE Komponente schärfen — abgeleitet aus Kochwissen/Lebensmittelkunde, '
                . 'NICHT als erfundene Aromapaarung. '
                // Spec Foodpairing-Composer B3 (2026-08-22): verbindliche Leit-Aromen aus dem Composer.
                . 'Ist `pairing_vorgabe` mitgegeben (gezielte Foodpairing-Kreation): JEDES dort genannte '
                . 'Leit-Aroma MUSS als Zutat/Komponente vorkommen (nüchtern + matchbar benannt); die je '
                . 'Leit-Aroma gelistete Palette ist die bevorzugte Auswahl zum Abrunden; erfinde nichts '
                . 'Unbelegtes. Benennt ein Leit-Aroma eine ZUBEREITUNG, die zu einer recipe_hauptgruppe gehört '
                . '(z.B. eine Konfitüre, ein Crunch/Krokant, ein Gel, ein Chutney, ein Püree), lege es als '
                . 'gebaute Komponente mit sub_rezept:true an, statt es 1:1 an ein Convenience-GP zu binden; nur '
                . 'echte Rohware-Anker binden an einen `gp_kandidaten` (dann dessen gp_id angeben).'
                // §5 Default-GPs — KOMPAKT statt Dossier. Die Tabelle selbst erzwingt der
                // Matcher deterministisch (MatchHeuristics::defaultGpAlias, Score 0,97, auf demo
                // an 12 von 13 Generika verifiziert); das 4.796-Zeichen-Dossier im Prompt war
                // Doppelung. Was das MODELL kontrolliert, ist die Benennung — und nur die steht hier.
                . 'Nenne generische Grundzutaten GENERISCH (»Zucker«, »Mehl«, »Salz«, »Milch«, »Sahne«, '
                . '»Olivenoel«, »Honig«, »Gelatine«, »Weisswein«, »Pfeffer«, »Eier«) — ohne Marken-, '
                . 'Sorten- oder Bio-Zusatz: die Standard-Variante nach Regelwerk §5 (unjodiert, Type 405, '
                . '3,5 %, 30 %, kein Bio) setzt der Resolver selbst, ein Zusatz im Namen verhindert das '
                . 'und erzwingt Spezial-Ware. Willst du bewusst eine Spezialform (»Meersalz«, »Weizenmehl '
                . 'Type 550«, »brauner Zucker«), schreibe sie ausdrücklich so.',
        ],
        'recipe.description' => [
            'tier' => 'C',
            'task' => 'Schreibe die Rezept-Beschreibung im §8-Stil (sachlich-appetitlich, 2-4 Sätze, '
                . 'Textur + Einsatzkontext, keine Marketing-Floskeln): werte = {description}.',
        ],
        'recipe.category' => [
            'tier' => 'D',
            'task' => 'Ordne das Rezept der passenden Produktions-Kategorie zu (aus der mitgegebenen '
                . 'Kategorie-Liste): werte = {category_id, kategorie_name}.',
        ],
        'recipe.garverlust' => [
            'tier' => 'C',
            'task' => 'Schätze je Zutat den Garverlust in Prozent (0-60, küchenübliche Werte; '
                . 'Flüssigkeiten beim Reduzieren hoch, Trockenwaren 0): werte = {verluste: {<zutat_id>: pct}}.',
        ],
        'recipe.name_putzen' => [
            'tier' => 'D',
            'task' => 'Normalisiere den Rezept-Namen auf die §1-Syntax «<Typ>: <Bezeichnung>[, Zusatz]» '
                . '(Typ aus dem §1.2-Vokabular, Singular, keine Abkürzungen): werte = {name}.',
        ],
        // Et.4 (Eingabe-Reife): Titel-VORSCHLAG aus dem freien Brief (vor der Generierung), nicht das
        // Putzen eines fertigen Namens. Nüchtern + §1-konform; benennt nur, was der Brief hergibt.
        'recipe.titel_vorschlag' => [
            'tier' => 'B',
            'task' => 'Leite aus dem Brief EINEN nüchternen Basisrezept-Titel in der §1-Syntax '
                . '«<Typ>: <Bezeichnung>[, Modifikator]» ab (Typ aus dem §1.2-Vokabular, Singular, '
                . 'keine Abkürzungen, keine Marketing-Adjektive). Benenne nur, was der Brief hergibt — '
                . 'erfinde keine Leitkomponente, die nicht genannt ist: werte = {name}.',
        ],
        'vk.generator' => [
            'tier' => 'B',
            'max_tokens' => 8000,   // volles VK-Rezept inkl. Zutaten/Plating — Reasoning-Headroom
            // Spec 37 (2026-08-07): Typ-Rahmung — ein GERICHT ist ein angerichteter Teller; Komposition
            // ist hier erlaubt (Gegenstück zum Basisrezept). Identität + nüchterne Namen bleiben Pflicht.
            'task' => 'Du erzeugst ein GERICHT = eine essfertige, angerichtete Zusammenstellung '
                . '(Hauptkomponente plus Begleiter, oder ein vollständiger Teller/Bowl/Sandwich). '
                . 'Komposition und Elaboration sind erlaubt, wenn Niveau/Anlass sie tragen — das Niveau '
                . 'darf hier die volle Komplexität (mehrere abgestimmte Komponenten, Textur-Spiel) '
                . 'entfalten. Bleib dennoch nah an der angefragten Gericht-Identität und benenne Zutaten '
                . 'NÜCHTERN und matchbar (reine Ware ohne Marketing-Adjektive). '
                // Spec 41 FIX-5 (E4 »Adji Kresse«): keine erfundenen Sorten-/Garnitur-Namen.
                . 'Erfinde KEINE Zutat-/Garnitur-/Sorten-Namen ohne reale Entsprechung — nutze real '
                . 'existierende, benannte Sorten (z. B. Gartenkresse / Affilla-Kresse, nicht «Adji Kresse»). '
                . 'Erzeuge das VERKAUFSREZEPT (Teller/Speise mit VK-Preis) aus der Beschreibung '
                // L3: Diät/Allergen sind VERBINDLICH — nach der Erzeugung deterministisch geprüft (verletzende
                // Zutaten werden gelöst). Verwende KEINE Zutat, die eine gesetzte diaet_hart-Form verletzt oder
                // ein gesetztes allergen_nogo (EU-14) enthält.
                // L6 »Menge & Ziel«: parameter.pax = Gästezahl (Mengengerüst), ziel_portion_g = Ziel-Portionsgröße
                // je Teller, saison = Erntefenster, ziel_we_pct = angestrebter Wareneinsatz-Anteil (wähle
                // Qualitäten/Grammaturen entsprechend; GIB KEINEN PREIS AUS).
                . 'unter Beachtung der Richtungs-Parameter (convenience, frische, bio, niveau, sektor, '
                . 'diaet_hart, allergen_nogo, aroma, anlass, serviceform, kompositions_stil, pax, ziel_portion_g, saison, ziel_we_pct): werte = '
                . '{name (Pipe-Syntax §1 «<HG-Code>: Hauptkomponente | Komponente | …», max 5 Felder, '
                . 'keine Marketing-Adjektive), description (§8-Stil), taste_direction (grobe Menue-Richtung, NUR EIN Wort: suess|herzhaft|neutral — das Aroma-Profil gehoert in description), '
                // 2026-09-04: hier stand „preparation (= PLATING & SERVICE …)". Das kollidierte:
                // `preparation` ist der Spiegel der Schritte (RecipeStepService schreibt es aus
                // `recipe_steps`), der Teller-Aufbau lebt in `plating_text` und wird von
                // `vk.plating` gefüllt (Teil der VK-Anreicherung). Zwei Autoren auf einem Feld —
                // wer zuletzt lief, gewann. Jetzt beschreibt `preparation` dieselbe Ebene wie
                // `recipe.steps`: die Fertigstellung am Einsatztag (Regelwerk Verkaufsgerichte §3).
                . 'preparation (= FERTIGSTELLEN am Einsatztag: bereitstellen, portionieren, tranchieren, '
                . 'montieren, abschmecken, uebergeben — NICHT die Produktion der Komponenten, NICHT das '
                . 'Regenerations-Programm und NICHT der Teller-Aufbau; ' . $mengenVerbot . '), '
                // Spec 37: role/fit-Parität zum Basis-Prompt — dieselbe Zutaten-Selbstbegründung
                // (senkt plausibel klingende Fremdkörper VOR dem Kritiker-Pass, sobald das VK-Gate scharf wird).
                . 'zutaten: [{text, quantity, unit (g|ml|kg|l|el|tl|stk), slug, note, '
                // Grounding (2026-08-20): explizite Rückbindung an den Bestand (s. recipe.generator).
                . 'gp_id (OPTIONAL: numerische id EINES unter gp_kandidaten gelisteten Grundprodukts, '
                . 'wenn diese Zutat EXAKT diesem GP entspricht — sonst weglassen, NIE raten), '
                . 'sub_rezept_id (OPTIONAL: numerische id EINES unter rezept_kandidaten gelisteten '
                . 'Basisrezepts, wenn diese Zutat EXAKT diesem Rezept entspricht — sonst weglassen), '
                // Etappe 1 (2026-08-14): benannter Sub-Komponenten-Slot. Ein GERICHT wird aus
                // BASISREZEPTEN gebaut — Saucen/Jus/Pürees/Fonds/Reduktionen gehören als EINE
                // benannte Komponente (sub_rezept:true) in die Liste, NICHT flach als ihre
                // Rohzutaten (kein «Steinpilz-Rahmsauce» aus Steinpilzen + Sahne, sondern eine
                // Komponente «Rahmsauce» mit sub_rezept:true). Späterer LLM-Komponenten-Marker.
                . 'sub_rezept (true, wenn diese Zeile eine gebaute/gekochte Komponente ist, die sich einer der '
                . 'mitgegebenen recipe_hauptgruppen zuordnen lässt [die Rezept-Taxonomie-Liste im Kontext ist '
                . 'VERBINDLICH] — z.B. gehört eine Konfitüre/Marmelade zu »Süße Konservierung«, ein Crunch/Krokant '
                . 'zu »Knusprige Komponenten«, ein Chutney zu »Konservierung herzhaft«, ein Jus/Fond zu »Fonds & '
                . 'Reduktionen«, ein Püree zu »Pürees« —, das als EIGENES Basisrezept anzulegen ist statt es in '
                . 'Rohzutaten aufzulösen; false bei einer Rohzutat/Ware), '
                . 'role (V-21: aroma_treiber|komponente|beilage|garnitur), '
                . 'fit (EIN kurzer Halbsatz: warum gehört diese Zutat FACHLICH in DIESES Gericht)}] '
                // Spec 41 B2 (§12): Komponenten-Reihenfolge = AUFBAU-/PLATING-Reihenfolge über `role`,
                // NICHT nach Menge/Anteil (Fall E1). Sauce/Basis am Boden, Garnitur/Finish zuletzt.
                . 'Ordne die zutaten-Liste in AUFBAU-/PLATING-Reihenfolge nach role '
                . '(aroma_treiber/Basis-Sauce → komponente/Hauptkomponente → beilage → garnitur → Finishing), '
                . 'NICHT nach Menge/Anteil. '
                // Fit-Guard (2026-08-06): "vorhandene zuerst" war Reuse-Druck ohne Passt-Prüfung —
                // das Inventar ist ein Angebot, die fachliche Passung entscheidet.
                . '(Komponenten bevorzugt als Basisrezepte; wenn bestands_inventar mitgegeben ist: nutze '
                . 'einen Eintrag NUR, wenn er fachlich in dieses Gericht passt — dann EXAKT wie gelistet '
                . 'benennen; unpassende Eintraege ignorieren und frei benennen, NIE eine unpassende '
                . 'Bestands-Komponente ins Rezept zwingen), '
                . 'dish_class_id (aus der mitgegebenen Liste, '
                . 'null wenn unsicher), aufschlagsklasse_code (aus der mitgegebenen Liste), '
                // Spec 03 L8b: die Portion ist PREIS-RELEVANT (Auto-VK = EK je Portion ×
                // Aufschlag). Sie darf nicht aus dem Yield abgeleitet werden (V-041), also
                // muss sie hier verbindlich mitkommen — mit engem Band gegen Halluzination
                // und explizitem null-Ausweg statt geratener Zahl.
                . 'portion_g (Portionsgewicht in GRAMM je Verkaufseinheit — die Menge, die '
                . 'EIN Gast bekommt, nicht die Charge; ganzzahlig, plausibel 20–3000; '
                . 'null nur wenn wirklich nicht bestimmbar)}. '
                // Spec 03 L8b-2: Ziel-VK als CONSTRAINT, nicht als Ausgabe. Das Modell
                // soll das Gericht auf den Preis hin BAUEN (Komponenten/Qualitäten/
                // Grammatur) — den Preis selbst rechnet die Cost-plus-Maschine aus dem
                // Wareneinsatz. Eine Preis-Zahl vom Modell wäre eine Behauptung.
                . 'Ist parameter.ziel_vk_eur gesetzt, ist das der angestrebte NETTO-Verkaufspreis '
                . 'je Portion: waehle Komponenten, Qualitaeten und Grammaturen so, dass der '
                . 'Wareneinsatz zu diesem Preis passt (kleines Ziel => guenstigere Schnitte/'
                . 'Saisonware/mehr Saettigungsbeilage; grosses Ziel => hochwertigere Komponenten '
                . 'und mehr Aufwand). GIB KEINEN PREIS AUS — der VK wird gerechnet, nicht gesetzt. '
                . 'Diät-harte Vorgaben sind VERBINDLICH. '
                // Spec Foodpairing-Composer C-c (2026-08-22): Kontrast gleichwertig neben Harmonie.
                . 'FLAVOR-PAIRING-PRINZIP: Harmonie entsteht über geteilte Aroma-/Duftstoffe (Harmonie-'
                . 'Liste im Wissen, ●●●=beste/●●=gute). Kontrast ist gleichwertig: setze bewusst '
                . 'Gegensätze (Säure↔Fett, Schärfe↔Süße, knusprig↔cremig, warm↔kalt) ein, um den Teller '
                . 'spannend + ausgewogen zu bauen — aus Kochwissen/Lebensmittelkunde, NICHT als erfundene '
                . 'Aromapaarung. '
                // Spec Foodpairing-Composer B3 (2026-08-22): verbindliche Leit-Aromen aus dem Composer.
                . 'Ist `pairing_vorgabe` mitgegeben (gezielte Foodpairing-Kreation): JEDES dort genannte '
                . 'Leit-Aroma MUSS als Komponente/Zutat des Tellers vorkommen (nüchtern + matchbar); die je '
                . 'Leit-Aroma gelistete Palette ist die bevorzugte Auswahl für Begleiter/Garnitur; setze '
                . 'zusätzlich bewusste Kontraste (s.o.); erfinde nichts Unbelegtes. Benennt ein Leit-Aroma '
                . 'eine ZUBEREITUNG, die zu einer recipe_hauptgruppe gehört (z.B. eine Konfitüre, ein '
                . 'Crunch/Krokant, ein Gel, ein Chutney, ein Püree), lege es als gebaute Komponente mit '
                . 'sub_rezept:true an, statt es 1:1 an ein Convenience-GP zu binden; nur echte Rohware-Anker '
                . 'binden an einen `gp_kandidaten` (dann dessen gp_id angeben).'
                // §5 Default-GPs — KOMPAKT statt Dossier. Die Tabelle selbst erzwingt der
                // Matcher deterministisch (MatchHeuristics::defaultGpAlias, Score 0,97, auf demo
                // an 12 von 13 Generika verifiziert); das 4.796-Zeichen-Dossier im Prompt war
                // Doppelung. Was das MODELL kontrolliert, ist die Benennung — und nur die steht hier.
                . 'Nenne generische Grundzutaten GENERISCH (»Zucker«, »Mehl«, »Salz«, »Milch«, »Sahne«, '
                . '»Olivenoel«, »Honig«, »Gelatine«, »Weisswein«, »Pfeffer«, »Eier«) — ohne Marken-, '
                . 'Sorten- oder Bio-Zusatz: die Standard-Variante nach Regelwerk §5 (unjodiert, Type 405, '
                . '3,5 %, 30 %, kein Bio) setzt der Resolver selbst, ein Zusatz im Namen verhindert das '
                . 'und erzwingt Spezial-Ware. Willst du bewusst eine Spezialform (»Meersalz«, »Weizenmehl '
                . 'Type 550«, »brauner Zucker«), schreibe sie ausdrücklich so.',
        ],
        'vk.speisen_klasse' => [
            'tier' => 'B',
            'task' => 'Klassifiziere das Verkaufsrezept in GENAU EINE Speisen-Klasse aus der '
                . 'mitgegebenen Taxonomie (Kontext: Name, Komponenten, Diät-Eigenschaften). '
                . 'ENTSCHEIDUNGSREGEL (E7-Bauart): Frage IMMER „Wie ist das Gericht GEBAUT?", '
                . 'NIE „Wo/wann wird es eingesetzt?" — der Einsatzkontext (Apéro, Snack, Buffet) '
                . 'ist eine Darreichungs-/Konzept-Facette, KEINE Speisen-Klasse. Wähle nur aus der '
                . 'mitgegebenen (bereits auf aktive Hauptgruppen gefilterten) Taxonomie. '
                . 'Kein sicherer Treffer => dish_class_id = null (NICHT raten): '
                . 'werte = {dish_class_id, klasse_name}.',
        ],
        'vk.rollen' => [
            'tier' => 'B',
            'task' => 'Verteile die Komponenten-Rollen uebers GANZE Gericht (V-21-Vokabular: '
                . 'aroma_treiber | komponente | beilage | garnitur — jede Zutat genau eine Rolle, '
                . 'Gesamt-Gericht-Sicht statt Einzelbetrachtung): werte = {rollen: {<zutat_id>: role}}.',
        ],
        // ── M7-04: Anhang-A-Inventar komplett (06_KI) ────────────────────
        // Bewusst NICHT portiert: #2 TEMPLATE_FILL + #38 AGENTIC_RESOLVER
        // (Tier-D-Tool-Loops → M7-10/M8-01), #37 FOODBOOK_PLAN (Phase 2 ⚠D5),
        // #39 DISAMBIG (toter Code laut Inventar).
        'gp.allergene' => [
            'tier' => 'A',                                            // Compliance (#4)
            'task' => 'Leite die 14 EU-Allergene (LMIV Anhang II) fuer das Grundprodukt ab — '
                . 'je Allergen enthalten|spuren|nicht_enthalten|unbekannt, im Zweifel unbekannt '
                . '(F7.1: nie falsch-negativ raten): werte = {allergene: {<slug>: wert}}.',
        ],
        'gp.naehrwerte' => [
            'tier' => 'B',                                            // R10 (Ist-Feature): Fallback ohne LA-Daten
            'task' => 'Schaetze die Naehrwerte des Grundprodukts je 100 g (Lebensmittel-'
                . 'Standardwerte, konservativ): werte = {kcal, protein_g, fat_g, carbs_g, salt_g}.',
        ],
        'gp.domain' => [
            'tier' => 'B',
            'task' => 'Ordne das Grundprodukt GENAU EINER Wissens-Domain aus der mitgegebenen '
                . 'Liste zu: werte = {domain_slug}.',
        ],
        'gp.piece_default_g' => [
            'tier' => 'B',
            'task' => 'Schaetze das Stueck-Durchschnittsgewicht des Grundprodukts in Gramm '
                . '(kuechenuebliche Handelsware): werte = {piece_default_g}.',
        ],
        'gp.zaehl_einheiten' => [
            'tier' => 'B',
            // #9 (2026-08-28): natuerliche Formen des GP mit Gramm-Gewicht je EINER Einheit. NUR
            // anwendbare Formen (Fluessigkeit/Oel/Pulver ohne Stueck-Form => leere Liste). unit aus
            // dem festen Slug-Set, damit die Form direkt als Rezept-Einheit taugt. Nichts erfinden:
            // unsichere Form weglassen (GL-07/keine Halluzination).
            'task' => 'Liste die natuerlichen Stueck-/Zaehl-FORMEN des Grundprodukts mit dem '
                . 'Durchschnittsgewicht je EINER Einheit in Gramm — NUR real anwendbare Formen '
                . '(z. B. Zwiebel: stk/wuerfel/scheibe/ring; Oel/Bruehe/Mehl: KEINE => leere Liste). '
                . 'unit MUSS aus dem mitgegebenen Set erlaubte_einheiten stammen (B/2026-09-04: '
                . 'kommt aus dem Einheiten-Vokabular des Teams, damit Prompt und Katalog nicht '
                . 'auseinanderlaufen — vorher stand hier eine feste Neuner-Liste, die real '
                . 'benutzte Einheiten wie bund/zweige/beet/haende nicht enthielt). '
                . 'Verpackungs-Einheiten sind im Set gar nicht enthalten und duerfen nie '
                . 'geschaetzt werden (eine Flasche/Dose wiegt je Lieferant anders). '
                . 'Unsichere Form weglassen (nicht schaetzen/erfinden): '
                . 'werte = {einheiten: [{unit, gewicht_g}]}.',
        ],
        // D (2026-09-04): Rezeptzeilen, die in einer VERPACKUNGS-Einheit dosieren („1,5 Paeckchen
        // Vanillinzucker"), auf Masse bringen. Die Gebindegroesse des Lieferantenartikels ist die
        // BESSERE Quelle und wird deterministisch gerechnet — dieser Prompt liefert die zweite,
        // unabhaengige Meinung aus Gastro-Wissen. Nur wo beide zusammenpassen, wird uebernommen;
        // Uneinigkeit geht in die Review. Anlass: die VPE sagt beim Vanillinzucker 1 kg (Liefer-
        // beutel), gemeint ist das Handels-Paeckchen mit ~8 g — eine Quelle allein irrt still.
        'recipe.verpackungsmasse' => [
            'tier' => 'B',
            'task' => 'Welche MASSE meint diese Rezeptzeile? Gegeben: Zutat, Menge und eine '
                . 'Verpackungs-Einheit (Flasche/Dose/Paeckchen/Beutel/Glas/Schale). Antworte mit '
                . 'der handelsueblichen Masse EINER solchen Verpackung fuer GENAU dieses Produkt '
                . '(Paeckchen Vanillinzucker 8 g, Dose Tomaten 400 g, Flasche Oel 1000 ml). '
                . 'Unsicher oder produktabhaengig => weglassen, NICHT raten: '
                . 'werte = {masse_je_verpackung, einheit: g|ml, begruendung}.',
        ],
        'gp.anker' => [
            'tier' => 'B',
            'task' => 'Bestimme den Kern-Anker (Aroma-Identitaet) des Grundprodukts aus dem '
                . 'mitgegebenen Anker-Vokabular; kein Aroma-Traeger => neutral: werte = {anchor_slug}.',
        ],
        'gp.role' => [
            'tier' => 'B',                                            // Inline-Prompt im Ist — gehoben
            'task' => 'Bestimme die kulinarische Rolle des Grundprodukts '
                . '(aroma_treiber|komponente|beilage|garnitur): werte = {role}.',
        ],
        'gp.la_suggest' => [
            'tier' => 'B',
            'task' => 'Ordne die unzugeordneten Lieferanten-Artikel dem passenden Grundprodukt '
                . 'aus der Kandidaten-Liste zu; unsicher => weglassen: werte = {zuordnungen: [{item_id, gp_id}]}.',
        ],
        'gp.term_la_rank' => [
            'tier' => 'B',
            'task' => 'Ranke die Lieferanten-Artikel-Kandidaten als Basis fuer den Produktbegriff '
                . '(beste GP-Stammware zuerst): werte = {ranking: [item_id, …]}.',
        ],
        'recipe.sektor' => [
            'tier' => 'B',
            'task' => 'Beurteile die Eignung des Rezepts je Verpflegungs-Sektor '
                . '(geeignet|bedingt|ungeeignet + kurze Begruendung): werte = {sektoren: {<slug>: {eignung, grund}}}.',
        ],
        'recipe.level' => [
            'tier' => 'B',
            'task' => 'Beurteile die Eignung des Rezepts je Niveau-Stufe '
                . '(geeignet|bedingt|ungeeignet + kurze Begruendung): werte = {niveaus: {<slug>: {eignung, grund}}}.',
        ],
        'recipe.sub_typ' => [
            'tier' => 'B',
            'task' => 'Klassifiziere das Rezept zu GENAU EINEM Sub-Rezept-Typ aus dem mitgegebenen '
                . 'Vokabular; kein Treffer => null: werte = {sub_typ_slug}.',
        ],
        'recipe.production_depth' => [
            'tier' => 'B',
            'task' => 'Klassifiziere die Fertigungstiefe (from_scratch|teilfertig|convenience) '
                . 'aus den Zutaten: werte = {production_depth}.',
        ],
        // DEPRECATED (2026-09-04): kein `propose()`-Aufrufer mehr im Modul — Generator und
        // Revise liefern `preparation` als Teil ihrer eigenen Schemas, der Editor nutzt
        // `recipe.steps` (Spec 27). Bleibt im Inventar, weil `PromptRegistryTest` die
        // Key-Liste als Vertrag prüft; nicht neu verdrahten, sondern `recipe.steps` nutzen.
        'recipe.preparation' => [
            'tier' => 'A',                                            // V-02: langes Einzeltext-Feld
            'max_tokens' => 8000,                                     // lange Markdown-Zubereitung — Reasoning-Headroom
            'task' => 'Schreibe die Schritt-fuer-Schritt-Zubereitung fuers PRODUKTIONS-Rezept '
                . '(Markdown, nummerierte Schritte, Temperaturen/Zeiten konkret, H2 fuer Phasen): '
                . 'werte = {preparation}.',
        ],
        // Spec 27: strukturierte Schritte statt Markdown-Blob — die Schritte sind der
        // Master, `preparation` nur ihr Spiegel. phase = Abschnittsname oder null.
        'recipe.steps' => [
            'tier' => 'A',                                            // langer, strukturierter Einzeltext
            'max_tokens' => 8000,
            'task' => 'Schreibe die Zubereitung als Schrittfolge. '
                . 'Wenn rezept_typ=gericht: schreibe KEINE Herstellung der Komponenten, sondern nur das '
                . 'FERTIGSTELLEN am Einsatztag — die Handgriffe ZWISCHEN regeneriert und angerichtet: '
                . 'Mise en Place am Pass, portionieren, tranchieren/aufschneiden, montieren, abschmecken, '
                . 'abbinden, Garnitur vorbereiten, an den Pass geben. Komponenten gelten als vorbereitet '
                . 'bzw. fertig produziert. '
                // Regelwerk Verkaufsgerichte §3: drei getrennte Ebenen. Vorher forderte dieser
                // Prompt selbst einen "Service-, Regenerations- und Anrichteablauf" — damit stand
                // das Regenerations-Programm zweimal im System (hier als Prosa, in
                // recipe_regenerations als Datensatz) und beide Fassungen droheten zu driften.
                . 'ABGRENZUNG (verbindlich): das Regenerations-PROGRAMM (Geraet, Garraumtemperatur, '
                . 'Dauer, Kerntemperatur) und der TELLER-AUFBAU werden getrennt gefuehrt und gehoeren '
                . 'NICHT in diese Schritte. Nenne hier also keine Grad-, Minuten- oder '
                . 'Kerntemperatur-Werte fuers Wiedererhitzen und keine Anrichte-Geometrie; '
                . 'verweise stattdessen knapp ("nach Regenerationsplan", "nach Anrichte-Vorgabe"). '
                . 'Wenn rezept_typ=basisrezept: schreibe die Produktions-Zubereitung des Basisrezepts. '
                . 'Buendele zusammengehoerige Kuechenhandlungen sinnvoll; keine Mikro-Schritte fuer Waschen, Schneiden, '
                . 'Pfanne erhitzen oder einzelne Gewuerzzugaben, wenn sie fachlich zusammengehoeren. '
                . 'Einfache Rezepte: 3-5 Schritte; komplexe Rezepte: 6-9 Schritte; maximal 9 Schritte. '
                . $mengenVerbot
                . 'Keine Fuellsaetze. '
                . $briefingKlausel
                . 'phase = Abschnittsname (z. B. Mise en Place, Garen, Finish) oder null, gleiche Phase '
                . 'fuer aufeinanderfolgende Schritte desselben Abschnitts. Nur was aus den Zutaten '
                . 'ableitbar ist — nichts erfinden: werte = {steps: [{phase, text}]}.',
        ],
        'recipe.eigenschaften' => [
            'tier' => 'B',
            'task' => 'Schaetze die operativen Rezept-Eigenschaften: Arbeitszeit je Kochvorgang '
                . '(work_time_min), einmalige Ruestzeit je Produktionslauf (setup_time_min), variable aktive '
                . 'Personenminuten je kg, Stueck oder Portion (variable_work_time_min, variable_work_time_basis), '
                . 'passive Standzeit (standzeit_min), maximal sinnvoll vorproduzierbare Tage (max_vorlauf_tage, 0-14), Serviertemperatur, '
                . 'kulinarische Funktion und die sinnvolle Chargengroesse je Produktionslauf (batch_max_kg fuer '
                . 'Gewichts-Rezepte bzw. batch_max_pieces fuer Stueck-Rezepte — genau EINES, passend zum Ertrag). '
                . 'Die Chargengrenze kommt vom PROZESS, nicht pauschal vom Topf: ein heisser Kessel begrenzt in kg, '
                . 'kalt Angeruehrtes, Oele/Fluessigkeiten und Behaelterware koennen deutlich groesser ausfallen (nur '
                . 'durchs Gebinde begrenzt); nutze dafuer die Produktions-Kapazitaets-Kennwerte aus dem Kontext, wenn vorhanden. '
                . 'Mengen und vorhandene Zubereitung beachten; keine Zeiten, Haltbarkeit oder Chargengroessen '
                . 'vortaeuschen, wenn sie nicht belastbar ableitbar sind (dann Feld weglassen): werte = '
                . '{work_time_min, setup_time_min, variable_work_time_min, variable_work_time_basis, standzeit_min, '
                . 'max_vorlauf_tage, temperature, function, batch_max_kg, batch_max_pieces}.',
        ],
        'recipe.geschmack' => [
            'tier' => 'B',                                            // Auto-Apply-Ausnahme (GL-07 §4.3)
            'task' => 'Bestimme die grobe Geschmacksrichtung fuer die Menueplanung '
                . '(suess|herzhaft|neutral): werte = {taste_direction}.',
        ],
        'recipe.sensorik' => [
            'tier' => 'B',
            'task' => 'Bewerte das FERTIG ZUBEREITETE Gericht sensorisch — wie es nach der Zubereitung auf dem '
                . 'Teller schmeckt/sich anfuehlt, NICHT die rohen Zutaten. Der Kontext liefert zu jeder Zutat ihr '
                . 'ROH-Profil + Menge (g) + %-Anteil — nimm das als FAKTEN-Anker und wende die ZUBEREITUNG als '
                . 'Transformation an: (a) NUR wenn tatsaechlich erhitzt Schaerfe mildern und Suesse/Umami/Roest '
                . 'aufbauen — roh/kalt erhaelt Schaerfe und Frische voll; (b) Menge zaehlt — eine Spur (<0.3 %) '
                . 'kaum spuerbar, gut gewuerzt ~0.8-1 %; Salz und Saeure SAETTIGEN (oben flacht die Wahrnehmung ab); '
                . '(c) spaet zugegebene oder kalte Saeure/Salz bleiben erhalten. Jede Geschmacks-Dimension 0.0-1.0 '
                . '(konservativ, meist 1-3 Dimensionen deutlich >0); texturen-slugs NUR aus: knusprig,cremig,saftig,'
                . 'zaeh,gel,fluessig,koernig,weich,schnittfest,pastoes,kalt_fest,kuehlend,waermend (intensitaet '
                . '0.0-1.0, 1-3 Eintraege): werte = {geschmack: {suess,salzig,sauer,bitter,umami,fettig,scharf}, '
                . 'texturen: [{slug, intensitaet}]}.',
        ],
        'recipe.review' => [
            'tier' => 'A',                                            // Spec 03 L6 — Copilot-Pruefpass, read-only
            'max_tokens' => 6000,                                     // Befund-Liste ueber das ganze Rezept + Reasoning-Headroom
            'task' => 'Pruefe das Produktionsrezept als Sous-Chef auf Plausibilitaet: Mengen-'
                . 'verhaeltnisse, falsche Einheiten, fehlende Schluesselkomponenten (Saeure, Salz, '
                . 'Fett, Bindung), Ueberfluessiges UND kulinarische Fremdkoerper. KONKRETE Befunde '
                . 'statt Floskeln — lieber drei belastbare als zehn vage. Je Befund die art waehlen: '
                . 'menge (zutat_id + neue quantity) | einheit (zutat_id + einheit_slug) | entfernen '
                . '(zutat_id) | fehlt (zutat_text + quantity + einheit_slug einer NEUEN Zutat) | '
                . 'fremdkoerper (zutat_id — eine Komponente, die fachlich/kulinarisch NICHT in DIESES '
                . 'Gericht gehoert: suesses/Dessert-Bauteil im herzhaften Gericht [Massstab: das Feld '
                . 'geschmack im Kontext], thematisch falsche Fond-/Sub-Variante, stilfremde Garnitur. '
                . 'Beurteile die GENANNTEN Komponenten-Namen — sie sind die verdrahtete Realitaet, '
                . 'nicht was gemeint war. Im Zweifel KEIN Befund: lieber durchlassen als Legitimes '
                . 'faelschlich flaggen) | hinweis (nur Text, kein Schreibziel — fuer alles Technik-/'
                . 'Reihenfolge-Bezogene). Nutze die mitgegebenen zutat-ids unveraendert; erfinde '
                . 'keine ids. Konfidenz 0..1: werte = {befunde: [{art, zutat_id, zutat_text, '
                . 'quantity, einheit_slug, begruendung, konfidenz}], gesamturteil}.',
        ],
        'recipe.bauart' => [
            'tier' => 'B',                                            // Spec 21 S5b-2 — Klassifikator, kein Kreativ-Pass (darum auch KEINE Food-DNA)
            'max_tokens' => 1200,                                     // eine Einstufung + Begruendung, sonst nichts
            'task' => 'Entscheide nach BAUART, ob dieses Rezept ein fertiges GERICHT oder eine '
                . 'KOMPONENTE ist. Massstab ist ausschliesslich „Wie ist es gebaut?" — NIE „Wo wird '
                . 'es eingesetzt?". Gericht = eine essfertige Zusammenstellung (Hauptkomponente plus '
                . 'Saettigungs-/Gemuese-/Sauciertem, oder ein in sich vollstaendiger Teller/Bowl/'
                . 'Sandwich). Komponente = ein Baustein, der allein nicht als Speise ausgegeben wird '
                . '(Sauce, Fond, Marinade, Teig, Creme, Wuerzmischung, einzelne Beilage, Garnitur). '
                . 'Dass eine Komponente verkauft wird oder ein Gericht nur im Buffet steht, aendert '
                . 'die Bauart NICHT. einstufung_ist im Kontext ist die BESTEHENDE Einordnung — '
                . 'bestaetige sie, wenn sie stimmt. Bei Zweifel bestaetigen (lieber kein Befund als '
                . 'ein falscher). Konfidenz 0..1: werte = {einstufung: "gericht"|"komponente", '
                . 'konfidenz, begruendung}.',
        ],
        // Schicht 3 (2026-08-27) — GENERISCHER Konformitaets-Critic. EIN Prompt fuer
        // ALLE Artefakt-Typen (Rezept/VK/GP/LA); der Adapter waehlt Kontext + Regelwerke,
        // die als knowledge-Option (voller §-Text, ungekappt) mitkommen. Auto-Pass nach
        // Generierung → Tier B (kostenbewusst); auf A bumpen, falls §-Recall zu schwach.
        'conformance.check' => [
            'tier' => 'B',
            'max_tokens' => 4000,                                    // §-Befund-Liste + Reasoning-Headroom
            'task' => 'Pruefe das mitgegebene ARTEFAKT (Kontext) §-genau gegen die REGELWERKE im '
                . 'Wissensblock. Melde NUR belastbare Regelverstoesse — im Zweifel KEIN Befund. Je '
                . 'Verstoss: paragraph (die §-Referenz AUS dem Regelwerk, z. B. "§6.1"; nie erfinden), '
                . 'schweregrad (hart = nicht-verhandelbare Struktur-/Naming-/Pflicht-Regel | weich = '
                . 'Stil/Empfehlung), feld (betroffenes Artefakt-Element, z. B. "name" | '
                . '"zutat:Sauce Hollandaise" | "kategorie"), begruendung (WAS gegen WELCHE Regel '
                . 'verstoesst — knapp, konkret, benenne die Regel), vorschlag (die konforme Fassung, '
                . 'wenn eindeutig ableitbar — sonst leer). Beurteile AUSSCHLIESSLICH gegen die '
                . 'beigefuegten Regeln; erfinde keine Regel, die nicht im Wissensblock steht. Sauberes '
                . 'Artefakt ⇒ leere Liste (Normalfall). Konfidenz 0..1: werte = {befunde: [{paragraph, '
                . 'schweregrad, feld, begruendung, vorschlag, konfidenz}], gesamturteil}.',
        ],
        // Schicht 3 · Slice 5 (LA-First-Selbstheilung GP): leitet die konformen GP-Feld-Werte AUS
        // dem Quell-LA ab, um die gemeldeten Verstoesse zu beheben. NIEMALS erfinden — was das LA
        // nicht hergibt, bleibt null (Hinweis). Nur tentative GPs (der Adapter guardt den Status).
        'gp.conformance_revise' => [
            'tier' => 'B',
            'max_tokens' => 1500,
            'task' => 'Leite die KONFORMEN Werte fuer das Grundprodukt AUS dem beigefuegten Quell-Lieferantenartikel '
                . '(quell_lieferantenartikel, LA-First) ab, um die gemeldeten Regelverstoesse (verstoesse) zu beheben. '
                . 'HARTE REGEL: NUR aus dem LA + den vorhandenen GP-Daten ableiten — was das LA nicht belegt, gib als '
                . 'null zurueck (NIEMALS raten/erfinden). Felder: name (§6-Schema, Singular §6.1), zustand (§9: '
                . 'frisch|TK|trocken|konserviert), warengruppe (§3-Code), sub_kategorie. Ein Feld, das schon konform '
                . 'oder nicht sicher aus dem LA ableitbar ist, bleibt null. werte = {name, zustand, warengruppe, sub_kategorie}.',
        ],
        'recipe.pairing' => [
            'tier' => 'A',                                            // groesster Ist-Kostenblock — Qualitaet zaehlt
            'task' => 'Schlage 12-25 BELEGTE Flavor-Pairing-Partner aus dem mitgegebenen '
                . 'Grounding vor (typ aroma|kontrast, konfidenz hoch|mittel|niedrig; '
                . 'erfinde KEINE unbelegten Paarungen; Vorschlaege sind KEIN Gold — nie als '
                . 'erprobt/klassisch/modern einlagern): werte = {pairings: [{slug, typ, konfidenz}]}.',
        ],
        'recipe.anker' => [
            'tier' => 'B',
            'task' => 'Bestimme die 1-5 Kern-Anker (Aroma-Identitaet) des Rezepts aus dem '
                . 'mitgegebenen Vokabular (GL-10 Cap 5): werte = {anker_slugs: []}.',
        ],
        'recipe.equipment' => [
            'tier' => 'B',
            'task' => 'Schlage das Equipment-Set fuer die Produktion aus dem mitgegebenen '
                . 'Vokabular vor: werte = {equipment_slugs: []}.',
        ],
        'recipe.ueberarbeiten' => [
            'tier' => 'A',                                            // R6 (Ist: KI-Überarbeiten-Button) — freie Anweisung, Gesamt-Rezept
            'max_tokens' => 8000,                                     // Gesamt-Rezept zurück (description+preparation+zutaten) — Reasoning-Headroom
            'task' => 'Ueberarbeite das Rezept exakt nach der freien Anweisung (anweisung) — '
                . 'aendere NUR Angefragtes, behalte ids bestehender Zutaten, neue Zutaten ohne id: '
                . 'werte = {name, description, preparation, zutaten: [{id, text, quantity, einheit_slug}], aenderungs_notiz}.',
        ],
        'recipe.extract' => [
            'tier' => 'C',                                            // Tier C: unset ⇒ Plattform-Default-Modell (Text ok); Vision (Bild) erst nach Core-Fix
            'max_tokens' => 8000,                                     // ganzes (evtl. verschachteltes) Rezept-JSON — Reasoning-Headroom
            // Rezept-IMPORT (2026-08-22): TREUE Extraktion aus roh_text — NICHTS anreichern/erfinden
            // (GL-13 Inv. 7, Wissenskontext bewusst leer). Die Erdung (GP-Bindung) macht der Persistenz-
            // Resolver danach, NICHT dieser Call. WICHTIG: verschachtelt — eigenständige Komponenten
            // (Sauce/Fond/Jus/Püree/Reduktion/Creme/Dressing …), die der Quelltext MIT ihren Zutaten
            // führt, gehören als `komponenten` (eigene Sub-Rezepte), NICHT flach in die Rohzutaten
            // aufgelöst. Kommt eine Komponente nur als Name vor (ohne eigene Zutaten), setze in der
            // Zutatenzeile `sub_rezept: true` (sie wird später als Stub angelegt).
            'task' => 'Extrahiere das Rezept TREU aus dem Rohtext (roh_text) — übernimm NUR, was dort '
                . 'steht; NICHTS anreichern, erfinden, umbenennen oder umsortieren. Erkenne den Typ aus '
                . 'dem Quelltext: `gericht` (angerichteter Teller / mehrere Komponenten) oder '
                . '`basisrezept` (EINE Komponente: Sauce, Fond, Teig, Beilage …). Verschachtelung: '
                . 'führt der Text eigenständige Komponenten MIT eigenen Zutaten (z. B. »Für die Sauce: …«, '
                . '»Für das Püree: …«), gib sie als `komponenten` aus (je ein Sub-Rezept), NICHT flach '
                . 'aufgelöst; eine nur namentlich genannte Komponente ohne eigene Zutaten markierst du in '
                . 'der Zutatenzeile mit `sub_rezept: true`. Übernimm Mengen/Einheiten wörtlich (unbekannt '
                . '⇒ weglassen, NICHT schätzen). werte = {typ (gericht|basisrezept), name, '
                . 'zutaten: [{text, quantity, unit, sub_rezept (bool, default false)}], preparation, '
                . 'komponenten: [{name, zutaten: [{text, quantity, unit}], preparation}] (leer, wenn keine)}.',
        ],
        // §3.3 Anrichten. Liefert bewusst MARKDOWN mit nummerierten Schritten und nicht
        // ein `steps`-Array: derselbe Prompt speist die Anreicherung (Bulk-Zielfeld
        // `plating_text`) UND den ✨-Knopf, der den Text in Anrichte-Schritte parst. Ein
        // Schema-Wechsel haette den Bulk-Accept gebrochen.
        //
        // Vorher forderte er einen Prosa-Block („Pro Portion ca. 432 g anrichten. Vorgewaermten
        // Teller verwenden …"). Daraus konnte `RecipeStepService::parse()` keine Schritte
        // machen — der Anrichten-Tab blieb bei „0 Schritte", obwohl der Vorschlag ankam.
        'vk.plating' => [
            'tier' => 'A',                                            // V-02
            'task' => 'Schreibe die ANRICHTE-Anleitung fuers Gericht als nummerierte Schrittfolge '
                . '(Markdown: jede Zeile beginnt mit «1.», «2.» …; optional «## Abschnitt» als '
                . 'Ueberschrift). Ein Schritt = EIN Handgriff am Pass, in Aufbau-Reihenfolge: '
                . 'Teller/Vehikel vorbereiten, Basis (Sauce/Creme/Spiegel) setzen, Hauptkomponente '
                . 'platzieren, Beilagen anlegen, Garnitur, Finish (Sauce nachziehen, Crunch zuletzt), '
                . 'Uebergabe. Nenne die Menge JE TELLER pro Komponente — eine Teller-Menge ist '
                . 'skalierungsfest. Ansatz- oder Gesamtmengen gehoeren NICHT in die Schritte: die '
                . 'Zutatenliste skaliert mit, der Schritt-Text nicht. '
                . 'ABGRENZUNG (verbindlich): KEINE Produktion der Komponenten, KEIN Regenerations-'
                . 'Programm (keine Grad-, Minuten- oder Kerntemperatur-Werte fuers Wiedererhitzen) '
                . 'und keine Fertigstellungs-Handgriffe wie tranchieren oder portionieren — die '
                . 'stehen in ihren eigenen Ebenen. '
                . $briefingKlausel
                . '4-8 Schritte: werte = {preparation}.',
        ],
        'vk.name_putzen' => [
            'tier' => 'B',
            'task' => 'Normalisiere den Verkaufsrezept-Namen auf die Pipe-Syntax §1 (VK-Regelwerk) '
                . '«<HG-Code>: Hauptkomponente | Komponente | …» (max 5 Felder, Title Case, '
                . 'keine Marketing-Adjektive): werte = {name}.',
        ],
        // Et.4 (Eingabe-Reife): Titel-VORSCHLAG aus dem freien Brief (vor der Generierung), nicht das
        // Putzen eines fertigen Namens. Nüchtern + §1-konform; benennt nur, was der Brief hergibt.
        'vk.titel_vorschlag' => [
            'tier' => 'B',
            // #75: HG-Code NICHT vom LLM raten lassen — aus einem freien Brief kann es die
            // Warengruppe kaum zuverlässig treffen. Der Titel-Vorschlag liefert nur die nüchterne
            // Komponenten-Pipe OHNE Code-Präfix; der §1-HG-Code wird separat/deterministisch
            // (bei der echten VK-Anlage über die Warengruppen-Klassifikation) ergänzt.
            'task' => 'Leite aus dem Brief EINEN nüchternen Gericht-Titel als Komponenten-Pipe ab: '
                . '«Hauptkomponente | Komponente | …» (max 5 Felder, Title Case, keine Marketing-'
                . 'Adjektive). KEIN Warengruppen-Code/Präfix — nur die Komponenten. Benenne nur, was '
                . 'der Brief hergibt — erfinde keine Komponente, die nicht genannt ist: werte = {name}.',
        ],
        'vk.marketing' => [
            'tier' => 'A',
            'task' => 'Schreibe den verkaeuferischen Marketing-Text fuers Foodbook (appetitlich, '
                . 'ehrlich, im mitgegebenen Schreibstil-Duktus): werte = {marketing_text}.',
        ],
        'vk.wording' => [
            'tier' => 'A',
            'task' => 'Generiere den kanonischen Marketing-Namen (VK-Wording-Standard, '
                . 'stil-neutral — Schreibstile transformieren erst spaeter): werte = {sales_wording_standard}.',
        ],
        'concept.brief_geruest' => [
            'tier' => 'A',
            'max_tokens' => 8000,                                     // volles Slot-/Regel-Gerüst — Reasoning-Headroom
            'system' => 'Du uebersetzt Kunden-Briefs in ein strukturiertes Planungs-Geruest (R4.1). '
                . 'Du erfindest keine konkreten Gerichte, Preise oder Fakten, die der Brief nicht hergibt (fehlende Angaben bleiben weg, Felder null/weglassen). '
                // Spec 41 B3 (§3 Regelwerk Concept, gegen RC-4/C1): das strukturelle SEKTIONIEREN ist kein Erfinden.
                . 'Das strukturelle SEKTIONIEREN eines Containers ist ausdruecklich ERLAUBT und PFLICHT: ein «Menue»/«Buffet»/«Lunchbuffet» '
                . 'ist NIE eine einzige Position, sondern IMMER ein Geruest aus MEHREREN Gang-/Stations-Slots mit Platzhaltern. '
                . 'Diaet-Werte NUR aus diaet_vokabular, Allergen-Keys NUR aus allergen_keys.',
            'task' => 'Uebersetze den Brief in ein Planungs-Geruest: werte = {name, target_price_pp, price_min_pp, price_max_pp, '
                . 'slots: [{label, slot_type (gang|station|kapitel), target_count, price_anchor, price_min, price_max, is_pflicht, '
                . 'rules: [{rule_type: diet_quota, ref_key, operator (min|max|exact), value_num, unit (count|percent)}]}], '
                . 'rules: [{rule_type: nogo_ingredient, value_text, severity (hart|weich)} | '
                . '{rule_type: nogo_allergen, ref_key} | {rule_type: allergen_line, value_text}]}. '
                . 'Preise netto p. P.; Gaenge/Stationen aus dem Anlass ableiten (Menü→gang, Buffet→station). '
                // Spec 41 B3: Container IMMER in mehrere Sektions-Slots aufloesen, NIE ein einziger «Buffet»/«Menue»-Slot.
                . 'Ein BUFFET erzeugt IMMER MEHRERE station-Slots (z.B. Kalte Vorspeisen/Salate; Suppe optional; '
                . 'Warme Hauptkomponente(n) inkl. Carving bei > 50 Pax; Saettigungsbeilagen Staerke+Gemuese; Dessert/Sweet-Table; Getraenke). '
                . 'Ein MENUE erzeugt IMMER MEHRERE gang-Slots (3/5/7/9 Gaenge). NIE ein einziger Container-Slot.',
        ],
        // Foodbook-GRUNDGERUEST (owner=foodbook): das Buch wird in KAPITEL gegliedert, NICHT in Gaenge.
        // Ein Kapitel = ein Menue / Thema / Anlass / Service-Format (belegt an realen Kundenbuechern
        // Broich/TM/DOEC: Kapitel-Typen Service-Format · Anlass · Konzept/Marke · einzelnes Menue — NIE
        // «Vorspeisen/Hauptgaenge/Desserts» als Top-Level). Die einzelnen Gaenge entstehen SPAETER im
        // Kapitel-Concept (menue_gaenge, Concept-Ebene) — hier NICHT. Schwester von concept.brief_geruest,
        // aber eine Ebene hoeher (Buch statt Menue).
        'foodbook.grundgeruest' => [
            'tier' => 'A',
            'max_tokens' => 8000,
            'system' => 'Du uebersetzt einen Kunden-Brief in das GRUNDGERUEST eines Foodbooks — seine KAPITEL. '
                . 'Ein Kapitel ist ein Menue, ein Thema, ein Anlass oder ein Service-Format — NIEMALS ein einzelner Gang. '
                . 'Die Regel «Menue erzeugt Gaenge» gilt hier ausdruecklich NICHT: die einzelnen Gaenge '
                . '(Vorspeise/Zwischengang/Hauptgang/Dessert) entstehen SPAETER im Kapitel-Concept, nicht auf dieser Ebene. '
                . 'Du erfindest keine konkreten Gerichte, Preise oder Fakten, die der Brief nicht hergibt (fehlende Felder weglassen/null). '
                . 'Diaet-Werte NUR aus diaet_vokabular, Allergen-Keys NUR aus allergen_keys.',
            'task' => 'Uebersetze den Brief in ein Foodbook-Grundgeruest: werte = {name, target_price_pp, price_min_pp, price_max_pp, '
                . 'slots: [{label, slot_type: IMMER "kapitel", target_count (Anzahl Menues/Gerichte im Kapitel, mind. 1), '
                . 'price_anchor, price_min, price_max, is_pflicht, '
                . 'rules: [{rule_type: diet_quota, ref_key, operator (min|max|exact), value_num, unit (count|percent)}]}], '
                . 'rules: [{rule_type: nogo_ingredient, value_text, severity (hart|weich)} | {rule_type: nogo_allergen, ref_key} | {rule_type: allergen_line, value_text}]}. '
                . 'Jeder Slot ist GENAU EIN KAPITEL. Leite die Kapitel aus dem Brief ab — moegliche Kapitel-Typen: '
                . 'Service-Format (Menue · Menue-Buffet/Stationen · Flying · Fingerfood/Empfang · Grillbuffet · Foodstationen · Midnight), '
                . 'Anlass/Tageszeit (Fruehstueck · Break · Lunch · Dinner · Konferenz · Mitternachtsnack), '
                . 'Konzept/Marke (thematisch benannt) oder ein einzelnes Menue (z.B. «Menue 01»). '
                . 'Bei «ein Menue» oder «ein Kapitel» im Brief -> GENAU EIN kapitel-Slot. Nur so viele Kapitel wie der Brief wirklich hergibt. '
                . 'ERZEUGE NIE gang- oder station-Slots. Preise netto p. P.',
        ],
        // Format-GRUNDGERUEST (owner=format): ein gebrandetes FOODKONZEPT (z.B. CHEFS.CORNER, Taste & Fly,
        // Lunchbuffet, Dinner) — eine Marke/Vorlage EINE Ebene ueber dem Concept. Das Geruest liefert die
        // MARKEN-IDENTITAET (consumer_name/claim/story) + die eigenstaendigen VERANSTALTUNGEN, die die Marke
        // buendelt (je Slot = ein GANZES Concept = ein Tag / ein Event / eine Menue-Variante). Die Stationen/
        // Pakete/Gaenge INNERHALB einer Veranstaltung baut der Conceptor spaeter IM Concept — NICHT hier. Ein
        // Brief ueber EINE Veranstaltung => GENAU EIN Concept-Slot. Schwester von foodbook.grundgeruest.
        'format.grundgeruest' => [
            'tier' => 'A',
            'max_tokens' => 8000,
            'system' => 'Du uebersetzt einen Brief in das GRUNDGERUEST eines gebrandeten FOODKONZEPTS (Format) — '
                . 'einer wiederverwendbaren Marke/Vorlage (z.B. ein Streetfood-Konzept, ein Lunchbuffet, ein Flying-Dinner). '
                . 'Ein Format hat eine IDENTITAET (Marken-Zeile, Claim, kurze Story) und BUENDELT mehrere EIGENSTAENDIGE Concepte. '
                . 'Jeder Slot ist ein VOLLSTAENDIGES Concept = eine VERANSTALTUNG (ein Tag, ein Event, eine Menue-Variante), die fuer sich steht. '
                . 'Die einzelnen Stationen/Pakete/Gaenge INNERHALB einer Veranstaltung entstehen SPAETER im Concept selbst '
                . '(der Conceptor zerlegt jedes Concept in seine Stationen/Gaenge) — NIEMALS auf dieser Ebene. '
                . 'Beschreibt der Brief nur EINE Veranstaltung, ist das GENAU EIN Slot; mehrere Tage/Events/Varianten => mehrere Slots. '
                . 'Die Veranstaltungen einer Marke muessen zueinander passen (gemeinsame Handschrift). '
                . 'NUTZE das mitgelieferte Wissen als INSPIRATION fuer Marken-Handschrift + Zuschnitt der Veranstaltungen: '
                . 'Signatur-Kuechen/Koeche, Weltkuechen, Konzept-/Format- und Event-Wissen (sofern beigefuegt) — als Ideengeber, '
                . 'NICHT als Faktenquelle: erfinde daraus keine harten Zahlen/Preise/Gerichte, die der Brief nicht hergibt. '
                . 'Du erfindest keine konkreten Gerichte, Preise oder Fakten, die der Brief nicht hergibt (fehlende Felder weglassen/null). '
                . 'consumer_name/claim/story DARFST du im Sinne des Briefs formulieren (Marken-Handschrift), aber im Rahmen bleiben. '
                . 'Diaet-Werte NUR aus diaet_vokabular, Allergen-Keys NUR aus allergen_keys.',
            'task' => 'Uebersetze den Brief in ein Format-Grundgeruest: werte = {name (interner Format-Name), '
                . 'consumer_name (gaeste-/kundenseitige Marken-Zeile, optional), claim (kurze Tagline, optional), '
                . 'story (1-3 Saetze Marken-Story, optional), target_price_pp, price_min_pp, price_max_pp, '
                . 'slots: [{label (Name der VERANSTALTUNG/Edition, z.B. «Tag 1 — Business-Lunch» oder «Menue-Variante Plant-Forward»), '
                . 'slot_type: IMMER "station" (technischer Platzhalter, KEINE Bedeutung), '
                . 'target_count (Anzahl Gerichte der GESAMTEN Veranstaltung, mind. 1), '
                . 'price_anchor, price_min, price_max, is_pflicht, '
                . 'rules: [{rule_type: diet_quota, ref_key, operator (min|max|exact), value_num, unit (count|percent)}]}], '
                . 'rules: [{rule_type: nogo_ingredient, value_text, severity (hart|weich)} | {rule_type: nogo_allergen, ref_key} | {rule_type: allergen_line, value_text}]}. '
                . 'Jeder Slot ist GENAU EINE eigenstaendige Veranstaltung/Edition des Foodkonzepts — ein ganzes Concept, das der Conceptor '
                . 'danach in seine Stationen/Gaenge zerlegt. Beispiele: ein 3-Tage-Lunch-Format => 3 Slots (Tag 1/2/3); ein einzelnes Event => 1 Slot. '
                . 'Leite die Zahl der Veranstaltungen aus dem Brief ab — nur so viele, wie er wirklich hergibt (bei EINER Veranstaltung GENAU EIN Slot). '
                . 'ERZEUGE NIE Stations-, gang- oder kapitel-Slots (Stationen/Gaenge baut der Conceptor im Concept). Preise netto p. P.',
        ],
        // Et.2b »Kreativ-Kopf«: Kunden-Brief → kreative Concept-Canvas (die IDEE, nicht das
        // Struktur-Geruest). Schwester von concept.brief_geruest — GERUEST erzeugt Slots/Preise/
        // Regeln (deterministischer Rahmen), PLAN erzeugt die kreative Handschrift (Leitidee/USP/
        // Inszenierung/Geschmackswelten). Fuellt die concept-Canvas (CanvasService::TEMPLATES['concept']).
        // Kreativ — DARF ausformulieren, was der Brief impliziert (anders als der Klassifikator-Grundsatz),
        // aber im Rahmen des Briefs bleiben: KEINE harten Zahlen/Preise/Allergen-/No-Go-Fakten erfinden
        // (die gehoeren ins Geruest/Frame), nichts gegen die Brief-Absicht setzen.
        'concept.plan' => [
            'tier' => 'B',
            'max_tokens' => 6000,                                     // Canvas: mehrere Langtext-Felder + Geschmackswelten-Liste — Reasoning-Headroom
            'system' => 'Du bist der kreative Kopf eines Foodkonzepts. Aus einem Kunden-Brief formst du '
                . 'die Leitidee eines Konzepts aus — Name, Claim, roter Faden, Verkaufsvorteil, Inszenierung '
                . 'und die Geschmackswelten. Du bleibst im Rahmen des Briefs (Anlass, Zielgaeste, Niveau) und '
                . 'fuehrst seine Absicht weiter, statt sie zu ueberschreiben. Du erfindest KEINE harten Fakten '
                . '(Preise, Portionsgroessen, Allergen- oder No-Go-Vorgaben) — die kommen aus dem Planungs-Geruest. '
                . 'Marketing-ehrlich: kein Superlativ-Nebel, konkrete kulinarische Aussagen.',
            'task' => 'Formuliere die Concept-Canvas aus dem Brief: werte = {name_claim, leitidee, usp_eignung, '
                . 'inszenierung, geschmackswelten: [{claim, description}]}. '
                . 'name_claim = praegnanter Konzept-Name + kurzer Claim (eine Zeile); '
                . 'leitidee = der rote Faden / das Versprechen des Konzepts (2–4 Saetze); '
                . 'usp_eignung = Vorteil/USP + fuer welchen Anlass/welche Gaeste es passt; '
                . 'inszenierung = Servier-/Praesentationsidee (Teller/Buffet/Station, Dramaturgie); '
                . 'geschmackswelten = 2–5 Geschmacks-/Themenwelten, je {claim (kurze Ueberschrift), '
                . 'description (1–2 Saetze)}. Nur was der Brief hergibt; fehlende Angaben weglassen.',
        ],
        // Trendradar: clustert Trend-Wissens-Docs in die zweistufige Taxonomie (Kategorie → Klasse).
        // Kategorie STRIKT aus der Vorgabe (fixes Seed-Vokabular); Klasse = kurze Unterkategorie
        // (bestehende bevorzugen, sonst neue vorschlagen → landet tentative in der Review-Queue).
        // Reine Einordnung — nichts erfinden, keinen Trend auslassen.
        'trend.cluster_label' => [
            'tier' => 'B',
            'max_tokens' => 6000,
            'system' => 'Du bist ein Food-Trend-Analyst. Du ordnest gesichtete Trends in eine feste '
                . 'zweistufige Taxonomie ein. Die KATEGORIE waehlst du AUSSCHLIESSLICH aus der Liste '
                . 'categories (Slug exakt uebernehmen). Die KLASSE ist eine kurze Unterkategorie: '
                . 'bevorzuge eine bereits bestehende (existing_classes), sonst schlage eine praegnante '
                . 'neue vor. Du erfindest keine Trends und laesst keinen aus.',
            'task' => 'Ordne JEDEN Trend aus trends einer Kategorie und Klasse zu und schaetze Reife/Hype: '
                . 'werte = {items: [{index, category, trend_class, maturity, is_hype, confidence}]}. '
                . 'index = der mitgegebene Trend-Index (Zahl); category = Slug exakt aus categories; '
                . 'trend_class = kurze Unterkategorie (Title Case, 1-3 Woerter); '
                . 'maturity ∈ {niche, emerging, mainstream, declining}; is_hype = true nur bei kurzlebigem Hype; '
                . 'confidence = Zahl 0..1. Gib fuer ALLE Eingabe-Trends genau EIN item zurueck.',
        ],
        'concept.wording' => [
            'tier' => 'A',
            'task' => 'Erzeuge ein stimmiges Konzept-Wording ueber ALLE Positionen STRIKT im vorgegebenen Schreibstil: '
                . 'richte Tonalitaet, Wortwahl und Satzbau eng an `schreibstil_anweisung` (Sprach-Duktus) aus — '
                . 'orientiere dich an `schreibstil_beispiele`, falls vorhanden; faellt beides weg, an `schreibstil`. '
                . 'Der Stil MUSS im Ergebnis klar erkennbar sein, ein anderer Stil muss deutlich anders klingen. '
                . 'werte = {intro, slots}. intro = kurzer Einleitungs-/Praesentationstext fuer das ganze Konzept. '
                . 'slots = Map slot_id -> Brand-Voice-Anzeigename je Position (Variante des neutralen sales_wording_standard, '
                . 'ueber das gesamte Menue stimmig und wiedererkennbar).',
        ],
        // Spec 19 E6.4: KI-Divergenz der Kreativ-/Skizzen-Phase. PRODUKT-BLIND — die KI DARF
        // frei erfinden (Anker-Graph nur als Inspiration), OHNE Bestandsprüfung; die Erdung
        // kommt erst beim Kapitel-Go (E7.4, Anpassungs-Schleife). Reine Skizzen-Titel/-Texte,
        // KEINE Rezepte/GPs/Konzepte (M4-Invariante). Food-DNA-Kette wird injiziert
        // (FOOD_DNA_KEYS), Leitplanken/Ziele/Rahmen stehen im Kontext (Kontext-Vertrag).
        'foodbook.kapitel_ideen' => [
            'tier' => 'B',
            'max_tokens' => 4000,
            'system' => 'Du bist ein kreativer Küchenchef in der DIVERGENZ-Phase eines Foodbook-Kapitels. '
                . 'Du darfst frei ideieren (Anker-Graph/Flavour-Pairing als Inspiration), OHNE zu prüfen, '
                . 'ob das Sortiment die Idee schon hergibt — die Erdung folgt später. Bleibe im Rahmen von '
                . 'Leitplanken (Zielgruppen/Niveau/Anlass) und Kapitel-Zielen (Menge/Preis), aber ersticke '
                . 'die Divergenz nicht: liefere unterschiedliche, eigenständige Ansätze statt Varianten desselben.',
            'task' => 'Entwirf «anzahl» kompakte Gericht-Baupläne: werte = {ideen: [{titel, beschreibung, komponenten: [{name, funktion, herstellung}]}]}. '
                . 'titel = kurzer, konkreter Gericht-Name (kein Marketing-Claim); beschreibung = 1–2 Sätze zur '
                . 'geschmacklichen/handwerklichen Idee. komponenten nennt die 2–6 tatsächlich benötigten Teller- oder '
                . 'Basisrezept-Komponenten; funktion erklärt knapp ihre Rolle, herstellung beschreibt in einem Satz das '
                . 'technische Ziel ohne Mengen. Noch keine vollständigen Rezepturen, Preise oder Datensätze anlegen.',
        ],
        'planning.dish_proposal_revise' => [
            'tier' => 'B',
            'max_tokens' => 1800,
            'system' => 'Du überarbeitest einen bereits vorhandenen Gericht-Bauplan gezielt nach menschlichem Feedback. '
                . 'Bleibe auf der Skizzenebene: keine Mengen, Preise, vollständigen Rezepturen oder Datensätze. '
                . 'Bewahre gute, vom Feedback nicht betroffene Entscheidungen. Erfinde keine zusätzlichen Varianten.',
            'task' => 'Überarbeite den einen Bauplan aus bestehend nach feedback: werte = {titel, beschreibung, komponenten: [{name, funktion, herstellung}]}. '
                . 'Liefere 2–6 tatsächlich benötigte Komponenten. Der Bauplan muss anschließend ausreichend konkret sein, '
                . 'um vorhandene Basisrezepte zu matchen und nur echte Lücken neu anzulegen.',
        ],
        // Spec 03 · L2: kundensichtbarer Einleitungstext eines Angebots-Abschnitts.
        // EIN Prompt für BEIDE Ebenen (`ebene` im Kontext): Foodbook-Einleitung und
        // Kapitel-Hinführung sind derselbe Auftrag in anderem Zuschnitt — zwei Keys
        // würden die Tonalität an zwei Orten definieren und auseinanderlaufen.
        // Strikt reproduktiv: geschrieben wird nur, was in `gliederung`/`briefing_ist`
        // steht (die Gerichtnamen kommen aus der Wording-Kette, nicht aus dem Modell).
        'foodbook.kundentext' => [
            'tier' => 'A',
            'max_tokens' => 1500,
            'system' => 'Du schreibst den kundensichtbaren Einleitungstext eines Catering-Angebots. '
                . 'Du bist NICHT in der Ideen-Phase: du erfindest keine Gerichte, Zutaten, Leistungen, '
                . 'Preise, Herkunfts- oder Bio-Aussagen. Es darf ausschließlich vorkommen, was im Kontext '
                . 'belegt ist — die Gliederung ist die Wahrheit über den Inhalt des Angebots. '
                . 'Steht ein Briefing im Kontext, ist das der Rohstoff: du formst es in Kundensprache, '
                . 'ohne seine Zusagen zu verändern oder zu erweitern.',
            'task' => 'Schreibe den Kundentext für die Ebene «ebene» (foodbook = Einleitung des ganzen '
                . 'Angebots, kapitel = Hinführung zu einem Kapitel): werte = {text}. '
                . '2–4 Sätze Fließtext, keine Überschrift, keine Aufzählung, keine Anrede und keine '
                . 'Grußformel, im mitgegebenen Schreibstil-Duktus. Keine Preise, keine Gramm-/Stück-Mengen. '
                . 'Nenne höchstens drei konkrete Positionen aus der Gliederung als Beispiel und nur, wenn '
                . 'sie dort stehen — der Text soll den Bogen spannen, nicht die Karte wiederholen. '
                . 'Steht «rahmen_einleitung» im Kontext, ist das die schon geschriebene Einleitung des '
                . 'Angebots: greife sie nicht auf, sondern führe von dort ins Kapitel weiter.',
        ],
        // Spec 43 Stufe 2: aus einem Freitext-Wunsch sandboxed CSS für die Präsentation erzeugen.
        'praesentation.design_css' => [
            'tier' => 'A',
            'max_tokens' => 2200,
            'system' => 'Du bist Web-/CSS-Designer für hochwertige, moderne Foodbook-Präsentationen '
                . '(Website-Qualität). Du lieferst AUSSCHLIESSLICH sandboxed CSS — KEIN HTML, KEIN JavaScript, '
                . 'KEIN <, KEIN @import, KEIN expression(), keine externen URLs. Das CSS stylt eine bestehende, '
                . 'datengebundene Seite; du änderst nur die Optik, nie Inhalte. Ziel-Selektoren sind fix und '
                . 'stehen im Kontext (pt-*). Nutze die vorhandenen CSS-Variablen (--pt-primary, --pt-accent, '
                . '--pt-bg, --pt-text, --pt-muted, --pt-heading-font, --pt-body-font) wo sinnvoll.',
            'task' => 'Erzeuge modernes, geschmackvolles CSS für den gewünschten Look aus «brief». '
                . 'werte = {css}. Verwende NUR die im Kontext gelisteten pt-*-Klassen als Selektoren. '
                . 'Gültiges, kompaktes CSS ohne Kommentare, ohne @import/@font-face, ohne <. Fokus auf '
                . 'Typo-Rhythmus, Weißraum, Cover/Hero, Sektionen und Menü-Zeilen. Keine Inhalte erfinden.',
        ],
        // Spec 51: `vk.behaelter` ist ERSATZLOS ENTFALLEN. Ein LLM zu fragen, wie viele GN
        // 14,2 kg ergeben, waehrend die Datenbank die 14,2 kg exakt kennt, ist genau das, was
        // die Kanon-Entscheidungsvorlage verbietet: Regelwerke durchsetzen statt in den Prompt
        // legen. Die ANZAHL rechnet BehaelterRechner; welcher Typ, entscheidet der Mensch.
        // Die KI darf stattdessen die Dichteklasse vorschlagen — siehe `recipe.dichteklasse`.
        // Fein justiert nach dem ersten Echtdaten-Lauf (demo, 2026-09-04): die erste Fassung
        // beschrieb `skalierung` MECHANISCH (»nur die Flaeche skaliert«) — das ist die Sicht des
        // Rechners, nicht die der Kueche. Ergebnis waren 6 von 6 Rezepten »tiefer_fuellbar«, also
        // kein Urteil, sondern der erstgenannte Wert. Jetzt steht die Entscheidungsfrage vorn, die
        // Werte tragen kulinarische Kriterien, und der Zweifelsfall ist die KONSERVATIVE Richtung:
        // lieber ein Behaelter mehr als einer, in dem die Ware zusammensackt.
        'recipe.dichteklasse' => [
            'tier' => 'B',
            'task' => 'Zwei PRODUKTeigenschaften schaetzen — keine Rechnung, keine Behaelterzahl. '
                . 'ERSTENS dichteklasse (wie schwer ist ein Liter davon): fluessig (Suppe, Fond, Sauce, Sud) | '
                . 'dicht (Pueree, Ragout, Auflaufmasse) | schuettfaehig (Gemuesewuerfel, Reis, Nudeln, '
                . 'angemachter Salat) | locker (Blattsalat, Kraeuter, Chips, Microgreens). '
                . 'ZWEITENS skalierung. Die Frage dazu lautet: Wuerde das Produkt in einem doppelt so '
                . 'TIEFEN Behaelter auch doppelt so hoch stehen, ohne Schaden zu nehmen? '
                . 'tiefer_fuellbar = ja, es fliesst oder setzt sich, Hoehe schadet nicht. '
                . 'hoehe_gebunden = nein: zu hoch drueckt es sich zusammen, wird ungleich warm oder '
                . 'unansehnlich — das gilt fuer fast alles Stueckige und fuer alles, was regeneriert wird. '
                . 'lagenware = es wird GELEGT statt geschuettet (Schnitzel, Papadam, Tartelettes, Blaetterteig). '
                . 'Im Zweifel hoehe_gebunden: ein Behaelter zu viel ist harmlos, zu tief gefuellt ruiniert die Ware. '
                . 'DRITTENS je Zweck den passenden Behaelter aus der mitgelieferten Liste `behaelter` waehlen — '
                . 'NUR deren id, nie einen erfundenen Namen. Die Zwecke sind verschiedene Momente, nicht Synonyme: '
                . 'abfuellen = direkt nach der Produktion, meist Eimer/Kanne bei Fluessigem, GN mit Deckel bei Stueckigem. '
                . 'regenerieren = am Einsatztag im Ofen, also ein ofenfestes FLACHES Format (Suppe wird NICHT im GN warm '
                . 'gemacht, sie kommt aus dem Kipper). ausgabe = am Pass/Buffet. transport = nur wenn wirklich gefahren '
                . 'wird. Nimm das GROESSTE Format, das kuechenueblich ist — nach unten rechnet das System selbst. '
                . 'Zweck nicht sinnvoll ⇒ dort null (lieber leer als falsch: eine falsche Zeile schickt die Ware in '
                . 'den falschen Behaelter). KEINE Mengen, KEINE Behaelterzahl — die rechnet das System aus der Ausbeute. '
                . 'Unsicher bei einem Feld ⇒ dort null, NIEMALS raten: '
                . 'werte = {dichteklasse, skalierung, behaelter_je_zweck: {abfuellen, regenerieren, ausgabe, transport}}.',
        ],

        'vk.regeneration' => [
            'tier' => 'B',
            'task' => 'Schlage die Regenerations-Programme als LISTE vor — eine Zeile pro '
                . 'erkannter Komponente (V-19; Geraet aus Vokabular, kalt = ohne Geraet): '
                . 'werte = {programme: [{component_label, geraet_id, temp_c, duration_min, core_temp_c, note}]}.',
        ],
        'vk.servier_vehikel' => [
            'tier' => 'B',
            'task' => 'Schlage das Servier-Vehikel vor (Kontext: Speisen-Klasse + Komposition + '
                . 'Portion, Vokabular mitgegeben): werte = {servier_vehikel_id}.',
        ],
        'vk.review' => [
            'tier' => 'A',                                            // Spec 03 L6 — VK-Zweig desselben Pruefpasses
            'max_tokens' => 6000,
            'task' => 'Pruefe das GERICHT (Verkaufsrezept) als Copilot auf Verkaufs-Tauglichkeit: '
                . 'Portionierung gegen die Speisen-Klasse, Komposition und Teller-Logik, Service-'
                . 'Tauglichkeit, Wording. Die Verkaufs-Facetten im Kontext (speisen_klasse, '
                . 'diaetform, portion_g) sind MASSSTAB, nicht Gegenstand — pruefe gegen sie, '
                . 'schlage sie nicht um. KONKRETE Befunde statt Floskeln. Je Befund die art '
                . 'waehlen: menge (zutat_id + neue quantity) | einheit (zutat_id + einheit_slug) | '
                . 'entfernen (zutat_id) | fehlt (zutat_text + quantity + einheit_slug einer NEUEN '
                . 'Komponente) | hinweis (nur Text, kein Schreibziel — fuer Portions-, Service- und '
                . 'Wording-Befunde). Nutze die mitgegebenen zutat-ids unveraendert; erfinde keine '
                . 'ids. Konfidenz 0..1: werte = {befunde: [{art, zutat_id, zutat_text, quantity, '
                . 'einheit_slug, begruendung, konfidenz}], gesamturteil}.',
        ],
        'vk.ueberarbeiten' => [
            'tier' => 'A',                                            // Spec 03 L1a — VK-Variante von recipe.ueberarbeiten
            'max_tokens' => 8000,                                     // Gesamt-Gericht zurueck (Komponenten + Texte) — Reasoning-Headroom
            'task' => 'Ueberarbeite das GERICHT (Verkaufsrezept) exakt nach der freien Anweisung '
                . '(anweisung) — aendere NUR Angefragtes und behalte ids bestehender Komponenten, '
                . 'neue Komponenten ohne id. Die Verkaufs-Facetten im Kontext (speisen_klasse, '
                . 'diaetform, darreichungen, verkaufseinheit, aufschlagsklasse) sind VORGABE, nicht '
                . 'Gegenstand der Ueberarbeitung: sie stehen dort, damit die Ueberarbeitung zu ihnen '
                . 'passt (z. B. eine Fingerfood-Klasse bleibt handlich, eine vegane Diaetform bleibt '
                . 'vegan) — gib sie NICHT aus und schlage keine Aenderung an ihnen vor. Widerspricht '
                . 'die Anweisung einer Facette, sag es in aenderungs_notiz statt die Facette zu '
                . 'unterlaufen: werte = {name, description, plating_text, sales_wording_standard, '
                . 'zutaten: [{id, text, quantity, einheit_slug}], aenderungs_notiz}.',
        ],
        'vk.kohaerenz' => [
            'tier' => 'A',                                            // Inline-Prompt im Ist (culinary_coherence_judge) — gehoben
            'task' => 'Beurteile die kulinarische Kohaerenz des Tellers (Score 0-100, Label wie '
                . '«Klassischer Teller», kurze Begruendung, groesste Schwachstelle als eine Zutat '
                . 'oder null): werte = {score, label, reasoning, schwachstelle}.',
        ],
        'vk.teller_heber' => [
            'tier' => 'A',                                            // Inline-Prompt im Ist (plate_suggester) — gehoben
            'task' => 'Schlage vor, was den Teller hebt (1-3 konkrete, machbare Verbesserungen — '
                . 'keine Fantasie-Zutaten; typ je Vorschlag: kontrast | ergaenzung | veredelung): '
                . 'werte = {einschaetzung, vorschlaege: [{typ, zutat, kategorie, reasoning, confidence}]}.',
        ],
        'price.plausi' => [
            'tier' => 'B',
            'task' => 'Pruefe den auffaelligen Lieferanten-Preis auf Plausibilitaet (Kontext: '
                . 'Artikel, Historie, Vergleichspreise): werte = {plausibel: bool, grund}.',
        ],
        'chat.message' => [
            'tier' => 'A',                                            // Inline-Prompt im Ist — gehoben
            'task' => 'Beantworte die Kuechen-/Datenfrage als Catering-Souschef auf Basis des '
                . 'mitgegebenen Kontexts — ehrlich bei Luecken: werte = {antwort}.',
        ],
        'gp.tags' => [
            'tier' => 'C',
            'task' => 'Bewerte die Eigenschafts-Tags des Grundprodukts (vegan, vegetarisch, halal, '
                . 'contains_pork, contains_beef, organic, regional, grundnahrungsmittel, convenience, '
                . 'lactose_free, gluten_free) als true/false; unbewertbare Tags weglassen: werte = {is_vegan: bool, …}.',
        ],
        // Signale-Cockpit „KI erledigen lassen" (Assistenz) — erzeugen einen Entwurf/Vorschlag,
        // KEINE Mutation. Antwort-Feld heisst 'entwurf'/'empfehlung'/'vorschlag' (SignalFixService::extractDraft).
        'signal.supplier_inquiry' => [
            'tier' => 'B',
            'task' => 'Formuliere einen sachlichen, freundlichen Lieferanten-Rueckfrage-Entwurf (deutsche '
                . 'Geschaeftsmail inkl. Betreff) zu einem auffaelligen Preissprung: nenne den Artikel, den '
                . 'alten→neuen Preis und die Marge-Wirkung, frage nach dem Grund und ob bessere Konditionen '
                . 'oder Alternativen moeglich sind. Kontext steht im payload (gp_name, preis_alt, preis_neu, '
                . 'guenstigere_alternative, beispiele). werte = {entwurf}.',
        ],
        'signal.margin_levers' => [
            'tier' => 'B',
            'task' => 'Schlage 2-3 konkrete, machbare Hebel vor, um die Marge des Gerichts auf die Zielmarge '
                . 'zu heben (VK-Erhoehung, guenstigere Warenkorb-Alternative, Portionierung) — keine Fantasie. '
                . 'Kontext im payload (db_pct/wareneinsatz_pct, ziel_pct, sales_net). werte = {empfehlung}.',
        ],
        'signal.vk_release_advice' => [
            'tier' => 'B',
            'task' => 'Gib eine kurze Freigabe-Empfehlung fuer die vom freigegebenen Snapshot abweichende '
                . 'Live-VK: soll die neue VK freigegeben werden? Nenne Chance und Risiko (Kunden-Preissprung). '
                . 'Kontext im payload. werte = {empfehlung}.',
        ],
        'signal.serving_form_suggest' => [
            'tier' => 'B',
            'task' => 'Schlage je genanntem Gericht die plausibelste Standard-Servierform vor (nach Bauart des '
                . 'Gerichts, nicht nach Einsatzort). Kontext: beispiele = Gerichtnamen. '
                . 'werte = {vorschlag} (Liste „Gericht → Form" mit kurzer Begruendung).',
        ],
        // Spec 21 Tranche A: Assist zu `rezept_kategorie_problem` (Kategorie fehlt oder haengt an
        // stillgelegter Hauptgruppe). Klassifikations-Regel = Bauart, nicht Einsatzort (Taxonomie-
        // Neutralisierung) — deshalb bewusst KEIN Food-DNA-Key: DNA wuerde die Struktur verzerren.
        'signal.recipe_category_suggest' => [
            'tier' => 'B',
            'task' => 'Schlage je genanntem Rezept die plausibelste Kategorie vor. Klassifiziere nach BAUART '
                . '(„wie ist es gebaut?"), nie nach Einsatzort („wo wird es serviert?"). Kontext: '
                . 'beispiele = Rezeptnamen. Nenne keine stillgelegten Hauptgruppen. '
                . 'werte = {vorschlag} (Liste „Rezept → Kategorie" mit kurzer Begruendung).',
        ],
        'signal.recipe_naming_suggest' => [
            'tier' => 'B',
            'task' => 'Schlage je genanntem Rezept einen regelkonformen Namen vor. Verkaufsgericht: fuehrendes '
                . 'Hauptgruppen-Kuerzel in eckigen Klammern, danach 3-5 Kern-Bausteine mit " | " getrennt, '
                . 'Leitkomponente zuerst (kein Marketing-Satz, keine Verbindungswoerter, keine Grammatur, '
                . 'keine Katalog-Marker). Basisrezept: "Typ: Bezeichnung". Erfinde keine Zutaten, die nicht '
                . 'im Ausgangsnamen stehen. Kontext: beispiele = Rezeptnamen. '
                . 'werte = {vorschlag} (Liste „alt → neu" mit kurzer Begruendung).',
        ],
    ],
];
