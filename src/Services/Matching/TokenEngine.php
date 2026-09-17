<?php

namespace Platform\FoodAlchemist\Services\Matching;

/**
 * M3-08: GL-04-Kern als 1:1-Port (tokenize → stem → token_matches → match_score).
 * Quelle: recipe_matching.rs:181–441 + stemming.rs:46–81 (Pseudocode GL-04 §3.1–3.3).
 * Die 96 Golden-Tests (M4-09) laufen GEGEN DIESE Klasse — bei Abweichung gewinnt
 * der Golden-Test (GL-04-Hierarchie: Golden > Tabelle > Pseudocode).
 *
 * Bewusst NICHT drin (A-5): Akzent-Folding (é bleibt erhalten) — erst nach
 * bestandener Paritäts-Suite als eigener Feature-Schritt (GL-04 §6.3 W-1).
 */
class TokenEngine
{
    /** stemming.rs:34 — Umlaut-Plural-Lookup (ganzes Wort ODER Kompositum-Suffix). */
    private const UMLAUT_PLURAL = [
        'nuesse' => 'nuss', 'wuerste' => 'wurst', 'saefte' => 'saft', 'aepfel' => 'apfel',
        'koepfe' => 'kopf', 'boeden' => 'boden', 'kloesse' => 'kloss',
    ];

    /** Reihenfolge fix; `innen` MUSS vor `en` stehen (GL-04 §3.1). */
    private const SUFFIXE = ['innen', 'nnen', 'en', 'er', 'e', 'n', 's'];

    public const PROCESSED_MARKERS = ['konzentrat', 'pulver', 'instant', 'portionsstick', 'fertig', 'vorgegart', 'vorgekocht', 'granulat'];

    public const CUT_FORM_MARKERS = ['brunoise', 'wuerfel', 'gehackt', 'geschnitten', 'gerieben', 'gestiftelt', 'stifte', 'scheiben', 'streifen', 'julienne'];

    private const QUALIFIER_PREFIXE = [
        'frisch', 'roh', 'tiefgek', 'gefror', 'konserv', 'getrock', 'trocken', 'eingelegt', 'haltbar',
        'stueckig', 'mini', 'baby', 'gross', 'klein', 'fein', 'grob', 'ganz', 'bio', 'gemischt', 'geschael',
        'gegart', 'gekocht', 'verzehrfertig',
    ];

    /** rs:181–200 — Tokens als MENGE (dedupe), nur Länge ≥ 2; Akzente bleiben (A-5!). */
    public function tokenize(string $s): array
    {
        $s = mb_strtolower($s);
        $s = strtr($s, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
        $s = strtr($s, ['-' => ' ', '_' => ' ', ',' => ' ', '.' => ' ', '(' => ' ', ')' => ' ', ':' => ' ', ';' => ' ', '/' => ' ', '\\' => ' ']);
        $s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s);

        $tokens = [];
        foreach (preg_split('/\s+/u', trim($s), -1, PREG_SPLIT_NO_EMPTY) as $t) {
            if (mb_strlen($t) >= 2) {
                $tokens[$t] = true;
            }
        }

        // strval: numerische Tokens ('30') würden als int-Array-Keys zurückkommen
        return array_map('strval', array_keys($tokens));
    }

    /** Phrasen der Dosen-Einkaufsform auf das §9-Zustandswort normalisieren. */
    private function normalisiereEinkaufsform(string $name): string
    {
        $name = preg_replace('/\baus\s+(?:der|einer)\s+dose\b|\bin\s+dosen\b/iu', 'konserviert', $name);

        return preg_replace('/\bdosentomaten?\b/iu', 'Tomaten konserviert', $name);
    }

    /** Einkaufsform normalisieren, ohne „konserviert“ als bedeutungslos zu entfernen. */
    public function ingredientTokens(string $name): array
    {
        return $this->tokenize($this->normalisiereEinkaufsform($name));
    }

    public function wantsCanned(string $name): bool
    {
        return preg_match('/\baus\s+(?:der|einer)\s+dose\b|\bin\s+dosen\b|\bdosentomaten?\b/iu', $name) === 1;
    }

    /**
     * §9-Zustandswörter aus freiem Text — Wort-Boundary wie GpZustandBackfillCommand::MUSTER
     * (dieselbe Quelle: Regelwerk_Grundprodukte.md §9 „frisch, tiefgekuehlt (TK), trocken,
     * konserviert"). Mehrdeutigkeit (mehr als ein Zustand im selben Text) bleibt KONSERVATIV
     * unentschieden statt geraten — dieselbe Politik wie der Backfill.
     */
    private const ZUSTAND_MUSTER = [
        'frisch' => '/\bfrisch\w*\b/iu',
        'TK' => '/\btk\b|\btiefgek(?:ue|ü)hlt\w*\b|\bgefroren\w*\b/iu',
        'trocken' => '/\btrocken\w*\b|\bgetrocknet\w*\b/iu',
        'konserviert' => '/\bkonserviert\w*\b/iu',
    ];

