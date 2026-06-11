<?php

use Platform\FoodAlchemist\Enums\MatchBand;
use Platform\FoodAlchemist\Services\Matching\MatchHeuristics;
use Platform\FoodAlchemist\Services\Matching\TokenEngine;

/**
 * M4-09: GL-04 Voll-Port, DB-freier Teil — 1:1 aus recipe_matching.rs (Tests ab Z. 1491)
 * + stemming.rs (7 Tests). Namen folgen den Rust-Tests (Rückverfolgbarkeit).
 */
beforeEach(function () {
    $this->e = new TokenEngine;
    $this->h = new MatchHeuristics($this->e);
    $this->ts = fn (string $s) => $this->e->tokenize($s);
});

// ──── stemming.rs (7) ────────────────────────────────────────────────────

it('stemming: regular_plural_converges', function () {
    foreach ([['tomate', 'tomaten'], ['bohne', 'bohnen'], ['kartoffel', 'kartoffeln'], ['zwiebel', 'zwiebeln'], ['karotte', 'karotten']] as [$a, $b]) {
        expect($this->e->stemGerman($a))->toBe($this->e->stemGerman($b), "{$a}↔{$b}");
    }
});

it('stemming: adjective_flexion_converges', function () {
    $base = $this->e->stemGerman('amerikanisch');
    expect($this->e->stemGerman('amerikanische'))->toBe($base)
        ->and($this->e->stemGerman('amerikanischen'))->toBe($base);
});

it('stemming: umlaut_plural_lookup_converges', function () {
    foreach ([['nuss', 'nuesse'], ['walnuss', 'walnuesse'], ['apfel', 'aepfel'], ['wurst', 'wuerste'], ['saft', 'saefte']] as [$a, $b]) {
        expect($this->e->stemGerman($a))->toBe($this->e->stemGerman($b), "{$a}↔{$b}");
    }
});

it('stemming: no_false_collision', function () {
    expect($this->e->stemGerman('kartoffel'))->not->toBe($this->e->stemGerman('karotte'))
        ->and($this->e->stemGerman('rotwein'))->not->toBe($this->e->stemGerman('rapshonig'))
        ->and($this->e->stemGerman('tomate'))->not->toBe($this->e->stemGerman('kartoffel'))
        ->and($this->e->stemGerman('zwiebel'))->not->toBe($this->e->stemGerman('knoblauch'));
});

it('stemming: short_tokens_untouched', function () {
    foreach (['ei', 'oel', 'reis', 'salz'] as $t) {
        expect($this->e->stemGerman($t))->toBe($t);
    }
});

it('stemming: min_stem_len_respected', function () {
    expect($this->e->stemGerman('ente'))->toBe('ente')
        ->and($this->e->stemGerman('enten'))->toBe('ent');
});

it('stemming: idempotent_on_singular_stems', function () {
    $once = $this->e->stemGerman('tomaten');
    expect($this->e->stemGerman($once))->toBe($once);
});

// ──── Tokenizer + Score-Modell ───────────────────────────────────────────

it('tokenize_normalizes_umlauts_and_separators', function () {
    $t = ($this->ts)('Holländer-Käse, mittelalt');
    expect($t)->toContain('hollaender')->toContain('kaese')->toContain('mittelalt');
});

it('slug_exact_match_wins', function () {
    $score = $this->e->matchScore(($this->ts)('Eigelb'), 'eigelb', ($this->ts)('Eigelb fluessig pasteurisiert'), 'eigelb');
    expect($score)->toBeGreaterThanOrEqual(0.99);
});

it('containment_handles_query_subset', function () {
    expect($this->e->matchScore(($this->ts)('Eigelb'), null, ($this->ts)('Eigelb fluessig pasteurisiert'), null))
        ->toBeGreaterThanOrEqual(0.5);
});

it('stemming_handles_plural_singular (score)', function () {
    expect($this->e->matchScore(($this->ts)('Gewürzgurke'), null, ($this->ts)('Gewuerzgurken Cornichons konserviert'), null))
        ->toBeGreaterThanOrEqual(0.3);
});

it('slug_prefix_boost (exact bleibt 1.0)', function () {
    expect($this->e->matchScore(($this->ts)('Roggenbrötchen'), 'roggenbroetchen', ($this->ts)('Roggenbroetchen TK 80 g'), 'roggenbroetchen'))
        ->toBeGreaterThanOrEqual(0.99);
});

