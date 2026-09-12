<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Platform\FoodAlchemist\Services\Knowledge\SteuerungSicherungService;

/**
 * Die Steuerung sichern, prüfen und zurückspielen — die dritte Sicherung neben Kanon und
 * Einordnung.
 *
 * Drei Dinge entscheiden, welches Wissen ein Schritt bekommt: Kanon („was muss"), Routings
 * („was darf gesucht werden") und Budget („wie viel passt"). Zwei davon waren gesichert, die
 * anderen beiden nicht — am 2026-09-12 lagen 41 frisch angelegte Arten-Routings und zwei
 * Budget-Werte ausschliesslich in der Datenbank.
 *
 * Global, ohne Team: beide Tabellen haben keine `team_id`.
 */
class WissenSteuerungSicherungCommand extends Command
{
    protected $signature = 'foodalchemist:wissen-steuerung-sicherung
        {richtung : export | pruefen | import}
        {--datei= : Pfad (Default: database/steuerung/steuerung-global.json im Modul)}
        {--apply : beim Import wirklich schreiben (ohne: Vorschau)}
        {--json : Maschinenlesbar}';

    protected $description = 'Sichert Routings und Budgets (die Verdrahtung) als Datei im Repo';

    public function handle(SteuerungSicherungService $dienst): int
    {
        $richtung = (string) $this->argument('richtung');
        if (! in_array($richtung, ['export', 'pruefen', 'import'], true)) {
            $this->error('richtung muss export, pruefen oder import sein.');

            return self::INVALID;
        }
        $datei = (string) ($this->option('datei') ?: $dienst->standardDatei());

        return match ($richtung) {
            'export' => $this->export($dienst, $datei),
            'pruefen' => $this->pruefen($dienst, $datei),
            default => $this->import($dienst, $datei),
        };
    }

    private function export(SteuerungSicherungService $dienst, string $datei): int
    {
        $inhalt = $dienst->inhalt();
        @mkdir(dirname($datei), 0775, true);
        file_put_contents($datei, json_encode($inhalt, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");

        if ($this->option('json')) {
            $this->line((string) json_encode(['datei' => $datei, 'routings' => count($inhalt['routings']),
                'budgets' => count($inhalt['budgets'])], JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }
        $arten = count(array_filter($inhalt['routings'], fn ($r) => $r['art'] !== null));
        $this->info(sprintf('✓ %d Routings (%d nach Art) und %d abweichende Budgets → %s',
            count($inhalt['routings']), $arten, count($inhalt['budgets']), $datei));

        // Auf einem Server liegt das Modul unter vendor/ — der nächste composer update
        // überschreibt es. Dieselbe Falle wie bei der Einordnungs-Sicherung.
        if (str_contains($datei, '/vendor/')) {
            $this->warn('⚠ Pfad liegt unter vendor/ — der nächste Deploy überschreibt ihn. Datei ins Repo holen.');
        }

        return self::SUCCESS;
    }

    private function pruefen(SteuerungSicherungService $dienst, string $datei): int
    {
        try {
            $ab = $dienst->abgleich($dienst->lade($datei));
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($ab, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line('deckungsgleich: '.$ab['deckungsgleich']);
            foreach (['nur_live' => 'nur LIVE (seit dem Export gesetzt — Export nachziehen)',
                'nur_datei' => 'nur in der DATEI (live verloren oder entfernt)'] as $feld => $label) {
                if ($ab[$feld] !== []) {
                    $this->line(sprintf('%s: %d', $label, count($ab[$feld])));
                    foreach (array_slice($ab[$feld], 0, 10) as $k) {
                        $this->line('    '.$k);
                    }
                }
            }
            foreach ($ab['abweichend'] as $a) {
                $this->line('  abweichend: '.$a['routing']);
            }
            foreach ($ab['budgets_abweichend'] as $b) {
                $this->line(sprintf('  Budget %s: live %s, Datei %s', $b['prompt_key'], $b['live'] ?? '—', $b['datei']));
            }
        }

        // Abweichung ist während laufender Verdrahtung der Normalfall, kein Fehler.
        return self::SUCCESS;
    }

    private function import(SteuerungSicherungService $dienst, string $datei): int
    {
        try {
            $inhalt = $dienst->lade($datei);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $apply = (bool) $this->option('apply');
        $e = $dienst->spielEin($inhalt, $apply);

        if ($this->option('json')) {
            $this->line((string) json_encode($e + ['modus' => $apply ? 'geschrieben' : 'vorschau'], JSON_UNESCAPED_UNICODE));

            return $e['fehler'] === [] ? self::SUCCESS : self::FAILURE;
        }
        $this->line(sprintf('%s: %d Routings, %d Budgets', $apply ? 'Geschrieben' : 'Vorschau', $e['routings'], $e['budgets']));
        foreach (array_slice($e['fehler'], 0, 15) as $f) {
            $this->warn('  '.($f['routing'] ?? $f['budget'] ?? '?').': '.$f['grund']);
        }
        if (! $apply) {
            $this->line('Nichts geschrieben. Mit --apply ausführen.');
        }

        return $e['fehler'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
