<?php

namespace Platform\FoodAlchemist\Observers;

use Platform\FoodAlchemist\Models\FoodAlchemistSpeisekarte;
use Platform\FoodAlchemist\Services\Ai\PoolEmbeddingService;

/**
 * Ausbau (b): hält den Speisekarte-Embedding-Vektor bei Einzeledits synchron
 * (Bulk = foodalchemist:embed --pool=speisekarten). No-op ohne Provider; nur bei
 * Änderung embed-relevanter Felder re-queuen.
 */
class SpeisekarteEmbeddingObserver
{
    private const RELEVANT = ['name', 'description', 'karten_typ'];

    public function created(FoodAlchemistSpeisekarte $m): void
    {
        app(PoolEmbeddingService::class)->queueSpeisekarte($m);
    }

    public function updated(FoodAlchemistSpeisekarte $m): void
    {
        if (! $m->wasChanged(self::RELEVANT)) {
            return;
        }
        app(PoolEmbeddingService::class)->queueSpeisekarte($m);
    }

    public function deleted(FoodAlchemistSpeisekarte $m): void
    {
        app(PoolEmbeddingService::class)->deleteSpeisekarte((int) $m->id, $m->team_id);
    }
}
