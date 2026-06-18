<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Platform\FoodAlchemist\Services\Ai\KnowledgeEmbeddingService;

/**
 * Indiziert den Wissens-Korpus (domain + pairing) im Core-Embedding-Store für
 * die semantische Pairing-/Domain-Suche. Läuft NACH foodalchemist:knowledge-import.
 *
 * Idempotent: Cores source_hash überspringt unveränderte Docs (kein API-Call).
 * Re-Run nach Korpus-Updates oder Provider-Wechsel ist unschädlich.
 */
class KnowledgeEmbedCommand extends Command
{
    protected $signature = 'foodalchemist:knowledge-embed
        {--kategorie=* : Nur diese Kategorien indizieren (Default: domain, pairing)}';

    protected $description = 'Embeddet den Wissens-Korpus für die semantische Pairing-/Domain-Suche';

    public function handle(KnowledgeEmbeddingService $service): int
    {
        if (! $service->isProviderAvailable()) {
            $this->error('Kein Embedding-Provider verfügbar — OPENAI_API_KEY setzen '
                . '(oder EMBEDDING_GEMINI_ENABLED=true + GEMINI_API_KEY).');

            return self::FAILURE;
        }

        $kategorien = (array) $this->option('kategorie');
        $kategorien = $kategorien !== [] ? $kategorien : KnowledgeEmbeddingService::INDEXED_KATEGORIEN;

        $this->info('Embedding-Lauf (idempotent — unveränderte Einträge werden übersprungen) …');
        $stats = $service->embedCorpus($kategorien);

        $rows = [];
        foreach ($stats['kategorien'] as $kat => $count) {
            $rows[] = [$kat, $count];
        }

        // Anker-Vokabular für die semantische Anker-Auflösung (B) mitindizieren.
        $anker = $service->embedAnkers();
        $rows[] = ['anker (Vokabular)', $anker['candidates']];

        $this->table(['Quelle', 'Einträge (Kandidaten)'], $rows);

        $this->line('Provider: ' . ($service->providerName() ?? 'core-default')
            . ' · Sentinel-Team (global): ' . $service->globalTeamId());
        $this->info('Fertig. Suche aktivieren mit: FOODALCHEMIST_SEMANTIC_SEARCH=true');

        return self::SUCCESS;
    }
}
