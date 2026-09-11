<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Services\Ai\KnowledgeEmbeddingService;

/**
 * Spec 52 — den kuratierten Bestand dorthin heben, wo er hingehört: nach global.
 *
 * Entscheid Dominique 2026-09-11: „alles was ich jetzt reingebe ist master, und das was
 * online ist ist auch master wissen". Neue Dossiers legt {@see \Platform\FoodAlchemist\Services\KnowledgeService::create}
 * seitdem global an; dieses Kommando holt den BESTAND nach.
 *
 * ⚠ REIHENFOLGE: erst `FOODALCHEMIST_MASTER_TEAM_ID` setzen und den Code ausrollen, DANN
 * heben. Wer zuerst hebt, sperrt sich aus seinen eigenen Dossiers aus — ohne Master-Config
 * ist globales Wissen für jeden read-only.
 *
 * ⚠ DER VEKTOR ZIEHT NICHT MIT. {@see KnowledgeEmbeddingService::queueDocument} rechnet die
 * Partition beim Embedden aus `team_id` aus und SPEICHERT sie beim Vektor. Ein gehobenes
 * Dossier lässt seinen alten Vektor in der Team-Partition liegen — und
 * {@see KnowledgeEmbeddingService::purgeStale} erreicht ihn nie, weil sie jede ID überspringt,
 * die noch in der Tabelle steht. Darum protokolliert dieses Kommando die Quell-Partition:
 * der Schluss-Lauf räumt sie mit `foodalchemist:embed --pool=knowledge --alt-partition=N`.
 *
 * Rückweg: die gehobenen IDs stehen im Protokoll — `UPDATE … SET team_id = N WHERE id IN (…)`.
 */
class WissenGlobalHebenCommand extends Command
{
    protected $signature = 'foodalchemist:wissen-global-heben
        {--team= : Quell-Team (Standard: das konfigurierte Master-Team)}
        {--apply : wirklich schreiben (ohne dies nur zählen und zeigen)}';

    protected $description = 'Hebt die Dossiers des Master-Teams auf globales Wissen (team_id NULL)';

    public function handle(): int
    {
        $team = $this->option('team') ?? config('foodalchemist.master_team_id');
        if (! is_numeric($team)) {
            $this->error('Kein Quell-Team: --team=N angeben oder FOODALCHEMIST_MASTER_TEAM_ID setzen.');

            return self::FAILURE;
        }
        $team = (int) $team;

        $ids = DB::table('foodalchemist_knowledge_documents')
            ->where('team_id', $team)->whereNull('deleted_at')
            ->orderBy('id')->pluck('id')->map(fn ($i) => (int) $i)->all();

        if ($ids === []) {
            $this->line("Team {$team} trägt kein Dossier — nichts zu heben.");

            return self::SUCCESS;
        }

        $aktiv = DB::table('foodalchemist_knowledge_documents')
            ->whereIn('id', $ids)->where('active', 1)->count();
        $this->line(sprintf('Team %d: %d Dossiers (%d aktiv) → global (team_id NULL)', $team, count($ids), $aktiv));

        if (! $this->option('apply')) {
            $this->line('Trockenlauf — nichts geschrieben. Mit --apply ausführen.');

            return self::SUCCESS;
        }

        DB::table('foodalchemist_knowledge_documents')->whereIn('id', $ids)->update(['team_id' => null]);

        // Das Protokoll IST der Rückweg. Ohne die ID-Liste liesse sich global nicht mehr von
        // dem trennen, was schon vorher global war.
        $protokoll = storage_path('logs/wissen-global-heben-'.now()->format('Ymd-His').'.json');
        file_put_contents($protokoll, (string) json_encode([
            'quell_team' => $team, 'anzahl' => count($ids), 'ids' => $ids,
            'zurueck' => "UPDATE foodalchemist_knowledge_documents SET team_id = {$team} WHERE id IN (…ids…)",
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->info(sprintf('✓ %d Dossiers gehoben. Rückweg: %s', count($ids), $protokoll));
        $this->warn(sprintf(
            'Die Vektoren liegen noch in Partition %d. Schluss-Lauf: '
            .'php artisan foodalchemist:embed --pool=knowledge --alt-partition=%d',
            $team, $team
        ));

        return self::SUCCESS;
    }
}
