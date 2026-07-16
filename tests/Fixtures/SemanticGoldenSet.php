<?php

/**
 * E0 (#507): Kalibrierungs-Golden-Set für den hybriden Retrieval-Layer (E2).
 *
 * ZWECK: das Pflicht-Gate vor dem Online-Scharfstellen. Der SEM_FLOOR (0.55) ist
 * Gemini-768d-geeicht — für OpenAI (Entscheid A) MÜSSEN alle Floors an DIESEM Set
 * neu kalibriert werden (E5). Ohne dieses Set ist jede Floor-Diskussion Bauchgefühl.
 *
 * ABGRENZUNG: Dieses Set spezifiziert ERWARTUNGEN (Query → semantisch verwandtes
 * Ziel bzw. verbotene Verwechslung), NICHT Fixture-GP-IDs. Die E5-Report-Harness
 * läuft es gegen den ECHTEN Master-GP-Bestand + echten Embedder und misst Recall@15
 * je Fallklasse. Unter dem FakeEmbeddingProvider ist es NICHT messbar (Bag-of-Words
 * stemmt/übersetzt nicht) — deshalb hier nur Wohlgeformtheit getestet.
 *
 * Fallklassen:
 *  - synonym     : anderes Wort, gleiche Zutat (Möhre/Karotte, Rahm/Sahne)
 *  - translation : fremdsprachig → deutsch (Beef/Rindfleisch, Erdapfel/Kartoffel)
 *  - compound    : Kompositum ↔ §1-Syntax (Kürbispüree ↔ Püree: Kürbis)
 *  - regional    : Dialekt/regional (Erdapfel, Fisolen, Topfen)
 *  - anti_marker : DARF NICHT matchen (Brie↛Bries) — Quelle Cross_Cutting/Anti_Marker.md
 *
 * @return list<array{query:string, expect:?string, forbid:?string, relation:string, polarity:string, note:string}>
 */

