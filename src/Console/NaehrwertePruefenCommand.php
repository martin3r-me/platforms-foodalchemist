<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Services\Naehrwerte\NaehrwertPruefLaufService;
use Platform\FoodAlchemist\Support\MasterTeam;

/**
 * Trockenlauf der Nährwert-Prüfung (gleiche Logik wie MCP `naehrwert_pruefung.GET`). Nur lesend; mit --csv die Befundliste je Artikel,
 * z. B. als Meldeliste an Lieferanten/Necta (Einheiten-Fehler: Regel H6).
 */
class NaehrwertePruefenCommand extends Command
{
    protected $signature = 'foodalchemist:naehrwerte-pruefen
        {--team= : Sicht dieses Teams (Standard: Master-Team)}
        {--limit=3 : Beispiele je Regel}
        {--csv= : alle Befunde je Artikel als CSV in diese Datei}';

    protected $description = 'Trockenlauf der Nährwert-Prüfung je Lieferantenartikel (nur lesend)';

    public function handle(NaehrwertPruefLaufService $service): int
    {
        $team = Team::find((int) ($this->option('team') ?: MasterTeam::id()));
        if ($team === null) {
            $this->error('Kein Team: --team=<id> angeben oder ein Master-Team konfigurieren.');

            return self::FAILURE;
        }
        $lauf = $service->lauf($team, max(1, (int) $this->option('limit')));
        $b = $lauf['bericht'];

        $this->info("Nährwert-Prüfung (Modus {$b['modus']}, Sicht Team {$team->id})");
        foreach (['artikel_geprueft', 'artikel_markiert', 'artikel_umgerechnet', 'artikel_nur_hinweis', 'artikel_freigegeben', 'gps_betroffen'] as $k) {
            $this->line(sprintf('  %-22s %s', $k, number_format($b[$k], 0, ',', '.')));
        }
        $this->newLine();
        $this->table(['Regel', 'Schwere', 'Befunde', 'Artikel', 'Beispiel'], array_map(fn ($r, $regel) => [
            $regel, $r['schwere'], $r['befunde'], $r['artikel'],
            isset($r['beispiele'][0]) ? mb_strimwidth(($r['beispiele'][0]['gp'] ?? '') . ' — ' . $r['beispiele'][0]['grund'], 0, 70, '…') : '',
        ], $b['je_regel'], array_keys($b['je_regel'])));
        if ($b['umrechnung_je_lieferant'] !== []) {
            $this->line('Umrechnung je Lieferant: ' . json_encode($b['umrechnung_je_lieferant'], JSON_UNESCAPED_UNICODE));
        }

        if (is_string($datei = $this->option('csv')) && $datei !== '') {
            $fp = fopen($datei, 'w');
            fputcsv($fp, ['regel', 'schwere', 'gp_id', 'gp', 'la_id', 'lieferant', 'bezeichnung', 'feld', 'wert', 'erwartet', 'grund', 'faktor', 'freigegeben'], ';');
            foreach ($lauf['zeilen'] as $z) {
                fputcsv($fp, array_map(fn ($v) => is_bool($v) ? ($v ? 'ja' : 'nein') : $v, array_values($z)), ';');
            }
            fclose($fp);
            $this->line("CSV: {$datei} (" . count($lauf['zeilen']) . ' Befunde)');
        }

        return self::SUCCESS;
    }
}