    /**
     * Review-Fund (Paul/cooking-jarvis-03, vor Commit): „frisch" ist meist ein
     * ZUBEREITUNGS-Adverb, kein §9-Zustand — „Pfeffer, schwarz, frisch gemahlen" beschreibt
     * WIE gemahlen wurde, nicht dass der Pfeffer als Frischware eingekauft wird (Pfeffer-GPs
     * sind trocken). Ungefiltert hätte acceptsProductForm() jeden Pfeffer-Kandidaten
     * abgelehnt (condition≠frisch) → target=none statt eines korrekten Treffers — eine
     * Verschlechterung gegenüber dem Ist-Stand vor #505-Nachtrag.
     */
    private const FRISCH_ZUBEREITUNG_MUSTER = '/\bfrisch\w*\s+(?:gemahlen|gerieben|gepresst|gehackt|geschnitten|gezupft|geraspelt|gestossen|gestoßen|zubereitet|gekocht)\w*\b/iu';

    /**
     * Rein deskriptive Geometrie/Zuschnitt-Wörter aus dem Brief — KEIN §9-Zustand, aber ein
     * Signal für den Form-Bonus im Ranking (mehrere GPs mit identischem Zustand/Score).
     * Erste passende Form gewinnt; anders als beim Zustand ist Mehrdeutigkeit hier unschädlich
     * (der Bonus ist ein Tiebreaker, kein Filter).
     */
    private const FORM_MUSTER = [
        'stueckig' => '/\bst(?:ue|ü)ckig\w*\b/iu',
        'passiert' => '/\bpassiert\w*\b/iu',
        'gewuerfelt' => '/\bgew(?:ue|ü)rfelt\w*\b/iu',
        'ganz' => '/\bganz\w*\b/iu',
    ];

    /**
     * Identität / Zustand / Form aus einer Zutatenbeschreibung trennen (#505-Nachtrag
     * 2026-09, Anlass „Creme-Suppe: Tomate-Speck" — GP 13757 „TK, getrocknet" wurde
     * gegen „konserviert" verrechnet, weil beide Zustände bis dahin in EINE Klasse fielen).
     * `identitaet` = Tokens ohne Zustands-/Form-/sonstige Qualifier-Wörter (isQualifierToken).
     *
     * @return array{identitaet: list<string>, zustand: ?string, form: ?string}
     */
    public function produktForm(string $name): array
    {
        $normalisiert = $this->normalisiereEinkaufsform($name);

        $zustandTreffer = [];
        foreach (self::ZUSTAND_MUSTER as $zustand => $muster) {
            if ($zustand === 'frisch' && preg_match(self::FRISCH_ZUBEREITUNG_MUSTER, $normalisiert) === 1) {
                continue;   // „frisch gemahlen/gerieben/…" ist Zubereitung, kein §9-Zustand
            }
            if (preg_match($muster, $normalisiert) === 1) {
                $zustandTreffer[] = $zustand;
            }
        }
        // Mehrdeutig (z. B. „TK, getrocknet") ⇒ kein Zustand gesetzt — Raten wäre hier
        // schlimmer als ungeprüft lassen (identische Politik zu GpZustandBackfillCommand).
        $zustand = count($zustandTreffer) === 1 ? $zustandTreffer[0] : null;

        $form = null;
        foreach (self::FORM_MUSTER as $kandidat => $muster) {
            if (preg_match($muster, $normalisiert) === 1) {
                $form = $kandidat;
                break;
            }
        }

        $identitaet = array_values(array_filter(
            $this->tokenize($normalisiert),
            fn ($t) => ! $this->isQualifierToken($t) && ! in_array($t, ['dose', 'dosen'], true),
        ));

        return ['identitaet' => $identitaet, 'zustand' => $zustand, 'form' => $form];
    }

    /**
     * Briefing-Sprache ist kein Produkt — Füllwörter/Artikel/Konnektoren aus freiem
     * Beschreibungstext vor dem Grounding-Retrieval raus, sonst verdrängen sie spät
     * genannte Zutaten. NICHT Teil des GL-04-Ports (rein Briefing-Vorfilter). Geteilt
     * zwischen GenerationContextService::leitTokens() und
     * RecipeGeneratorService::bestandsInventar() — beide sondieren denselben Wortschatz.
     */
    private const BRIEF_STOPWORDS = [
        'bitte', 'kannst', 'koenntest', 'erstelle', 'erstellen', 'mache', 'machen',
        'haette', 'moechte', 'brauche', 'rezept', 'basisrezept', 'gericht', 'suppe',
        'einen', 'eine', 'einer', 'einem', 'eines', 'soll', 'sollen', 'sein', 'werden',
        'wird', 'sind', 'dabei', 'dazu', 'darin', 'drin', 'auch', 'noch', 'etwas',
        'bisschen', 'klassisch', 'klassischen', 'klassische', 'ansatz', 'fuer',
        'ohne', 'oder', 'aber', 'dann', 'diese', 'dieser', 'dieses', 'eher',
        'gemischt', 'gemixt', 'eingemixt', 'pueriert', 'fertig',
        'mit', 'und', 'der', 'die', 'das', 'den', 'dem', 'aus', 'zum', 'zur', 'ich', 'ein', 'mal',
    ];

