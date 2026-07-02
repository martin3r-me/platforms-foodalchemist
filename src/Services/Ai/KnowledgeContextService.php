<?php

namespace Platform\FoodAlchemist\Services\Ai;

use Illuminate\Support\Facades\DB;

/**
 * M5-06 / GL-13: Wissenskontext-Beschaffung für KI-Calls — 1:1-Port von
 * vault_context.rs, Quelle sind die foodalchemist_knowledge_*-Tabellen (D4)
 * statt Disk-Reads. Liefert FAKTEN-Wissen als Teil des User-Prompts; die
 * Hüllen (GL-06) liefern Verhalten als systemInstruction — additiv, nie
 * redundant.
 *
 * Routing pro Feature kommt aus foodalchemist_knowledge_routings (Tabelle 4.1
 * als Daten): cross_cutting/always = die 7 Always-Load-Einheiten, domain/
 * discovery = zweistufige Domain-Discovery (Alias-Mapping, Filename-Fallback),
 * pairing/discovery = kompakter FLAVOR-PAIRING-Block (nur Partner-NAMEN, kein
 * Prosa-Volltext), pairing/grounding = Doku-Auszüge je Hauptzutat-Slug.
 * Fehlende Quelle = leerer Kontext, nie Fehler (Invariante 6).
 */
class KnowledgeContextService
{
    /** Invariante 1: diese 7 gehen bei Generator-Calls IMMER mit (Reihenfolge = Ist). */
    public const ALWAYS_LOAD_CROSS_CUTTING = [
        'substitutionen', 'saisonkalender', 'synonyme', 'sauce_mutterstrukturen',
        'mengen_defaults', 'techniken', 'bruehen_fonds',
    ];

    public const CROSS_CUTTING_TRUNCATE_CHARS = 4000;

    public const DOMAIN_TRUNCATE_CHARS = 6000;

    public const DOMAIN_TOP_K = 4;

    public const PAIRING_TOP_K = 3;

    public const MAX_PARTNERS = 28;

    /**
     * Haupt-Einstieg (Pseudocode §3): baut den Wissens-Block für ein KI-Feature.
     *
     * @param  list<string>  $hauptzutatSlugs  nur für Grounding-Features (ai_suggest_pairings, ai_infer_ankers)
     * @return array{block: string, files_used: list<string>, total_chars: int}
     */
    public function contextFor(string $feature, string $beschreibung, ?string $stil = null, array $hauptzutatSlugs = []): array
    {
        $routing = DB::table('foodalchemist_knowledge_routings')
            ->where('feature', $feature)
            ->get()->keyBy(fn ($r) => $r->kategorie . ':' . $r->modus);

        $filesUsed = [];
        $parts = [];

        // ── 1. VAULT-WISSEN: Cross-Cutting (always) + Domains (discovery) ──
        $blocks = [];
        if ($routing->has('cross_cutting:always')) {
            foreach ($this->crossCuttingDocs() as $doc) {
                $blocks[] = "## CROSS_CUTTING: {$doc->slug}\n\n" . $this->truncate($doc->inhalt_md, self::CROSS_CUTTING_TRUNCATE_CHARS);
                $filesUsed[] = "{$doc->slug}@v{$doc->version}";
            }
        }
        if ($routing->has('domain:discovery')) {
            foreach ($this->discoverDomains($beschreibung) as $doc) {
                $blocks[] = "## DOMAIN: {$doc->slug}\n\n" . $this->truncate($doc->inhalt_md, self::DOMAIN_TRUNCATE_CHARS);
                $filesUsed[] = "{$doc->slug}@v{$doc->version}";
            }
        }
        if ($blocks !== []) {
            $parts[] = "# VAULT-WISSEN (Catering-Wissensbasis)\n\n"
                . "Folgende Domain- und Cross-Cutting-Files aus der Wissensbasis sind für diesen Generator-Call relevant.\n"
                . "Nutze sie als Souschef-Wissen: klassische Verhältnisse, Substitutionen, Synonyme, Sub-Rezept-Patterns.\n\n"
                . implode("\n\n---\n\n", $blocks);
        }

        // ── 2. FLAVOR-PAIRING-Block (Generator-Features; SQL-Anker-Graph bleibt primär, GL-10) ──
        if ($routing->has('pairing:discovery')) {
            $pairing = $this->pairingBlock($beschreibung, $stil, $filesUsed);
            if ($pairing !== null) {
                $parts[] = $pairing;
            }
        }

        // ── 3. Pairing-Doku-Grounding (Anker-/Pairing-Inferenz) ──
        if (($r = $routing->get('pairing:grounding')) !== null) {
            $parts[] = $this->groundingBlock($hauptzutatSlugs, (int) $r->max_docs, (int) $r->max_chars_per_doc, $filesUsed);
        }

        $block = implode("\n\n", $parts);

        return ['block' => $block, 'files_used' => $filesUsed, 'total_chars' => mb_strlen($block)];
    }