return [
    // ── translation: fremdsprachig → deutsche Zutat ──────────────────────────
    ['query' => 'Beef',               'expect' => 'Rindfleisch',        'forbid' => null, 'relation' => 'translation', 'polarity' => 'positive', 'note' => 'EN→DE; Demo-Fall #507 (MATCH("Beef")→Corned Beef statt Rind)'],
    ['query' => 'Pork',               'expect' => 'Schweinefleisch',    'forbid' => null, 'relation' => 'translation', 'polarity' => 'positive', 'note' => 'EN→DE'],
    ['query' => 'Chicken breast',     'expect' => 'Hähnchenbrust',      'forbid' => null, 'relation' => 'translation', 'polarity' => 'positive', 'note' => 'EN→DE'],
    ['query' => 'Salmon',             'expect' => 'Lachs',              'forbid' => null, 'relation' => 'translation', 'polarity' => 'positive', 'note' => 'EN→DE'],
    ['query' => 'Aubergine',          'expect' => 'Aubergine',          'forbid' => null, 'relation' => 'translation', 'polarity' => 'positive', 'note' => 'FR/EN=DE, Kontrolle'],
    ['query' => 'Courgette',          'expect' => 'Zucchini',           'forbid' => null, 'relation' => 'translation', 'polarity' => 'positive', 'note' => 'EN→DE'],
    ['query' => 'Coriander',          'expect' => 'Koriander',          'forbid' => null, 'relation' => 'translation', 'polarity' => 'positive', 'note' => 'EN→DE'],
    ['query' => 'Prawns',             'expect' => 'Garnelen',           'forbid' => null, 'relation' => 'translation', 'polarity' => 'positive', 'note' => 'EN→DE (Sammelware Plural)'],

    // ── synonym: anderes Wort, gleiche Zutat ─────────────────────────────────
    ['query' => 'Möhre',              'expect' => 'Karotte',            'forbid' => null, 'relation' => 'synonym', 'polarity' => 'positive', 'note' => 'DE-Synonym'],
    ['query' => 'Rahm',               'expect' => 'Sahne',              'forbid' => null, 'relation' => 'synonym', 'polarity' => 'positive', 'note' => 'DE-Synonym'],
    ['query' => 'Sauerrahm',          'expect' => 'saure Sahne',        'forbid' => null, 'relation' => 'synonym', 'polarity' => 'positive', 'note' => 'DE-Synonym'],
    ['query' => 'Blumenkohl',         'expect' => 'Karfiol',            'forbid' => null, 'relation' => 'synonym', 'polarity' => 'positive', 'note' => 'DE/AT-Synonym'],
    ['query' => 'Rote Bete',          'expect' => 'Rote Rübe',          'forbid' => null, 'relation' => 'synonym', 'polarity' => 'positive', 'note' => 'DE-Synonym'],
    ['query' => 'Kohlrübe',           'expect' => 'Steckrübe',          'forbid' => null, 'relation' => 'synonym', 'polarity' => 'positive', 'note' => 'DE-Synonym'],
    ['query' => 'Pilz',               'expect' => 'Champignon',         'forbid' => null, 'relation' => 'synonym', 'polarity' => 'positive', 'note' => 'Hyperonym→gängigster Vertreter'],
    ['query' => 'Speisequark',        'expect' => 'Quark',              'forbid' => null, 'relation' => 'synonym', 'polarity' => 'positive', 'note' => 'DE-Synonym'],

    // ── regional/dialektal ───────────────────────────────────────────────────
    ['query' => 'Erdapfel',           'expect' => 'Kartoffel',          'forbid' => null, 'relation' => 'regional', 'polarity' => 'positive', 'note' => 'AT→DE; Plan-Beispiel'],
    ['query' => 'Fisolen',            'expect' => 'grüne Bohnen',       'forbid' => null, 'relation' => 'regional', 'polarity' => 'positive', 'note' => 'AT→DE'],
    ['query' => 'Topfen',             'expect' => 'Quark',              'forbid' => null, 'relation' => 'regional', 'polarity' => 'positive', 'note' => 'AT→DE'],
    ['query' => 'Paradeiser',         'expect' => 'Tomate',             'forbid' => null, 'relation' => 'regional', 'polarity' => 'positive', 'note' => 'AT→DE'],
    ['query' => 'Obers',             'expect' => 'Sahne',              'forbid' => null, 'relation' => 'regional', 'polarity' => 'positive', 'note' => 'AT→DE'],
    ['query' => 'Marille',            'expect' => 'Aprikose',           'forbid' => null, 'relation' => 'regional', 'polarity' => 'positive', 'note' => 'AT→DE'],

    // ── compound: Kompositum ↔ §1-Syntax / Wortreihenfolge ───────────────────
    ['query' => 'Kürbispüree',        'expect' => 'Püree: Kürbis',      'forbid' => null, 'relation' => 'compound', 'polarity' => 'positive', 'note' => 'Kompositum↔Doppelpunkt-Syntax; Plan-Beispiel + GL-04 §6.2'],
    ['query' => 'Rinderhackfleisch',  'expect' => 'Hackfleisch vom Rind','forbid' => null,'relation' => 'compound', 'polarity' => 'positive', 'note' => 'GT-T94b dokumentierte Lücke'],
    ['query' => 'Kalbsjus',           'expect' => 'Jus: Kalb',          'forbid' => null, 'relation' => 'compound', 'polarity' => 'positive', 'note' => 'Kompositum↔Syntax'],
    ['query' => 'Tomatensugo',        'expect' => 'Sugo: Tomate',       'forbid' => null, 'relation' => 'compound', 'polarity' => 'positive', 'note' => 'Kompositum↔Syntax'],
    ['query' => 'Rotweinjus',         'expect' => 'Jus: Rotwein',       'forbid' => null, 'relation' => 'compound', 'polarity' => 'positive', 'note' => 'Kompositum↔Syntax'],
    ['query' => 'Petersilienwurzel',  'expect' => 'Wurzelpetersilie',   'forbid' => null, 'relation' => 'compound', 'polarity' => 'positive', 'note' => 'Wortreihenfolge'],
    ['query' => 'Bürgermeisterstück', 'expect' => 'Rind',               'forbid' => null, 'relation' => 'compound', 'polarity' => 'positive', 'note' => 'Teilstück→Tier; Plan-Beispiel'],
    ['query' => 'Tafelspitz',         'expect' => 'Rind',               'forbid' => null, 'relation' => 'compound', 'polarity' => 'positive', 'note' => 'Teilstück→Tier'],
    ['query' => 'Schweinsbraten',     'expect' => 'Schweinefleisch',    'forbid' => null, 'relation' => 'compound', 'polarity' => 'positive', 'note' => 'Zubereitung→Rohware'],
    ['query' => 'Topinambur',         'expect' => 'Wurzelgemüse',       'forbid' => null, 'relation' => 'compound', 'polarity' => 'positive', 'note' => 'Domain-Discovery-Fall (KnowledgeEmbeddingService-Test)'],

    // ── anti_marker: DARF NICHT matchen (Quelle Anti_Marker.md) ──────────────
    ['query' => 'Bries',              'expect' => null, 'forbid' => 'Brie',              'relation' => 'anti_marker', 'polarity' => 'negative', 'note' => 'Innerei (Kalbsthymus) ↛ Weichkäse; Anti_Marker #2'],
    ['query' => 'Kalbsbries',         'expect' => null, 'forbid' => 'Brie',              'relation' => 'anti_marker', 'polarity' => 'negative', 'note' => 'Innerei ↛ Käse'],
    ['query' => 'Triple Sec',         'expect' => null, 'forbid' => 'Triple Chocolate',  'relation' => 'anti_marker', 'polarity' => 'negative', 'note' => 'Likör ↛ Schoko-Backware; Anti_Marker #1'],
    ['query' => 'Cookies Triple Chocolate', 'expect' => null, 'forbid' => 'Triple Sec',  'relation' => 'anti_marker', 'polarity' => 'negative', 'note' => 'Backware ↛ Spirituose'],
    ['query' => 'Cointreau',          'expect' => null, 'forbid' => 'Orange',            'relation' => 'anti_marker', 'polarity' => 'negative', 'note' => 'Spirituose ↛ Frucht'],
    ['query' => 'Limoncello',         'expect' => null, 'forbid' => 'Zitrone',           'relation' => 'anti_marker', 'polarity' => 'negative', 'note' => 'Spirituose ↛ Frucht'],
    ['query' => 'Paprikapulver',      'expect' => null, 'forbid' => 'Paprika: frisch',   'relation' => 'anti_marker', 'polarity' => 'negative', 'note' => 'Gewürz-Pulver ↛ frische Schote; Anti_Marker'],
    ['query' => 'Sojasauce',          'expect' => null, 'forbid' => 'Sauce Hollandaise', 'relation' => 'anti_marker', 'polarity' => 'negative', 'note' => 'gemeinsames Token "Sauce" darf nicht verbinden (GL-04-Schutz)'],
];
