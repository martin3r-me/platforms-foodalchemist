<?php

namespace Platform\FoodAlchemist\Observers;

use Platform\FoodAlchemist\Models\FoodAlchemistFormat;
use Platform\FoodAlchemist\Services\Ai\PoolEmbeddingService;

/**
 * Ausbau (b): hält den Format-Embedding-Vektor bei Einzeledits synchron
 * (Bulk = foodalchemist:embed --pool=formate). No-op ohne Provider; nur bei
 * Änderung embed-relevanter Felder re-queuen.
 */
class FormatEmbeddingObserver
{
    private const RELEVANT = ['name', 'consumer_name', 'claim', 'story', 'origin'];

    public function created(FoodAlchemistFormat $m): void
    {
        app(PoolEmbeddingService::class)->queueFormat($m);
    }

    public function updated(FoodAlchemistFormat $m): void
    {
        if (! $m->wasChanged(self::RELEVANT)) {
            return;
        }
        app(PoolEmbeddingService::class)->queueFormat($m);
    }

    public function deleted(FoodAlchemistFormat $m): void
    {
        app(PoolEmbeddingService::class)->deleteFormat((int) $m->id, $m->team_id);
    }
}
