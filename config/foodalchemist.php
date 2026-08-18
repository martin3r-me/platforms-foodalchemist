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

return [
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
                    // Doppel-Diamant: Trend/Brief → Analyse/Skizzen → Go auf Basisrezept/Gericht/Concept.
                    'label' => 'Planung',
                    'route' => 'foodalchemist.planung.index',
                    'icon'  => 'heroicon-o-light-bulb',
                ],
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
        'provider' => env('FOODALCHEMIST_STT_PROVIDER', 'fake'),
        'key' => env('ASSEMBLYAI_API_KEY', ''),
        'timeout_s' => 30,
        'fake_text' => 'Suche BBQ Sauce',
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
         * M9-04 / V-09: €-Preise je Tier (in/out je 1 Mio Tokens) — DEPLOYMENT-
         * Config wie die Modell-Strings; Defaults = Anthropic-Listenpreise der
         * Default-Modelle (Stand 2026-06, in €) — beim Modell-Wechsel anpassen.
         */
        'kosten_pro_mio' => [
            'A' => ['in' => (float) env('FOODALCHEMIST_AI_KOSTEN_A_IN', 2.80), 'out' => (float) env('FOODALCHEMIST_AI_KOSTEN_A_OUT', 14.00)],
            'B' => ['in' => (float) env('FOODALCHEMIST_AI_KOSTEN_B_IN', 0.75), 'out' => (float) env('FOODALCHEMIST_AI_KOSTEN_B_OUT', 3.75)],
            'C' => ['in' => (float) env('FOODALCHEMIST_AI_KOSTEN_C_IN', 2.80), 'out' => (float) env('FOODALCHEMIST_AI_KOSTEN_C_OUT', 14.00)],
            'D' => ['in' => (float) env('FOODALCHEMIST_AI_KOSTEN_D_IN', 2.80), 'out' => (float) env('FOODALCHEMIST_AI_KOSTEN_D_OUT', 14.00)],
        ],

        /*
         * Bild-Calls haben keine Text-Tokens im ai_call_log. Der Betrag ist daher
         * eine Deployment-Pauschale pro erfolgreich/versucht geloggtem Bild-Call.
         */
        'bildkosten' => [
            'recipe.step_photos' => (float) env('FOODALCHEMIST_AI_IMAGE_STEP_PHOTO_EUR', 0.0),
        ],
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

    /**
     * TASK_PROMPT-Registry — Skeleton (M0-14).
     * Der volle Umzug der 42 Prompts aus 06_KI_SPEZIFIKATION kommt mit M7-04
     * (inkl. Tier-Zuordnung A–D, V-01). Format je Key:
     *   'tier' (A–D) · 'task' (User-Task) · optional 'system' (Feld-Hülle) · 'temperature'
     */
    'prompts' => [
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
                . '(frisch|TK|trocken|konserviert), processing, form, pflichtangabe}. '
                . 'Singular/Lemma (§6.1), keine Verpackungswörter (§7.1), Marke nur nach §5-Tiebreaker.',
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
                . 'Erzeuge das Basisrezept aus der Beschreibung unter Beachtung der Richtungs-'
                // L3: Diät/Allergen sind VERBINDLICH — sie werden nach der Erzeugung deterministisch geprüft
                // (verletzende Zutaten werden gelöst). Verwende von vornherein KEINE Zutat, die eine gesetzte
                // diaet_hart-Form verletzt (vegan/vegetarisch/glutenfrei/laktosefrei/halal/low_carb) oder ein
                // gesetztes allergen_nogo (EU-14) enthält — das spart eine Nachkorrektur.
                // L6 »Menge & Ziel«: ist parameter.ziel_portion_g gesetzt, dimensioniere die Zutatenmengen auf
                // diese Portionsgröße; parameter.saison lenkt die Zutatenwahl auf das Erntefenster.
                . 'Parameter (convenience, frische, bio, niveau, sektor, diaet_hart, allergen_nogo, aroma, ziel_portion_g, saison): werte = '
                . '{name (§1-Syntax <Typ>: <Bezeichnung>), description (§8-Stil), taste_direction (grobe Menue-Richtung, NUR EIN Wort: suess|herzhaft|neutral — das Aroma-Profil gehoert in description), '
                . 'preparation (Markdown-Schritte), zutaten: [{text, quantity, unit (g|ml|kg|l|el|tl|stk), '
                . 'slug (hauptzutat), commodity_group, note, '
                // Etappe 1 (2026-08-14): benannter Sub-Komponenten-Slot. Ein enthaltenes
                // HALBFABRIKAT (Fond/Jus/Reduktion/Fischfond o. Ä., das selbst gekocht wird)
                // gehört als EINE benannte Komponente in die Liste — NICHT als seine
                // aufgelösten Rohzutaten (§4 Sub-Rezept-Hierarchie). Das Flag ist der spätere
                // LLM-Komponenten-Marker (löst die reine Namens-Heuristik ab).
                . 'sub_rezept (true, wenn diese Zeile ein eigenständiges Halbfabrikat / '
                . 'Sub-Basisrezept ist — Sauce, Jus, Fond, Sud, Essenz, Reduktion, Püree, '
                . 'Creme, Dressing, Vinaigrette, Espuma, Duxelles, Farce —, das als EIGENES Basisrezept anzulegen '
                . 'ist statt es in Rohzutaten aufzulösen; false bei einer Rohzutat/Ware), '
                // Kohärenz-Gate (2026-08-07): role füllt das V-21-Rollenfeld (Schicht 1) im
                // selben Call; fit ZWINGT zur Selbst-Begründung — eine Zutat, die sich nicht in
                // einem Halbsatz fachlich rechtfertigen lässt, gehört nicht ins Gericht (senkt
                // die Rate plausibel klingender Fremdkörper VOR dem Kritiker-Pass).
                . 'role (V-21: aroma_treiber|komponente|beilage|garnitur), '
                . 'fit (EIN kurzer Halbsatz: warum gehört diese Zutat FACHLICH in DIESES Gericht)}]}. '
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
                . '13 Convenience/Komponenten · 14 Vegane Ersatzprodukte · 15 Getränke.',
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
                . 'Erzeuge das VERKAUFSREZEPT (Teller/Speise mit VK-Preis) aus der Beschreibung '
                // L3: Diät/Allergen sind VERBINDLICH — nach der Erzeugung deterministisch geprüft (verletzende
                // Zutaten werden gelöst). Verwende KEINE Zutat, die eine gesetzte diaet_hart-Form verletzt oder
                // ein gesetztes allergen_nogo (EU-14) enthält.
                // L6 »Menge & Ziel«: parameter.pax = Gästezahl (Mengengerüst), ziel_portion_g = Ziel-Portionsgröße
                // je Teller, saison = Erntefenster, ziel_we_pct = angestrebter Wareneinsatz-Anteil (wähle
                // Qualitäten/Grammaturen entsprechend; GIB KEINEN PREIS AUS).
                . 'unter Beachtung der Richtungs-Parameter (convenience, frische, bio, niveau, sektor, '
                . 'diaet_hart, allergen_nogo, aroma, anlass, serviceform, kompositions_stil, pax, ziel_portion_g, saison, ziel_we_pct): werte = '
                . '{name (Pipe-Syntax §4.4 «<HG-Code>: Hauptkomponente | Komponente | …», max 5 Felder, '
                . 'keine Marketing-Adjektive), description (§8-Stil), taste_direction (grobe Menue-Richtung, NUR EIN Wort: suess|herzhaft|neutral — das Aroma-Profil gehoert in description), '
                . 'preparation (= PLATING & SERVICE: Teller-Aufbau, Mengenverteilung, Service-Anweisung — '
                // Spec 37: role/fit-Parität zum Basis-Prompt — dieselbe Zutaten-Selbstbegründung
                // (senkt plausibel klingende Fremdkörper VOR dem Kritiker-Pass, sobald das VK-Gate scharf wird).
                . 'NICHT die Produktion), zutaten: [{text, quantity, unit (g|ml|kg|l|el|tl|stk), slug, note, '
                // Etappe 1 (2026-08-14): benannter Sub-Komponenten-Slot. Ein GERICHT wird aus
                // BASISREZEPTEN gebaut — Saucen/Jus/Pürees/Fonds/Reduktionen gehören als EINE
                // benannte Komponente (sub_rezept:true) in die Liste, NICHT flach als ihre
                // Rohzutaten (kein «Steinpilz-Rahmsauce» aus Steinpilzen + Sahne, sondern eine
                // Komponente «Rahmsauce» mit sub_rezept:true). Späterer LLM-Komponenten-Marker.
                . 'sub_rezept (true, wenn diese Zeile ein eigenständiges Halbfabrikat / '
                . 'Sub-Basisrezept ist — Sauce, Jus, Fond, Sud, Essenz, Reduktion, Püree, '
                . 'Creme, Dressing, Vinaigrette, Espuma, Duxelles, Farce —, das als EIGENES Basisrezept anzulegen '
                . 'ist statt es in Rohzutaten aufzulösen; false bei einer Rohzutat/Ware), '
                . 'role (V-21: aroma_treiber|komponente|beilage|garnitur), '
                . 'fit (EIN kurzer Halbsatz: warum gehört diese Zutat FACHLICH in DIESES Gericht)}] '
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
                . 'Diät-harte Vorgaben sind VERBINDLICH.',
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
            'task' => 'Liste die natuerlichen Zaehl-Einheiten des Grundprodukts mit '
                . 'Durchschnittsgewichten: werte = {einheiten: [{unit, gewicht_g}]}.',
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
        // Markdown-Variante: liefert weiter EINEN Textblock. Bleibt im Inventar, weil
        // Generator/Revise/MCP Markdown schreiben (es wird beim Write in Schritte
        // geparst). Für den Editor ist `recipe.steps` der Weg (Spec 27).
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
                . 'Wenn rezept_typ=gericht: schreibe KEINE Herstellung der Komponenten, sondern einen kompakten '
                . 'Service-, Regenerations- und Anrichteablauf fuer ein Verkaufsgericht. Komponenten gelten als '
                . 'vorbereitet bzw. fertig produziert; nur temperieren, regenerieren, warmhalten, finalisieren, '
                . 'portionieren und anrichten. '
                . 'Wenn rezept_typ=basisrezept: schreibe die Produktions-Zubereitung des Basisrezepts. '
                . 'Buendele zusammengehoerige Kuechenhandlungen sinnvoll; keine Mikro-Schritte fuer Waschen, Schneiden, '
                . 'Pfanne erhitzen oder einzelne Gewuerzzugaben, wenn sie fachlich zusammengehoeren. '
                . 'Einfache Rezepte: 3-5 Schritte; komplexe Rezepte: 6-9 Schritte; maximal 9 Schritte. '
                . 'Temperaturen/Zeiten/Mengen konkret, aber Kleinstmengen nicht mechanisch in jeden Satz kopieren. '
                . 'Keine Fuellsaetze. '
                . 'phase = Abschnittsname (z. B. Mise en Place, Garen, Finish) oder null, gleiche Phase '
                . 'fuer aufeinanderfolgende Schritte desselben Abschnitts. Nur was aus den Zutaten '
                . 'ableitbar ist — nichts erfinden: werte = {steps: [{phase, text}]}.',
        ],
        'recipe.eigenschaften' => [
            'tier' => 'B',
            'task' => 'Schaetze die drei Rezept-Eigenschaften (haltbarkeit_tage, '
                . 'regenerierbarkeit gut|bedingt|nein, transportstabilitaet gut|bedingt|nein): '
                . 'werte = {haltbarkeit_tage, regenerierbarkeit, transportstabilitaet}.',
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
                . 'werte = {description, preparation, zutaten: [{id, text, quantity, einheit_slug}], aenderungs_notiz}.',
        ],
        'recipe.extract' => [
            'tier' => 'C',                                            // Vision — blockiert auf Martin-Frage (Offene Entscheide)
            'task' => 'Extrahiere das Rezept TREU aus dem Anhang (Foto/PDF/Text) — NICHTS '
                . 'anreichern oder erfinden (GL-13 Inv. 7, Wissenskontext bewusst leer): '
                . 'werte = {name, zutaten: [{text, quantity, unit}], preparation}.',
        ],
        'vk.plating' => [
            'tier' => 'A',                                            // V-02
            'task' => 'Schreibe die Hybrid-Plating-Anweisung fuers Verkaufsrezept (Teller-Aufbau, '
                . 'Mengenverteilung pro Komponente, Service-Anweisung — NICHT die Produktion): '
                . 'werte = {preparation}.',
        ],
        'vk.name_putzen' => [
            'tier' => 'B',
            'task' => 'Normalisiere den Verkaufsrezept-Namen auf die Pipe-Syntax §4.4 '
                . '«<HG-Code>: Hauptkomponente | Komponente | …» (max 5 Felder, Title Case, '
                . 'keine Marketing-Adjektive): werte = {name}.',
        ],
        // Et.4 (Eingabe-Reife): Titel-VORSCHLAG aus dem freien Brief (vor der Generierung), nicht das
        // Putzen eines fertigen Namens. Nüchtern + §4.4-konform; benennt nur, was der Brief hergibt.
        'vk.titel_vorschlag' => [
            'tier' => 'B',
            // #75: HG-Code NICHT vom LLM raten lassen — aus einem freien Brief kann es die
            // Warengruppe kaum zuverlässig treffen. Der Titel-Vorschlag liefert nur die nüchterne
            // Komponenten-Pipe OHNE Code-Präfix; der §4.4-HG-Code wird separat/deterministisch
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
                . 'Du erfindest NICHTS: nur, was der Brief hergibt — fehlende Angaben bleiben weg (Felder null/weglassen). '
                . 'Diaet-Werte NUR aus diaet_vokabular, Allergen-Keys NUR aus allergen_keys.',
            'task' => 'Uebersetze den Brief in ein Planungs-Geruest: werte = {name, target_price_pp, price_min_pp, price_max_pp, '
                . 'slots: [{label, slot_type (gang|station|kapitel), target_count, price_anchor, price_min, price_max, is_pflicht, '
                . 'rules: [{rule_type: diet_quota, ref_key, operator (min|max|exact), value_num, unit (count|percent)}]}], '
                . 'rules: [{rule_type: nogo_ingredient, value_text, severity (hart|weich)} | '
                . '{rule_type: nogo_allergen, ref_key} | {rule_type: allergen_line, value_text}]}. '
                . 'Preise netto p. P.; Gaenge/Stationen aus dem Anlass ableiten (Menü→gang, Buffet→station).',
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
            'task' => 'Erzeuge im mitgegebenen Schreibstil ein stimmiges Konzept-Wording ueber ALLE Positionen: '
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
            'task' => 'Entwirf «anzahl» Gericht-Skizzen für das Kapitel: werte = {ideen: [{titel, beschreibung}]}. '
                . 'titel = kurzer, konkreter Gericht-Name (kein Marketing-Claim); beschreibung = 1–2 Sätze zur '
                . 'geschmacklichen/handwerklichen Idee. Nur Skizzen — keine Mengen, keine Preise, keine Zutatenlisten.',
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
        'vk.behaelter' => [
            'tier' => 'B',
            'task' => 'Schlage Behaelter (warm/kalt getrennt) + Anzahl fuers Catering vor '
                . '(Kontext: Gesamtgewicht + Speisen-Klasse, Vokabular mitgegeben): '
                . 'werte = {behaelter_warm_id, container_warm_count, behaelter_kalt_id, container_cold_count}.',
        ],
        'vk.regeneration' => [
            'tier' => 'B',
            'task' => 'Schlage die Regenerations-Programme als LISTE vor — eine Zeile pro '
                . 'erkannter Komponente (V-19; Geraet aus Vokabular, kalt = ohne Geraet): '
                . 'werte = {programme: [{component_label, geraet_id, temp_c, duration_min, core_temp_c, hinweis}]}.',
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
                . 'unterlaufen: werte = {description, plating_text, sales_wording_standard, '
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
