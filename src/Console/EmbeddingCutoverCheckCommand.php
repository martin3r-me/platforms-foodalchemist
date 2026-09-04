<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Platform\FoodAlchemist\Services\Ai\KnowledgeEmbeddingService;
use Platform\FoodAlchemist\Services\Ai\PoolEmbeddingService;

/**
 * BEREITSCHAFTS-PRÜFUNG für den Qdrant-Cutover (Runbook 34) — rein lesend.
 *
 * Warum es das braucht (gemessen auf demo, 2026-09-02, 23:50):
 * `foodalchemist:embeddings:migrate-store` HAT die 8 kopierbaren Pools nach Qdrant
 * geschrieben, aber `FOODALCHEMIST_EMBEDDING_STORE` steht weiterhin auf 'mysql'. Gelesen
 * wird also der Alt-Bestand — und der ist für das Wissen längst veraltet: von 1.018
 * MySQL-Zeilen zeigen nur 86 auf aktive Dokumente (14 % von 598), während Qdrant 601
 * vollständige Vektoren hält. Die semantische Wissens-Discovery lief damit auf einem
 * Vierzehntel des Korpus, ohne dass es irgendwo auffiel.
 *
 * Der Flip ist EINE ENV-Zeile — aber er ist nicht gratis, und diese Prüfung macht den Preis
 * sichtbar statt ihn zu verschweigen:
 *
 *  · Die Routing-INVARIANTE (FoodAlchemistServiceProvider) verlangt EINEN Store für ALLE
 *    neun Pools: `SemanticRetrievalService::candidates()` übergibt gemischte entity_type-
 *    Arrays, und `search()` routet am ERSTEN Typ. Einzelne Pools umzurouten zerreisst
 *    Mixed-Type-Suchen. Es gibt also kein „nur das Wissen umstellen".
 *  · `supplier_item` (LA) ist per Docblock NICHT kopierbar und muss nach dem Flip frisch
 *    embeddet werden. Bis dahin ist die LA-Suche leer. Das ist der eigentliche Preis.
 *
 * Ergebnis: GO oder NO-GO mit Begründung, plus die Befehlszeilen für beide Richtungen.
 */
class EmbeddingCutoverCheckCommand extends Command
{
    protected $signature = 'foodalchemist:embedding-cutover-check
        {--luecke=5 : erlaubte Prozent-Lücke, ab der ein Pool als nicht bereit gilt}';

    protected $description = 'Prüft (lesend), ob der Qdrant-Cutover gefahrlos ist — Pool für Pool, mit GO/NO-GO';

