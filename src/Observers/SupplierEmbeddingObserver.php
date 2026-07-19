<?php

namespace Platform\FoodAlchemist\Observers;

use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Services\Ai\PoolEmbeddingService;

/**
 * Spec 15 §5a: hält den Lieferanten-Embedding-Vektor bei Einzeledits synchron
 * (Bulk = foodalchemist:embed --pool=suppliers). No-op ohne Provider; nur bei
 * Änderung embed-relevanter Felder re-queuen (kein Churn bei Adress-/Konditions-Writes).
 */
class SupplierEmbeddingObserver
{
    private const RELEVANT = ['name', 'branch', 'city', 'is_inactive'];

    public function created(FoodAlchemistSupplier $s): void
    {
        app(PoolEmbeddingService::class)->queueSupplier($s);
    }

    public function updated(FoodAlchemistSupplier $s): void
    {
        if (! $s->wasChanged(self::RELEVANT)) {
            return;
        }
        app(PoolEmbeddingService::class)->queueSupplier($s);
    }

    public function deleted(FoodAlchemistSupplier $s): void
    {
        app(PoolEmbeddingService::class)->deleteSupplier((int) $s->id, $s->team_id);
    }
}