    /**
     * Leit-Tokens eines freien Beschreibungstexts: tokenisiert, Briefing-Füllwörter,
     * Kurzwörter (< 3 Zeichen) und Zahlen raus, auf `$max` gekappt (Sondierungs-Budget,
     * NICHT das Prompt-Budget — der Aufrufer kappt Kandidaten danach nach Trefferqualität).
     *
     * @return list<string>
     */
    public function leitTokens(string $description, int $max = 16): array
    {
        $tokens = array_values(array_filter(
            $this->tokenize($description),
            fn ($t) => mb_strlen($t) >= 3 && ! in_array($t, self::BRIEF_STOPWORDS, true) && ! ctype_digit($t),
        ));

        return array_slice($tokens, 0, $max);
    }

    /** rs:203–217 — '-'→'_', nur Alphanumerik + '_' behalten, Rest ERSATZLOS weg (auch Spaces). */
    public function normalizeSlug(string $s): string
    {
        $s = mb_strtolower($s);
        $s = strtr($s, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss', '-' => '_']);

        return (string) preg_replace('/[^\p{L}\p{N}_]+/u', '', $s);
    }

    /** stemming.rs:46–81 — Konvergenz, kein Lemma. */
    public function stemGerman(string $t): string
    {
        if (mb_strlen($t) <= 4) {
            return $t;                                          // Ei, oel, Reis, Salz unangetastet
        }
        foreach (self::UMLAUT_PLURAL as $plural => $singular) {
            if ($t === $plural) {
                return $singular;
            }
            if (str_ends_with($t, $plural)) {
                return mb_substr($t, 0, mb_strlen($t) - mb_strlen($plural)) . $singular; // walnuesse → walnuss
            }
        }
        if (str_ends_with($t, 'ss')) {
            return $t;                                          // Nuss/Fluss sind Stamm
        }
        foreach (self::SUFFIXE as $suffix) {
            if (str_ends_with($t, $suffix) && mb_strlen($t) - mb_strlen($suffix) >= 3) {
                return mb_substr($t, 0, mb_strlen($t) - mb_strlen($suffix)); // ERSTES passendes Suffix
            }
        }

        return $t;
    }

    /** rs:223–229 — Slug segmentweise stemmen. */
    public function stemSlug(string $slug): string
    {
        return implode('_', array_map(fn ($seg) => $this->stemGerman($seg), explode('_', $slug)));
    }

    /** rs:240–257 — exakt / Stem-gleich (≥4) / Prefix bei Längen-Diff ≤ 2. */
    public function tokenMatches(string $a, string $b): bool
    {
        if ($a === $b) {
            return true;
        }
        $stemA = $this->stemGerman($a);
        if (mb_strlen($stemA) >= 4 && $stemA === $this->stemGerman($b)) {
            return true;
        }
        $la = mb_strlen($a);
        $lb = mb_strlen($b);

        return $la >= 5 && $lb >= 5 && abs($la - $lb) <= 2
            && (str_starts_with($b, $a) || str_starts_with($a, $b)); // butter(6) ↛ butternut(9, Diff 3)
    }

    /** rs:265–277 */
    /**
     * Ist dieses Token eine SCHNITTFORM? Enger als `isQualifierToken()`, das auch
     * Zustand (frisch/TK), Größe (mini/gross) und Verarbeitungsgrad (Pulver/Konzentrat)
     * einschließt. Für §2 Verarbeitungs-Reduktion zählt nur der Zuschnitt: »Brunoise«
     * beschreibt, was mit der Karotte gemacht wurde — nicht, DASS es eine Karotte ist.
     */
    public function isCutFormToken(string $t): bool
    {
        foreach (self::CUT_FORM_MARKERS as $marker) {
            if (str_contains($t, $marker)) {
                return true;
            }
        }

        return false;
    }

    public function isQualifierToken(string $t): bool
    {
        if ($t === 'tk') {
            return true;
        }
        foreach (self::QUALIFIER_PREFIXE as $prefix) {
            if (str_starts_with($t, $prefix)) {
                return true;
            }
        }
        foreach ([...self::PROCESSED_MARKERS, ...self::CUT_FORM_MARKERS] as $marker) {
            if (str_contains($t, $marker)) {
                return true;
            }
        }

        return false;
    }

    /** rs:289–301 — generischer Slug-Exact darf den spezifischeren Query nicht kapern (4.4q). */
    public function nameOutspecifiesSlug(array $queryTokens, string $qn): bool
    {
        if (mb_strlen($qn) < 3) {
            return false;                                       // "ei"/"oel" → kein verlässliches Signal
        }
        $superstring = false;
        $slugIstToken = false;
        $extraContent = false;
        foreach ($queryTokens as $q) {
            if (mb_strlen($q) > mb_strlen($qn) && str_contains($q, $qn)) {
                $superstring = true;                            // "meersalz" ⊃ "salz"
            }
            if ($q === $qn) {
                $slugIstToken = true;
            }
            if ($q !== $qn && mb_strlen($q) >= 4 && ! $this->isQualifierToken($q)) {
                $extraContent = true;                           // "dijon"
            }
        }

        return $superstring || ($slugIstToken && $extraContent);
    }

    /**
     * rs:307–406 — Kern-Score: Slug-Exact 1.0 (mit Spezifitäts-Guard), F1 über
     * gestemmte Intersection, Slug-Prefix-Bonus +0.15, Slug-Mismatch-Cap 0.45.
     *
     * @param array<string> $queryTokens
     * @param array<string> $candidateTokens
     */
    public function matchScore(array $queryTokens, ?string $querySlug, array $candidateTokens, ?string $candidateSlug): float
    {
        $qn = $cn = null;
        if ($querySlug !== null && $querySlug !== '' && $candidateSlug !== null && $candidateSlug !== '') {
            $qn = $this->normalizeSlug($querySlug);
            $cn = $this->normalizeSlug($candidateSlug);
            $slugEq = $qn === $cn
                || (mb_strlen($this->stemSlug($qn)) >= 5 && $this->stemSlug($qn) === $this->stemSlug($cn));
            if ($slugEq && ! $this->nameOutspecifiesSlug($queryTokens, $qn)) {
                return 1.0;                                     // 1. SLUG-EXACT
            }
        }

        if ($queryTokens === [] || $candidateTokens === []) {
            return 0.0;
        }

        $i = 0;                                                 // 3. F1 über gestemmte Intersection
        foreach ($queryTokens as $q) {
            foreach ($candidateTokens as $c) {
                if ($this->tokenMatches($q, $c)) {
                    $i++;

                    continue 2;
                }
            }
        }
        if ($i === 0) {
            return 0.0;
        }
        $cq = $i / count($queryTokens);
        $cc = $i / count($candidateTokens);
        $score = 2 * $cq * $cc / ($cq + $cc);                   // bestraft Kandidaten mit Fremd-Tokens

        if ($qn !== null && $cn !== null) {
            // 4. SLUG-PREFIX-BONUS (+0.15)
            if (mb_strlen($qn) >= 4 && mb_strlen($cn) >= 4 && abs(mb_strlen($qn) - mb_strlen($cn)) <= 3
                && (str_starts_with($cn, $qn) || str_starts_with($qn, $cn))) {
                $score = min(1.0, $score + 0.15);
            }
            // 5. SLUG-MISMATCH-PENALTY — Cap 0.45 ⇒ faktisch no_match
            if (! $this->slugsVerwandt($qn, $cn)) {
                return min($score, 0.45);
            }
        }

        return $score;
    }

    /** rs:413–441 — Name-Containment-Floor (4.4o): Kopf == Query ⇒ Score-Floor 0.90. */
    public function headMatchesQuery(string $kandidatName, array $queryTokens): bool
    {
        $kopf = preg_split('/[:,(]/u', $kandidatName, 2)[0];
        $h = $this->tokenize($kopf);
        if ($h === [] || count($h) !== count($queryTokens)) {
            return false;                                       // MENGEN-GLEICHHEIT, keine Teilmenge
        }
        foreach ($h as $token) {
            $gefunden = false;
            foreach ($queryTokens as $q) {
                if ($this->tokenMatches($token, $q)) {
                    $gefunden = true;

                    break;
                }
            }
            if (! $gefunden) {
                return false;
            }
        }

        return true;
    }

    /** rotwein↔rapshonig (nur "r") gekappt; rindfleisch↔rindergulasch ("rind") bleibt. */
    private function slugsVerwandt(string $qn, string $cn): bool
    {
        if (str_starts_with($cn, $qn) || str_starts_with($qn, $cn)) {
            return true;
        }
        $n = min(mb_strlen($qn), mb_strlen($cn));
        $gemeinsam = 0;
        for ($i = 0; $i < $n; $i++) {
            if (mb_substr($qn, $i, 1) !== mb_substr($cn, $i, 1)) {
                break;
            }
            $gemeinsam++;
        }

        return $gemeinsam >= 4;
    }
}
