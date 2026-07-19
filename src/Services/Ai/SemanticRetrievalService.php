<?php

namespace Platform\FoodAlchemist\Services\Ai;

use Platform\Core\Contracts\EmbeddingStoreContract;
use Platform\Core\Services\EmbeddingProviderRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\Core\Models\Team;
use Throwable;

/**
 * E2 (#507): Hybrider Retrieval-Layer über den GP-/Rezept-Pools (E1) — der
 * PHP-Port des V-04-Embedding-Passes aus GL-04 §6.1 (Referenz-App
 * build_inventory_bausteine / semantic_candidates).
 *
 * ROLLE (Invariante, DoD): reine Recall-/Shortlist-Schicht. Diese Klasse liefert
 * KANDIDATEN, nie ein Urteil. Der deterministische Matcher
 * ({@see IngredientMatchService}) und die LLM-Disambiguierung bleiben die
 * Entscheider; Anti-Marker/Schwellen liegen NACH dieser Schicht. Nie finaler
 * Ranker, nie für Pairing/Kontrast.
 *
 * Partition-Merge (Entscheid B — kein Core-Change): Cores EmbeddingService.search
 * nimmt genau EIN team_id. FA-GPs/Rezepte sind master-vererbt (Ahnenkette ∪
 * Global-Sentinel). Deshalb suchen wir je Partition und mergen score-basiert
 * modulseitig. Perf: Query wird EINMAL embeddet (nicht je Partition), dann läuft
 * die Vektor-Suche je Partition über Cores Store-Contract.
 *
 * Graceful Degradation (GL-13 Invariante 6): Flag aus / kein Provider / Fehler
 * ⇒ leere Treffer, NIE Fehler nach oben ⇒ der Aufrufer bleibt rein lexikalisch.
 */
class SemanticRetrievalService
{
    /** Ist der Hybrid-Recall scharf (Flag + verfügbarer Provider)? */
    public function enabled(): bool
    {
        if (! (bool) config('foodalchemist.semantic_search.enabled', false)) {
            return false;
        }

        return $this->providerOrNull() !== null;
    }

    public function poolSemFloor(): float
    {
        return (float) config('foodalchemist.semantic_search.pool_sem_floor', 0.55);
    }

    /**
     * Team-Partitionen für die Merge-Suche: eigene Ahnenkette (eigenes Team +
     * Master-Kette) ∪ optionale explizite Master-ID ∪ Global-Sentinel.
     *
     * @return list<int>
     */
    public function partitionsFor(Team $team): array
    {
        $ids = FoodAlchemistGp::teamAncestryIds($team);
        $master = config('foodalchemist.semantic_search.master_team_id');
        if ($master !== null) {
            $ids[] = (int) $master;
        }
        $ids[] = (int) config('foodalchemist.semantic_search.global_team_id', 0);

        return array_values(array_unique(array_map('intval', $ids)));
    }

    /**
     * Semantischer Pass: Freitext → Kandidaten über die Pools, gemerged über alle
     * sichtbaren Partitionen (dedupe je (entity_type, entity_id), max. Score).
     *
     * @param  list<string>  $entityTypes  z.B. [ENTITY_TYPE_GP, ENTITY_TYPE_RECIPE]
     * @return list<array{entity_type: string, entity_id: int, score: float, metadata: ?array}>
     */
    public function candidates(Team $team, string $queryText, array $entityTypes, int $limit = 15, ?float $floor = null): array
    {
        $queryText = trim($queryText);
        if ($queryText === '' || $entityTypes === [] || $limit <= 0 || ! $this->enabled()) {
            return [];
        }
        $floor ??= $this->poolSemFloor();

        try {
            $provider = $this->providerOrNull();
            if ($provider === null) {
                return [];
            }
            // Query EINMAL embedden (nicht je Partition) → dann Store-Suche je Partition.
            // Symmetrisch zum Ziel-Embed-Text normalisieren (gleicher Vektorraum,
            // sonst kein trennbarer Floor — E5-Eichung 2026-07-19).
            $vectors = $provider->embed([PoolEmbeddingService::normalizeForEmbedding($queryText)], 'query');
            if ($vectors === [] || ! isset($vectors[0])) {
                return [];
            }
            $vector = $vectors[0];
            $store = app(EmbeddingStoreContract::class);

            $best = [];   // "type\0id" => hit (max Score)
            foreach ($this->partitionsFor($team) as $partition) {
                $hits = $store->search(
                    teamId: $partition,
                    queryVector: $vector,
                    provider: $provider->getName(),
                    model: $provider->getModel(),
                    entityTypes: $entityTypes,
                    limit: $limit,
                    minScore: $floor,
                );
                foreach ($hits as $hit) {
                    $type = (string) $hit['entity_type'];
                    $id = (int) $hit['entity_id'];
                    $score = (float) $hit['score'];
                    $key = $type . "\0" . $id;
                    if (! isset($best[$key]) || $score > $best[$key]['score']) {
                        $best[$key] = [
                            'entity_type' => $type,
                            'entity_id' => $id,
                            'score' => $score,
                            'metadata' => $hit['metadata'] ?? null,
                        ];
                    }
                }
            }
        } catch (Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[SemanticRetrievalService] candidates failed', ['error' => $e->getMessage()]);

            return [];
        }

        $out = array_values($best);
        usort($out, static fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($out, 0, $limit);
    }

    /** Konfigurierter Provider oder null (fängt jeden Registry-Fehler ab). */
    private function providerOrNull(): ?\Platform\Core\Contracts\EmbeddingProviderContract
    {
        try {
            $registry = app(EmbeddingProviderRegistry::class);
            $name = config('foodalchemist.semantic_search.provider');
            $name = is_string($name) && $name !== '' ? $name : null;
            $provider = $name !== null ? $registry->get($name) : $registry->getDefaultProvider();

            return ($provider !== null && $provider->isAvailable()) ? $provider : null;
        } catch (Throwable) {
            return null;
        }
    }
}
