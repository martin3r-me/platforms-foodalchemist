<?php

namespace Platform\FoodAlchemist\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Platform\FoodAlchemist\Services\Ai\KnowledgeEmbeddingService;

/**
 * Zutaten-Bulk-Import, 2026-09-18: reiner Verzögerungs-Träger für
 * {@see KnowledgeEmbeddingService::queueDocument()}. `GenerateEmbeddingJob` (Core-Modul) hat
 * KEINE Rate-Limiting-Middleware und keinen Delay-Parameter — Core wird hier bewusst NICHT
 * angefasst (Fremdmodul-Grenze). Stattdessen dispatcht `KnowledgeService::setActiveBatch()`
 * diesen kleinen, modul-eigenen Job MIT `->delay()` gestaffelt über Chunks — beim Ausführen löst
 * er `queueDocument()` genauso aus wie ein einzelnes SET_ACTIVE, nur zeitlich verteilt statt als
 * Burst. Orchestrierung 2026-09-18: 500 Slugs über ~3 Minuten statt als einmaliger Stoß auf die
 * 2 `default`-Queue-Worker + den Embedding-Provider.
 */
class QueueKnowledgeEmbedJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 30;

    public function __construct(public string $slug) {}

    public function handle(): void
    {
        $doc = DB::table('foodalchemist_knowledge_documents')->where('slug', $this->slug)->whereNull('deleted_at')->first();
        if ($doc === null) {
            return;   // zwischenzeitlich geloescht — kein Fehler, einfach nichts zu tun
        }
        app(KnowledgeEmbeddingService::class)->queueDocument($doc);
    }

    public function failed(?\Throwable $e): void
    {
        Log::warning('[FA/Zutaten-Import] QueueKnowledgeEmbedJob abgebrochen — Dossier bleibt ohne Embedding', [
            'slug' => $this->slug, 'fehler' => $e?->getMessage(),
        ]);
    }
}
