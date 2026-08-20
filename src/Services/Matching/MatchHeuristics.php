<?php

namespace Platform\FoodAlchemist\Services\Matching;

/**
 * M4-09: GL-04-Heuristik-Schicht — 1:1-Port von recipe_matching.rs (DB-frei):
 * Sub-Typ-Hints (4.4b), Halbfabrikat-Gate (4.4k), Zubereitungs-Marker (P8),
 * Zustand-/Bio-/Cut-Tiebreaker (4.4m/n/r/u), §4-/§5-Default-Aliasse (4.4n/s),
 * Substring-Overlap für die Shortlist (4.4p).
 */
class MatchHeuristics
{
    public const SUB_TYP_HINT_BOOST = 0.20;

    public const SUB_ALIAS_SCORE = 0.95;

    public const DEFAULT_GP_ALIAS_SCORE = 0.97;

    public const NAME_CONTAINMENT_FLOOR = 0.90;

    public const GP_PRIORITY_THRESHOLD = 0.50;

    public const SUB_PRIORITY_THRESHOLD = 0.50;

    public const SUB_EXACT_OVERRIDE = 0.85;

    public const MIN_MATCH_SCORE = 0.50;

    public const SCORE_EPS = 0.001;

    public const CUT_FORM_PENALTY = -2;

    /** 4.4b — Verb-/Substantiv-Marker → sub_rezept_typ-Slug (Reihenfolge = erste Regel gewinnt). */
    private const VERB_TO_SUB_TYP = [
        ['karamellisier', 'karamell'], ['marinier', 'marinade'], ['gebeizt', 'beize'],
        ['reduzier', 'reduktion'], ['glasier', 'glasur'], ['purier', 'pueree'],
        ['passier', 'coulis'], ['kandier', 'karamell'], ['eingekocht', 'kompott'],
        ['karamell', 'karamell'], ['reduktion', 'reduktion'], ['marinade', 'marinade'],
        ['pesto', 'paste'], ['tapenade', 'paste'], ['paste', 'paste'],
        ['vinaigrette', 'vinaigrette'], ['chutney', 'chutney'], ['kompott', 'kompott'],
        ['kraeuteroel', 'kraeuter_oel'], ['kraeuter_oel', 'kraeuter_oel'], ['kraeuteroil', 'kraeuter_oel'],
        ['butter_aromat', 'butter_aromat'], ['aromabutter', 'butter_aromat'],
        ['crumble', 'crumble'], ['streusel', 'streusel'],
        ['hollandaise', 'emulsion'], ['mayonnaise', 'emulsion'], ['aioli', 'emulsion'], ['emulsion', 'emulsion'],
        ['coulis', 'coulis'], ['puree', 'pueree'], ['pueree', 'pueree'],
        ['praline', 'praline'], ['praliné', 'praline'], ['sirup', 'sirup'],
        ['fond', 'fond'], ['bruehe', 'bruehe'], ['jus', 'jus'],
    ];

    /** 4.4k — konservative Halbfabrikat-Marker (steuern die Pool-Priorität). */
    public const HALBFABRIKAT_MARKER = [
        'fond', 'bruehe', 'reduktion', 'demi', 'coulis',
        'pueree', 'glace', 'fumet', 'veloute', 'espuma', 'fumét',
        'bechamel', 'béchamel', 'mornay', 'hollandaise', 'bearnaise', 'béarnaise',
        'duxelles', 'farce', 'salpicon', // D1 2026-08-18: benannte Sub-Zubereitungen (Pilz-Duxelles/Farce/Salpicon)
    ];

