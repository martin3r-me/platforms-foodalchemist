<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Platform\FoodAlchemist\Services\Regeln\RegelKonsumentenPruefung;

/**
 * Spec 81 — Wächter vor Paket 5: welcher Prompt liest ein Regelwerk-Dossier mit aktiver Regel, bekommt aber den
 * Regel-Block nicht? Nach der Bereinigung des Dossiers verlöre er die Regel. Rein lesend; Exit 1 bei Befunden.
 */
class RegelnKonsumentenCommand extends Command
{
    protected $signature = 'foodalchemist:regeln-konsumenten {--json : maschinenlesbar}';

    protected $description = 'Spec 81: Kanon-Leser aktiver Regeln ohne Regel-Block (Schutz vor der Dossier-Bereinigung)';

    public function handle(RegelKonsumentenPruefung $pruefung): int
    {
        $befunde = $pruefung->befunde();
        if ($this->option('json')) {
            $this->line(json_encode($befunde, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        } elseif ($befunde === []) {
            $this->info('Jeder Kanon-Leser einer aktiven Regel bekommt den Regel-Block (oder ist bewusst ausgenommen).');
        } else {
            $this->table(['Prompt-Key', 'Regel', 'Dossier'], array_map(fn ($b) => [$b['prompt_key'], $b['schluessel'], $b['dossier']], $befunde));
            $this->warn(count($befunde) . ' Befund(e): in RegelPromptBlock::KONSUMENTEN aufnehmen oder bewusst ausnehmen.');
        }

        return $befunde === [] ? self::SUCCESS : self::FAILURE;
    }
}
