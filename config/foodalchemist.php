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
            'group' => 'Allgemein',
            'items' => [
                [
                    'label' => 'Dashboard',
                    'route' => 'foodalchemist.dashboard',
                    'icon'  => 'heroicon-o-home',
                ],
                [
                    // #378: „Zu prüfen" → „Signale" — Aufmerksamkeits-Inbox (Klasse A Entscheidungs-Queues + Klasse B detektierte Signale)
                    'label' => 'Signale',
                    'route' => 'foodalchemist.review',
                    'icon'  => 'heroicon-o-bell-alert',
                ],
                [
                    // #389: Food DNA — „Markenkern Küche", stehende KI-Referenz für alle Generatoren
                    'label' => 'Food DNA',
                    'route' => 'foodalchemist.food-dna.index',
                    'icon'  => 'heroicon-o-finger-print',
                ],
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
            ],
        ],
        [
            'group' => 'Rezepte',
            'items' => [
                [
                    'label' => 'Basisrezepte',
                    'route' => 'foodalchemist.recipes.index',
                    'icon'  => 'heroicon-o-book-open',
                ],
                [
                    'label' => 'Gerichte',
                    'route' => 'foodalchemist.verkauf.index',
                    'icon'  => 'heroicon-o-banknotes',
                ],
            ],
        ],
        [
            // Verdichtung 2026-07-14 (Dominique): früher je Einzel-Eintrag eine eigene
            // Sektion (Concepter/Foodbook/Angebote/Kalkulation …) → zu viele Ein-Item-Header.
            // Jetzt nach Workflow gebündelt: Rezepte & Konzepte / Verkauf / Planung / System.
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
                    'icon'  => 'heroicon-o-banknotes',
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
            // Verkauf: Portfolio (Foodbook) + Kunden-Angebote + Preissimulation.
            'group' => 'Verkauf',
            'items' => [
                [
                    'label' => 'Foodbook / Portfolio',
                    'route' => 'foodalchemist.foodbooks.index',
                    'icon'  => 'heroicon-o-book-open',
                ],
                [
                    'label' => 'Angebote',
                    'route' => 'foodalchemist.angebote.index',
                    'icon'  => 'heroicon-o-document-text',
                ],
                [
                    // #502: Was-wäre-wenn-Preissimulation als eigener Screen.
                    'label' => 'Preissimulation',
                    'route' => 'foodalchemist.kalkulation.index',
                    'icon'  => 'heroicon-o-arrows-right-left',
                ],
            ],
        ],
        [
            // Planung: Zeitachse (Speiseplan) + operative Planungs-Blätter (R7.1).
            'group' => 'Planung',
            'items' => [
                [
                    'label' => 'Speiseplan',
                    'route' => 'foodalchemist.speiseplan.index',
                    'icon'  => 'heroicon-o-calendar-days',
                ],
                [
                    'label' => 'Planungs-Blätter',
                    'route' => 'foodalchemist.blaetter.index',
                    'icon'  => 'heroicon-o-clipboard-document-list',
                ],
            ],
        ],
        [
            // System: Wissensbasis + Einstellungen.
            'group' => 'System',
            'items' => [
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
    'stt' => [
        'provider' => env('FOODALCHEMIST_STT_PROVIDER', 'fake'),
        'key' => env('ASSEMBLYAI_API_KEY', ''),
        'timeout_s' => 30,
        'fake_text' => 'Suche BBQ Sauce',
    ],

    'ai' => [
        'provider' => env('FOODALCHEMIST_AI_PROVIDER', 'core'),

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
    'semantic_search' => [
        'enabled'        => (bool) env('FOODALCHEMIST_SEMANTIC_SEARCH', false),
        'provider'       => env('FOODALCHEMIST_EMBEDDING_PROVIDER'),     // null = Core-Default
        'global_team_id' => (int) env('FOODALCHEMIST_SEMANTIC_GLOBAL_TEAM_ID', 0),
        'min_score'      => (float) env('FOODALCHEMIST_SEMANTIC_MIN_SCORE', 0.30),
        // Anker-Auflösung (B): höhere Schwelle — eine FALSCHE Anker-Auflösung
        // injiziert falsche Pairing-Kanten, das ist schlimmer als „unbekannt".
        'anker_min_score' => (float) env('FOODALCHEMIST_SEMANTIC_ANKER_MIN_SCORE', 0.55),
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
            'task' => 'Erzeuge ein Basisrezept aus der Beschreibung unter Beachtung der Richtungs-'
                . 'Parameter (convenience, frische, bio, niveau, sektor, diaet_hart, aroma): werte = '
                . '{name (§1-Syntax <Typ>: <Bezeichnung>), description (§8-Stil), taste_direction, '
                . 'preparation (Markdown-Schritte), zutaten: [{text, quantity, unit (g|ml|kg|l|el|tl|stk), '
                . 'slug (hauptzutat), note}]}. Diät-harte Vorgaben sind VERBINDLICH.',
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
        'vk.generator' => [
            'tier' => 'B',
            'task' => 'Erzeuge ein VERKAUFSREZEPT (Teller/Speise mit VK-Preis) aus der Beschreibung '
                . 'unter Beachtung der Richtungs-Parameter (convenience, frische, bio, niveau, sektor, '
                . 'diaet_hart, aroma, anlass, serviceform, kompositions_stil): werte = '
                . '{name (Pipe-Syntax §4.4 «<HG-Code>: Hauptkomponente | Komponente | …», max 5 Felder, '
                . 'keine Marketing-Adjektive), description (§8-Stil), taste_direction, '
                . 'preparation (= PLATING & SERVICE: Teller-Aufbau, Mengenverteilung, Service-Anweisung — '
                . 'NICHT die Produktion), zutaten: [{text, quantity, unit (g|ml|kg|l|el|tl|stk), slug, note}] '
                . '(Komponenten bevorzugt als Basisrezepte; wenn bestands_inventar mitgegeben ist, benenne '
                . 'passende Komponenten EXAKT wie dort gelistet — vorhandene Basisrezepte zuerst), '
                . 'dish_class_id (aus der mitgegebenen Liste, '
                . 'null wenn unsicher), aufschlagsklasse_code (aus der mitgegebenen Liste)}. '
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
        'recipe.preparation' => [
            'tier' => 'A',                                            // V-02: langes Einzeltext-Feld
            'task' => 'Schreibe die Schritt-fuer-Schritt-Zubereitung fuers PRODUKTIONS-Rezept '
                . '(Markdown, nummerierte Schritte, Temperaturen/Zeiten konkret, H2 fuer Phasen): '
                . 'werte = {preparation}.',
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
            'tier' => 'A',
            'task' => 'Pruefe das Produktionsrezept als Sous-Chef auf Plausibilitaet (Mengen, '
                . 'Technik, Reihenfolge, Luecken) — konkrete Befunde statt Floskeln: '
                . 'werte = {befunde: [{schwere, text}], gesamturteil}.',
        ],
        'recipe.pairing' => [
            'tier' => 'A',                                            // groesster Ist-Kostenblock — Qualitaet zaehlt
            'task' => 'Schlage 12-25 BELEGTE Flavor-Pairing-Partner aus dem mitgegebenen '
                . 'Grounding vor (typ klassisch|modern|kontrast, konfidenz hoch|mittel|niedrig; '
                . 'erfinde KEINE unbelegten Paarungen): werte = {pairings: [{slug, typ, konfidenz}]}.',
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
        'concept.wording' => [
            'tier' => 'A',
            'task' => 'Erzeuge im mitgegebenen Schreibstil ein stimmiges Konzept-Wording ueber ALLE Positionen: '
                . 'werte = {intro, slots}. intro = kurzer Einleitungs-/Praesentationstext fuer das ganze Konzept. '
                . 'slots = Map slot_id -> Brand-Voice-Anzeigename je Position (Variante des neutralen sales_wording_standard, '
                . 'ueber das gesamte Menue stimmig und wiedererkennbar).',
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
            'tier' => 'A',
            'task' => 'Pruefe das Verkaufsrezept als Copilot auf Verkaufs-Tauglichkeit '
                . '(Marge, Portionierung, Service-Logik, Wording): werte = {befunde: [{schwere, text}], gesamturteil}.',
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
    ],
];
