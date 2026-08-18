<?php

namespace Platform\FoodAlchemist\Observers;

use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\Ai\PoolEmbeddingService;

/**
 * Spec 15 §5c / RAG-Autoindex A3: hält den Lieferartikel-Embedding-Vektor bei
 * INTERAKTIVEN Einzeledits synchron. Der Katalog-BULK-Import (Necta, umgeht Eloquent)
 * bleibt bewusst ein expliziter Backfill-Schritt (foodalchemist:embed --pool=la) — ein
 * Live-Embed je Zeile wäre bei ~264k Artikeln zu teuer (Kosten/Eloquent-Umgehung).
 *
 * Nur bei tatsächlicher Änderung embed-relevanter Felder re-queuen (kein Churn bei Preis-/
 * Gebinde-/Quota-Writes, die den Embed-Text nicht berühren). Die klassifizierte Oberfläche
 * (main_ingredient_display / gp_name_derived) lebt in der Structure-Schicht (eigene Tabelle,
 * kein Item-Event) — deren Änderungen deckt der Bulk-Backfill ab, nicht dieser Observer.
 * No-op ohne Provider (Sandbox) — die Arbeit + das Austritts-Gate macht
 * {@see PoolEmbeddingService::queueSupplierItem()}.
 */
class SupplierItemEmbeddingObserver
{
    /** Felder, die den Embed-Text bzw. die Pool-Mitgliedschaft bestimmen. */
    private const RELEVANT = ['designation', 'marketing_name', 'brand', 'is_discontinued'];

    public function created(FoodAlchemistSupplierItem $si): void
    {
        app(PoolEmbeddingService::class)->queueSupplierItem($si);
    }

    public function updated(FoodAlchemistSupplierItem $si): void
    {
        // wasChanged() spiegelt genau die gerade persistierte Änderung.
        if (! $si->wasChanged(self::RELEVANT)) {
            return;
        }
        app(PoolEmbeddingService::class)->queueSupplierItem($si);
    }

    public function deleted(FoodAlchemistSupplierItem $si): void
    {
        app(PoolEmbeddingService::class)->deleteSupplierItem((int) $si->id, $si->team_id);
    }
}
