<?php

namespace Platform\FoodAlchemist\Services\Ai;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Platform\Core\Services\EmbeddingProviderRegistry;
use Platform\Core\Services\EmbeddingService;
use Throwable;

/**
 * Semantische Recall-Schicht ÜBER der deterministischen Lexik in
 * {@see KnowledgeContextService} — Hybrid, KEIN Ersatz.
 *
 * Warum:
 *  - Domain-Discovery + Pairing-Stem-Matching greifen heute rein lexikalisch
 *    (Alias-Map 258 Paare + Jaccard/Substring gegen Slug/Titel). Das bricht bei
 *    Synonymen, die nicht in der Alias-Map stehen ("Topinambur", "Erdapfel" …).
 *  - Diese Klasse embeddet den globalen Wissens-Korpus über Cores
 *    {@see EmbeddingService} (Commit 32b66074) und findet semantisch das passende
 *    Domain-/Pairing-Doc, WENN die Lexik dünn bleibt.
 *  - Der präzise Anker-Edge-Graph (foodalchemist_pairing_anker_edges) bleibt
 *    unangetastet: Semantik löst Freitext → Doc-/Stem-Slug auf, der Graph paart.
 *
 * Was wird embeddet (die Qualitäts-Stellschraube):
 *  - domain : Titel + Lead (erste ~2000 Zeichen) → Doc-Level-Relevanz reicht
 *             für Domain-Discovery.
 *  - pairing: Stem + die VERIFIZIERTEN Partner-NAMEN (über
 *             {@see KnowledgeContextService::extractPairingNames()}), NICHT die
 *             molekulare Prosa — die Zutaten-Oberfläche ist das, was zur
 *             Gericht-Beschreibung matchen soll.
 *  - cross_cutting wird NICHT indiziert (always-load, kein Discovery).
 *
 * Globaler Korpus: knowledge_documents.team_id ist NULL (BHG-kuratiert, D1).
 * Cores Store verlangt aber team_id:int — wir mappen NULL → Sentinel
 * (config foodalchemist.semantic_search.global_team_id, default 0). Gefahrlos,
 * weil core_embeddings.team_id nur ein indizierter bigint ist (kein FK).
 * → Offener Core-Wunsch an Martin: nativer Global-/Shared-Scope + global∪team-OR.
 *
 * Graceful Degradation: kein Embedding-Provider verfügbar (Sandbox ohne Key)
 * ⇒ alle Methoden no-op / leere Treffer ⇒ KnowledgeContextService fällt auf die
 * bestehende Lexik zurück. Niemals Fehler nach oben (GL-13 Invariante 6).
 */
class KnowledgeEmbeddingService
{
    /** Polymorpher entity_type im Core-Store. */
    public const ENTITY_TYPE = 'foodalchemist_knowledge_document';

    /** Kategorien mit Discovery-Bedarf — diese werden indiziert. */
    public const INDEXED_KATEGORIEN = ['domain', 'pairing'];

    /** Lead-Budget für Domain-Docs (Titel + erste N Zeichen). */
    private const DOMAIN_LEAD_CHARS = 2000;

    /** Max. Partner-Namen, die in den Pairing-Embedding-Text einfließen. */
    private const PAIRING_MAX_PARTNERS = 40;

    /**
     * Sentinel-Team für den globalen Korpus (NULL → diese ID).
     */
    public function globalTeamId(): int
    {
        return (int) config('foodalchemist.semantic_search.global_team_id', 0);
    }

    /**
     * Konfigurierter Provider-Name (null = Core-Default).
     */
    public function providerName(): ?string
    {
        $name = config('foodalchemist.semantic_search.provider');

        return is_string($name) && $name !== '' ? $name : null;
    }

