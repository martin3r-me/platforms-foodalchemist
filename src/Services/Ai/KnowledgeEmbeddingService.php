<?php

namespace Platform\FoodAlchemist\Services\Ai;

use Illuminate\Support\Facades\Auth;
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
 *  - Der präzise Anker-Edge-Graph (foodalchemist_pairing_anchor_edges) bleibt
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

    /** entity_type für das Anker-Vokabular (semantische Anker-Auflösung, B). */
    public const ENTITY_TYPE_ANKER = 'foodalchemist_pairing_anker';

    /**
     * Kategorien mit Discovery-Bedarf im KI-Hot-Path (semanticSlugs).
     * Der Embedding-LAUF (embedCorpus) indiziert dagegen per Default ALLE aktiven
     * Kategorien — die manuelle Browser-Semantiksuche (#469) durchsucht den
     * ganzen Korpus, nicht nur Discovery-Kategorien.
     */
    public const INDEXED_KATEGORIEN = ['domain', 'pairing'];

    /**
     * Lead-Budget für Domain-Docs (Titel + erste N Zeichen).
     *
     * W1-1 WURDE GEMESSEN UND VERWORFEN (2026-09-03). Der Plan wollte 2000 → 8000 heben
     * („nur 52 % des Korpus semantisch findbar"). Diese Zahl war eine ZEICHEN-Rechnung.
     * Gemessen mit `foodalchemist:wissen-recall-probe --team=6` (dieselben Fragen, nur der
     * Index dazwischen neu):
     *
     *                                   Fenster 2000    Fenster 8000
     *   Anfragen aus dem KOPF              92 %            92 %
     *   Anfragen JENSEITS von 2000         72 %            68 %
     *
     * Der Inhalt war bei 8000 nachweislich IM Vektor (source_hash gegengeprüft) — und wurde
     * trotzdem nicht besser gefunden, eher minimal schlechter. Grund ist die Verdünnung:
     * ein Vektor über 8000 Zeichen ist ein Mittelwert über mehr Inhalt, die Themen-Signatur
     * dominiert, und die Konkurrenz zwischen den Dokumenten steigt.
     *
     * Zwei Schlüsse, die für Welle 1 wichtiger sind als der Wert selbst:
     *  1. Inhalt jenseits des Fensters ist NICHT unsichtbar (72 %, nicht 0) — die
     *     52-%-Rechnung hat das Problem stark überzeichnet.
     *  2. Mehr Text pro Vektor ist KEIN Ersatz für Chunking. Abdeckung ohne Schärfe bringt
     *     nichts; W1-5 (ein Vektor je Abschnitt, mit heading_path) bleibt der echte Fix.
     *
     * Darum wieder 2000: kostet ein Viertel des Embedding-Textes und liefert dasselbe.
     * Wer den Wert erneut anfassen will, fährt bitte ZUERST den Probe — die Messung ist da.
     */
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
     * Alle aktiven, nicht-leeren Kategorien mit indizierbaren Docs (Default-Scope
     * des Embedding-Laufs). Für die Browser-Volltext-Semantiksuche über den
     * gesamten Korpus (#469), nicht nur die Discovery-Kategorien.
     *
     * @return list<string>
     */
    public function indexableKategorien(): array
    {
        return DB::table('foodalchemist_knowledge_documents')
            ->where('active', 1)
            ->whereNull('deleted_at')
            ->whereNotNull('category')
            ->where('category', '!=', '')
            ->distinct()
            ->orderBy('category')
            ->pluck('category')
            ->map(static fn ($c) => (string) $c)
            ->all();
    }

    /**
     * Indiziert den Wissens-Korpus im Core-Embedding-Store. Default = ALLE aktiven
     * Kategorien (Browser-Semantiksuche über den ganzen Korpus). Idempotent über
     * Cores source_hash (unveränderter Text ⇒ kein API-Call, kein DB-Write).
     * Global (team_id NULL) → Sentinel; team-eigene Docs unter ihrer realen team_id.
     *
     * @param  list<string>|null  $kategorien  null = alle indizierbaren Kategorien
     * @return array{available: bool, candidates: int, kategorien: array<string,int>}
     */
    public function embedCorpus(?array $kategorien = null, bool $purge = false): array
    {
        if (! $this->isProviderAvailable()) {
            return ['available' => false, 'candidates' => 0, 'kategorien' => []];
        }

        $kategorien ??= $this->indexableKategorien();

        $service = app(EmbeddingService::class);
        $providerName = $this->providerName();
        $globalTeam = $this->globalTeamId();

        $perKat = [];
        $candidates = 0;

        foreach ($kategorien as $kategorie) {
            $docs = DB::table('foodalchemist_knowledge_documents')
                ->where('category', $kategorie)
                ->where('active', 1)
                ->whereNull('deleted_at')
                ->get(['id', 'slug', 'title', 'category', 'content_md', 'team_id']);

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

        $result = ['available' => true, 'candidates' => $candidates, 'kategorien' => $perKat];
        if ($purge) {
            // A2: verwaiste Vektoren (deaktivierte + historische Waisen) entfernen. Bewusst NUR bei
            // ausdrücklichem --purge — der Probe-Delete ist zahlreich (off-peak, nicht jeder Backfill).
            $result['purge'] = $this->purgeStale(true);
        }

        return $result;
    }

    // ── Inkrementell (Service-/UI-Pfad, kein Eloquent-Observer) ──────────────

    /**
     * Partition-Team eines Docs: NULL (global/BHG-kuratiert) → Sentinel, sonst reale ID.
     * Identisch zu {@see PoolEmbeddingService::partitionTeamId} — der Store verlangt int.
     */
    public function partitionTeamId(int|string|null $teamId): int
    {
        return $teamId === null ? $this->globalTeamId() : (int) $teamId;
    }

    /**
     * Inkrementelles Re-Embed EINES Wissens-Dokuments (Service-/UI-Pfad). Ein Eloquent-
     * Observer ist unmöglich: {@see \Platform\FoodAlchemist\Services\KnowledgeService} und der
     * Knowledge-Browser schreiben per DB::table (kein Model-Event). Darum ruft der Schreib-Pfad
     * diese Methode explizit. Async über die Queue ({@see EmbeddingService::queueEmbedAndStore}
     * → GenerateEmbeddingJob), source_hash-idempotent — Spiegel von PoolEmbeddingService::queueGp.
     *
     * Quarantäne-Invariante (identisch zum active=1-Filter in {@see embedCorpus}): NUR aktive,
     * nicht gelöschte Docs gehören in den Recall-Pool. Inaktiv/soft-deleted ⇒ Vektor LÖSCHEN statt
     * embedden — so decken Aktivieren (embed) UND Deaktivieren (purge) denselben Aufruf ab. Der
     * Doc-Parameter ist eine volle Zeile (KnowledgeService::find / Browser DB::first). No-op ohne
     * Provider (Sandbox) — nie Fehler nach oben (GL-13 Invariante 6).
     */
    public function queueDocument(object $doc): void
    {
        if (! $this->isProviderAvailable()) {
            return;
        }
        $active = (int) ($doc->active ?? 0) === 1 && ($doc->deleted_at ?? null) === null;
        if (! $active) {
            $this->deleteDocument((int) $doc->id, $doc->team_id ?? null);

            return;
        }
        $text = $this->embedText($doc);
        if ($text === '') {
            return;
        }
        app(EmbeddingService::class)->queueEmbedAndStore(
            teamId: $this->partitionTeamId($doc->team_id ?? null),
            entityType: self::ENTITY_TYPE,
            entityId: (int) $doc->id,
            text: $text,
            providerName: $this->providerName(),
        );
    }

    /** Löscht den Doc-Vektor (Deaktivierung/Hard-Delete). Fehler-tolerant (GL-13 Invariante 6). */
    public function deleteDocument(int $id, int|string|null $rawTeamId = null): void
    {
        try {
            app(EmbeddingService::class)->delete($this->partitionTeamId($rawTeamId), self::ENTITY_TYPE, $id);
        } catch (Throwable $e) {
            Log::warning('[KnowledgeEmbeddingService] delete failed', [
                'entity_type' => self::ENTITY_TYPE, 'id' => $id, 'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * A2 — Waisen-Purge des Wissens-Index: entfernt Vektoren, die zu keinem AKTIVEN Doc
     * (mehr) gehören. Der Soll-Zustand des Index = exakt die aktiven, nicht gelöschten Docs
     * (identisch zum active=1-Filter in {@see embedCorpus}). Zwei Klassen von Stale:
     *   (1) im Bestand, aber inaktiv/soft-deleted — enumerierbar, immer bereinigt.
     *   (2) historische Waisen, deren Doc-ID gar nicht mehr in der Tabelle steht (Re-Import gab
     *       neue IDs, alte nie gepurged). Nur per Probe über [1..maxId] erreichbar → nur bei $deep.
     *
     * Warum Probe statt gezielt: der Core-Store-Contract bietet KEINE Enumeration
     * (store/search/delete/getSourceHash/purgeProvider). Ein Store-Delete einer nicht
     * vorhandenen ID ist ein gefahrloser No-op (Qdrant/MySQL) → der Probe-Ansatz ist sicher,
     * nur zahlreich (einmaliger Maintenance-Lauf, off-peak). Die Partition einer verschwundenen
     * ID ist unbekannt → jede aktive Partition + der Sentinel werden probiert (Fehl-Partition = No-op).
     *
     * OFFENER CORE-WUNSCH (Martin): ein entityIds(teamId, entityType) (Qdrant-Scroll / MySQL-DISTINCT)
     * würde (2) gezielt statt per Probe lösen — dann fällt der Brute-Force weg.
     *
     * @return array{available: bool, deleted: int, probed: int}
     */
    public function purgeStale(bool $deep = false): array
    {
        if (! $this->isProviderAvailable()) {
            return ['available' => false, 'deleted' => 0, 'probed' => 0];
        }

        $service = app(EmbeddingService::class);

        // Aktive Doc-IDs je Partition (global NULL → Sentinel) = der Soll-Index.
        $liveByPartition = [];
        foreach (DB::table('foodalchemist_knowledge_documents')
            ->where('active', 1)->whereNull('deleted_at')->get(['id', 'team_id']) as $r) {
            $liveByPartition[$this->partitionTeamId($r->team_id)][(int) $r->id] = true;
        }

        // (1) Enumerierbare Stale: Docs, die es noch gibt, aber NICHT (aktiv & nicht gelöscht) sind.
        $deleted = 0;
        foreach (DB::table('foodalchemist_knowledge_documents')
            ->where(fn ($q) => $q->where('active', '!=', 1)->orWhereNotNull('deleted_at'))
            ->get(['id', 'team_id']) as $r) {
            $this->safeStoreDelete($service, $this->partitionTeamId($r->team_id), (int) $r->id);
            $deleted++;
        }

        // (2) Historische Waisen: IDs, die gar nicht mehr in der Tabelle stehen. Probe je Partition.
        $probed = 0;
        if ($deep) {
            $maxId = (int) DB::table('foodalchemist_knowledge_documents')->max('id');
            $existing = DB::table('foodalchemist_knowledge_documents')->pluck('id')
                ->mapWithKeys(fn ($i) => [(int) $i => true])->all();
            $partitions = array_keys($liveByPartition);
            $sentinel = $this->globalTeamId();
            if (! in_array($sentinel, $partitions, true)) {
                $partitions[] = $sentinel;   // historische globale Waisen liegen im Sentinel
            }
            foreach ($partitions as $partition) {
                $live = $liveByPartition[$partition] ?? [];
                for ($id = 1; $id <= $maxId; $id++) {
                    if (isset($live[$id]) || isset($existing[$id])) {
                        continue;   // aktiv (behalten) oder schon in (1) behandelt
                    }
                    $this->safeStoreDelete($service, $partition, $id);
                    $probed++;
                }
            }
        }

        Log::info('[KnowledgeEmbeddingService] purgeStale', ['deep' => $deep, 'deleted' => $deleted, 'probed' => $probed]);

        return ['available' => true, 'deleted' => $deleted, 'probed' => $probed];
    }

    /** Store-Delete eines einzelnen Vektors, fehler-tolerant (ein fehlender Punkt ist ein No-op). */
    private function safeStoreDelete(EmbeddingService $service, int $teamId, int $id): void
    {
        try {
            $service->delete($teamId, self::ENTITY_TYPE, $id);
        } catch (Throwable $e) {
            Log::warning('[KnowledgeEmbeddingService] purge delete failed', [
                'team' => $teamId, 'id' => $id, 'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Indiziert das Anker-Vokabular (für die semantische Anker-Auflösung, B).
     * Text = display_de + Slug-Worte. 'neutral' wird ausgelassen (kein Aroma).
     *
     * @return array{available: bool, candidates: int}
     */
    public function embedAnkers(): array
    {
        if (! $this->isProviderAvailable()) {
            return ['available' => false, 'candidates' => 0];
        }

        $service = app(EmbeddingService::class);
        $providerName = $this->providerName();
        $globalTeam = $this->globalTeamId();

        $rows = DB::table('foodalchemist_vocab_pairing_anchors')
            ->whereNull('deleted_at')
            ->where('slug', '!=', 'neutral')
            ->get(['id', 'slug', 'display_de', 'team_id']);

        $byTeam = [];
        foreach ($rows as $r) {
            $teamId = $r->team_id === null ? $globalTeam : (int) $r->team_id;
            $text = trim((string) $r->display_de . ' ' . str_replace('_', ' ', (string) $r->slug));
            if ($text === '') {
                continue;
            }
            $byTeam[$teamId][] = ['id' => (int) $r->id, 'text' => $text];
        }

        $count = 0;
        foreach ($byTeam as $teamId => $entries) {
            $service->embedAndStoreBatch(
                teamId: (int) $teamId,
                entityType: self::ENTITY_TYPE_ANKER,
                entries: $entries,
                providerName: $providerName,
            );
            $count += count($entries);
        }

        return ['available' => true, 'candidates' => $count];
    }

    /**
     * Semantische Anker-Auflösung (B): Freitext → Anker-ID des besten Treffers
     * über der Konfidenz-Schwelle (anker_min_score, höher als die Doc-Suche, weil
     * eine FALSCHE Auflösung schädlicher ist als gar keine). null bei kein
     * Treffer / kein Provider / Fehler.
     */
    public function resolveAnkerId(string $name, ?float $minScore = null): ?int
    {
        return $this->resolveAnkerWithScore($name, $minScore)['id'] ?? null;
    }

    /**
     * Wie resolveAnkerId, aber mit Konfidenz: Anker-ID + Score des besten
     * Treffers über der Schwelle. Für die hybride Namens-Auflösung, die den
     * Match-Weg transparent machen will (analog gps.SEARCH semantic_score).
     * null bei kein Treffer / kein Provider / Fehler.
     *
     * @return array{id: int, score: float}|null
     */
    public function resolveAnkerWithScore(string $name, ?float $minScore = null): ?array
    {
        $name = trim($name);
        if ($name === '' || ! $this->isProviderAvailable()) {
            return null;
        }
        $minScore ??= (float) config('foodalchemist.semantic_search.anker_min_score', 0.55);

        $hits = $this->searchMerged($name, self::ENTITY_TYPE_ANKER, 1, $minScore);

        if (! isset($hits[0])) {
            return null;
        }

        return ['id' => (int) $hits[0]['entity_id'], 'score' => (float) $hits[0]['score']];
    }

    /**
     * Semantische Anker-Auflösung, Mehrfach-Variante: Freitext → Liste passender Anker-Slugs
     * (bestes zuerst). Ersetzt für die Pairing-Erdung die frühere Doc-basierte
     * searchSlugs(['pairing']) — die Token→Anker-Brücke lebt jetzt am Anker-Graphen
     * (embedAnkers), damit die Pairing-Docs aufgeräumt werden können, ohne den semantischen
     * Recall zu verlieren. Leeres Ergebnis bei fehlendem Provider / Fehler (GL-13 Invariante 6).
     *
     * @return list<string>
     */
    public function searchAnkerSlugs(string $query, int $limit = 4, ?float $minScore = null): array
    {
        $query = trim($query);
        if ($query === '' || $limit <= 0 || ! $this->isProviderAvailable()) {
            return [];
        }
        $minScore ??= (float) config('foodalchemist.semantic_search.min_score', 0.30);

        $hits = $this->searchMerged($query, self::ENTITY_TYPE_ANKER, $limit, $minScore);
        if ($hits === []) {
            return [];
        }

        // entity_id → Anker-Slug auflösen, Score-Reihenfolge erhalten.
        $ids = array_map(static fn ($h) => (int) $h['entity_id'], $hits);
        $slugs = DB::table('foodalchemist_vocab_pairing_anchors')
            ->whereIn('id', $ids)->whereNull('deleted_at')->pluck('slug', 'id');
        $out = [];
        foreach ($hits as $h) {
            $slug = $slugs->get((int) $h['entity_id']);
            if ($slug !== null) {
                $out[$slug] = true;
            }
        }

        return array_keys($out);
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

        $hits = $this->searchMerged($query, self::ENTITY_TYPE, $limit * 3, $minScore);

        if ($hits === []) {
            return [];
        }

        // entity_id → Doc (Slug + Kategorie) auflösen und auf Kategorie filtern.
        $ids = array_map(static fn ($h) => (int) $h['entity_id'], $hits);
        $docs = DB::table('foodalchemist_knowledge_documents')
            ->whereIn('id', $ids)
            ->whereIn('category', $kategorien)
            ->where('active', 1)
            ->whereNull('deleted_at')
            ->get(['id', 'slug', 'category'])
            ->keyBy('id');

        $slugs = [];
        foreach ($hits as $hit) {                                   // bereits nach Score sortiert
            $doc = $docs->get((int) $hit['entity_id']);
            if ($doc === null) {
                continue;
            }
            $slug = $doc->slug;
            if ($doc->category === 'pairing' && str_starts_with($slug, 'pairing.')) {
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
     * Manuelle Browser-Semantiksuche (#469): Freitext → geordnete Liste von
     * knowledge_documents-IDs über den gesamten indizierten Korpus (bestes Match
     * zuerst), OHNE Kategorie-Filter — der Browser filtert selbst nach Kategorie/
     * Status. Leeres Ergebnis bei fehlendem Provider / leerer Query / Fehler
     * (GL-13 Invariante 6: nie Fehler nach oben).
     *
     * @return list<int>  Doc-IDs, Score-sortiert
     */
    public function searchDocIds(string $query, int $limit = 50, ?float $minScore = null): array
    {
        $query = trim($query);
        if ($query === '' || $limit <= 0 || ! $this->isProviderAvailable()) {
            return [];
        }
        $minScore ??= (float) config('foodalchemist.semantic_search.min_score', 0.30);

        $hits = $this->searchMerged($query, self::ENTITY_TYPE, $limit, $minScore);

        return array_values(array_map(static fn ($h) => (int) $h['entity_id'], $hits));
    }

    /**
     * Sichtbare Suchpartitionen für die Vokabular-/Doc-Lookups: die Ahnenkette des
     * aktuellen Teams ∪ Global-Sentinel — identisch zu
     * {@see SemanticRetrievalService::partitionsFor}. Ohne Auth-Team (Konsole/Job
     * ohne Team-Kontext) bleibt es der reine Sentinel (= bisheriges Verhalten).
     *
     * @return list<int>
     */
    private function searchPartitions(): array
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null) {
            return [$this->globalTeamId()];
        }

        return app(SemanticRetrievalService::class)->partitionsFor($team);
    }

    /**
     * Semantische Suche über {@see searchPartitions()} statt nur im Sentinel:
     * je Partition suchen, dedupe je entity_id mit max. Score, Score-absteigend,
     * auf $limit gekappt. Spiegelt {@see SemanticRetrievalService::candidates} für
     * die Anker-/Doc-Auflösung — team-eigene Anker/Docs (unter der realen team_id)
     * werden gefunden, nicht nur der Sentinel-Korpus. Nie Fehler nach oben
     * (GL-13 Invariante 6).
     *
     * @return list<array{entity_id: int, score: float}>
     */
    private function searchMerged(string $query, string $entityType, int $limit, float $minScore): array
    {
        $service = app(EmbeddingService::class);
        $best = [];   // entity_id => max. Score
        foreach ($this->searchPartitions() as $partition) {
            try {
                $hits = $service->search(
                    teamId: $partition,
                    queryText: $query,
                    entityTypes: [$entityType],
                    limit: $limit,
                    minScore: $minScore,
                    providerName: $this->providerName(),
                );
            } catch (Throwable $e) {
                Log::warning('[KnowledgeEmbeddingService] partition search failed', [
                    'team' => $partition,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }
            foreach ($hits as $hit) {
                $id = (int) $hit['entity_id'];
                $score = (float) ($hit['score'] ?? 0.0);
                if (! isset($best[$id]) || $score > $best[$id]) {
                    $best[$id] = $score;
                }
            }
        }
        if ($best === []) {
            return [];
        }
        arsort($best);
        $out = [];
        foreach ($best as $id => $score) {
            $out[] = ['entity_id' => $id, 'score' => $score];
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * Baut den zu embeddenden Text je Kategorie (die Qualitäts-Stellschraube).
     */
    private function embedText(object $doc): string
    {
        $titel = trim((string) ($doc->title ?? ''));
        $inhalt = (string) ($doc->content_md ?? '');

        if (($doc->category ?? '') === 'pairing') {
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