    /**
     * 4.4k (Erweiterung 2026-08-14, Roadmap Etappe 1 »Gericht = Basisrezepte«):
     * gemachte Saucen/Reduktionen als Halbfabrikat-Marker — damit »Steinpilz-Rahmsauce«
     * als eigenes Sub-Basisrezept gilt statt flach in Steinpilze + Sahne aufgelöst zu werden (§4).
     * Token-EXAKT via patternMatchesToken (≤ 5 Chars exakt, länger als Präfix), NICHT Substring:
     * so trifft »sauce« nur als eigenständiges Token (Tokenizer splittet »-«/Space), während
     * gekaufte Ein-Wort-Kondimente (Sojasauce/Fischsauce) NICHT anschlagen
     * (Golden-Test: Sojasauce = kein Halbfabrikat). Bleibt Keyword-Hack bis zum LLM-Komponenten-Flag.
     * »essenz« bewusst NICHT aufgenommen: mehrdeutig zwischen gemachter Klar-Essenz (Sub) und
     * gekaufter Frucht-Essenz/Extrakt (GP) — die DoD M4-14 pinnt »Drachenfrucht-Essenz« als GP-Lücke.
     * Disambiguierung gehört ans LLM-Komponenten-Flag, nicht an ein blindes Keyword.
     */
    public const SUB_SAUCEN_MARKER = [
        'sauce', 'rahmsauce', 'dressing', 'vinaigrette',
    ];

    /**
     * Kompositum-fähige Halbfabrikat-Grundwörter (2026-08-17, Bug-Fix Real-Abnahme »Schweinejus«):
     * `jus`/`sud` (3 Chars) als **SUFFIX** matchen, nicht exakt-Token — im Deutschen stehen sie als
     * Grundwort im Kompositum (Schweinejus/Rotweinjus/Kalbsjus/Gemüsesud/Fischsud). Der frühere
     * Exakt-Token-Match (aus Furcht vor »Sojasauce«) verfehlte genau diese Einwortformen → ein
     * hausgemachter Jus blieb flache Zutat statt Basisrezept (§4). Suffix ist hier gefahrlos: kein
     * gekauftes Ein-Wort-Kondiment endet auf »jus«/»sud« (anders als »sauce« ⊂ »sojasauce«); »fond«/
     * »reduktion« (≥4) sind über den Substring-Zweig ohnehin abgedeckt. Standalone »jus«/»sud« endet
     * auf sich selbst → weiterhin getroffen.
     */
    public const SUB_KOMPOSITUM_SUFFIX = [
        'jus', 'sud',
    ];

    /** P8 — breitere Zubereitungs-Marker, NUR Button-Heuristik. */
    public const ZUBEREITUNG_MARKER = [
        'creme', 'crème', 'mousse', 'ganache', 'crumble', 'streusel', 'krokant',
        'sorbet', 'parfait', 'sabayon', 'praline', 'gelee', 'chutney', 'kompott',
        'marinade', 'pesto', 'tatar', 'schaum',
        'sautiert', 'gebraten', 'gebacken', 'geschmort', 'gegrillt', 'frittiert',
        'pochiert', 'glasiert', 'mariniert', 'paniert', 'gratiniert', 'blanchiert',
        'gedaempft', 'geduenstet', 'geraeuchert', 'karamellisiert', 'flambiert', 'confiert',
    ];

    /**
     * D5 2026-08-18 — »<Typ>: <Name>«-Präfix des Generators (Gel:/Creme:/Jus:/Espuma: …) als
     * starkes Sub-Zubereitungs-Signal. Deckt die Kurzform »Gel:« (3 Zeichen, verfehlt den
     * Substring-Marker) und macht die Label-Konvention verbindlich — Rolle/GP-Treffer egal.
     * NUR echte Zubereitungs-Typen, KEINE Rollen-/Warenlabels (Beilage/Fleisch/Kresse/Gemüse):
     * die tragen nach dem Doppelpunkt eine Rohware und dürfen GP bleiben. Tokens normalisiert
     * (Püree→pueree, Öl→oel, Crème→creme) wie TokenEngine::tokenize.
     */
    public const SUB_ZUBEREITUNG_PRAEFIX = [
        'gel', 'gelee', 'creme', 'jus', 'fond', 'sud', 'sauce', 'espuma', 'schaum',
        'mousse', 'pueree', 'coulis', 'reduktion', 'oel', 'emulsion', 'ganache',
        'sorbet', 'parfait', 'sabayon', 'praline', 'duxelles', 'farce', 'glace',
        'veloute', 'sirup', 'vinaigrette', 'dressing', 'chutney', 'kompott', 'pesto', 'marinade',
    ];

