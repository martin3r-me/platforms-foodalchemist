<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Platform\FoodAlchemist\Services\Ai\KnowledgeEmbeddingService;
use Platform\FoodAlchemist\Services\Ai\PoolEmbeddingService;

/**
 * Sammel-Wrapper um cores `embeddings:migrate-store`: verschiebt alle
 * KOPIERBAREN FoodAlchemist-Embedding-Pools in einem Rutsch (MySQL → Qdrant),
 * ohne dass man die entity_type-Strings von Hand tippen muss.
 *
 * Die Liste lebt hier im Modul (aus den Service-Konstanten), damit core
 * entity-agnostisch bleibt. Reihenfolge/Umfang spiegeln den Routing-Block im
 * FoodAlchemistServiceProvider.
 *
 * WICHTIG — nicht enthalten: ENTITY_TYPE_SUPPLIER_ITEM (LA, ~264k). Der liegt
 * NICHT in MySQL (deshalb Qdrant) und kann nicht kopiert werden — er muss nach
 * dem Cutover frisch embeddet werden: `php artisan foodalchemist:embed --pool=la`.
 *
 * Der eigentliche Store-Wechsel bleibt ein bewusster ENV-Schritt
 * (FOODALCHEMIST_EMBEDDING_STORE=qdrant) — dieser Command kopiert nur Daten.
 */
class EmbeddingsMigrateStoreCommand extends Command
{
    protected $signature = 'foodalchemist:embeddings:migrate-store
        {--to=qdrant : Ziel-Store (Default: qdrant).}
        {--team= : Nur diese team_id (Default: alle Partitionen inkl. Sentinel/global).}
        {--purge : Quell-Rows in MySQL nach fehlerfreiem Kopieren löschen.}
        {--dry-run : Nur zählen und anzeigen, nichts schreiben.}';

    protected $description = 'Kopiert alle kopierbaren FoodAlchemist-Embedding-Pools (8 Types) MySQL → Zielstore (z.B. Qdrant). LA (supplier_item) ausgenommen.';

    /**
     * Die 8 kopierbaren Entity-Types (alles außer LA/supplier_item).
     *
     * @return string[]
     */
    private function copyableEntityTypes(): array
    {
        return [
            PoolEmbeddingService::ENTITY_TYPE_GP,
            PoolEmbeddingService::ENTITY_TYPE_RECIPE,
            PoolEmbeddingService::ENTITY_TYPE_SUPPLIER,
            PoolEmbeddingService::ENTITY_TYPE_CONCEPT,
            PoolEmbeddingService::ENTITY_TYPE_FOODBOOK,
            PoolEmbeddingService::ENTITY_TYPE_LAB_NOTE,
            KnowledgeEmbeddingService::ENTITY_TYPE,
            KnowledgeEmbeddingService::ENTITY_TYPE_ANKER,
        ];
    }

    public function handle(): int
    {
        if (! $this->getApplication()->has('embeddings:migrate-store')) {
            $this->error('Core-Command `embeddings:migrate-store` nicht verfügbar — platform-core aktualisieren.');
            return self::FAILURE;
        }

        $to = (string) $this->option('to');
        $team = $this->option('team');
        $purge = (bool) $this->option('purge');
        $dryRun = (bool) $this->option('dry-run');

        $types = $this->copyableEntityTypes();

        $this->info(sprintf(
            '%sVerschiebe %d kopierbare Pool(s) → Store "%s"%s%s.',
            $dryRun ? '[DRY-RUN] ' : '',
            count($types),
            $to,
            $team !== null && $team !== '' ? " (team {$team})" : '',
            $purge ? ', danach MySQL-Purge' : '',
        ));
        $this->newLine();

        $failed = [];

        foreach ($types as $type) {
            $this->line("── {$type} ──");

            $params = ['entityType' => $type, '--to' => $to];
            if ($team !== null && $team !== '') {
                $params['--team'] = $team;
            }
            if ($purge) {
                $params['--purge'] = true;
            }
            if ($dryRun) {
                $params['--dry-run'] = true;
            }

            $code = $this->call('embeddings:migrate-store', $params);
            if ($code !== self::SUCCESS) {
                $failed[] = $type;
            }
            $this->newLine();
        }

        if ($failed !== []) {
            $this->error('Fehlgeschlagen: ' . implode(', ', $failed) . ' — bitte prüfen und erneut ausführen (idempotent).');
            return self::FAILURE;
        }

        $this->info('Alle kopierbaren Pools verarbeitet.');
        $this->newLine();
        $this->warn('Nicht enthalten — LA / supplier_item (~264k, nicht in MySQL):');
        $this->line('  Nach dem Cutover frisch embedden:  php artisan foodalchemist:embed --pool=la');
        $this->newLine();
        $this->line('Store-Wechsel aktivieren (falls noch nicht geschehen):');
        $this->line('  FOODALCHEMIST_EMBEDDING_STORE=qdrant  →  php artisan config:clear');

        return self::SUCCESS;
    }
}
