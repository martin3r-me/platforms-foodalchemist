<?php

namespace Platform\FoodAlchemist\Observers;

use Platform\FoodAlchemist\Models\FoodAlchemistPaket;
use Platform\FoodAlchemist\Services\Ai\PoolEmbeddingService;

/**
 * Ausbau (b): hält den Paket-Embedding-Vektor bei Einzeledits synchron
 * (Bulk = foodalchemist:embed --pool=pakete). No-op ohne Provider; nur bei
 * Änderung embed-relevanter Felder re-queuen.
 */
class PaketEmbeddingObserver
{
    private const RELEVANT = ['name', 'consumer_name', 'role', 'description'];

    public function created(FoodAlchemistPaket $m): void
    {
        app(PoolEmbeddingService::class)->queuePaket($m);
    }

    public function updated(FoodAlchemistPaket $m): void
    {
        if (! $m->wasChanged(self::RELEVANT)) {
            return;
        }
        app(PoolEmbeddingService::class)->queuePaket($m);
    }

    public function deleted(FoodAlchemistPaket $m): void
    {
        app(PoolEmbeddingService::class)->deletePaket((int) $m->id, $m->team_id);
    }
}
