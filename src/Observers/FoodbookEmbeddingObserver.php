<?php

namespace Platform\FoodAlchemist\Observers;

use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Services\Ai\PoolEmbeddingService;

/**
 * Spec 15 §5a: hält den Foodbook-Embedding-Vektor bei Einzeledits synchron
 * (Bulk = foodalchemist:embed --pool=foodbooks). No-op ohne Provider; nur bei
 * Änderung embed-relevanter Felder re-queuen.
 */
class FoodbookEmbeddingObserver
{
    private const RELEVANT = ['label', 'customer', 'description'];

    public function created(FoodAlchemistFoodbook $f): void
    {
        app(PoolEmbeddingService::class)->queueFoodbook($f);
    }

    public function updated(FoodAlchemistFoodbook $f): void
    {
        if (! $f->wasChanged(self::RELEVANT)) {
            return;
        }
        app(PoolEmbeddingService::class)->queueFoodbook($f);
    }

    public function deleted(FoodAlchemistFoodbook $f): void
    {
        app(PoolEmbeddingService::class)->deleteFoodbook((int) $f->id, $f->team_id);
    }
}
