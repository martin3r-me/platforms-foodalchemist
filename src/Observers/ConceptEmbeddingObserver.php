<?php

namespace Platform\FoodAlchemist\Observers;

use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Services\Ai\PoolEmbeddingService;

/**
 * Spec 15 §5a: hält den Konzept-Embedding-Vektor bei Einzeledits synchron
 * (Bulk = foodalchemist:embed --pool=concepts). No-op ohne Provider; nur bei
 * Änderung embed-relevanter Felder re-queuen (kein Churn bei Preis-/Cache-Writes).
 */
class ConceptEmbeddingObserver
{
    private const RELEVANT = ['name', 'consumer_name', 'occasion', 'season', 'target_group', 'description'];

    public function created(FoodAlchemistConcept $c): void
    {
        app(PoolEmbeddingService::class)->queueConcept($c);
    }

    public function updated(FoodAlchemistConcept $c): void
    {
        if (! $c->wasChanged(self::RELEVANT)) {
            return;
        }
        app(PoolEmbeddingService::class)->queueConcept($c);
    }

    public function deleted(FoodAlchemistConcept $c): void
    {
        app(PoolEmbeddingService::class)->deleteConcept((int) $c->id, $c->team_id);
    }
}
