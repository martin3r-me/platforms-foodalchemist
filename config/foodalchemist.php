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
                    'label' => 'Zu prüfen',
                    'route' => 'foodalchemist.review',
                    'icon'  => 'heroicon-o-clipboard-document-check',
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
            // M10 GEBAUT (Doc 15 §M10): Concepter ist das Rückgrat → eigene AKTIVE Gruppe
            // über "In Planung". Concepts = Slot-Gerüst über mehrere Rollen; Pakete =
            // bepreiste Bündel mehrerer Gerichte (im Konzeptpapier "Modul").
            'group' => 'Concepter',
            'items' => [
                [
                    // M10R-5 (§10.2): EIN Eintrag — der vereinheitlichte Browser mit
                    // Umschalter Concepts | Pakete + Voll-Editor-Modal (Tabs). Die
                    // alten Einzel-Screens /concepts + /pakete bleiben als Deep-Link
                    // erreichbar (nicht mehr in der Sidebar).
                    'label' => 'Concepter',
                    'route' => 'foodalchemist.concepter.index',
                    'icon'  => 'heroicon-o-square-3-stack-3d',
                ],
            ],
        ],
        [
            // M11 GEBAUT: Foodbook stellt fertige Concepts zu Kunden-Angeboten zusammen
            // (Kapitel, Pax, Angebots-Preise) — KEINE Einzel-Gerichte (Concepter = Kern).
            'group' => 'Foodbook',
            'items' => [
                [
                    'label' => 'Foodbook / Portfolio',
                    'route' => 'foodalchemist.foodbooks.index',
                    'icon'  => 'heroicon-o-book-open',
                ],
            ],
        ],
        [
            // M12 GEBAUT: Kalkulations-Übersicht (HK1 Wareneinsatz → HK2 Vollkosten + Deckungsbeitrag).
            'group' => 'Kalkulation',
            'items' => [
                [
                    'label' => 'Kalkulation (HK2)',
                    'route' => 'foodalchemist.kalkulation.index',
                    'icon'  => 'heroicon-o-calculator',
                ],
            ],
        ],
        [
            // M14 GEBAUT: Speiseplan — Bausteine über die Zeitachse (Tag × Mahlzeit, Wochen-Zyklus).
            // M10–M14 sind damit alle gebaut. M16+-Domänen (Produktionsplanung/Einkauf/Lager/
            // Controlling) + Zielpreis-Konfigurator-Modus stehen in docs/15_MASTERPLAN_VISION.md;
            // Sidebar zeigt nur Gebautes (Dominique 2026-06-13). Konfigurator = Modus im Concept-Editor.
            'group' => 'Speiseplan',
            'items' => [
                [
                    'label' => 'Speiseplan',
                    'route' => 'foodalchemist.speiseplan.index',
                    'icon'  => 'heroicon-o-calendar-days',
                ],
            ],
        ],
        [
            'group' => 'Einstellungen',
            'items' => [
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
                . '{"werte": …, "confidence": 0-1, "begruendung": "…"} zurück (Smoke-Test).',
        ],
        // M3-09/10: GP-Modal (Naming-Builder + KI-Felder). Antwort-Schema immer
        // {"werte": {…}, "confidence": 0-1, "begruendung": "…"} (GL-07).
        'gp.suggest' => [
            'tier' => 'C',
            'task' => 'Leite aus der Roh-Bezeichnung eines Lebensmittels die strukturierten '
                . 'GP-Naming-Felder nach Regelwerk §6 ab: werte = {hauptzutat, zustand '
                . '(frisch|TK|trocken|konserviert), verarbeitung, form, pflichtangabe}. '
                . 'Singular/Lemma (§6.1), keine Verpackungswörter (§7.1), Marke nur nach §5-Tiebreaker.',
        ],
        'gp.zustand' => [
            'tier' => 'D',
            'task' => 'Bestimme den §9-Zustand (frisch|TK|trocken|konserviert) des Grundprodukts '
                . 'aus Name und Lieferantenartikeln: werte = {zustand}.',
        ],
        'recipe.generator' => [
            'tier' => 'B',
            'task' => 'Erzeuge ein Basisrezept aus der Beschreibung unter Beachtung der Richtungs-'
                . 'Parameter (convenience, frische, bio, niveau, sektor, diaet_hart, aroma): werte = '
                . '{name (§1-Syntax <Typ>: <Bezeichnung>), beschreibung (§8-Stil), geschmacksrichtung, '
                . 'zubereitung (Markdown-Schritte), zutaten: [{text, menge, einheit (g|ml|kg|l|el|tl|stk), '
                . 'slug (hauptzutat), note}]}. Diät-harte Vorgaben sind VERBINDLICH.',
        ],
        'recipe.beschreibung' => [
            'tier' => 'C',
            'task' => 'Schreibe die Rezept-Beschreibung im §8-Stil (sachlich-appetitlich, 2-4 Sätze, '
                . 'Textur + Einsatzkontext, keine Marketing-Floskeln): werte = {beschreibung}.',
        ],
        'recipe.kategorie' => [
            'tier' => 'D',
            'task' => 'Ordne das Rezept der passenden Produktions-Kategorie zu (aus der mitgegebenen '
                . 'Kategorie-Liste): werte = {kategorie_id, kategorie_name}.',
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
                . 'keine Marketing-Adjektive), beschreibung (§8-Stil), geschmacksrichtung, '
                . 'zubereitung (= PLATING & SERVICE: Teller-Aufbau, Mengenverteilung, Service-Anweisung — '
                . 'NICHT die Produktion), zutaten: [{text, menge, einheit (g|ml|kg|l|el|tl|stk), slug, note}] '
                . '(Komponenten bevorzugt als Basisrezepte; wenn bestands_inventar mitgegeben ist, benenne '
                . 'passende Komponenten EXAKT wie dort gelistet — vorhandene Basisrezepte zuerst), '
                . 'speisen_klasse_id (aus der mitgegebenen Liste, '
                . 'null wenn unsicher), aufschlagsklasse_code (aus der mitgegebenen Liste)}. '
                . 'Diät-harte Vorgaben sind VERBINDLICH.',
        ],
        'vk.speisen_klasse' => [
            'tier' => 'B',
            'task' => 'Klassifiziere das Verkaufsrezept in GENAU EINE Speisen-Klasse aus der '
                . 'mitgegebenen Taxonomie (Kontext: Name, Komponenten, Diät-Eigenschaften). '
                . 'Kein sicherer Treffer => speisen_klasse_id = null (NICHT raten): '
                . 'werte = {speisen_klasse_id, klasse_name}.',
        ],
        'vk.rollen' => [
            'tier' => 'B',
            'task' => 'Verteile die Komponenten-Rollen uebers GANZE Gericht (V-21-Vokabular: '
                . 'aroma_treiber | komponente | beilage | garnitur — jede Zutat genau eine Rolle, '
                . 'Gesamt-Gericht-Sicht statt Einzelbetrachtung): werte = {rollen: {<zutat_id>: rolle}}.',
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
        'gp.stk_default_g' => [
            'tier' => 'B',
            'task' => 'Schaetze das Stueck-Durchschnittsgewicht des Grundprodukts in Gramm '
                . '(kuechenuebliche Handelsware): werte = {stk_default_g}.',
        ],
        'gp.zaehl_einheiten' => [
            'tier' => 'B',
            'task' => 'Liste die natuerlichen Zaehl-Einheiten des Grundprodukts mit '
                . 'Durchschnittsgewichten: werte = {einheiten: [{einheit, gewicht_g}]}.',
        ],
        'gp.anker' => [
            'tier' => 'B',
            'task' => 'Bestimme den Kern-Anker (Aroma-Identitaet) des Grundprodukts aus dem '
                . 'mitgegebenen Anker-Vokabular; kein Aroma-Traeger => neutral: werte = {anker_slug}.',
        ],
        'gp.rolle' => [
            'tier' => 'B',                                            // Inline-Prompt im Ist — gehoben
            'task' => 'Bestimme die kulinarische Rolle des Grundprodukts '
                . '(aroma_treiber|komponente|beilage|garnitur): werte = {rolle}.',
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
        'recipe.niveau' => [
            'tier' => 'B',
            'task' => 'Beurteile die Eignung des Rezepts je Niveau-Stufe '
                . '(geeignet|bedingt|ungeeignet + kurze Begruendung): werte = {niveaus: {<slug>: {eignung, grund}}}.',
        ],
        'recipe.sub_typ' => [
            'tier' => 'B',
            'task' => 'Klassifiziere das Rezept zu GENAU EINEM Sub-Rezept-Typ aus dem mitgegebenen '
                . 'Vokabular; kein Treffer => null: werte = {sub_typ_slug}.',
        ],
        'recipe.fertigungstiefe' => [
            'tier' => 'B',
            'task' => 'Klassifiziere die Fertigungstiefe (from_scratch|teilfertig|convenience) '
                . 'aus den Zutaten: werte = {fertigungstiefe}.',
        ],
        'recipe.zubereitung' => [
            'tier' => 'A',                                            // V-02: langes Einzeltext-Feld
            'task' => 'Schreibe die Schritt-fuer-Schritt-Zubereitung fuers PRODUKTIONS-Rezept '
                . '(Markdown, nummerierte Schritte, Temperaturen/Zeiten konkret, H2 fuer Phasen): '
                . 'werte = {zubereitung}.',
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
                . '(suess|herzhaft|neutral): werte = {geschmacksrichtung}.',
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
                . 'werte = {beschreibung, zubereitung, zutaten: [{id, text, menge, einheit_slug}], aenderungs_notiz}.',
        ],
        'recipe.extract' => [
            'tier' => 'C',                                            // Vision — blockiert auf Martin-Frage (Offene Entscheide)
            'task' => 'Extrahiere das Rezept TREU aus dem Anhang (Foto/PDF/Text) — NICHTS '
                . 'anreichern oder erfinden (GL-13 Inv. 7, Wissenskontext bewusst leer): '
                . 'werte = {name, zutaten: [{text, menge, einheit}], zubereitung}.',
        ],
        'vk.plating' => [
            'tier' => 'A',                                            // V-02
            'task' => 'Schreibe die Hybrid-Plating-Anweisung fuers Verkaufsrezept (Teller-Aufbau, '
                . 'Mengenverteilung pro Komponente, Service-Anweisung — NICHT die Produktion): '
                . 'werte = {zubereitung}.',
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
                . 'stil-neutral — Schreibstile transformieren erst spaeter): werte = {vk_wording_standard}.',
        ],
        'vk.behaelter' => [
            'tier' => 'B',
            'task' => 'Schlage Behaelter (warm/kalt getrennt) + Anzahl fuers Catering vor '
                . '(Kontext: Gesamtgewicht + Speisen-Klasse, Vokabular mitgegeben): '
                . 'werte = {behaelter_warm_id, behaelter_warm_anzahl, behaelter_kalt_id, behaelter_kalt_anzahl}.',
        ],
        'vk.regeneration' => [
            'tier' => 'B',
            'task' => 'Schlage die Regenerations-Programme als LISTE vor — eine Zeile pro '
                . 'erkannter Komponente (V-19; Geraet aus Vokabular, kalt = ohne Geraet): '
                . 'werte = {programme: [{komponente_label, geraet_id, temp_c, dauer_min, kerntemp_c, hinweis}]}.',
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
                . 'oder null): werte = {score, label, begruendung, schwachstelle}.',
        ],
        'vk.teller_heber' => [
            'tier' => 'A',                                            // Inline-Prompt im Ist (plate_suggester) — gehoben
            'task' => 'Schlage vor, was den Teller hebt (1-3 konkrete, machbare Verbesserungen — '
                . 'keine Fantasie-Zutaten; typ je Vorschlag: kontrast | ergaenzung | veredelung): '
                . 'werte = {einschaetzung, vorschlaege: [{typ, zutat, kategorie, begruendung, confidence}]}.',
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