it('f1_rejects_single_token_in_long_candidate', function () {
    expect($this->e->matchScore(($this->ts)('Butter'), 'butter', ($this->ts)('Eiscreme Peanut Butter TK Ben Jerrys'), 'peanut_butter'))
        ->toBeLessThan(0.5);
    expect($this->e->matchScore(($this->ts)('Honig'), 'honig', ($this->ts)('Lammspiesse frisch mariniert Honig Thymian Portion 35 g'), 'lammspiesse'))
        ->toBeLessThan(0.5);
});

it('f1_keeps_clean_match', function () {
    expect($this->e->matchScore(($this->ts)('Butter'), 'butter', ($this->ts)('Butter frisch 250 g'), 'butter'))
        ->toBeGreaterThanOrEqual(0.99);
});

it('stemming_does_not_match_unrelated_prefixes', function () {
    expect($this->e->tokenMatches('butter', 'butternut'))->toBeFalse()
        ->and($this->e->tokenMatches('wasser', 'wasserlos'))->toBeFalse()
        ->and($this->e->tokenMatches('gewuerzgurke', 'gewuerzgurken'))->toBeTrue()
        ->and($this->e->tokenMatches('scharf', 'scharfer'))->toBeTrue();
});

it('no_match_low_overlap', function () {
    expect($this->e->matchScore(($this->ts)('Vanilleeis'), null, ($this->ts)('Salat Romana frisch'), null))
        ->toBeLessThan(0.3);
});

it('slug_mismatch_penalty_rotwein_rapshonig', function () {
    expect($this->e->matchScore(($this->ts)('Rotwein trocken'), 'rotwein', ($this->ts)('Rapshonig trocken'), 'rapshonig'))
        ->toBeLessThan(0.5);
});

it('slug_mismatch_penalty_keeps_related_stem', function () {
    expect($this->e->matchScore(($this->ts)('Rindfleisch aus der Keule'), 'rindfleisch', ($this->ts)('Rindergulasch frisch aus der Keule'), 'rindergulasch'))
        ->toBeGreaterThanOrEqual(0.5);
});

it('slug_mismatch_no_penalty_without_slugs', function () {
    expect($this->e->matchScore(($this->ts)('Butter'), null, ($this->ts)('Butter frisch 250 g'), null))
        ->toBeGreaterThanOrEqual(0.4);
});

it('status_thresholds', function () {
    expect(MatchBand::fuerScore(0.95))->toBe(MatchBand::Exact)
        ->and(MatchBand::fuerScore(0.80))->toBe(MatchBand::FuzzyHigh)
        ->and(MatchBand::fuerScore(0.55))->toBe(MatchBand::FuzzyLow)
        ->and(MatchBand::fuerScore(0.30))->toBe(MatchBand::NoMatch);
});

// ──── 4.4l (Bug B): Slug-Stem-Konvergenz ────────────────────────────────

it('slug_stem_singular_plural_is_exact', function () {
    expect($this->e->matchScore(($this->ts)('Rinderbrühe'), 'rinderbruehe', ($this->ts)('Rinderbruehe klar gekocht'), 'rinderbruehen'))
        ->toBeGreaterThanOrEqual(0.99);
});

it('slug_stem_does_not_collide_unrelated', function () {
    expect($this->e->matchScore(($this->ts)('Tomate'), 'tomate', ($this->ts)('Tomatenmark dreifach konzentriert'), 'tomatenmark'))
        ->toBeLessThan(0.99);
});

// ──── 4.4b: Sub-Typ-Hints ────────────────────────────────────────────────

it('sub_typ_hint detektiert Verb-/Substantiv-Marker', function () {
    expect($this->h->detectSubTypHint(($this->ts)('karamellisierte Walnüsse')))->toBe('karamell')
        ->and($this->h->detectSubTypHint(($this->ts)('marinierte Tomaten')))->toBe('marinade')
        ->and($this->h->detectSubTypHint(($this->ts)('Basilikum Pesto Genovese')))->toBe('paste')
        ->and($this->h->detectSubTypHint(($this->ts)('Limonen-Vinaigrette mit Senf')))->toBe('vinaigrette')
        ->and($this->h->detectSubTypHint(($this->ts)('Sauce Hollandaise')))->toBe('emulsion')
        ->and($this->h->detectSubTypHint(($this->ts)('Karotten')))->toBeNull()
        ->and($this->h->detectSubTypHint(($this->ts)('Vollmilch frisch pasteurisiert')))->toBeNull();
});