    /**
     * Tokenisiert für Alias- und Slug-Matching (vault_context.rs:343-362):
     * lowercase, Umlaut-Expansion (ä→ae ö→oe ü→ue ß→ss), nur Alphanumerik,
     * Token ≥3 Zeichen.
     *
     * @return list<string> dedupliziert
     */
    public function tokenize(string $s): array
    {
        $s = str_replace(['ä', 'ö', 'ü', 'ß'], ['ae', 'oe', 'ue', 'ss'], mb_strtolower($s));
        $s = (string) preg_replace('/[^[:alnum:]]+/u', ' ', $s);
        $tokens = [];
        foreach (preg_split('/\s+/u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $tok) {
            if (mb_strlen($tok) >= 3) {
                $tokens[$tok] = true;
            }
        }

        return array_map('strval', array_keys($tokens));
    }

    /** @param list<string> $a @param list<string> $b */
    public function jaccard(array $a, array $b): float
    {
        if ($a === [] || $b === []) {
            return 0.0;
        }
        $intersect = count(array_intersect($a, $b));
        $union = count(array_unique([...$a, ...$b]));

        return $union === 0 ? 0.0 : $intersect / $union;
    }

    /**
     * Hybrid-Recall: semantische Slugs aus dem Embedding-Store, opt-in über
     * config foodalchemist.semantic_search.enabled. Leerer Rückgabewert wenn
     * deaktiviert (Default) / kein Provider — die Lexik bleibt führend, Fehler
     * werden geschluckt (Invariante 6: fehlende Quelle = leerer Kontext, nie Fehler).
     *
     * @param  list<string>  $kategorien
     * @return list<string>
     */
    private function semanticSlugs(string $beschreibung, array $kategorien, int $limit): array
    {
        if ($limit <= 0 || ! config('foodalchemist.semantic_search.enabled', false)) {
            return [];
        }
        try {
            $svc = app(KnowledgeEmbeddingService::class);
            if (! $svc->searchEnabled()) {
                return [];
            }

            return $svc->searchSlugs($beschreibung, $kategorien, $limit);
        } catch (\Throwable) {
            return [];
        }
    }

    /** Invariante 3: hartes Per-Dokument-Budget mit wörtlichem Kürzungs-Marker. */
    public function truncate(string $text, int $maxChars): string
    {
        if (mb_strlen($text) <= $maxChars) {
            return $text;
        }

        return mb_substr($text, 0, $maxChars) . "\n\n[…gekürzt für KI-Kontext…]";
    }

    /**
     * Invariante 4: extrahiert KOMPAKT die verifizierten Partner-NAMEN aus der
     * »## Pairings«-Region (Wikilink-Displays + **bold**, ≤40 Zeichen, dedupe),
     * NICHT die molekulare Prosa. Region endet bei »## Notizen«/»## Eigene«.
     * $sections = Stil-Filter (Tabelle 4.2): null = ganze Region inkl.
     * Verbund/Trinitas; sonst nur ###-Untersektionen, deren Header mit einem
     * Key beginnt — neue ##-Sektion schaltet den Filter wieder aus.
     *
     * @param  list<string>|null  $sections
     * @return list<string>
     */
    public function extractPairingNames(string $content, ?array $sections = null): array
    {
        $start = mb_strpos($content, '## Pairings');
        if ($start === false) {
            return [];
        }
        $rest = mb_substr($content, $start);
        $end = mb_strpos($rest, '## Notizen');
        if ($end === false) {
            $end = mb_strpos($rest, '## Eigene');
        }
        $region = $end === false ? $rest : mb_substr($rest, 0, $end);

        $scan = $region;
        if ($sections !== null) {
            $kept = '';
            $keep = false;
            foreach (explode("\n", $region) as $line) {
                if (str_starts_with($line, '### ')) {
                    $h = substr($line, 4);
                    $keep = count(array_filter($sections, fn ($k) => str_starts_with($h, $k))) > 0;
                } elseif (str_starts_with($line, '## ')) {
                    $keep = false;                                  // neue ##-Sektion (z.B. Verbund) → aus
                }
                if ($keep) {
                    $kept .= $line . "\n";
                }
            }
            $scan = $kept;
        }

        $names = [];
        $seen = [];
        $push = function (string $raw) use (&$names, &$seen): void {
            $name = trim($raw);
            $key = mb_strtolower($name);
            if ($name !== '' && strlen($name) <= 40 && ! isset($seen[$key])) {
                $seen[$key] = true;
                $names[] = $name;
            }
        };
        // Wikilinks [[slug|Display]] / [[Display]] → Display
        foreach (array_slice(explode('[[', $scan), 1) as $part) {
            $close = strpos($part, ']]');
            if ($close !== false) {
                $inner = substr($part, 0, $close);
                $segments = explode('|', $inner);
                $push(end($segments));
            }
        }
        // **Bold**-Pairings (Einträge ohne eigene Datei)
        $boldParts = explode('**', $scan);
        for ($i = 1; $i < count($boldParts); $i += 2) {
            if (strlen($boldParts[$i]) <= 40 && preg_match('/[\x00-\x1f]/', $boldParts[$i]) === 0) {
                $push($boldParts[$i]);
            }
        }

        return $names;
    }

    /** Die 7 Always-Load-Dokumente in Ist-Reihenfolge (fehlende werden still übersprungen). */
    private function crossCuttingDocs(): array
    {
        $docs = DB::table('foodalchemist_knowledge_documents')
            ->where('kategorie', 'cross_cutting')->where('aktiv', 1)->whereNull('deleted_at')
            ->whereIn('slug', self::ALWAYS_LOAD_CROSS_CUTTING)
            ->get(['slug', 'inhalt_md', 'version'])->keyBy('slug');

        return array_values(array_filter(array_map(fn ($slug) => $docs->get($slug), self::ALWAYS_LOAD_CROSS_CUTTING)));
    }

    /**
     * Invariante 2 — Domain-Discovery zweistufig: (a) Alias-Mapping (ersetzt
     * HAUPTZUTAT_TO_DOMAIN) gegen die tokenisierte Beschreibung; (b) nur wenn
     * <2 Treffer: Filename-Token-Fallback (Jaccard + 0,1·Wort-Treffer). Max 4,
     * alphabetisch sortiert geladen.
     */
    private function discoverDomains(string $beschreibung): array
    {
        $tokens = $this->tokenize($beschreibung);
        $slugs = [];

        if ($tokens !== []) {
            // 2a. Explizites Alias-Mapping
            $aliases = DB::table('foodalchemist_knowledge_aliases as a')
                ->join('foodalchemist_knowledge_documents as d', 'd.id', 'a.knowledge_document_id')
                ->where('d.kategorie', 'domain')->where('d.aktiv', 1)->whereNull('d.deleted_at')
                ->get(['a.alias_slug', 'd.slug']);
            foreach ($aliases as $alias) {
                $a = mb_strtolower($alias->alias_slug);
                foreach ($tokens as $t) {
                    if ($t === $a
                        || (mb_strlen($t) >= 4 && str_contains($a, $t))
                        || (mb_strlen($a) >= 4 && str_contains($t, $a))) {
                        $slugs[$alias->slug] = true;
                        break;
                    }
                }
            }
        }

        // 2b. Fallback: Slug-/Titel-Token-Match, nur wenn das Mapping kaum greift
        if (count($slugs) < 2 && $tokens !== []) {
            $scored = [];
            foreach ($this->domainDocs()->keys() as $slug) {
                $slugTokens = $this->tokenize($slug);
                $score = $this->jaccard($tokens, $slugTokens);
                $wordHits = count(array_filter($tokens, fn ($t) => str_contains($slug, $t)
                    || count(array_filter($slugTokens, fn ($st) => str_contains($st, $t))) > 0));
                $combined = $score + $wordHits * 0.1;
                if ($combined > 0.0) {
                    $scored[] = [$slug, $combined];
                }
            }
            usort($scored, fn ($x, $y) => $y[1] <=> $x[1]);
            foreach (array_slice($scored, 0, max(0, self::DOMAIN_TOP_K - count($slugs))) as [$slug]) {
                $slugs[$slug] = true;
            }
        }

        // 2c. Semantischer Recall (Hybrid, opt-in): füllt auf, wenn die Lexik
        // < TOP_K Domains liefert. Deaktiviert (Default) = unverändertes Verhalten.
        if (count($slugs) < self::DOMAIN_TOP_K) {
            foreach ($this->semanticSlugs($beschreibung, ['domain'], self::DOMAIN_TOP_K - count($slugs)) as $slug) {
                $slugs[$slug] = true;
            }
        }

        $slugList = array_map('strval', array_keys($slugs));
        sort($slugList);
        $docs = $this->domainDocs();

        return array_values(array_filter(array_map(
            fn ($slug) => $docs->get($slug),
            array_slice($slugList, 0, self::DOMAIN_TOP_K)
        )));
    }

    private function domainDocs(): \Illuminate\Support\Collection
    {
        return DB::table('foodalchemist_knowledge_documents')
            ->where('kategorie', 'domain')->where('aktiv', 1)->whereNull('deleted_at')
            ->get(['slug', 'inhalt_md', 'version'])->keyBy('slug');
    }

    /**
     * Kompakter FLAVOR-PAIRING-Block (vault_context.rs:464-539): Beschreibung
     * gegen Pairing-Doc-Stems matchen (max 3, sortiert), je Anker eine Zeile
     * »- <stem>: A · B · C« (max 28 Partner). Invariante 5: auch »gewagt«
     * zieht NUR belegte Paarungen — der Header sagt das der KI explizit.
     *
     * @param  list<string>  $filesUsed  by-ref-Audit
     */
    private function pairingBlock(string $beschreibung, ?string $stil, array &$filesUsed): ?string
    {
        $sectionFilter = match ($stil) {
            'klassisch' => ['Klassisch'],
            'kreativ' => ['Klassisch', 'Modern'],
            'gewagt' => ['Modern', 'Kontrast'],
            default => null,
        };
        $stilHint = match ($stil) {
            'klassisch' => ' (Stil KLASSISCH — etablierte, traditionelle Kombinationen)',
            'kreativ' => ' (Stil KREATIV — klassische Basis + moderne, Foodpairing-belegte Twists)',
            'gewagt' => ' (Stil GEWAGT — moderne + kontrastreiche Paarungen, bewusst mutig, aber NUR belegte aus dieser Liste)',
            default => '',
        };

        $tokens = $this->tokenize($beschreibung);
        if ($tokens === []) {
            return null;
        }

        $matched = [];
        foreach ($this->pairingStems() as $stem) {
            $stemNorm = str_replace(['-', '_'], '', $stem);          // mehrteilige Slugs auch ohne Trenner matchen
            foreach ($tokens as $t) {
                if ($t === $stem || $t === $stemNorm
                    || (mb_strlen($t) >= 4 && (str_contains($stem, $t) || str_contains($stemNorm, $t)))
                    || (mb_strlen($stem) >= 4 && str_contains($t, $stem))) {
                    $matched[] = $stem;
                    break;
                }
            }
        }

        // Semantischer Recall (Hybrid, opt-in): ergänzt eine dünne/leere Lexik um
        // semantisch passende Pairing-Stems. Deaktiviert (Default) = no-op.
        if (count($matched) < self::PAIRING_TOP_K) {
            foreach ($this->semanticSlugs($beschreibung, ['pairing'], self::PAIRING_TOP_K) as $stem) {
                if (! in_array($stem, $matched, true)) {
                    $matched[] = $stem;
                }
            }
        }

        if ($matched === []) {
            return null;
        }
        sort($matched);

        $zeilen = [];
        foreach (array_slice($matched, 0, self::PAIRING_TOP_K) as $stem) {
            $doc = $this->pairingDoc($stem);
            if ($doc === null) {
                continue;
            }
            $names = $this->extractPairingNames($doc->inhalt_md, $sectionFilter);
            if ($names !== []) {
                $zeilen[] = "- {$stem}: " . implode(' · ', array_slice($names, 0, self::MAX_PARTNERS));
                $filesUsed[] = "{$doc->slug}@v{$doc->version}";
            }
        }
        if ($zeilen === []) {
            return null;
        }

        return "# FLAVOR-PAIRING (verifizierte Kombinationen aus der Wissensbasis{$stilHint}"
            . " — bevorzuge diese fuer Komponenten + Garnitur; erfinde KEINE unbelegten Paarungen):\n"
            . implode("\n", $zeilen);
    }

    /**
     * Pairing-Doku-Grounding für Anker-/Pairing-Inferenz: je Hauptzutat-Slug
     * die Doku(s) per Identitäts-/Präfix-Match (slug == hz, slug startet mit
     * »hz_«, hz startet mit »slug_«), dedupliziert, bis max_docs erreicht.
     *
     * @param  list<string>  $hauptzutatSlugs
     * @param  list<string>  $filesUsed  by-ref-Audit
     */
    private function groundingBlock(array $hauptzutatSlugs, int $maxDocs, int $maxChars, array &$filesUsed): string
    {
        $blocks = [];
        $geladen = [];
        foreach ($hauptzutatSlugs as $hz) {
            if (count($geladen) >= $maxDocs) {
                break;
            }
            $hz = mb_strtolower(trim($hz));
            if ($hz === '') {
                continue;
            }
            foreach ($this->pairingStems() as $stem) {
                if (count($geladen) >= $maxDocs) {
                    break;
                }
                if ($stem === $hz || str_starts_with($stem, $hz . '_') || str_starts_with($hz, $stem . '_')) {
                    if (isset($geladen[$stem])) {
                        continue;
                    }
                    $doc = $this->pairingDoc($stem);
                    if ($doc !== null) {
                        $geladen[$stem] = true;
                        $blocks[] = "### Pairing-Doku: {$stem}\n" . $this->truncate($doc->inhalt_md, $maxChars);
                        $filesUsed[] = "{$doc->slug}@v{$doc->version}";
                    }
                }
            }
        }
        if ($blocks === []) {
            return '(keine spezifische Doku gefunden — nutze allgemeines Wissen)';
        }

        return implode("\n\n", $blocks);
    }

    /** @return list<string> Pairing-Doc-Stems (Slug ohne »pairing.«-Präfix), sortiert */
    private function pairingStems(): array
    {
        static $stems = null;
        if ($stems === null || app()->runningUnitTests()) {
            $stems = DB::table('foodalchemist_knowledge_documents')
                ->where('kategorie', 'pairing')->where('aktiv', 1)->whereNull('deleted_at')
                ->orderBy('slug')->pluck('slug')
                ->map(fn ($s) => str_starts_with($s, 'pairing.') ? substr($s, 8) : $s)
                ->all();
        }

        return $stems;
    }

    private function pairingDoc(string $stem): ?object
    {
        return DB::table('foodalchemist_knowledge_documents')
            ->where('kategorie', 'pairing')->where('aktiv', 1)->whereNull('deleted_at')
            ->whereIn('slug', ["pairing.{$stem}", $stem])
            ->first(['slug', 'inhalt_md', 'version']);
    }
}