    /**
     * D3 2026-08-18 — §11.2 Nebenprodukt-Derivat-Marker (NUR eindeutige, User-Entscheid). Saft/Fett/Grün
     * bewusst RAUS (kontext-ambig: Saft-Derivat nur Zitrus, »entsafteter Saft« = Rezept). Kerne sind
     * KEINE Derivate (§11.2-Anti-Pattern: Pinien/Walnuss/Kürbiskerne = eigene GPs) → nicht in der Liste.
     * Tokens normalisiert (Parüren→parueren) wie TokenEngine::tokenize.
     */
    public const NEBENPRODUKT_MARKER = [
        'abschnitte', 'abschnitt', 'knochen', 'karkasse', 'karkassen',
        'paruere', 'parueren', 'schale', 'schalen', 'stiel', 'stiele', 'zeste', 'zesten',
    ];

    /** D-6: Einzel-/Deko-/Convenience-Artikel bleiben GP/LA-first, auch im Gericht. */
    public const DIREKT_ARTIKEL_MARKER = [
        'fleur', 'sel', 'salz', 'meersalz', 'gewuerz', 'gewuerze', 'kresse',
        'microgreen', 'microgreens', 'bluete', 'blueten', 'deko', 'garnitur',
        'crisp', 'crisps', 'chip', 'chips', 'fertig', 'belegt', 'broetchen',
        'sandwich', 'canape', 'wrap',
    ];

    public function __construct(private TokenEngine $engine)
    {
    }

    /** Kurze Patterns (≤ 5 Chars) token-EXAKT, längere via Prefix (paste ≠ pasteurisiert). */
    public static function patternMatchesToken(string $token, string $pattern): bool
    {
        return mb_strlen($pattern) <= 5 ? $token === $pattern : str_starts_with($token, $pattern);
    }

    /** 4.4b — erster Treffer gewinnt; Tokens müssen normalisiert sein. */
    public function detectSubTypHint(array $queryTokens): ?string
    {
        foreach (self::VERB_TO_SUB_TYP as [$pattern, $slug]) {
            foreach ($queryTokens as $token) {
                if (self::patternMatchesToken($token, $pattern)) {
                    return $slug;
                }
            }
        }

        return null;
    }