// ──── 4.4k: Halbfabrikat-Gate ────────────────────────────────────────────

it('halbfabrikat_gate_detects_komposita', function () {
    expect($this->h->queryIstHalbfabrikat(($this->ts)('Braune Kalbsbrühe')))->toBeTrue()
        ->and($this->h->queryIstHalbfabrikat(($this->ts)('Kalbsfond')))->toBeTrue()
        ->and($this->h->queryIstHalbfabrikat(($this->ts)('Rotweinreduktion')))->toBeTrue()
        ->and($this->h->queryIstHalbfabrikat(($this->ts)('Himbeercoulis')))->toBeTrue();
});

it('halbfabrikat_gate_rejects_grundzutaten', function () {
    expect($this->h->queryIstHalbfabrikat(($this->ts)('Rotwein trocken')))->toBeFalse()
        ->and($this->h->queryIstHalbfabrikat(($this->ts)('Knoblauch')))->toBeFalse()
        ->and($this->h->queryIstHalbfabrikat(($this->ts)('Zwiebeln')))->toBeFalse()
        ->and($this->h->queryIstHalbfabrikat(($this->ts)('Sojasauce')))->toBeFalse();
});

// ──── P8: Button-Heuristik ───────────────────────────────────────────────

it('sub_kandidat_erkennt_zubereitungen', function () {
    foreach (['Schokoladencreme', 'Baileysmousse', 'Schoko-Ganache', 'Kakao-Crumble', 'Crème brûlée',
        'Sautierter gruener Spargel', 'Rosa gebratenes Rinderfilet-Medaillon', 'Geschmorte Ochsenbacke', 'Gegrillte Zucchini'] as $name) {
        expect($this->h->istSubRezeptKandidat($name))->toBeTrue($name);
    }
});

it('sub_kandidat_lehnt_rohprodukte_ab', function () {
    foreach (['Ziegenfrischkäse', 'Rote Bete', 'Sojasauce', 'Eisbergsalat'] as $name) {
        expect($this->h->istSubRezeptKandidat($name))->toBeFalse($name);
    }
});

// ──── 4.4l/m/n: Varianten-Tiebreaker ─────────────────────────────────────

it('variant_rank_signals', function () {
    $vr = fn (string $name, string $pref, bool $raw = false) => $this->h->variantRankResolved($name, $pref, $raw);

    expect($vr('Karotten: frisch, geschaelt', 'fresh_first'))->toBeGreaterThan(0)
        ->and($vr('Karotten: TK, Baby', 'fresh_first'))->toBeLessThan(0)
        ->and($vr('Karotten: TK, Baby', 'frozen_first'))->toBeGreaterThan(0)
        ->and($vr('Tomaten: konserviert', 'frozen_first'))->toBeLessThan(0)
        ->and($vr('Tomaten: konserviert', 'preserved_first'))->toBeGreaterThan(0)
        ->and($vr('Karotten: frisch, geschaelt', 'preserved_first'))->toBeLessThan(0)
        ->and($vr('Karotten: TK, Baby', 'preserved_first'))->toBeGreaterThan(0)
        ->and($vr('Karotten: frisch, geschaelt', 'neutral'))->toBe(0)
        ->and($vr('Karotten: TK, Baby', 'neutral'))->toBe(0)
        ->and($vr('Speisesalz: jodiert', 'fresh_first'))->toBe(0);
});

it('cut_form_penalty_prefers_uncut_under_prefer_raw', function () {
    expect($this->h->variantRankResolved('Karotten: frisch, ganz', 'fresh_first', true))
        ->toBeGreaterThan($this->h->variantRankResolved('Karotten: frisch, Stifte', 'fresh_first', true));
    expect($this->h->variantRankResolved('Sellerie: TK, ganz', 'neutral', true))
        ->toBeGreaterThan($this->h->variantRankResolved('Sellerie: TK, Wuerfel', 'neutral', true));
});