    /**
     * Ist ein nutzbarer Embedding-Provider registriert + verfügbar?
     * Fängt jeden Fehler ab (Sandbox/Migrationen) → false.
     */
    public function isProviderAvailable(): bool
    {
        try {
            $registry = app(EmbeddingProviderRegistry::class);
            $name = $this->providerName();
            if ($name !== null) {
                $provider = $registry->get($name);

                return $provider !== null && $provider->isAvailable();
            }

            return $registry->getDefaultProvider() !== null;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Darf der semantische Fallback im Hot-Path verwendet werden?
     * = Config-Flag UND ein Provider verfügbar.
     */
    public function searchEnabled(): bool
    {
        return (bool) config('foodalchemist.semantic_search.enabled', false)
            && $this->isProviderAvailable();
    }

    /**
     * Indiziert den Wissens-Korpus (domain + pairing) im Core-Embedding-Store.
     * Idempotent über Cores source_hash (unveränderter Text ⇒ kein API-Call,
     * kein DB-Write). Global (team_id NULL) → Sentinel; team-eigene Docs unter
     * ihrer realen team_id.
     *
     * @param  list<string>  $kategorien
     * @return array{available: bool, candidates: int, kategorien: array<string,int>}
     */
    public function embedCorpus(array $kategorien = self::INDEXED_KATEGORIEN): array
    {
        if (! $this->isProviderAvailable()) {
            return ['available' => false, 'candidates' => 0, 'kategorien' => []];
        }

        $service = app(EmbeddingService::class);
        $providerName = $this->providerName();
        $globalTeam = $this->globalTeamId();

        $perKat = [];
        $candidates = 0;

        foreach ($kategorien as $kategorie) {
            $docs = DB::table('foodalchemist_knowledge_documents')
                ->where('kategorie', $kategorie)
                ->where('aktiv', 1)
                ->whereNull('deleted_at')
                ->get(['id', 'slug', 'titel', 'kategorie', 'inhalt_md', 'team_id']);

            // Nach Team-Partition gruppieren (global NULL → Sentinel).
            $byTeam = [];
            foreach ($docs as $doc) {
                $teamId = $doc->team_id === null ? $globalTeam : (int) $doc->team_id;
                $text = $this->embedText($doc);
                if ($text === '') {
                    continue;
                }
                $byTeam[$teamId][] = ['id' => (int) $doc->id, 'text' => $text];
            }

            foreach ($byTeam as $teamId => $entries) {
                $service->embedAndStoreBatch(
                    teamId: (int) $teamId,
                    entityType: self::ENTITY_TYPE,
                    entries: $entries,
                    providerName: $providerName,
                );
                $candidates += count($entries);
            }

            $perKat[$kategorie] = $docs->count();
        }

        return ['available' => true, 'candidates' => $candidates, 'kategorien' => $perKat];
    }

    /**
     * Semantische Suche → Liste passender knowledge_documents-Slugs der
     * gewünschten Kategorie(n), bestes Match zuerst. Leeres Ergebnis bei
     * fehlendem Provider / Fehler (GL-13 Invariante 6).
     *
     * @param  list<string>  $kategorien
     * @return list<string>  Slugs — für 'pairing' OHNE "pairing."-Präfix (= Stem,
     *                        konsistent mit KnowledgeContextService::pairingStems()).
     */
    public function searchSlugs(string $query, array $kategorien, int $limit = 4, ?float $minScore = null): array
    {
        $query = trim($query);
        if ($query === '' || $limit <= 0 || ! $this->isProviderAvailable()) {
            return [];
        }
        $minScore ??= (float) config('foodalchemist.semantic_search.min_score', 0.30);

        try {
            $hits = app(EmbeddingService::class)->search(
                teamId: $this->globalTeamId(),
                queryText: $query,
                entityTypes: [self::ENTITY_TYPE],
                limit: $limit * 3,              // Überhang für den Kategorie-Filter
                minScore: $minScore,
                providerName: $this->providerName(),
            );
        } catch (Throwable $e) {
            Log::warning('[KnowledgeEmbeddingService] search failed', ['error' => $e->getMessage()]);

            return [];
        }

        if ($hits === []) {
            return [];
        }

        // entity_id → Doc (Slug + Kategorie) auflösen und auf Kategorie filtern.
        $ids = array_map(static fn ($h) => (int) $h['entity_id'], $hits);
        $docs = DB::table('foodalchemist_knowledge_documents')
            ->whereIn('id', $ids)
            ->whereIn('kategorie', $kategorien)
            ->where('aktiv', 1)
            ->whereNull('deleted_at')
            ->get(['id', 'slug', 'kategorie'])
            ->keyBy('id');

        $slugs = [];
        foreach ($hits as $hit) {                                   // bereits nach Score sortiert
            $doc = $docs->get((int) $hit['entity_id']);
            if ($doc === null) {
                continue;
            }
            $slug = $doc->slug;
            if ($doc->kategorie === 'pairing' && str_starts_with($slug, 'pairing.')) {
                $slug = substr($slug, 8);                           // Stem-Form
            }
            $slugs[$slug] = true;
            if (count($slugs) >= $limit) {
                break;
            }
        }

        return array_map('strval', array_keys($slugs));
    }

    /**
     * Baut den zu embeddenden Text je Kategorie (die Qualitäts-Stellschraube).
     */
    private function embedText(object $doc): string
    {
        $titel = trim((string) ($doc->titel ?? ''));
        $inhalt = (string) ($doc->inhalt_md ?? '');

        if (($doc->kategorie ?? '') === 'pairing') {
            $slug = (string) ($doc->slug ?? '');
            $stem = str_starts_with($slug, 'pairing.') ? substr($slug, 8) : $slug;
            $surface = str_replace('_', ' ', $stem);

            $names = (new KnowledgeContextService())->extractPairingNames($inhalt);
            if ($names !== []) {
                $surface .= ': ' . implode(', ', array_slice($names, 0, self::PAIRING_MAX_PARTNERS));
            }

            return $titel !== '' ? $titel . ' — ' . $surface : $surface;
        }

        // domain (+ Fallback): Titel-gewichtet + Lead.
        return trim($titel . "\n\n" . mb_substr($inhalt, 0, self::DOMAIN_LEAD_CHARS));
    }
}
