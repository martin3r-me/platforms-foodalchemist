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
                    'label' => 'Test',
                    'route' => 'foodalchemist.test',
                    'icon'  => 'heroicon-o-beaker',
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
                    'label' => 'Verkaufsrezepte',
                    'route' => 'foodalchemist.verkauf.index',
                    'icon'  => 'heroicon-o-banknotes',
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
    'ai' => [
        'provider' => env('FOODALCHEMIST_AI_PROVIDER', 'core'),
    ],

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
                . '(Komponenten bevorzugt als Basisrezepte), speisen_klasse_id (aus der mitgegebenen Liste, '
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
        'gp.tags' => [
            'tier' => 'C',
            'task' => 'Bewerte die Eigenschafts-Tags des Grundprodukts (vegan, vegetarisch, halal, '
                . 'contains_pork, contains_beef, organic, regional, grundnahrungsmittel, convenience, '
                . 'lactose_free, gluten_free) als true/false; unbewertbare Tags weglassen: werte = {is_vegan: bool, …}.',
        ],
    ],
];