it('cut_form_penalty_off_without_prefer_raw', function () {
    expect($this->h->variantRankResolved('Karotten: frisch, ganz', 'fresh_first', false))
        ->toBe($this->h->variantRankResolved('Karotten: frisch, Stifte', 'fresh_first', false));
});

it('cut_form_penalty_never_flips_zustand', function () {
    expect($this->h->variantRankResolved('Karotten: frisch, Stifte', 'fresh_first', true))
        ->toBeGreaterThan($this->h->variantRankResolved('Karotten: TK, ganz', 'fresh_first', true));
});

it('has_cut_form_detects_markers', function () {
    expect($this->h->hasCutForm(($this->ts)('Karotten: frisch, Brunoise')))->toBeTrue()
        ->and($this->h->hasCutForm(($this->ts)('Zwiebeln: frisch, gewuerfelt')))->toBeTrue()
        ->and($this->h->hasCutForm(($this->ts)('Lauch: frisch, in Streifen')))->toBeTrue()
        ->and($this->h->hasCutForm(($this->ts)('Karotten: frisch, ganz')))->toBeFalse()
        ->and($this->h->hasCutForm(($this->ts)('Zwiebeln: frisch, geschaelt')))->toBeFalse();
});

// ──── 4.4r: Bio-Achse ────────────────────────────────────────────────────

it('is_bio_tokens_signals', function () {
    expect($this->h->isBioTokens(($this->ts)('Karotten: frisch, Stifte, Bio')))->toBeTrue()
        ->and($this->h->isBioTokens(($this->ts)('Eigelb: frisch, fluessig, Bio')))->toBeTrue()
        ->and($this->h->isBioTokens(($this->ts)('Apfelsaft: biologisch')))->toBeTrue()
        ->and($this->h->isBioTokens(($this->ts)('Karotten: frisch, mini, gemischt')))->toBeFalse()
        ->and($this->h->isBioTokens(($this->ts)('Olivenoel: trocken')))->toBeFalse();
});

it('variant_rank_bio: conventional demotes / bio prefers / neutral ignores / kippt Zustand nie', function () {
    $vrb = fn (string $name, string $pref, string $bio) => $this->h->variantRankResolved($name, $pref, false, $bio);

    expect($vrb('Olivenoel: trocken', 'neutral', 'conventional'))
        ->toBeGreaterThan($vrb('Olivenoel: trocken, bio', 'neutral', 'conventional'));
    expect($vrb('Olivenoel: trocken, bio', 'neutral', 'bio'))
        ->toBeGreaterThan($vrb('Olivenoel: trocken', 'neutral', 'bio'));
    expect($vrb('Olivenoel: trocken, bio', 'neutral', 'neutral'))
        ->toBe($vrb('Olivenoel: trocken', 'neutral', 'neutral'));
    expect($vrb('Karotten: frisch, Bio', 'fresh_first', 'conventional'))
        ->toBeGreaterThan($vrb('Karotten: TK', 'fresh_first', 'conventional'));
});

// ──── 4.4u: Feld-primär ──────────────────────────────────────────────────

it('zustand_class_resolved_prefers_column', function () {
    expect($this->h->zustandClassResolved('Sellerie Spezial', 'TK'))->toBe('frozen')
        ->and($this->h->zustandClassResolved('Sellerie Spezial', 'frisch'))->toBe('fresh')
        ->and($this->h->zustandClassResolved('Sellerie Spezial', 'konserviert'))->toBe('preserved')
        ->and($this->h->zustandClassResolved('Sellerie Spezial', 'trocken'))->toBe('preserved')
        ->and($this->h->zustandClassResolved('Karotten: TK, Baby', null))->toBe('frozen')
        ->and($this->h->zustandClassResolved('Sellerie Spezial', ''))->toBe('unknown');
});

it('is_bio_resolved_prefers_column', function () {
    expect($this->h->isBioResolved(($this->ts)('Olivenoel xyz'), 'bio'))->toBeTrue()
        ->and($this->h->isBioResolved(($this->ts)('Olivenoel: trocken, bio'), 'konventionell'))->toBeFalse()
        ->and($this->h->isBioResolved(($this->ts)('Karotten: frisch, Bio'), null))->toBeTrue()
        ->and($this->h->isBioResolved(($this->ts)('Karotten: frisch'), null))->toBeFalse();
});

