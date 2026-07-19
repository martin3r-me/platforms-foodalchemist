<?php

namespace Platform\FoodAlchemist\Observers;

use Platform\FoodAlchemist\Models\FoodAlchemistLabNote;
use Platform\FoodAlchemist\Services\Ai\PoolEmbeddingService;

/**
 * Spec 15 §5b: hält den Lab-Note-Embedding-Vektor bei Einzeledits synchron
 * (Bulk = foodalchemist:embed --pool=lab_notes). No-op ohne Provider; nur bei
 * Änderung embed-relevanter Felder re-queuen.
 */
class LabNoteEmbeddingObserver
{
    private const RELEVANT = ['title', 'body'];

    public function created(FoodAlchemistLabNote $n): void
    {
        app(PoolEmbeddingService::class)->queueLabNote($n);
    }

    public function updated(FoodAlchemistLabNote $n): void
    {
        if (! $n->wasChanged(self::RELEVANT)) {
            return;
        }
        app(PoolEmbeddingService::class)->queueLabNote($n);
    }

    public function deleted(FoodAlchemistLabNote $n): void
    {
        app(PoolEmbeddingService::class)->deleteLabNote((int) $n->id, $n->team_id);
    }
}