    public function handle(): int
    {
        $aktuell = (string) config('foodalchemist.embedding_store', 'mysql');
        $url = rtrim((string) (config('embeddings.qdrant.url') ?: env('QDRANT_URL')), '/');
        if ($url === '') {
            $this->error('Kein QDRANT_URL konfiguriert — Cutover nicht möglich.');

            return self::FAILURE;
        }
        $key = config('embeddings.qdrant.api_key') ?: env('QDRANT_API_KEY');
        $kopf = $key ? ['api-key' => $key] : [];
        $coll = (string) (config('embeddings.qdrant.collection') ?: 'emb_openai_text_embedding_3_large');

        $pools = [
            'GP' => PoolEmbeddingService::ENTITY_TYPE_GP,
            'Rezepte' => PoolEmbeddingService::ENTITY_TYPE_RECIPE,
            'Lieferanten' => PoolEmbeddingService::ENTITY_TYPE_SUPPLIER,
            'Concepts' => PoolEmbeddingService::ENTITY_TYPE_CONCEPT,
            'Foodbooks' => PoolEmbeddingService::ENTITY_TYPE_FOODBOOK,
            'Lab-Notizen' => PoolEmbeddingService::ENTITY_TYPE_LAB_NOTE,
            'Wissen' => KnowledgeEmbeddingService::ENTITY_TYPE,
            'Pairing-Anker' => KnowledgeEmbeddingService::ENTITY_TYPE_ANKER,
            'Lieferantenartikel (LA)' => PoolEmbeddingService::ENTITY_TYPE_SUPPLIER_ITEM,
        ];
        $toleranz = max(0, (int) $this->option('luecke')) / 100;

        $this->line('');
        $this->line("QDRANT-CUTOVER — BEREITSCHAFT   (aktiver Store: {$aktuell})");
        $this->line('');
        $this->line(sprintf('  %-26s %10s %10s   %s', 'Pool', 'MySQL', 'Qdrant', 'Bewertung'));

        $blocker = [];
        foreach ($pools as $label => $typ) {
            $mysql = DB::table('core_embeddings')->where('entity_type', $typ)->count();
            $qd = (int) (Http::withHeaders($kopf)->timeout(20)
                ->post("$url/collections/$coll/points/count", ['exact' => true,
                    'filter' => ['must' => [['key' => 'entity_type', 'match' => ['value' => $typ]]]]])
                ->json('result.count') ?? 0);

            if ($mysql === 0 && $qd === 0) {
                $bewertung = 'leer (beide) — egal';
            } elseif ($qd >= $mysql * (1 - $toleranz)) {
                $bewertung = $qd > $mysql ? 'bereit (Qdrant führt)' : 'bereit';
            } else {
                $fehlt = $mysql - $qd;
                $bewertung = sprintf('NICHT BEREIT — %s Vektoren fehlen in Qdrant', number_format($fehlt, 0, ',', '.'));
                $blocker[$label] = ['typ' => $typ, 'fehlt' => $fehlt];
            }
            $this->line(sprintf('  %-26s %10s %10s   %s', $label,
                number_format($mysql, 0, ',', '.'), number_format($qd, 0, ',', '.'), $bewertung));
        }

        // Der Wissens-Sonderfall: MySQL-Zeilen können zahlreich UND wertlos sein.
        $aktiveDocs = DB::table('foodalchemist_knowledge_documents')->where('active', 1)->whereNull('deleted_at')->count();
        $gueltig = DB::table('core_embeddings as e')
            ->join('foodalchemist_knowledge_documents as d', DB::raw('CAST(d.id AS CHAR)'), '=', 'e.entity_id')
            ->where('e.entity_type', KnowledgeEmbeddingService::ENTITY_TYPE)
            ->where('d.active', 1)->whereNull('d.deleted_at')->count();
        $this->line('');
        $this->line(sprintf('  Wissen im Detail: %d aktive Dokumente — im MySQL-Store nur %d davon indiziert (%.0f %%).',
            $aktiveDocs, $gueltig, $aktiveDocs > 0 ? $gueltig / $aktiveDocs * 100 : 0));
        $this->line('  Solange MySQL liest, ist genau das die Reichweite der semantischen Wissens-Suche.');

        $this->line('');
        if ($blocker === []) {
            $this->info('GO — alle Pools sind in Qdrant vollständig.');
            $this->line('  Flip:     FOODALCHEMIST_EMBEDDING_STORE=qdrant   (in der .env von demo)');
            $this->line('  Rollback: den Wert wieder entfernen — Default ist mysql, kein Deploy nötig.');

            return self::SUCCESS;
        }

        $this->warn('NO-GO — der Flip würde diese Pools blind machen:');
        foreach ($blocker as $label => $b) {
            $this->line(sprintf('  · %-26s %s Vektoren fehlen', $label, number_format($b['fehlt'], 0, ',', '.')));
        }
        $this->line('');
        $this->line('  Es gibt kein „nur einen Pool umstellen": die Routing-Invariante verlangt EINEN Store');
        $this->line('  für alle neun (search() routet bei Mixed-Type-Anfragen am ersten Typ).');
        $this->line('');
        $this->line('  Weg zum GO:');
        $this->line('    1) kopierbare Pools auffüllen:  php artisan foodalchemist:embeddings:migrate-store --to=qdrant');
        $this->line('    2) LA ist NICHT kopierbar — nach dem Flip frisch embedden: foodalchemist:embed --pool=la');
        $this->line('       (bis das durch ist, ist die LA-Suche leer — der eigentliche Preis des Cutovers)');

        return self::SUCCESS;
    }
}