it('variant_rank_resolved_column_drives_tiebreak', function () {
    $tk = $this->h->variantRankResolved('Sellerie Spezial', 'frozen_first', false, 'neutral', 'TK', null);
    $fr = $this->h->variantRankResolved('Sellerie Spezial', 'frozen_first', false, 'neutral', 'frisch', null);
    expect($tk)->toBeGreaterThan($fr);

    $bio = $this->h->variantRankResolved('Olivenoel xyz', 'neutral', false, 'conventional', null, 'bio');
    $konv = $this->h->variantRankResolved('Olivenoel xyz', 'neutral', false, 'conventional', null, null);
    expect($bio)->toBeLessThan($konv);
});

// ──── 4.4q: Spezifitäts-Guard ────────────────────────────────────────────

it('name_outspecifies_slug_signals', function () {
    expect($this->e->nameOutspecifiesSlug(($this->ts)('Meersalz'), 'salz'))->toBeTrue()
        ->and($this->e->nameOutspecifiesSlug(($this->ts)('Glattpetersilie'), 'petersilie'))->toBeTrue()
        ->and($this->e->nameOutspecifiesSlug(($this->ts)('Dijon-Senf'), 'senf'))->toBeTrue()
        ->and($this->e->nameOutspecifiesSlug(($this->ts)('Salz'), 'salz'))->toBeFalse()
        ->and($this->e->nameOutspecifiesSlug(($this->ts)('Rinderhackfleisch'), 'rinderhackfleisch'))->toBeFalse()
        ->and($this->e->nameOutspecifiesSlug(($this->ts)('Tomaten frisch'), 'tomaten'))->toBeFalse();
});

it('specificity_guard_demotes_generic_slug (Kompositum + Mehrwort) und keeps_benign_exact', function () {
    expect($this->e->matchScore(($this->ts)('Meersalz'), 'salz', ($this->ts)('Salz trocken raffiniert jodiert'), 'salz'))
        ->toBeLessThan(0.85);
    expect($this->e->matchScore(($this->ts)('Dijon-Senf'), 'senf', ($this->ts)('Senf trocken extra scharf'), 'senf'))
        ->toBeLessThan(0.85);
    expect($this->e->matchScore(($this->ts)('Salz'), 'salz', ($this->ts)('Speisesalz jodiert'), 'salz'))
        ->toBeGreaterThanOrEqual(0.99);
});

// ──── 4.4o: head_matches_query ───────────────────────────────────────────

it('head_matches_query_positive_and_negative', function () {
    expect($this->e->headMatchesQuery('Rinderhackfleisch: frisch', ($this->ts)('Rinderhackfleisch')))->toBeTrue()
        ->and($this->e->headMatchesQuery('Karotten: frisch, mini, gemischt', ($this->ts)('Karotten')))->toBeTrue()
        ->and($this->e->headMatchesQuery('Tomatensugo (klassisch)', ($this->ts)('Tomatensugo')))->toBeTrue()
        ->and($this->e->headMatchesQuery('Rote Zwiebel: frisch', ($this->ts)('Rote Zwiebel')))->toBeTrue()
        ->and($this->e->headMatchesQuery('Eier-Likör Sahne Whisky Dessert', ($this->ts)('Eier')))->toBeFalse()
        ->and($this->e->headMatchesQuery('Karotten: frisch', ($this->ts)('Karotten gewuerfelt')))->toBeFalse()
        ->and($this->e->headMatchesQuery('Rapshonig trocken', ($this->ts)('Rotwein trocken')))->toBeFalse();
});

// ──── 4.4s/4.4n: Default-Aliasse (pure) ──────────────────────────────────

it('default_gp_alias_salz_only_generic', function () {
    expect($this->h->defaultGpAlias(($this->ts)('Salz'), false))->toBe('Salz / Kochsalz: trocken, unjodiert, Raffinade')
        ->and($this->h->defaultGpAlias(($this->ts)('Meersalz'), false))->toBeNull()
        ->and($this->h->defaultGpAlias(($this->ts)('Fleur de Sel'), false))->toBeNull()
        ->and($this->h->defaultGpAlias(($this->ts)('Knoblauch'), false))->toBeNull();
});

