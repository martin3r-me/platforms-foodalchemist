<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Services\Knowledge\EinordnungSicherungService;

/**
 * Die Einordnung sichern, prüfen und zurückspielen — Gegenstück zu `wissen-kanon-sicherung`.
 *
 * Anlass: der Korpus-Durchgang über 1.074 Dossiers. `art`, `geltung`, `datenwerte` und die
 * Aliase leben ausschliesslich in der Datenbank; der Vault-Export trägt sie nicht mit. Ohne
 * diese Datei wäre die Kurationsarbeit — also das menschliche Urteil, der teure Teil — bei
 * einem Datenbankverlust ersatzlos verloren.
 *
 * `export`  schreibt den Live-Stand ins Repo (nur Dossiers, die eine Einordnung tragen)
 * `pruefen` hält Datei gegen live und meldet vier getrennte Fälle
 * `import`  spielt zurück; ohne `--apply` nur Vorschau
 */
class WissenEinordnungSicherungCommand extends Command
{
    protected $signature = 'foodalchemist:wissen-einordnung-sicherung
        {richtung : export | pruefen | import}
        {--team=6 : Team-Kontext}
        {--datei= : Pfad (Default: database/einordnung/einordnung-team-<id>.json im Modul)}
        {--apply : beim Import wirklich schreiben (ohne: Vorschau)}
        {--json : Maschinenlesbar}';

    protected $description = 'Sichert die Wissens-Einordnung (Art, Geltung, Datenwerte, Aliase) als Datei im Repo';

    public function handle(EinordnungSicherungService $dienst): int
    {
        $richtung = (string) $this->argument('richtung');
        if (! in_array($richtung, ['export', 'pruefen', 'import'], true)) {
            $this->error('richtung muss export, pruefen oder import sein.');

            return self::INVALID;
        }
        $team = Team::find((int) $this->option('team'));
        if ($team === null) {
            $this->error('Team '.$this->option('team').' nicht gefunden.');

            return self::INVALID;
        }
        $datei = (string) ($this->option('datei') ?: $dienst->standardDatei((int) $team->id));

        return match ($richtung) {
            'export' => $this->export($dienst, $team, $datei),
            'pruefen' => $this->pruefen($dienst, $team, $datei),
            default => $this->import($dienst, $team, $datei),
        };
    }

    private function export(EinordnungSicherungService $dienst, Team $team, string $datei): int
    {
        $inhalt = $dienst->inhalt($team);
        @mkdir(dirname($datei), 0775, true);
        file_put_contents($datei, json_encode($inhalt, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");

        if ($this->option('json')) {
            $this->line((string) json_encode(['datei' => $datei, 'zeilen' => count($inhalt['zeilen'])], JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }
        $this->info(sprintf('✓ %d eingeordnete Dossiers gesichert → %s', count($inhalt['zeilen']), $datei));
        $this->line('Die Datei gehört in den Commit — sie ist der einzige Rückweg für diese Arbeit.');

        return self::SUCCESS;
    }

    private function pruefen(EinordnungSicherungService $dienst, Team $team, string $datei): int
    {
        try {
            $ab = $dienst->abgleich($team, $dienst->lade($datei));
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($ab, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line(sprintf('deckungsgleich: %d', $ab['deckungsgleich']));
            foreach ([
                'nur_live' => 'nur LIVE (seit dem Export eingeordnet — Export nachziehen)',
                'nur_datei' => 'nur in der DATEI (live verloren oder zurückgenommen)',
                'ohne_dossier' => '⚠ ohne Dossier (Slug gibt es nicht mehr — diese Zeile lässt sich NICHT einspielen)',
            ] as $feld => $label) {
                if ($ab[$feld] !== []) {
                    $this->line(sprintf('%s: %d', $label, count($ab[$feld])));
                    foreach (array_slice($ab[$feld], 0, 10) as $slug) {
                        $this->line('    '.$slug);
                    }
                }
            }
            if ($ab['abweichend'] !== []) {
                $this->line(sprintf('abweichend: %d', count($ab['abweichend'])));
                foreach (array_slice($ab['abweichend'], 0, 10) as $a) {
                    $this->line('    '.$a['slug'].' — '.implode(', ', array_keys(array_diff_key($a, ['slug' => 1]))));
                }
            }
        }

        // Abweichungen sind KEIN Fehler, sondern der Normalfall während der Kuration. Ein Befund
        // ist nur, was sich nicht mehr einspielen liesse.
        return $ab['ohne_dossier'] === [] ? self::SUCCESS : self::FAILURE;
    }

    private function import(EinordnungSicherungService $dienst, Team $team, string $datei): int
    {
        try {
            $zeilen = $dienst->lade($datei);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $apply = (bool) $this->option('apply');
        $e = $dienst->spielEin($team, $zeilen, $apply);

        if ($this->option('json')) {
            $this->line((string) json_encode($e + ['modus' => $apply ? 'geschrieben' : 'vorschau'],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return $e['fehler'] === [] ? self::SUCCESS : self::FAILURE;
        }

        $this->line(sprintf('%s: %d Einordnungen, %d Aliase, %d unverändert übersprungen',
            $apply ? 'Geschrieben' : 'Vorschau', $e['gesetzt'], $e['aliase'], $e['uebersprungen']));
        foreach (array_slice($e['fehler'], 0, 15) as $f) {
            $this->warn('  '.$f['slug'].': '.$f['grund']);
        }
        if (! $apply) {
            $this->line('Nichts geschrieben. Mit --apply ausführen.');
        }

        return $e['fehler'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
