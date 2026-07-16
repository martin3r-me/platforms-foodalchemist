<?php

namespace Platform\FoodAlchemist\Observers;

use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Services\Ai\PoolEmbeddingService;

/**
 * E1 (#507): hält den GP-Embedding-Vektor bei INTERAKTIVEN Einzeledits synchron
 * (Bulk-Backfill läuft über foodalchemist:embed). No-op ohne Provider
 * (Sandbox) — die eigentliche Arbeit + das Merge-/Austritts-Gate macht
 * {@see PoolEmbeddingService::queueGp()}.
 *
 * Nur bei tatsächlicher Änderung embed-relevanter Felder re-queuen (kein Churn
 * bei Preis-/Aggregat-Writes, die den Embed-Text nicht berühren).
 */
class GpEmbeddingObserver
{
    /** Felder, die den Embed-Text bzw. die Pool-Mitgliedschaft bestimmen. */
    private const RELEVANT = [
        'name', 'main_ingredient_display', 'main_ingredient_slug', 'condition',
        'commodity_group_code', 'status', 'is_platzhalter', 'merged_into_id',
    ];

    public function created(FoodAlchemistGp $gp): void
    {
        app(PoolEmbeddingService::class)->queueGp($gp);
    }

    public function updated(FoodAlchemistGp $gp): void
    {
        // wasChanged() spiegelt genau die gerade persistierte Änderung (im Gegensatz
        // zu wasRecentlyCreated, das an der Instanz „true" klebt).
        if (! $gp->wasChanged(self::RELEVANT)) {
            return;
        }
        app(PoolEmbeddingService::class)->queueGp($gp);
    }

    public function deleted(FoodAlchemistGp $gp): void
    {
        app(PoolEmbeddingService::class)->deleteGp((int) $gp->team_id, (int) $gp->id, $gp->team_id);
    }
}
