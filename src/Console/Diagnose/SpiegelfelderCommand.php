<?php

namespace Platform\FoodAlchemist\Console\Diagnose;

use Illuminate\Console\Command;
use Platform\FoodAlchemist\Services\Rezept\SpiegelfelderReport;

/**
 * Plan 98 · 3.5 Schritt 6c · Abweichungsreport Rezept ≠ Standard-Darreichung vor dem Entfernen der Spiegelfelder. **Read-only.**
 * Grundlage für Dominiques Freigabe („Ja, nach Report“); gemergt wird 6c erst danach.
 */
class SpiegelfelderCommand extends Command
{
    protected $signature = 'foodalchemist:diagnose:spiegelfelder
        {--beispiele=10 : Beispiel-Zeilen je Block}
        {--json : Maschinen-lesbare Ausgabe}';

    protected $description = 'Abweichungsreport der gespiegelten Darreichungsfelder am Rezept (98 · 3.5 · 6c) — read-only.';

    public function handle(SpiegelfelderReport $report): int
    {
        $b = $report->bericht(max(0, (int) $this->option('beispiele')));

        if ($this->option('json')) {
            $this->line((string) json_encode($b, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        if ($b['entfernt']) {
            $this->info('Spiegelspalten am Rezept sind entfernt (98 · 3.5 6c) — nichts zu prüfen.');

            return self::SUCCESS;
        }
        $this->info("{$b['rezepte']} Rezeptzeilen geprüft (davon {$b['fassungen']} Fassungen).");
        $this->table(['Feld', 'abweichend', 'nur am Rezept (ginge verloren)'],
            array_map(fn ($f, $w) => [$f, $w['abweichend'], $w['nur_rezept']], array_keys($b['felder']), $b['felder']));
        $this->line("Gerichte mit sales_unit_count > 1: {$b['portionen_gt_1']['anzahl']}");
        $this->line("Spiegelwerte ohne Standard-Darreichung: {$b['ohne_standard']['anzahl']}");
        $this->line('Altspalten belegt: '.json_encode($b['altspalten']));
        foreach ($b['felder'] as $feld => $w) {
            if ($w['beispiele'] !== []) {
                $this->newLine();
                $this->line("Beispiele {$feld}:");
                $this->table(array_keys($w['beispiele'][0]), array_map(fn ($z) => array_map(fn ($v) => is_bool($v) ? ($v ? 'ja' : 'nein') : $v, $z), $w['beispiele']));
            }
        }
        foreach (['portionen_gt_1' => 'Gerichte mit sales_unit_count > 1', 'ohne_standard' => 'ohne Standard-Darreichung'] as $k => $titel) {
            if ($b[$k]['beispiele'] !== []) {
                $this->newLine();
                $this->line("Beispiele {$titel}:");
                $this->table(array_keys($b[$k]['beispiele'][0]), array_map(fn ($z) => array_map(fn ($v) => is_array($v) ? implode(', ', $v) : (is_bool($v) ? ($v ? 'ja' : 'nein') : $v), $z), $b[$k]['beispiele']));
            }
        }

        return self::SUCCESS;
    }
}
