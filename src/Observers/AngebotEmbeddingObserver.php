<?php

namespace Platform\FoodAlchemist\Observers;

use Platform\FoodAlchemist\Models\FoodAlchemistAngebot;
use Platform\FoodAlchemist\Services\Ai\PoolEmbeddingService;

/**
 * Ausbau (b): hält den Angebot-Embedding-Vektor bei Einzeledits synchron
 * (Bulk = foodalchemist:embed --pool=angebote). No-op ohne Provider; nur bei
 * Änderung embed-relevanter Felder re-queuen.
 */
class AngebotEmbeddingObserver
{
    private const RELEVANT = ['name', 'occasion', 'location', 'brief', 'description'];

    public function created(FoodAlchemistAngebot $m): void
    {
        app(PoolEmbeddingService::class)->queueAngebot($m);
    }

    public function updated(FoodAlchemistAngebot $m): void
    {
        if (! $m->wasChanged(self::RELEVANT)) {
            return;
        }
        app(PoolEmbeddingService::class)->queueAngebot($m);
    }

    public function deleted(FoodAlchemistAngebot $m): void
    {
        app(PoolEmbeddingService::class)->deleteAngebot((int) $m->id, $m->team_id);
    }
}
