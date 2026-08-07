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

    /** Spec 08 P6: Fallback-Budget für `concept:always`, wenn die Routing-Zeile nichts vorgibt. */
    public const CONCEPT_MAX_DOCS = 4;

    public const CONCEPT_TRUNCATE_CHARS = 4000;

    /**
     * Haupt-Einstieg (Pseudocode §3): baut den Wissens-Block für ein KI-Feature.
     *
     * @param  list<string>  $hauptzutatSlugs  nur für Grounding-Features (ai_suggest_pairings, ai_infer_ankers)
     * @return array{block: string, files_used: list<string>, total_chars: int}
     */
    public function contextFor(string $feature, string $description, ?string $stil = null, array $hauptzutatSlugs = [], array $params = []): array
    {
        $routing = DB::table('foodalchemist_knowledge_routings')
            ->where('feature', $feature)
            ->get()->keyBy(fn ($r) => $r->category . ':' . $r->mode);

        $filesUsed = [];
        $parts = [];

        // ── 0. CONCEPTING-WISSEN (Spec 08 P6, nur Planungs-Features) ──
        // Steht bewusst VOR dem Food-Wissen: es sagt, wie ein Konzept gebaut ist,
        // und rahmt damit die Zutaten-Ebene darunter. Leere Kategorie ⇒ kein Block.
        if (($r = $routing->get('concept:always')) !== null) {
            $concept = $this->conceptBlock(
                (int) ($r->max_docs ?: self::CONCEPT_MAX_DOCS),
                (int) ($r->max_chars_per_doc ?: self::CONCEPT_TRUNCATE_CHARS),
                $filesUsed
            );
            if ($concept !== null) {
                $parts[] = $concept;
            }
        }

        // ── 0b. TREND-WISSEN (Trendradar) — discovery, thematisch zur Beschreibung ──
        // Steht bei den rahmenden Blöcken: aktuelle Trends sagen, WAS gerade relevant
        // ist (Anlass/Inspiration), bevor die Zutaten-Ebene darunter greift.
        if (($r = $routing->get('trend:discovery')) !== null) {
            $trend = $this->trendBlock(
                (int) ($r->max_docs ?: 5),
                (int) ($r->max_chars_per_doc ?: 1500),
                $description,
                $filesUsed
            );
            if ($trend !== null) {
                $parts[] = $trend;
            }
        }

        // ── 1. VAULT-WISSEN: Cross-Cutting (always) + Domains (discovery) ──
        $blocks = [];
        if ($routing->has('cross_cutting:always')) {
            foreach ($this->crossCuttingDocs() as $doc) {
                $blocks[] = "## CROSS_CUTTING: {$doc->slug}\n\n" . $this->truncate($doc->content_md, self::CROSS_CUTTING_TRUNCATE_CHARS);
                $filesUsed[] = "{$doc->slug}@v{$doc->version}";
            }
        }
        if ($routing->has('domain:discovery')) {
            foreach ($this->discoverDomains($description) as $doc) {
                $blocks[] = "## DOMAIN: {$doc->slug}\n\n" . $this->truncate($doc->content_md, self::DOMAIN_TRUNCATE_CHARS);
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
            $pairing = $this->pairingBlock($description, $stil, $filesUsed);
            if ($pairing !== null) {
                $parts[] = $pairing;
            }
        }

        // ── 3. Pairing-Doku-Grounding (Anker-/Pairing-Inferenz) ──
        if (($r = $routing->get('pairing:grounding')) !== null) {
            $parts[] = $this->groundingBlock($hauptzutatSlugs, (int) $r->max_docs, (int) $r->max_chars_per_doc, $filesUsed);
        }

        // ── 4. GENERISCHE discovery-Kategorien (S1 Skalierbarkeit) ──
        // Jede als `discovery` geroutete Kategorie OHNE Spezial-Handler (domain/pairing/
        // trend/concept haben eigene, oben) wird hier generisch per Beschreibung + Leitplanken-
        // Werten (Niveau/Sektor) entdeckt und gedeckelt geladen. Damit skaliert die Wissensbasis:
        // eine neue Kategorie braucht nur eine Routing-Zeile, KEINEN Service-Code. Bestehende
        // Kategorien werden übersprungen → Verhalten für sie byte-identisch (golden-safe).
        $spezial = ['domain', 'pairing', 'trend', 'concept', 'cross_cutting'];
        $leitplankenQuery = trim($description . ' ' . implode(' ', array_filter([
            (string) ($params['niveau'] ?? $params['level'] ?? ''),
            (string) ($params['sektor'] ?? ''),
        ], fn ($v) => $v !== '')));
        foreach ($routing as $r) {
            if ($r->mode !== 'discovery' || in_array($r->category, $spezial, true)) {
                continue;
            }
            $generic = $this->discoverGenericBlock(
                (string) $r->category, $leitplankenQuery,
                (int) ($r->max_docs ?: 3), (int) ($r->max_chars_per_doc ?: 3000), $filesUsed
            );
            if ($generic !== null) {
                $parts[] = $generic;
            }
        }

        // (#469-Bindungs-Injektion passiert jetzt zentral im AiGatewayService::propose für ALLE Prompts.)

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
    private function semanticSlugs(string $description, array $kategorien, int $limit): array
    {
        if ($limit <= 0 || ! config('foodalchemist.semantic_search.enabled', false)) {
            return [];
        }
        try {
            $svc = app(KnowledgeEmbeddingService::class);
            if (! $svc->searchEnabled()) {
                return [];
            }

            return $svc->searchSlugs($description, $kategorien, $limit);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Semantischer Recall für Pairing über die ANKER-Embeddings (embedAnkers), nicht die
     * Pairing-Docs. Gleiche Gates wie semanticSlugs (config + Provider), damit deaktivierte
     * Semantik/kein Provider sauber zu no-op werden.
     *
     * @return list<string> Anker-Slugs, bestes zuerst
     */
    private function semanticAnkerStems(string $description, int $limit): array
    {
        if ($limit <= 0 || ! config('foodalchemist.semantic_search.enabled', false)) {
            return [];
        }
        try {
            $svc = app(KnowledgeEmbeddingService::class);
            if (! $svc->searchEnabled()) {
                return [];
            }

            return $svc->searchAnkerSlugs($description, $limit);
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

    /**
     * Spec 08 P6: Concepting-Wissen für die Planungs-Features (`foodbook.plan`,
     * `concept.plan`). Bewusst `always` statt `discovery`: der Bestand ist klein
     * und beschreibt Handwerk, kein Produkt — eine Beschreibungs-Discovery würde
     * hier nach Zutaten filtern, wo Dramaturgie gefragt ist. Deckel kommt aus der
     * Routing-Zeile (max_docs/max_chars_per_doc), Reihenfolge ist Slug-stabil.
     *
     * @param  list<string>  $filesUsed  by-ref-Audit
     */
    private function conceptBlock(int $maxDocs, int $maxChars, array &$filesUsed): ?string
    {
        $docs = DB::table('foodalchemist_knowledge_documents')
            ->where('category', 'concept')->where('active', 1)->whereNull('deleted_at')
            ->orderBy('slug')->limit(max(1, $maxDocs))
            ->get(['slug', 'content_md', 'version']);
        if ($docs->isEmpty()) {
            return null;                                             // Invariante 6: fehlende Quelle = leerer Kontext
        }

        $blocks = [];
        foreach ($docs as $doc) {
            $blocks[] = "## CONCEPT: {$doc->slug}\n\n" . $this->truncate((string) $doc->content_md, $maxChars);
            $filesUsed[] = "{$doc->slug}@v{$doc->version}";
        }

        return "# CONCEPTING-WISSEN (Konzept-/Menü-Handwerk: Dramaturgie, Gang-Aufbau, Anlass- und Gäste-Fit, Balance)\n\n"
            . "Maßstab für den PLAN: es sagt, WIE ein gutes Konzept gebaut ist — nicht, welches Gericht darin steht.\n\n"
            . implode("\n\n---\n\n", $blocks);
    }

    /**
     * TREND-WISSEN (Trendradar, `foodbook.plan` / `concept.brief_geruest`): discovery
     * über die geclusterten Trend-Docs. Auswahl = Relevanz (aus trend_meta) + Token-Overlap
     * der Beschreibung gegen Titel/Slug/Klasse/Kategorie, damit nur thematisch passende
     * Trends ins Prompt-Budget kommen. Deckel aus der Routing-Zeile. Ohne Bestand: null
     * (Invariante 6 — fehlende Quelle = leerer Kontext, nie Fehler).
     *
     * @param  list<string>  $filesUsed  by-ref-Audit
     */
    private function trendBlock(int $maxDocs, int $maxChars, string $description, array &$filesUsed): ?string
    {
        $maxDocs = max(1, $maxDocs);
        $tokens = $this->tokenize($description);
        $weight = ['high' => 3, 'medium' => 2, 'low' => 1];

        $rows = DB::table('foodalchemist_knowledge_documents as d')
            ->leftJoin('foodalchemist_trend_meta as m', 'm.knowledge_document_id', '=', 'd.id')
            ->where('d.category', 'trend')->where('d.active', 1)->whereNull('d.deleted_at')
            ->get(['d.id', 'd.slug', 'd.title', 'd.version', 'm.relevance', 'm.trend_class', 'm.category']);
        if ($rows->isEmpty()) {
            return null;
        }

        $scored = [];
        foreach ($rows as $r) {
            $matchTokens = $this->tokenize("{$r->title} {$r->slug} {$r->trend_class} {$r->category}");
            $overlap = $tokens === [] ? 0 : count(array_intersect($tokens, $matchTokens));
            $scored[] = [$r, ($weight[$r->relevance] ?? 1) + $overlap * 2];
        }
        usort($scored, fn ($x, $y) => $y[1] <=> $x[1]);
        $top = array_slice($scored, 0, $maxDocs);

        $ids = array_map(fn ($p) => $p[0]->id, $top);
        $docs = DB::table('foodalchemist_knowledge_documents')->whereIn('id', $ids)
            ->get(['id', 'slug', 'content_md', 'version'])->keyBy('id');

        $blocks = [];
        foreach ($top as [$r]) {
            $doc = $docs->get($r->id);
            if ($doc === null) {
                continue;
            }
            $blocks[] = "## TREND: {$doc->slug}\n\n" . $this->truncate((string) $doc->content_md, $maxChars);
            $filesUsed[] = "{$doc->slug}@v{$doc->version}";
        }
        if ($blocks === []) {
            return null;
        }

        return "# TREND-WISSEN (aktuelle Food-Trends aus dem Trendradar)\n\n"
            . "Diese Signale sagen, WAS gerade relevant ist — nutze sie als Anlass/Inspiration. "
            . "Erfinde nichts hinzu, was die Trends nicht hergeben.\n\n"
            . implode("\n\n---\n\n", $blocks);
    }

    /** Die 7 Always-Load-Dokumente in Ist-Reihenfolge (fehlende werden still übersprungen). */
    private function crossCuttingDocs(): array
    {
        $docs = DB::table('foodalchemist_knowledge_documents')
            ->where('category', 'cross_cutting')->where('active', 1)->whereNull('deleted_at')
            ->whereIn('slug', self::ALWAYS_LOAD_CROSS_CUTTING)
            ->get(['slug', 'content_md', 'version'])->keyBy('slug');

        return array_values(array_filter(array_map(fn ($slug) => $docs->get($slug), self::ALWAYS_LOAD_CROSS_CUTTING)));
    }

    /**
     * S1 (Skalierbarkeit): generische discovery für JEDE als `discovery` geroutete Kategorie
     * OHNE eigenen Spezial-Handler. Rankt die aktiven Docs der Kategorie gegen die (Leitplanken-
     * augmentierte) Query — Alias-Bonus, dann Slug-Token (Jaccard + Wort-Treffer, wie der
     * Domain-Fallback) — und lädt Top-K gedeckelt. So trägt jedes neu gepflegte Doc automatisch,
     * ohne Service-Änderung; der Prompt bleibt durch top_k/chars beschränkt (O(1), nicht O(n)).
     */
    private function discoverGenericBlock(string $category, string $query, int $topK, int $maxChars, array &$filesUsed): ?string
    {
        $tokens = $this->tokenize($query);
        if ($tokens === [] || $topK <= 0) {
            return null;
        }

        $docs = DB::table('foodalchemist_knowledge_documents')
            ->where('category', $category)->where('active', 1)->whereNull('deleted_at')
            ->get(['id', 'slug', 'content_md', 'version']);
        if ($docs->isEmpty()) {
            return null;
        }

        // Alias-Treffer (falls gepflegt) → Bonus, damit ein exakt passendes Doc sicher oben landet.
        $aliasBySlug = [];
        foreach (DB::table('foodalchemist_knowledge_aliases as a')
            ->join('foodalchemist_knowledge_documents as d', 'd.id', 'a.knowledge_document_id')
            ->where('d.category', $category)->where('d.active', 1)->whereNull('d.deleted_at')
            ->get(['a.alias_slug', 'd.slug']) as $al) {
            $a = mb_strtolower($al->alias_slug);
            foreach ($tokens as $t) {
                if ($t === $a || (mb_strlen($t) >= 4 && str_contains($a, $t)) || (mb_strlen($a) >= 4 && str_contains($t, $a))) {
                    $aliasBySlug[$al->slug] = true;
                    break;
                }
            }
        }

        $scored = [];
        foreach ($docs as $doc) {
            $slugTokens = $this->tokenize((string) $doc->slug);
            $score = $this->jaccard($tokens, $slugTokens)
                + 0.1 * count(array_filter($tokens, fn ($t) => count(array_filter($slugTokens, fn ($st) => str_contains($st, $t))) > 0))
                + (isset($aliasBySlug[$doc->slug]) ? 1.0 : 0.0);
            if ($score > 0.0) {
                $scored[] = [$doc, $score];
            }
        }
        if ($scored === []) {
            return null;
        }
        usort($scored, fn ($x, $y) => $y[1] <=> $x[1]);

        $label = mb_strtoupper($category);
        $blocks = [];
        foreach (array_slice($scored, 0, $topK) as [$doc]) {
            $blocks[] = "## {$label}: {$doc->slug}\n\n" . $this->truncate((string) $doc->content_md, $maxChars);
            $filesUsed[] = "{$doc->slug}@v{$doc->version}";
        }

        return '# ' . $label . "-WISSEN\n\n" . implode("\n\n---\n\n", $blocks);
    }

    /**
     * Invariante 2 — Domain-Discovery zweistufig: (a) Alias-Mapping (ersetzt
     * HAUPTZUTAT_TO_DOMAIN) gegen die tokenisierte Beschreibung; (b) nur wenn
     * <2 Treffer: Filename-Token-Fallback (Jaccard + 0,1·Wort-Treffer). Max 4,
     * alphabetisch sortiert geladen.
     */
    private function discoverDomains(string $description): array
    {
        $tokens = $this->tokenize($description);
        $slugs = [];

        if ($tokens !== []) {
            // 2a. Explizites Alias-Mapping
            $aliases = DB::table('foodalchemist_knowledge_aliases as a')
                ->join('foodalchemist_knowledge_documents as d', 'd.id', 'a.knowledge_document_id')
                ->where('d.category', 'domain')->where('d.active', 1)->whereNull('d.deleted_at')
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
            foreach ($this->domainSlugs() as $slug) {
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
            foreach ($this->semanticSlugs($description, ['domain'], self::DOMAIN_TOP_K - count($slugs)) as $slug) {
                $slugs[$slug] = true;
            }
        }

        $slugList = array_map('strval', array_keys($slugs));
        sort($slugList);
        $topK = array_slice($slugList, 0, self::DOMAIN_TOP_K);
        $docs = $this->domainDocsBySlug($topK);   // Volltext NUR für die gewählten (Tauri-Muster)

        return array_values(array_filter(array_map(
            fn ($slug) => $docs->get($slug),
            $topK
        )));
    }

    /**
     * Nur die Domain-Slugs (KEIN content_md) fürs Discovery-Scoring — spiegelt die Tauri-App
     * (`vault_context.rs`: Verzeichnis listen + nach Dateinamen scoren). Früher zog `domainDocs()`
     * ALLE Dossier-Volltexte in den PHP-Speicher, nur um Slugs zu scoren (2×/Lauf, ungecacht) —
     * das war der zweite Speicherfresser neben der (abgeschalteten) Embedding-Schicht.
     *
     * @return list<string>
     */
    private function domainSlugs(): array
    {
        return DB::table('foodalchemist_knowledge_documents')
            ->where('category', 'domain')->where('active', 1)->whereNull('deleted_at')
            ->orderBy('slug')->pluck('slug')->map(fn ($s) => (string) $s)->all();
    }

    /**
     * content_md NUR für die ausgewählten Top-K-Slugs laden (entspricht dem `read_truncated`
     * je Top-K der Tauri-App). Leere Auswahl → leere Collection (kein Query).
     */
    private function domainDocsBySlug(array $slugs): \Illuminate\Support\Collection
    {
        if ($slugs === []) {
            return collect();
        }

        return DB::table('foodalchemist_knowledge_documents')
            ->where('category', 'domain')->where('active', 1)->whereNull('deleted_at')
            ->whereIn('slug', $slugs)
            ->get(['slug', 'content_md', 'version'])->keyBy('slug');
    }

    /**
     * Kompakter FLAVOR-PAIRING-Block (vault_context.rs:464-539): Beschreibung
     * gegen Pairing-Doc-Stems matchen (max 3, sortiert), je Anker eine Zeile
     * »- <stem>: A · B · C« (max 28 Partner). Invariante 5: auch »gewagt«
     * zieht NUR belegte Paarungen — der Header sagt das der KI explizit.
     *
     * @param  list<string>  $filesUsed  by-ref-Audit
     */
    private function pairingBlock(string $description, ?string $stil, array &$filesUsed): ?string
    {
        // Graph-first (2026-07-13): Partner kommen aus dem Anker-Graphen (PairingService),
        // NICHT mehr aus dem Markdown-Volltext. Der Graph ist das Gehirn (kuratiert + Buch +
        // computed, ~179k Kanten); die md-Prosa liefert nur noch Grounding (groundingBlock).
        // Stil → Kanten-Typen (neue Taxonomie aroma/kontrast/erprobt).
        $typen = match ($stil) {
            'klassisch' => ['erprobt'],
            'kreativ' => ['erprobt', 'aroma'],
            'gewagt' => ['aroma', 'kontrast'],
            default => ['erprobt', 'aroma', 'kontrast'],
        };
        $stilHint = match ($stil) {
            'klassisch' => ' (Stil KLASSISCH — etablierte, erprobte Kombinationen)',
            'kreativ' => ' (Stil KREATIV — erprobte Basis + Aroma-belegte Twists)',
            'gewagt' => ' (Stil GEWAGT — Aroma + Kontrast, bewusst mutig, aber NUR belegte aus dieser Liste)',
            default => '',
        };

        $tokens = $this->tokenize($description);
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

        // Semantischer Recall (Hybrid, opt-in): ergänzt eine dünne/leere Lexik um semantisch
        // passende Anker-Stems (über die Anker-Embeddings, NICHT mehr Pairing-Docs).
        // Deaktiviert (Default) = no-op.
        if (count($matched) < self::PAIRING_TOP_K) {
            foreach ($this->semanticAnkerStems($description, self::PAIRING_TOP_K) as $stem) {
                if (! in_array($stem, $matched, true)) {
                    $matched[] = $stem;
                }
            }
        }

        if ($matched === []) {
            return null;
        }
        sort($matched);

        $svc = app(\Platform\FoodAlchemist\Services\PairingService::class);
        $typSet = array_flip($typen);
        $zeilen = [];
        foreach (array_slice($matched, 0, self::PAIRING_TOP_K) as $stem) {
            // Stem (Doc-Slug, evtl. mit »-«) → Anker-Slug (»_«) für die Graph-Auflösung.
            $res = $svc->neighborsForName(str_replace('-', '_', $stem), null, self::MAX_PARTNERS * 4);
            if ($res['anker'] === null) {
                continue;
            }
            $namen = [];                                             // display_de → true (Typ-Prio-sortiert: erprobt vor aroma vor kontrast)
            foreach ($res['partner'] as $p) {
                if (! isset($typSet[$p->type])) {
                    continue;
                }
                $namen[$p->display_de ?: $p->slug] = true;
                if (count($namen) >= self::MAX_PARTNERS) {
                    break;
                }
            }
            if ($namen !== []) {
                $zeilen[] = "- {$stem}: " . implode(' · ', array_keys($namen));
                $filesUsed[] = "graph:{$res['anker']['slug']}";
            }
        }
        if ($zeilen === []) {
            return null;
        }

        return "# FLAVOR-PAIRING (verifizierte Kombinationen aus dem Anker-Graphen{$stilHint}"
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
                        $blocks[] = "### Pairing-Doku: {$stem}\n" . $this->truncate($doc->content_md, $maxChars);
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

    /**
     * @return list<string> Anker-Slugs aus dem Pairing-Graphen (Inspire-Anker-Vokabular), sortiert.
     *
     * Quelle ist seit 2026-08-07 das Anker-VOKABULAR (foodalchemist_vocab_pairing_anchors),
     * nicht mehr die Pairing-Docs (category='pairing'). Die Token→Anker-Brücke lebt damit am
     * Graphen selbst — die Pairing-Docs sind für die KI-Rezept-Erdung nicht mehr nötig und
     * dürfen aufgeräumt werden (die Partner kommen ohnehin aus PairingService, nicht aus den
     * Docs). 'neutral' bleibt außen vor (kein Aroma), konsistent mit embedAnkers().
     */
    private function pairingStems(): array
    {
        static $stems = null;
        if ($stems === null || app()->runningUnitTests()) {
            $stems = DB::table('foodalchemist_vocab_pairing_anchors')
                ->whereNull('deleted_at')->where('slug', '!=', 'neutral')
                ->orderBy('slug')->pluck('slug')
                ->all();
        }

        return $stems;
    }

    private function pairingDoc(string $stem): ?object
    {
        return DB::table('foodalchemist_knowledge_documents')
            ->where('category', 'pairing')->where('active', 1)->whereNull('deleted_at')
            ->whereIn('slug', ["pairing.{$stem}", $stem])
            ->first(['slug', 'content_md', 'version']);
    }

    // ── MCP-Discovery (Phase K): Wissens-Suche für externe LLM-Clients ──────

    /**
     * Volltext-leichte Suche über den Wissens-Bestand: Token-Treffer in
     * slug/titel + Alias-Treffer (gewichtet). Kein Team-Filter — der
     * Wissens-Bestand ist global (wie crossCuttingDocs/discoverDomains).
     *
     * @return list<array{slug: string, titel: string, kategorie: string, version: int, char_count: int, score: float}>
     */
    public function searchDocuments(string $q, ?string $kategorie = null, int $limit = 10): array
    {
        $tokens = $this->tokenize($q);
        if ($tokens === []) {
            return [];
        }
        $limit = max(1, min(50, $limit));

        // Alias-Treffer: exakte Token-Übereinstimmung zählt doppelt
        $aliasHits = DB::table('foodalchemist_knowledge_aliases')
            ->whereIn('alias_slug', $tokens)
            ->pluck('knowledge_document_id')
            ->countBy()->all();

        $scored = [];
        $docs = DB::table('foodalchemist_knowledge_documents')
            ->where('active', 1)->whereNull('deleted_at')
            ->when($kategorie !== null, fn ($query) => $query->where('category', $kategorie))
            ->get(['id', 'slug', 'title', 'category', 'version', 'char_count']);
        foreach ($docs as $doc) {
            $haystack = $this->tokenize($doc->slug . ' ' . $doc->title);
            $score = count(array_intersect($tokens, $haystack))
                + 2.0 * ($aliasHits[$doc->id] ?? 0);
            if ($score > 0) {
                $scored[] = ['doc' => $doc, 'score' => $score];
            }
        }
        usort($scored, fn ($a, $b) => ($b['score'] <=> $a['score']) ?: strcmp($a['doc']->slug, $b['doc']->slug));

        $out = array_map(fn ($item) => [
            'slug' => $item['doc']->slug,
            'title' => $item['doc']->title,
            'category' => $item['doc']->category,
            'version' => (int) $item['doc']->version,
            'char_count' => (int) $item['doc']->char_count,
            'score' => $item['score'],
            'via' => 'lexical',
        ], array_slice($scored, 0, $limit));

        // E4 (#507): semantische Ergänzung (nutzte bisher nur der Browser) — Docs,
        // die die Token-/Alias-Lexik verfehlt, werden angehängt. Graceful ohne Provider.
        if (count($out) < $limit) {
            $embed = app(KnowledgeEmbeddingService::class);
            if ($embed->searchEnabled()) {
                $vorhanden = array_flip(array_column($out, 'slug'));
                $ids = $embed->searchDocIds($q, $limit * 2);
                if ($ids !== []) {
                    $semDocs = DB::table('foodalchemist_knowledge_documents')
                        ->whereIn('id', $ids)->where('active', 1)->whereNull('deleted_at')
                        ->when($kategorie !== null, fn ($query) => $query->where('category', $kategorie))
                        ->get(['id', 'slug', 'title', 'category', 'version', 'char_count'])->keyBy('id');
                    foreach ($ids as $id) {            // bereits Score-sortiert
                        $doc = $semDocs->get($id);
                        if ($doc === null || isset($vorhanden[$doc->slug]) || count($out) >= $limit) {
                            continue;
                        }
                        $vorhanden[$doc->slug] = true;
                        $out[] = [
                            'slug' => $doc->slug, 'title' => $doc->title, 'category' => $doc->category,
                            'version' => (int) $doc->version, 'char_count' => (int) $doc->char_count,
                            'score' => 0, 'via' => 'semantic',
                        ];
                    }
                }
            }
        }

        return $out;
    }

    /**
     * #496: Vollständige, seiten-basierte Enumeration des Wissens-Bestands
     * (ohne Suchbegriff, ohne 50er-Cap) — für MCP-Clients, die den ganzen
     * Katalog abrufen wollen. Optional pro Kategorie gefiltert; Frontmatter
     * (thema/sub_thema/relevanz/recherche_datum/tags) wird bei Bedarf aus dem
     * content_md geparst.
     *
     * @return array{total: int, offset: int, limit: int, next_offset: ?int, categories: array<string,int>, documents: list<array>}
     */
    public function listDocuments(?string $kategorie, int $offset, int $limit, bool $mitFrontmatter = true): array
    {
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);

        $base = DB::table('foodalchemist_knowledge_documents')
            ->where('active', 1)->whereNull('deleted_at')
            ->when($kategorie !== null, fn ($q) => $q->where('category', $kategorie));

        $total = (clone $base)->count();
        $categories = DB::table('foodalchemist_knowledge_documents')
            ->where('active', 1)->whereNull('deleted_at')
            ->select('category', DB::raw('COUNT(*) AS c'))->groupBy('category')
            ->pluck('c', 'category')->map(fn ($c) => (int) $c)->all();

        $spalten = ['slug', 'title', 'category', 'version', 'char_count', 'updated_at'];
        if ($mitFrontmatter) {
            $spalten[] = 'content_md';
        }
        $docs = (clone $base)->orderBy('category')->orderBy('slug')
            ->offset($offset)->limit($limit)->get($spalten);

        $documents = $docs->map(function ($doc) use ($mitFrontmatter) {
            $row = [
                'slug' => $doc->slug,
                'title' => $doc->title,
                'category' => $doc->category,
                'version' => (int) $doc->version,
                'char_count' => (int) $doc->char_count,
                'updated_at' => $doc->updated_at,
            ];
            if ($mitFrontmatter) {
                $fm = $this->parseFrontmatter((string) $doc->content_md);
                $row['frontmatter'] = [
                    'thema' => $fm['thema'] ?? null,
                    'sub_thema' => $fm['sub_thema'] ?? null,
                    'relevanz' => $fm['relevanz'] ?? null,
                    'recherche_datum' => $fm['recherche_datum'] ?? null,
                    'tags' => $this->normalizeTags($fm['tags'] ?? []),
                ];
            }

            return $row;
        })->all();

        $next = ($offset + count($documents) < $total) ? $offset + count($documents) : null;

        return [
            'total' => $total,
            'offset' => $offset,
            'limit' => $limit,
            'next_offset' => $next,
            'categories' => $categories,
            'documents' => $documents,
        ];
    }

    /**
     * Öffentliche Naht für den Trendradar (Clustering + UI): derselbe Parser
     * wie intern, aber mit auf Listen normalisierten `tags`/`quellen`. So
     * bleibt EINE Parser-Wahrheit — der Trendradar kopiert keine eigene
     * (schwächere) Frontmatter-Logik.
     *
     * @return array<string, string|list<string>>
     */
    public function frontmatterOf(string $md): array
    {
        $fm = $this->parseFrontmatter($md);
        foreach (['tags', 'quellen'] as $listenfeld) {
            if (array_key_exists($listenfeld, $fm)) {
                $fm[$listenfeld] = $this->normalizeTags($fm[$listenfeld]);
            }
        }

        return $fm;
    }

    /**
     * Minimaler, dependency-freier YAML-Frontmatter-Parser (Skalar-Keys +
     * Block-Sequenzen `- item`). Reicht für die geSyncten Research-/Domain-
     * Header; kein voller YAML-Support (bewusst schlank, keine Symfony-Yaml-
     * Abhängigkeit).
     *
     * @return array<string, string|list<string>>
     */
    private function parseFrontmatter(string $md): array
    {
        if (! preg_match('/\A\x{FEFF}?\s*---\R(.*?)\R---\s*(\R|$)/su', $md, $m)) {
            return [];
        }
        $fm = [];
        $listKey = null;
        foreach (preg_split('/\R/', $m[1]) as $line) {
            if ($listKey !== null && preg_match('/^\s*-\s+(.*\S)\s*$/', $line, $lm)) {
                $fm[$listKey][] = $this->frontmatterScalar($lm[1]);

                continue;
            }
            if (preg_match('/^([A-Za-z0-9_]+):\s*(.*)$/', $line, $km)) {
                $wert = trim($km[2]);
                if ($wert === '') {
                    $listKey = $km[1];
                    $fm[$listKey] = [];
                } else {
                    $fm[$km[1]] = $this->frontmatterScalar($wert);
                    $listKey = null;
                }
            }
        }

        return $fm;
    }

    /**
     * tags-Frontmatter auf eine Liste bringen — deckt Block-Sequenz (schon
     * Array), Flow-Sequenz `[a, b, c]` und Einzel-Skalar ab.
     *
     * @param  string|list<string>  $tags
     * @return list<string>
     */
    private function normalizeTags($tags): array
    {
        if (is_array($tags)) {
            return array_values(array_filter(array_map(fn ($t) => $this->frontmatterScalar((string) $t), $tags), fn ($t) => $t !== ''));
        }
        $s = trim((string) $tags);
        if ($s === '') {
            return [];
        }
        if (str_starts_with($s, '[') && str_ends_with($s, ']')) {
            $s = substr($s, 1, -1);
        }

        return array_values(array_filter(array_map(fn ($t) => $this->frontmatterScalar($t), explode(',', $s)), fn ($t) => $t !== ''));
    }

    private function frontmatterScalar(string $s): string
    {
        $s = trim($s);
        if (strlen($s) >= 2 && ($s[0] === '"' || $s[0] === "'") && $s[-1] === $s[0]) {
            $s = substr($s, 1, -1);
        }

        return trim($s);
    }

    /** Einzelnes Wissens-Dokument per Slug (aktiv, nicht gelöscht). */
    public function getDocument(string $slug): ?object
    {
        return DB::table('foodalchemist_knowledge_documents')
            ->where('slug', $slug)->where('active', 1)->whereNull('deleted_at')
            ->first(['slug', 'title', 'category', 'version', 'char_count', 'content_md']);
    }
}