it('default_gp_alias_full_set', function () {
    $a = fn (string $s, bool $raw = false) => $this->h->defaultGpAlias(($this->ts)($s), $raw);

    expect($a('Eigelb'))->toBe('Eigelb: fluessig, pasteurisiert')
        ->and($a('Eiweiß'))->toBe('Huehnereiweiss: fluessig, pasteurisiert')
        ->and($a('Eigelb', true))->toBe('Eier: frisch, Groesse L, Bodenhaltung')
        ->and($a('Eiweiß', true))->toBe('Eier: frisch, Groesse L, Bodenhaltung')
        ->and($a('Eier'))->toBe('Eier: frisch, Groesse L, Bodenhaltung')
        ->and($a('Sahne'))->toBe('Sahne: konserviert, 30 % Fett')
        ->and($a('Milch'))->toBe('Milch: frisch, 3,5 % Fett')
        ->and($a('Mehl'))->toBe('Weizenmehl: trocken, Type 405')
        ->and($a('Zucker'))->toBe('Zucker Raffinade: trocken, weiss')
        ->and($a('Olivenöl'))->toBe('Olivenoel: trocken, hochwertig')
        ->and($a('Pfeffer'))->toBe('Pfeffer schwarz: trocken, gemahlen')
        ->and($a('Schwarzer Pfeffer'))->toBe('Pfeffer schwarz: trocken, gemahlen')
        ->and($a('Weißer Pfeffer'))->toBe('Pfeffer weiss: trocken, gemahlen')
        ->and($a('Petersilie'))->toBe('Petersilie glatt: frisch, gehackt');
});

it('default_gp_alias_generic_only_guards', function () {
    foreach (['Weizenmehl Type 550', 'Trüffelhonig', 'Cayennepfeffer', 'Bunter Pfeffer',
        'Sojasauce hell', 'Petersilie glatt', 'Petersilienwurzel', 'Brauner Zucker'] as $q) {
        expect($this->h->defaultGpAlias(($this->ts)($q), false))->toBeNull($q);
    }
});

it('default_sub_alias_rinderbruehe_maps_to_kalbsfond', function () {
    $a = fn (string $s) => $this->h->defaultSubAlias(($this->ts)($s));

    expect($a('Rinderbrühe hell'))->toBe('HELLER KALBSFOND')
        ->and($a('Rinderbrühe'))->toBe('HELLER KALBSFOND')
        ->and($a('Brauner Rinderfond'))->toBe('BRAUNER KALBSFOND')
        ->and($a('dunkle Fleischbrühe'))->toBe('BRAUNER KALBSFOND');
});

it('default_sub_alias_gefluegel_and_gemuese + jus + generisch + mayo', function () {
    $a = fn (string $s) => $this->h->defaultSubAlias(($this->ts)($s));

    expect($a('Geflügelbrühe'))->toBe('HELLER GEFLÜGELFOND')
        ->and($a('Hühnerbrühe'))->toBe('HELLER GEFLÜGELFOND')
        ->and($a('dunkle Geflügelbrühe'))->toBe('DUNKLER GEFLÜGELFOND')
        ->and($a('Gemüsebrühe'))->toBe('GEMÜSEBRÜHE')
        ->and($a('Lammjus'))->toBe('BRAUNER LAMMFOND')
        ->and($a('Kalbsjus'))->toBe('BRAUNER KALBSFOND')
        ->and($a('Geflügeljus'))->toBe('DUNKLER GEFLÜGELFOND')
        ->and($a('Brühe'))->toBe('HELLER GEFLÜGELFOND')
        ->and($a('dunkle Brühe'))->toBe('DUNKLER GEFLÜGELFOND')
        ->and($a('Mayonnaise'))->toBe('STANDARD MAYONNAISE')
        ->and($a('Trüffelmayonnaise mit Senf'))->toBeNull()
        ->and($a('Balsamico-Dressing'))->toBe('VR HAUSDRESSING');
});

it('default_sub_alias_none_for_plain_zutaten', function () {
    foreach (['Rotwein trocken', 'Knoblauch', 'Karotten frisch'] as $q) {
        expect($this->h->defaultSubAlias(($this->ts)($q)))->toBeNull($q);
    }
});
