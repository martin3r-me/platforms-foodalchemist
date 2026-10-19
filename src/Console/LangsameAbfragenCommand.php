<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Platform\FoodAlchemist\Support\LangsameAbfragen;

/**
 * Spec 84 E-1 · Auswertung des Kanals `fa-langsam` (Messwoche).
 *
 * Liest die Tages-Dateien der letzten `--tage` und zeigt die Top 20 je Summe, Anzahl und Maximum, gruppiert nach
 * SQL-Muster und Eingang, dazu die häufigsten N+1-Muster aus den Zusammenfassungen. Nur lesend.
 *
 * Spec 85: Einträge „nachgeladene Spalte“ (z. B. `art=ek_pro_portion_nachgeladen` aus DarreichungResolver::ekProPortion)
 * sind keine langsamen Abfragen — sie erscheinen in einem eigenen Block mit Spalte, Aufrufer und Anzahl, damit die
 * Wochenauswertung zeigt, welcher Select die Spalte vergisst.
 */
class LangsameAbfragenCommand extends Command
{
    protected $signature = 'foodalchemist:langsame-abfragen
        {--tage=7 : so viele Tage zurück (Tages-Dateien des Kanals)}
        {--top=20 : Zeilen je Tabelle}';

    protected $description = 'Spec 84 E-1: langsame Abfragen auswerten (Top nach Summe/Anzahl/Max, N+1-Muster, nachgeladene Spalten)';

    /** Spec 85: `art` → nachgeladene Spalte (Klartext für den Block). */
    public const NACHGELADEN = [
        'ek_pro_portion_nachgeladen' => 'sales_unit_count (EK je Portion)',
    ];

    public function handle(): int
    {
        $tage = max(1, (int) $this->option('tage'));
        $top = max(1, (int) $this->option('top'));
        $pfad = (string) config('logging.channels.' . LangsameAbfragen::KANAL . '.path', storage_path('logs/fa-langsam.log'));
        $info = pathinfo($pfad);

        $dateien = [];
        for ($i = 0; $i < $tage; $i++) {
            $d = $info['dirname'] . '/' . $info['filename'] . '-' . now()->subDays($i)->format('Y-m-d') . '.' . ($info['extension'] ?? 'log');
            if (is_file($d)) {
                $dateien[] = $d;
            }
        }
        if ($dateien === []) {
            $this->warn('Keine Dateien gefunden (' . $info['dirname'] . '/' . $info['filename'] . '-*.log). Ist FOODALCHEMIST_LANGSAME_ABFRAGEN_MS gesetzt?');

            return self::SUCCESS;
        }

        $gruppen = [];
        $nPlus1 = [];
        $nachgeladen = [];
        $zusammenfassungen = 0;
        foreach ($dateien as $datei) {
            foreach (new \SplFileObject($datei) as $zeile) {
                $j = json_decode((string) $zeile, true);
                $k = is_array($j) ? ($j['context'] ?? null) : null;
                if (! is_array($k)) {
                    continue;
                }
                if (($k['art'] ?? null) === 'langsam') {
                    $schluessel = ($k['sql'] ?? '?') . "\0" . ($k['eingang'] ?? '?');
                    $g = $gruppen[$schluessel] ?? ['sql' => $k['sql'] ?? '?', 'eingang' => $k['eingang'] ?? '?', 'anzahl' => 0, 'summe' => 0.0, 'max' => 0.0, 'aufrufer' => $k['aufrufer'] ?? null];
                    $ms = (float) ($k['ms'] ?? 0);
                    $g['anzahl']++;
                    $g['summe'] += $ms;
                    $g['max'] = max($g['max'], $ms);
                    $gruppen[$schluessel] = $g;
                } elseif (isset(self::NACHGELADEN[$k['art'] ?? ''])) {
                    $s = $k['art'] . "\0" . ($k['aufrufer'] ?? '');
                    $n = $nachgeladen[$s] ?? ['spalte' => self::NACHGELADEN[$k['art']], 'aufrufer' => $k['aufrufer'] ?? null, 'anzahl' => 0, 'rezepte' => []];
                    $n['anzahl']++;
                    if (isset($k['recipe_id']) && count($n['rezepte']) < 5 && ! in_array((int) $k['recipe_id'], $n['rezepte'], true)) {
                        $n['rezepte'][] = (int) $k['recipe_id'];
                    }
                    $nachgeladen[$s] = $n;
                } elseif (($k['art'] ?? null) === 'zusammenfassung') {
                    $zusammenfassungen++;
                    foreach ((array) ($k['n_plus_1'] ?? []) as $m) {
                        $s = ($m['sql'] ?? '?') . "\0" . ($k['eingang'] ?? '?');
                        $n = $nPlus1[$s] ?? ['sql' => $m['sql'] ?? '?', 'eingang' => $k['eingang'] ?? '?', 'faelle' => 0, 'max_anzahl' => 0];
                        $n['faelle']++;
                        $n['max_anzahl'] = max($n['max_anzahl'], (int) ($m['anzahl'] ?? 0));
                        $nPlus1[$s] = $n;
                    }
                }
            }
        }

        $this->info(count($dateien) . ' Datei(en), ' . array_sum(array_column($gruppen, 'anzahl')) . ' langsame Abfragen, ' . $zusammenfassungen . ' Zusammenfassungen, '
            . array_sum(array_column($nachgeladen, 'anzahl')) . ' nachgeladene Spalten');
        $zeilen = fn (array $liste) => array_map(fn ($g) => [
            $g['anzahl'], number_format($g['summe'], 0, ',', '.'), number_format($g['max'], 0, ',', '.'),
            $g['eingang'], mb_strimwidth($g['sql'], 0, 110, '…'), $g['aufrufer'] ?? '',
        ], array_slice($liste, 0, $top));
        $kopf = ['Anzahl', 'Summe ms', 'Max ms', 'Eingang', 'SQL-Muster', 'Aufrufer'];

        foreach (['Summe' => 'summe', 'Anzahl' => 'anzahl', 'Max' => 'max'] as $titel => $feld) {
            $liste = array_values($gruppen);
            usort($liste, fn ($a, $b) => $b[$feld] <=> $a[$feld]);
            $this->line('');
            $this->line("<comment>Top {$top} nach {$titel}</comment>");
            $this->table($kopf, $zeilen($liste));
        }

        if ($nPlus1 !== []) {
            $liste = array_values($nPlus1);
            usort($liste, fn ($a, $b) => [$b['faelle'], $b['max_anzahl']] <=> [$a['faelle'], $a['max_anzahl']]);
            $this->line('');
            $this->line("<comment>N+1-Muster (Top {$top})</comment>");
            $this->table(['Fälle', 'max. Wiederholung', 'Eingang', 'SQL-Muster'],
                array_map(fn ($n) => [$n['faelle'], $n['max_anzahl'], $n['eingang'], mb_strimwidth($n['sql'], 0, 110, '…')], array_slice($liste, 0, $top)));
        }

        if ($nachgeladen !== []) {
            $liste = array_values($nachgeladen);
            usort($liste, fn ($a, $b) => $b['anzahl'] <=> $a['anzahl']);
            $this->line('');
            $this->line("<comment>Nachgeladene Spalten (Select ergänzen, Top {$top})</comment>");
            $this->table(['Anzahl', 'Spalte', 'Aufrufer', 'Rezepte (Beispiele)'],
                array_map(fn ($n) => [$n['anzahl'], $n['spalte'], $n['aufrufer'] ?? '—', implode(', ', $n['rezepte'])], array_slice($liste, 0, $top)));
        }

        return self::SUCCESS;
    }
}
