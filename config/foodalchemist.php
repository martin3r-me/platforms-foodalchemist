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
    ],
];