    /** 4.4k — Substring-Match ≥ 4 Chars (kalbsbruehe ⊃ bruehe, rotweinreduktion ⊃ reduktion). */
    public function queryIstHalbfabrikat(array $queryTokens): bool
    {
        foreach ($queryTokens as $t) {
            foreach (self::HALBFABRIKAT_MARKER as $m) {
                if (mb_strlen($m) >= 4 && str_contains($t, $m)) {
                    return true;
                }
            }
            foreach (self::SUB_SAUCEN_MARKER as $m) {
                if (self::patternMatchesToken($t, $m)) {
                    return true;
                }
            }
            // Kompositum-Grundwort per Suffix (Schweinejus/Gemüsesud) — deckt die deutschen Einwortformen,
            // die der Exakt-Token-Match verfehlt (standalone »jus«/»sud« endet auf sich selbst).
            foreach (self::SUB_KOMPOSITUM_SUFFIX as $s) {
                if (str_ends_with($t, $s)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** D5 2026-08-18 — »<Typ>:«-Präfix (Gel:/Creme:/Jus: …) = starke Sub-Zubereitung; prüft NUR das Label vor dem ersten Doppelpunkt. */
    public function hatSubZubereitungsPraefix(string $name): bool
    {
        $pos = mb_strpos($name, ':');
        if ($pos === false || $pos <= 0) {
            return false;
        }
        foreach ($this->engine->tokenize(mb_substr($name, 0, $pos)) as $t) {
            if (in_array($t, self::SUB_ZUBEREITUNG_PRAEFIX, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * D3 2026-08-18 — §11.2: erkennt ein Nebenprodukt-Derivat und trennt Mutter-Text von Derivat-Form.
     * Compound »Rinderabschnitte« → [mutter_text: »rinder«, form: »Abschnitte«]; Mehr-Token
     * »Kalb Parüren« → [»kalb«, »Parüren«]. null, wenn kein Marker greift oder kein Mutter-Rest bleibt
     * (bloßes »Knochen« ohne Mutter → kein Split, fällt auf Sourcing-Lücke zurück).
     *
     * @return array{mutter_text: string, form: string}|null
     */
    public function nebenproduktDerivat(string $name): ?array
    {
        $tokens = $this->engine->tokenize($name);
        if ($tokens === []) {
            return null;
        }
        // Mehr-Token: ein ganzes Token IST der Marker → Rest = Mutter (»Kalb Parüren«).
        if (count($tokens) > 1) {
            foreach ($tokens as $i => $t) {
                if (in_array($t, self::NEBENPRODUKT_MARKER, true)) {
                    $mutter = array_values(array_diff_key($tokens, [$i => true]));
                    if ($mutter !== []) {
                        return ['mutter_text' => implode(' ', $mutter), 'form' => mb_convert_case($t, MB_CASE_TITLE, 'UTF-8')];
                    }
                }
            }
        }
        // Compound-Suffix in einem Token (»rinderabschnitte« → Präfix »rinder«).
        foreach ($tokens as $t) {
            foreach (self::NEBENPRODUKT_MARKER as $m) {
                $pos = mb_strpos($t, $m);
                if ($pos !== false && mb_strlen(mb_substr($t, 0, $pos)) >= 3) {
                    return ['mutter_text' => mb_substr($t, 0, $pos), 'form' => mb_convert_case($m, MB_CASE_TITLE, 'UTF-8')];
                }
            }
        }

        return null;
    }

    /** P8 — Button-Heuristik: Label-Hinweis ODER Halbfabrikat ODER Zubereitungs-Marker. */
    public function istSubRezeptKandidat(string $name): bool
    {
        if ($this->istDirektArtikelKandidat($name)) {
            return false;
        }
        $lower = mb_strtolower($name);
        if (str_contains($lower, 'basisrezept') || str_contains($lower, 'sub-rezept') || str_contains($lower, 'sub rezept')) {
            return true;
        }
        if ($this->hatSubZubereitungsPraefix($name)) {
            return true;
        }
        $tokens = $this->engine->tokenize($name);
        if ($this->queryIstHalbfabrikat($tokens)) {
            return true;
        }
        foreach ($tokens as $t) {
            foreach (self::ZUBEREITUNG_MARKER as $m) {
                if (mb_strlen($m) >= 4 && str_contains($t, $m)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Einzelartikel, die im VK-Gericht ueber GP/LA laufen sollen, nicht als Basisrezept. */
    public function istDirektArtikelKandidat(string $name): bool
    {
        $tokens = $this->engine->tokenize($name);
        if ($tokens === [] || $this->queryIstHalbfabrikat($tokens) || $this->hatSubZubereitungsPraefix($name)) {
            return false;
        }

        foreach ($tokens as $t) {
            foreach (self::DIREKT_ARTIKEL_MARKER as $m) {
                if (self::patternMatchesToken($t, $m)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Zustand aus Namens-Tokens (Fallback-Pfad von 4.4u). */
    public function zustandClass(string $gpName): string
    {
        $tokens = $this->engine->tokenize($gpName);
        foreach ($tokens as $t) {
            if ($t === 'tk' || str_contains($t, 'tiefgek') || str_contains($t, 'gefroren')) {
                return 'frozen';
            }
        }
        foreach ($tokens as $t) {
            if (str_contains($t, 'frisch') || $t === 'roh') {
                return 'fresh';
            }
        }
        foreach ($tokens as $t) {
            if (str_contains($t, 'konserv') || str_contains($t, 'getrocknet')
                || str_contains($t, 'eingelegt') || str_contains($t, 'haltbar')) {
                return 'preserved';
            }
            foreach (TokenEngine::PROCESSED_MARKERS as $m) {
                if (str_contains($t, $m)) {
                    return 'preserved';
                }
            }
        }

        return 'unknown';
    }

    /** 4.4u — Feld-primär (condition-Spalte), Namens-Tokens nur als Fallback. */
    public function zustandClassResolved(string $gpName, ?string $zustandCol): string
    {
        $z = $zustandCol !== null ? trim($zustandCol) : '';
        if ($z !== '') {
            $mapped = match ($z) {
                'frisch' => 'fresh',
                'TK' => 'frozen',
                'trocken', 'konserviert' => 'preserved',
                default => null,
            };
            if ($mapped !== null) {
                return $mapped;
            }
        }

        return $this->zustandClass($gpName);
    }

    /** Exakt bio/oeko + Präfix biolog…/oekolog… — kein Substring. */
    public function isBioTokens(array $tokens): bool
    {
        foreach ($tokens as $t) {
            if ($t === 'bio' || $t === 'oeko' || str_starts_with($t, 'biolog') || str_starts_with($t, 'oekolog')) {
                return true;
            }
        }

        return false;
    }

    /** 4.4u — Feld-primär (bio-Spalte: bio|konventionell), Token-Fallback. */
    public function isBioResolved(array $tokens, ?string $bioCol): bool
    {
        return match ($bioCol !== null ? trim($bioCol) : null) {
            'bio' => true,
            'konventionell' => false,
            default => $this->isBioTokens($tokens),
        };
    }

    public function hasCutForm(array $tokens): bool
    {
        foreach ($tokens as $t) {
            foreach (TokenEngine::CUT_FORM_MARKERS as $m) {
                if (str_contains($t, $m)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * 4.4l/m/n/r/u — Tiebreaker-Rang bei Score-Gleichstand. $pref ∈
     * fresh_first|frozen_first|preserved_first|neutral; $bio ∈ bio|conventional|neutral.
     */
    public function variantRankResolved(
        string $gpName,
        string $pref = 'neutral',
        bool $preferRaw = false,
        string $bio = 'neutral',
        ?string $zustandCol = null,
        ?string $bioCol = null,
    ): int {
        $tokens = $this->engine->tokenize($gpName);

        $zustandForm = 0;
        if ($pref !== 'neutral') {
            $class = $this->zustandClassResolved($gpName, $zustandCol);
            $isProcessed = false;
            foreach ($tokens as $t) {
                foreach (TokenEngine::PROCESSED_MARKERS as $m) {
                    if (str_contains($t, $m)) {
                        $isProcessed = true;

                        break 2;
                    }
                }
            }
            $condition = match ([$pref, $class]) {
                ['fresh_first', 'fresh'] => 3,
                ['fresh_first', 'frozen'] => -1,
                ['fresh_first', 'preserved'] => -2,
                ['frozen_first', 'frozen'] => 3,
                ['frozen_first', 'fresh'] => 1,        // frisch als Roh-Fallback
                ['frozen_first', 'preserved'] => -2,
                ['preserved_first', 'preserved'] => 3,
                ['preserved_first', 'frozen'] => 1,    // TK als Convenience-Fallback
                ['preserved_first', 'fresh'] => -2,
                default => 0,                          // Unknown-Zustand
            };
            $form = match ($pref) {
                'fresh_first', 'frozen_first' => $isProcessed ? -2 : 0,
                'preserved_first' => $isProcessed ? 1 : 0,
                default => 0,
            };
            $zustandForm = $condition + $form;
        }

        $cut = ($preferRaw && $this->hasCutForm($tokens)) ? self::CUT_FORM_PENALTY : 0;

        $isBio = $this->isBioResolved($tokens, $bioCol);
        $bioAdj = match ($bio) {
            'bio' => $isBio ? 2 : 0,
            'conventional' => $isBio ? -2 : 0,
            default => 0,
        };

        // D4 2026-08-18: Basis-Form-Tiebreak — greift NUR bei komplett neutralen Parametern
        // (pref + bio neutral, kein preferRaw), also genau dort, wo sonst ALLE Terme 0 sind und die
        // id-Reihenfolge entschied (Kaskaden-Default; Live-Bug: »Karotte mini gemischt«, »Zwiebel
        // geachtelt« gewannen gegen die Basisform). So kann er KEIN pref/bio/cut-Signal überstimmen
        // (Sign-Semantik dieser Signale bleibt) — eine über-spezifische Größen-/Schnitt-Variante
        // verliert nur im echten Gleichstand gegen die neutralere Basisform. Exakt-Token.
        $baseForm = 0;
        if ($pref === 'neutral' && $bio === 'neutral' && ! $preferRaw) {
            foreach ($tokens as $t) {
                if (in_array($t, ['mini', 'baby', 'gemischt', 'geachtelt', 'achtel'], true)) {
                    $baseForm = -1;

                    break;
                }
            }
        }

        return $zustandForm + $cut + $bioAdj + $baseForm;
    }

    /** 4.4n — Regelwerk §4 Default-Sub-Aliasse (deterministisch, hell/braun-bewusst). */
    public function defaultSubAlias(array $tokens): ?string
    {
        $has = fn (string $t) => in_array($t, $tokens, true);
        $hasSub = function (string $s) use ($tokens) {
            foreach ($tokens as $x) {
                if (str_contains($x, $s)) {
                    return true;
                }
            }

            return false;
        };
        $hasPre = function (string $p) use ($tokens) {
            foreach ($tokens as $x) {
                if (str_starts_with($x, $p)) {
                    return true;
                }
            }

            return false;
        };

        $dark = $hasPre('dunk') || $hasPre('braun');
        $poultry = $has('gefluegel') || $has('huhn') || $has('haehnchen') || $has('haehnchenbrust');

        // Jus (spezifisch) — VOR den Brühen
        if ($hasSub('lammjus') || ($has('lamm') && $hasSub('jus'))) {
            return 'BRAUNER LAMMFOND';
        }
        if ($hasSub('kalbsjus') || $hasSub('backenschmorjus') || ($has('kalb') && $hasSub('jus'))) {
            return 'BRAUNER KALBSFOND';
        }
        if ($hasSub('gefluegeljus') || ($poultry && $hasSub('jus'))) {
            return 'DUNKLER GEFLÜGELFOND';
        }

        // Brühen / Fonds
        if ($hasSub('rinderbrueh') || $hasSub('fleischbrueh') || $hasSub('rinderfond')
            || ($has('rind') && ($hasSub('brueh') || $hasSub('fond')))) {
            return $dark ? 'BRAUNER KALBSFOND' : 'HELLER KALBSFOND';
        }
        if ($hasSub('gefluegelbrueh') || $hasSub('huehnerbrueh') || ($poultry && $hasSub('brueh'))) {
            return $dark ? 'DUNKLER GEFLÜGELFOND' : 'HELLER GEFLÜGELFOND';
        }
        if ($hasSub('gemuesebrueh') || ($has('gemuese') && $hasSub('brueh'))) {
            return 'GEMÜSEBRÜHE';
        }
        if ($hasSub('brueh')) {
            return $dark ? 'DUNKLER GEFLÜGELFOND' : 'HELLER GEFLÜGELFOND';
        }

        // Mayonnaise / Dressing
        if ($has('mayonnaise') && count($tokens) === 1) {
            return 'STANDARD MAYONNAISE';
        }
        if ($hasSub('balsamico') && $hasSub('dressing')) {
            return 'VR HAUSDRESSING';
        }

        return null;
    }

    /** 4.4s — Regelwerk §5 Default-GPs (1-Token-Guard; prefer_raw schaltet Ei-Produkte). */
    public function defaultGpAlias(array $tokens, bool $preferRaw): ?string
    {
        $has = fn (string $t) => in_array($t, $tokens, true);
        $hasPre = function (string $p) use ($tokens) {
            foreach ($tokens as $x) {
                if (str_starts_with($x, $p)) {
                    return true;
                }
            }

            return false;
        };
        $n = count($tokens);

        if ($has('salz') && $n === 1) {
            return 'Salz / Kochsalz: trocken, unjodiert, Raffinade';
        }
        // D4 2026-08-18: bare »Wasser«/»Leitungswasser« → Leitungswasser-Basis-GP (normales GP, requires_la=0,
        // gratis) statt der nächsten Flaschen-/Bio-Variante (Live-Bug: »Wasser« matchte »Wasser: still, 0,5 l,
        // Bio«). NUR n===1 → »Mineralwasser«/»Wasser still«/»Sprudel« bleiben bewusst die gekaufte Ware.
        // Inert, solange das »Wasser: Leitung«-GP nicht existiert (resolveGpByName → null → Pool-Scan wie bisher).
        if (($has('wasser') || $has('leitungswasser')) && $n === 1) {
            return 'Wasser: Leitung';
        }
        if ($n === 1 && ($has('zucker') || $has('feinzucker') || $has('kristallzucker')
            || $has('streuzucker') || $has('raffinadezucker') || $has('haushaltszucker') || $has('weisszucker'))) {
            return 'Zucker Raffinade: trocken, weiss';
        }
        if ($has('eigelb') && $n === 1) {
            return $preferRaw ? 'Eier: frisch, Groesse L, Bodenhaltung' : 'Eigelb: fluessig, pasteurisiert';
        }
        if ($has('eiweiss') && $n === 1) {
            return $preferRaw ? 'Eier: frisch, Groesse L, Bodenhaltung' : 'Huehnereiweiss: fluessig, pasteurisiert';
        }
        if (($has('ei') || $has('eier')) && $n === 1) {
            return 'Eier: frisch, Groesse L, Bodenhaltung';
        }
        if (($has('sahne') || $has('schlagsahne')) && $n === 1) {
            return 'Sahne: konserviert, 30 % Fett';
        }
        if ($has('milch') && $n === 1) {
            return 'Milch: frisch, 3,5 % Fett';
        }
        if ($has('mehl') && $n === 1) {
            return 'Weizenmehl: trocken, Type 405';
        }
        if ($has('gelatine') && $n === 1) {
            return 'Gelatine: trocken, kaltloeslich';
        }
        if ($has('weisswein') && $n === 1) {
            return 'Weisswein: konserviert, zum Kochen';
        }
        if ($has('olivenoel') && $n === 1) {
            return 'Olivenoel: trocken, hochwertig';
        }
        if ($has('honig') && $n === 1) {
            return 'Honig: konserviert, Imker';
        }
        if ($has('sojasauce') && $n === 1) {
            return 'Sojasauce: konserviert, glutenfrei';
        }
        if ($has('petersilie') && $n === 1) {
            return 'Petersilie glatt: frisch, gehackt';
        }
        if ($has('pfeffer')) {
            if ($hasPre('weiss')) {
                return 'Pfeffer weiss: trocken, gemahlen';
            }
            $nurGenerisch = true;
            foreach ($tokens as $t) {
                if (! in_array($t, ['pfeffer', 'schwarz', 'schwarzer', 'ganz', 'gemahlen'], true)) {
                    $nurGenerisch = false;

                    break;
                }
            }
            if ($nurGenerisch) {
                return 'Pfeffer schwarz: trocken, gemahlen';
            }
        }

        return null;
    }

    /** 4.4p — Recall-Substring-Overlap für die LLM-Shortlist (Komposita überleben). */
    public function substringOverlap(array $queryTokens, string $candName): float
    {
        $toks = array_values(array_filter($queryTokens, fn ($t) => mb_strlen($t) >= 3));
        if ($toks === []) {
            return 0.0;
        }
        $candNorm = implode(' ', $this->engine->tokenize($candName));
        $hits = 0;
        foreach ($toks as $t) {
            if (str_contains($candNorm, $t)) {
                $hits++;
            }
        }

        return $hits / count($toks);
    }
}
