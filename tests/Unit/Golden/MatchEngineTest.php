<?php

use Platform\FoodAlchemist\Enums\MatchBand;
use Platform\FoodAlchemist\Services\Matching\TokenEngine;

/**
 * M3-08: GL-04-Teilset (Tokenizer/Stemmer/Score-Kern, §3.1–3.3 + §4.1) — reine
 * Unit-Golden ohne DB. Der Voll-Port (96 Cases, Pools/Aliasse/Tiebreaker) ist M4-09.
 */
beforeEach(function () {
    $this->e = new TokenEngine;
});

it('§3.1 tokenize: Umlaute → ae/oe/ue/ss, Separatoren, Länge ≥ 2, MENGE (dedupe), Akzente bleiben (A-5)', function () {
    expect($this->e->tokenize('Süße Säfte, süße'))->toBe(['suesse', 'saefte'])
        ->and($this->e->tokenize('Zander-Filet (400g): TK/roh'))->toBe(['zander', 'filet', '400g', 'tk', 'roh'])
        ->and($this->e->tokenize('Ei à 30%'))->toBe(['ei', '30'])              // '%' raus, 'à' (Länge 1) raus
        ->and($this->e->tokenize('Crème fraîche'))->toBe(['crème', 'fraîche']); // Akzente NICHT gefoldet (A-5)
});

it('§3.1 normalize_slug: "-"→"_", Rest ersatzlos (auch Spaces)', function () {
    expect($this->e->normalizeSlug('Crème-Fraîche 30%'))->toBe('crème_fraîche30')
        ->and($this->e->normalizeSlug('Rinder-Gulasch'))->toBe('rinder_gulasch')
        ->and($this->e->normalizeSlug('Süßkartoffel'))->toBe('suesskartoffel');
});

it('§3.1 stem_german: Umlaut-Plural (auch Kompositum), ss-Stopp, Suffix-Reihenfolge, ≤4 unangetastet', function () {
    expect($this->e->stemGerman('walnuesse'))->toBe('walnuss')   // Kompositum-Suffix
        ->and($this->e->stemGerman('nuesse'))->toBe('nuss')
        ->and($this->e->stemGerman('aepfel'))->toBe('apfel')
        ->and($this->e->stemGerman('fluss'))->toBe('fluss')      // endet "ss" → Stamm
        ->and($this->e->stemGerman('salz'))->toBe('salz')        // ≤ 4 Zeichen
        ->and($this->e->stemGerman('reis'))->toBe('reis')
        ->and($this->e->stemGerman('tomaten'))->toBe('tomat')    // "en"
        ->and($this->e->stemGerman('zwiebeln'))->toBe('zwiebel') // "n" (nicht "en": endet "ln")
        ->and($this->e->stemGerman('koechinnen'))->toBe('koech'); // "innen" VOR "en"
});

it('§3.1 token_matches: exakt / Stem ≥ 4 / Prefix nur bei Längen-Diff ≤ 2', function () {
    expect($this->e->tokenMatches('tomate', 'tomaten'))->toBeTrue()       // Stem-gleich
        ->and($this->e->tokenMatches('zander', 'zander'))->toBeTrue()
        ->and($this->e->tokenMatches('butter', 'butternut'))->toBeFalse() // Diff 3 — der Spec-Fall!
        ->and($this->e->tokenMatches('gurke', 'gurken'))->toBeTrue()      // Prefix, Diff 1
        ->and($this->e->tokenMatches('senf', 'senfkoerner'))->toBeFalse(); // Stem "senf" < 4? nein =4… aber stem('senfkoerner')≠senf und Längen-Diff > 2
});

it('§3.2 Slug-Exact → 1.0; Spezifitäts-Guard 4.4q verhindert Kapern durch generischen Slug', function () {
    $score = $this->e->matchScore($this->e->tokenize('Zander'), 'zander', $this->e->tokenize('Zanderfilet: TK'), 'zander');
    expect($score)->toBe(1.0);

    // Query "Meersalz" gegen GP-Slug "salz": superstring ⇒ KEIN 1.0-Kapern, F1-Pfad
    $guard = $this->e->matchScore($this->e->tokenize('Meersalz'), 'salz', $this->e->tokenize('Salz: trocken'), 'salz');
    expect($guard)->toBeLessThan(1.0);
});

it('§3.2 F1 über gestemmte Intersection bestraft Fremd-Tokens', function () {
    // Q = {limettensaft}, C = {limettensaft, konserviert}: cq=1, cc=0.5 ⇒ f1 = 2/3
    $score = $this->e->matchScore(['limettensaft'], null, ['limettensaft', 'konserviert'], null);
    expect(round($score, 4))->toBe(round(2 / 3, 4));

    expect($this->e->matchScore(['rotwein'], null, ['weizenmehl'], null))->toBe(0.0); // I=0
});

it('§3.2 Slug-Prefix-Bonus +0.15 (Längen-Diff ≤ 3)', function () {
    // f1 = 2/3 + 0.15 Bonus (rind ⊑ rinder)
    $score = $this->e->matchScore(['rind'], 'rind', ['rind', 'gulasch'], 'rinder');
    expect(round($score, 4))->toBe(round(2 / 3 + 0.15, 4));
});

it('§3.2 Slug-Mismatch-Penalty: unverwandte Slugs cappen auf 0.45 ⇒ faktisch no_match', function () {
    // Token-seitig perfekter Treffer, aber rotwein↔rapshonig teilen nur "r"
    $score = $this->e->matchScore(['edel'], 'rotwein', ['edel'], 'rapshonig');
    expect($score)->toBeLessThanOrEqual(0.45)
        ->and(MatchBand::fuerScore($score))->toBe(MatchBand::NoMatch);

    // rindfleisch↔rindergulasch: Stamm "rind" (≥ 4 gemeinsame Zeichen) bleibt ungekappt
    $ok = $this->e->matchScore(['edel'], 'rindfleisch', ['edel'], 'rindergulasch');
    expect($ok)->toBe(1.0);
});

it('§3.3 Name-Containment-Floor: Kopf == Query (Mengen-Gleichheit, keine Teilmenge)', function () {
    expect($this->e->headMatchesQuery('Limettensaft: konserviert', ['limettensaft']))->toBeTrue()
        ->and($this->e->headMatchesQuery('Eier-Likör Sahne: konserviert', ['eier']))->toBeFalse() // |H|=3 ≠ 1
        ->and($this->e->headMatchesQuery('Tomaten, passiert', ['tomate']))->toBeTrue();           // Stem-Match im Kopf
});

it('§4.1 Schwellen-Bänder wörtlich (0.85 / 0.70 / 0.50)', function () {
    expect(MatchBand::fuerScore(0.85))->toBe(MatchBand::Exact)
        ->and(MatchBand::fuerScore(0.8499))->toBe(MatchBand::FuzzyHigh)
        ->and(MatchBand::fuerScore(0.70))->toBe(MatchBand::FuzzyHigh)
        ->and(MatchBand::fuerScore(0.6999))->toBe(MatchBand::FuzzyLow)
        ->and(MatchBand::fuerScore(0.50))->toBe(MatchBand::FuzzyLow)
        ->and(MatchBand::fuerScore(0.4999))->toBe(MatchBand::NoMatch);
});
