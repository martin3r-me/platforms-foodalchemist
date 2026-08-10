<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;

/**
 * Übersetzt die (englischen) Anker-Anzeigenamen ins Deutsche: `display_de` wird deutsch, das
 * Original wandert nach `display_en` (= Idempotenz-Marker). Marken/Eigennamen/Spezialprodukte
 * bleiben unverändert (Prompt `pairing.anchor_translate`, brand-safe).
 *
 * Global (team_id=NULL Inspire-Katalog), läuft headless über den Core-AiGateway (Tier B = günstig).
 * Idempotent + resume-fähig: nur Zeilen mit `display_en IS NULL`; ein fehlgeschlagener Batch bleibt
 * offen und wird beim nächsten Lauf erneut versucht. Dry-Run by default, `--apply` schreibt.
 */
class AnchorsTranslateCommand extends Command
{
    protected $signature = 'foodalchemist:anchors-translate
        {--apply : Schreiben (sonst Dry-Run: nur zählen, keine KI-Calls)}
        {--batch=25 : Anker pro KI-Call}
        {--limit=0 : Max. Anker in diesem Lauf (0 = alle offenen)}
        {--tier=B : KI-Tier (B = günstig)}';

    protected $description = 'Übersetzt Anker-Anzeigenamen ins Deutsche (display_de); Original → display_en';

    private const ANCHORS = 'foodalchemist_vocab_pairing_anchors';

    public function handle(AiGatewayService $ai): int
    {
        $apply = (bool) $this->option('apply');
        $batchSize = max(1, (int) $this->option('batch'));
        $limit = max(0, (int) $this->option('limit'));
        $tier = (string) $this->option('tier');

        $q = DB::table(self::ANCHORS)
            ->whereNull('deleted_at')
            ->whereNull('display_en')       // Idempotenz: nur noch nicht übersetzte
            ->whereNotNull('display_de')
            ->orderBy('id');
        if ($limit > 0) {
            $q->limit($limit);
        }
        $anker = $q->get(['id', 'display_de']);

        $this->info(sprintf('%d Anker offen (Batch %d, Tier %s)%s.',
            $anker->count(), $batchSize, $tier, $apply ? '' : ' [DRY-RUN]'));
        if ($anker->isEmpty()) {
            $this->line('Nichts zu tun.');

            return self::SUCCESS;
        }
        if (! $apply) {
            $this->line('Dry-Run — mit --apply ausführen.');

            return self::SUCCESS;
        }

        $ok = 0;
        $unveraendert = 0;
        $fehler = 0;
        foreach ($anker->chunk($batchSize) as $chunk) {
            $items = [];
            $byIndex = [];
            foreach ($chunk->values() as $i => $a) {
                $items[] = ['index' => $i, 'name' => $a->display_de];
                $byIndex[$i] = $a;
            }
            try {
                $proposal = $ai->propose('pairing.anchor_translate', ['items' => $items], ['tier' => $tier]);
            } catch (\Throwable $e) {
                $fehler += count($chunk);
                $this->warn('Batch fehlgeschlagen (bleibt offen): '.$e->getMessage());
                continue;
            }

            foreach (($proposal->werte['items'] ?? []) as $r) {
                $idx = (int) ($r['index'] ?? -1);
                $a = $byIndex[$idx] ?? null;
                if ($a === null) {
                    continue;
                }
                $de = trim((string) ($r['de'] ?? ''));
                if ($de === '') {
                    $de = $a->display_de; // Fallback: Original behalten (display_de ist NOT NULL)
                }
                DB::table(self::ANCHORS)->where('id', $a->id)->update([
                    'display_en' => $a->display_de, // Original sichern = Marker
                    'display_de' => $de,
                    'updated_at' => now(),
                ]);
                $de === $a->display_de ? $unveraendert++ : $ok++;
            }
        }

        $this->info(sprintf('Fertig: %d übersetzt · %d unverändert (Marke/gleich) · %d Fehler (offen geblieben).',
            $ok, $unveraendert, $fehler));

        return self::SUCCESS;
    }
}
