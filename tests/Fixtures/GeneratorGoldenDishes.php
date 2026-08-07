<?php

/**
 * Phase 4 (Kohärenz-Gate): Golden-Gerichte für {@see \Platform\FoodAlchemist\Console\GeneratorEvalCommand}.
 *
 * ZWECK: die Gate-Wirkung END-TO-END gegen den ECHTEN Provider messen, statt sie
 * anekdotisch am Rahmeis-Fall zu behaupten (Memory-Regel: verify before claiming).
 *
 * Zwei Klassen:
 *  - class=risk  : herzhaftes Gericht, in das ein süßes/Dessert-Bauteil (oder eine
 *                  thematisch/stilistisch fremde Komponente) NICHT gehört. `forbid`
 *                  = distinktive Muster (SUBSTRING, umlaut-gefaltet); ist eines davon
 *                  am Ende noch VERDRAHTET, hat das Gate versagt. Bewusst KEIN blosses
 *                  „eis" (steckt in „Fleisch") — nur eindeutige Süß-/Dessert-Wörter.
 *  - class=clean : Gericht, dessen normale Komponenten LEGITIM sind (auch süße im
 *                  Dessert). Entdrahtet der Kritiker hier irgendetwas, ist das ein
 *                  False Positive — die Make-or-Break-Metrik (Legitimes verschonen).
 *
 * Die Muster sind ein Startpunkt; nach dem ersten echten Lauf (--details) verfeinern.
 *
 * @return list<array{name:string, class:string, description:string, parameter:array, forbid?:list<string>}>
 */
return [
    // ── Risiko: herzhafte Gerichte, Süß-/Dessert-/Stilfremd-Fremdkörper ───────────
    [
        'name' => 'Tomatensuppe',
        'class' => 'risk',
        'description' => 'Klassische samtige Tomatensuppe aus reifen Tomaten, herzhaft, als Menü-Suppe für ein Bankett.',
        'parameter' => ['convenience' => 'from_scratch'],
        'forbid' => ['rahmeis', 'sorbet', 'parfait', 'dessert', 'schokolade', 'karamell', 'tiramisu', 'vanillesauce'],
    ],
    [
        'name' => 'Rindergulasch',
        'class' => 'risk',
        'description' => 'Deftiges Rindergulasch mit Zwiebeln und Paprika, lange geschmort.',
        'parameter' => ['convenience' => 'from_scratch'],
        'forbid' => ['rahmeis', 'sorbet', 'parfait', 'dessert', 'schokolade', 'karamell', 'nougat'],
    ],
    [
        'name' => 'Wiener Schnitzel',
        'class' => 'risk',
        'description' => 'Klassisches Wiener Schnitzel vom Kalb, paniert, mit Zitrone.',
        'parameter' => ['convenience' => 'from_scratch'],
        'forbid' => ['cajun', 'teriyaki', 'sojasauce', 'currypaste', 'sorbet', 'parfait', 'schokolade'],
    ],
    [
        'name' => 'Kartoffelsuppe',
        'class' => 'risk',
        'description' => 'Herzhafte Kartoffelsuppe mit Wurzelgemüse und Majoran.',
        'parameter' => ['convenience' => 'from_scratch'],
        'forbid' => ['rahmeis', 'sorbet', 'parfait', 'dessert', 'schokolade', 'karamell'],
    ],
    [
        'name' => 'Rinderbraten mit Rotweinsauce',
        'class' => 'risk',
        'description' => 'Geschmorter Rinderbraten mit kräftiger Rotweinsauce und Wurzelgemüse.',
        'parameter' => ['convenience' => 'from_scratch'],
        'forbid' => ['rahmeis', 'sorbet', 'parfait', 'dessert', 'karamell', 'vanilleeis'],
    ],
    [
        'name' => 'Kürbiscremesuppe',
        'class' => 'risk',
        'description' => 'Herzhafte Kürbiscremesuppe mit Ingwer und Kokosmilch.',
        'parameter' => ['convenience' => 'from_scratch'],
        'forbid' => ['rahmeis', 'sorbet', 'parfait', 'dessert', 'schokolade', 'tiramisu'],
    ],
    [
        'name' => 'Hühnerfrikassee',
        'class' => 'risk',
        'description' => 'Cremiges Hühnerfrikassee mit Champignons und Spargel in heller Sauce.',
        'parameter' => ['convenience' => 'from_scratch'],
        'forbid' => ['rahmeis', 'sorbet', 'parfait', 'dessert', 'schokolade', 'karamell'],
    ],
    [
        'name' => 'Linseneintopf',
        'class' => 'risk',
        'description' => 'Deftiger Linseneintopf mit Suppengrün und Kartoffeln.',
        'parameter' => ['convenience' => 'from_scratch'],
        'forbid' => ['rahmeis', 'sorbet', 'parfait', 'dessert', 'schokolade', 'nougat'],
    ],
    [
        'name' => 'Spaghetti Bolognese',
        'class' => 'risk',
        'description' => 'Spaghetti mit klassischer Bolognese aus Rinderhack und Tomaten.',
        'parameter' => ['convenience' => 'from_scratch'],
        'forbid' => ['rahmeis', 'sorbet', 'parfait', 'dessert', 'schokolade', 'karamell'],
    ],
    [
        'name' => 'Caesar Salad',
        'class' => 'risk',
        'description' => 'Caesar Salad mit Römersalat, Croûtons, Parmesan und Sardellendressing.',
        'parameter' => ['convenience' => 'from_scratch'],
        'forbid' => ['rahmeis', 'sorbet', 'parfait', 'dessert', 'schokolade', 'karamell'],
    ],

    // ── Sauber: legitime Komponenten (auch süße im Dessert) — dürfen NICHT flaggen ─
    [
        'name' => 'Schokoladenmousse',
        'class' => 'clean',
        'description' => 'Klassisches Schokoladenmousse aus Zartbitterschokolade und Sahne, als Dessert.',
        'parameter' => ['convenience' => 'from_scratch'],
    ],
    [
        'name' => 'Vanilleeis',
        'class' => 'clean',
        'description' => 'Cremiges Vanilleeis aus Milch, Sahne, Eigelb und Vanille.',
        'parameter' => ['convenience' => 'from_scratch'],
    ],
    [
        'name' => 'Apfelstrudel',
        'class' => 'clean',
        'description' => 'Wiener Apfelstrudel mit Äpfeln, Rosinen, Zimt und Zucker, als Dessert.',
        'parameter' => ['convenience' => 'from_scratch'],
    ],
    [
        'name' => 'Gemüsebrühe',
        'class' => 'clean',
        'description' => 'Klare Gemüsebrühe aus Suppengrün, Zwiebel und Lorbeer.',
        'parameter' => ['convenience' => 'from_scratch'],
    ],
    [
        'name' => 'Rinderroulade',
        'class' => 'clean',
        'description' => 'Klassische Rinderroulade mit Senf, Speck, Zwiebel und Gewürzgurke in dunkler Sauce.',
        'parameter' => ['convenience' => 'from_scratch'],
    ],
    [
        'name' => 'Ratatouille',
        'class' => 'clean',
        'description' => 'Provenzalisches Ratatouille aus Aubergine, Zucchini, Paprika und Tomaten.',
        'parameter' => ['convenience' => 'from_scratch'],
    ],
];
