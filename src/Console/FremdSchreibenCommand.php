<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Platform\FoodAlchemist\Support\FremdSchreiben;

/**
 * Spec 84 B4 · Auswertung des Kanals `fa-fremdschreiben` (Messwoche, zusammen mit E-1).
 *
 * Liest die Tages-Dateien der letzten `--tage`, gruppiert nach Art (fremd | ohne_kontext), Modell, Eingang und Aufrufer und zeigt die
 * häufigsten Gruppen, dazu die unterdrückten Fälle. Jede Zeile im Log steht schon für einen Schlüssel je Prozess — „Fälle“ zählt also
 * Prozesse (Requests, Jobs, Kommandos), nicht einzelne Speichervorgänge. Nur lesend.
 */
class FremdSchreibenCommand extends Command
{
    protected $signature = 'foodalchemist:fremd-schreiben
        {--tage=7 : so viele Tage zurück (Tages-Dateien des Kanals)}
        {--top=30 : Zeilen je Tabelle}';

    protected $description = 'Spec 84 B4: Schreiben auf fremde Zeilen auswerten (nach Art, Modell, Eingang, Aufrufer)';

    public function handle(): int
    {
        $tage = max(1, (int) $this->option('tage'));
        $top = max(1, (int) $this->option('top'));
        $pfad = (string) config('logging.channels.' . FremdSchreiben::KANAL . '.path', storage_path('logs/fa-fremdschreiben.log'));
        $info = pathinfo($pfad);

        $dateien = [];
        for ($i = 0; $i < $tage; $i++) {
            $d = $info['dirname'] . '/' . $info['filename'] . '-' . now()->subDays($i)->format('Y-m-d') . '.' . ($info['extension'] ?? 'log');
            if (is_file($d)) {
                $dateien[] = $d;
            }
        }
        if ($dateien === []) {
            $this->warn('Keine Dateien gefunden (' . $info['dirname'] . '/' . $info['filename'] . '-*.log). Ist FOODALCHEMIST_FREMD_SCHREIBEN=log gesetzt?');

            return self::SUCCESS;
        }

        $gruppen = [];
        $unterdrueckt = [];
        foreach ($dateien as $datei) {
            foreach (new \SplFileObject($datei) as $zeile) {
                $j = json_decode((string) $zeile, true);
                $k = is_array($j) ? ($j['context'] ?? null) : null;
                if (! is_array($k)) {
                    continue;
                }
                if (($k['art'] ?? null) === 'unterdrueckt') {
                    $e = (string) ($k['eingang'] ?? '?');
                    $unterdrueckt[$e] = ($unterdrueckt[$e] ?? 0) + (int) ($k['anzahl'] ?? 0);

                    continue;
                }
                $schluessel = implode("\0", [$k['art'] ?? '?', $k['modell'] ?? '?', $k['eingang'] ?? '?', $k['aufrufer'] ?? '']);
                $g = $gruppen[$schluessel] ?? ['art' => $k['art'] ?? '?', 'modell' => $k['modell'] ?? '?', 'eingang' => $k['eingang'] ?? '?',
                    'aufrufer' => $k['aufrufer'] ?? '', 'faelle' => 0, 'teams' => []];
                $g['faelle']++;
                $g['teams'][($k['zeilen_team'] ?? 'NULL') . '←' . ($k['kontext_team'] ?? '-')] = true;
                $gruppen[$schluessel] = $g;
            }
        }

        $liste = array_values($gruppen);
        usort($liste, fn ($a, $b) => [$a['art'] === 'fremd' ? 0 : 1, -$a['faelle']] <=> [$b['art'] === 'fremd' ? 0 : 1, -$b['faelle']]);
        $this->info(count($dateien) . ' Datei(en), ' . count($liste) . ' Gruppen, davon fremd: ' . count(array_filter($liste, fn ($g) => $g['art'] === 'fremd')));
        $this->table(['Art', 'Fälle', 'Modell', 'Eingang', 'Aufrufer', 'Zeile←Kontext (Beispiele)'], array_map(fn ($g) => [
            $g['art'], $g['faelle'], $g['modell'], mb_strimwidth($g['eingang'], 0, 50, '…'), $g['aufrufer'],
            implode(', ', array_slice(array_keys($g['teams']), 0, 4)),
        ], array_slice($liste, 0, $top)));
        if ($unterdrueckt !== []) {
            arsort($unterdrueckt);
            $this->line('');
            $this->line('<comment>Unterdrückt (Obergrenze ' . FremdSchreiben::MAX_ZEILEN . ' Zeilen je Prozess erreicht)</comment>');
            $this->table(['Eingang', 'unterdrückt'], array_map(fn ($e, $n) => [$e, $n], array_keys($unterdrueckt), $unterdrueckt));
        }

        return self::SUCCESS;
    }
}
