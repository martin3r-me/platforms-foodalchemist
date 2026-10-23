<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Platform\FoodAlchemist\Services\GpHomogenitaetService;

/**
 * Spec 82 §2 — Bericht „GPs mit uneinheitlichen Artikeln“. Nur lesend: ändert keine Verknüpfung.
 */
class GpHomogenitaetBerichtCommand extends Command
{
    protected $signature = 'foodalchemist:gp-homogenitaet-bericht
        {--limit=30 : Anzahl GPs in der Liste (0 = alle)}
        {--csv= : zusätzlich als CSV in diese Datei schreiben}
        {--detail-csv= : Detail-CSV je GP × Merkmal (abweichende Artikel, Anteile, Vorschlag „neues GP“, Konfidenz) — Plan §0.8 allergen_abweichungen.csv}';

    protected $description = 'Bericht: GPs, unter denen Artikel mit abweichenden Allergenen/Zusatzstoffen hängen (nur lesend)';

    public function handle(GpHomogenitaetService $service): int
    {
        $bericht = $service->bericht();

        $this->info('Zusammenfassung (nur „enthalten“ zählt, Spuren nicht; fehlende Daten = unbekannt)');
        foreach ($bericht['zusammenfassung'] as $schluessel => $wert) {
            $this->line(sprintf('  %-45s %s', $schluessel, number_format($wert, 0, ',', '.')));
        }

        $limit = (int) $this->option('limit');
        $liste = $limit > 0 ? array_slice($bericht['gps'], 0, $limit) : $bericht['gps'];
        $this->newLine();
        $this->table(['GP', 'Name', 'Artikel', 'unbekannt', 'Abweichungen'], array_map(fn ($g) => [
            $g['gp_id'], mb_strimwidth($g['name'], 0, 40, '…'), $g['artikel'], $g['unbekannt'], implode('; ', array_slice($g['abweichungen'], 0, 3)),
        ], $liste));

        if (is_string($datei = $this->option('csv')) && $datei !== '') {
            $fp = fopen($datei, 'w');
            fputcsv($fp, ['gp_id', 'name', 'artikel', 'unbekannt', 'abweichungen'], ';');
            foreach ($bericht['gps'] as $g) {
                fputcsv($fp, [$g['gp_id'], $g['name'], $g['artikel'], $g['unbekannt'], implode(' | ', $g['abweichungen'])], ';');
            }
            fclose($fp);
            $this->line("CSV: {$datei}");
        }

        if (is_string($detail = $this->option('detail-csv')) && $detail !== '') {
            $fp = fopen($detail, 'w');
            fputcsv($fp, ['gp_id', 'gp_key', 'name', 'wg', 'merkmal', 'artikel', 'mit', 'ohne', 'unbekannt', 'minderheit', 'abweichende_artikel', 'vorschlag', 'konfidenz'], ';');
            foreach ($bericht['details'] as $d) {
                fputcsv($fp, [$d['gp_id'], $d['gp_key'], $d['name'], $d['wg'], $d['merkmal'], $d['artikel'], $d['mit'], $d['ohne'], $d['unbekannt'],
                    $d['minderheit'], implode(' ## ', $d['abweichende_artikel']), $d['vorschlag'], $d['konfidenz']], ';');
            }
            fclose($fp);
            $this->line("Detail-CSV: {$detail} (" . count($bericht['details']) . ' Zeilen)');
        }

        return self::SUCCESS;
    }
}
