<?php

namespace Platform\FoodAlchemist\Console\Concerns;

use Platform\FoodAlchemist\Support\MasterTeam;

/**
 * Gemeinsame Konsolen-Bausteine der GP-Lauf-Kommandos (gp-felder, gp-bereinigen, gp-umhaengen): Bereich aus --team/--global, Backup-Nachweis, CSV.
 */
trait GpLaufKonsole
{
    /**
     * Team und Bereich aus den Optionen. `--global` (team_id NULL, stamm_paket) nur als Master-Team. Null = Fehler (schon ausgegeben).
     *
     * @return array{team: int, global: bool}|null
     */
    protected function bereichAusOptionen(): ?array
    {
        $team = $this->option('team') ?? MasterTeam::id();
        if (! is_numeric($team)) {
            $this->error('Kein Team: --team=N angeben oder Master-Team konfigurieren.');

            return null;
        }
        $team = (int) $team;
        $global = (bool) $this->option('global');
        if ($global && (MasterTeam::id() === null || $team !== (int) MasterTeam::id())) {
            $this->error('--global ist nur als Master-Team erlaubt (Master: ' . (MasterTeam::id() ?? 'nicht konfiguriert') . ', angefragt: ' . $team . ').');

            return null;
        }

        return ['team' => $team, 'global' => $global];
    }

    protected function backupOk(): bool
    {
        $b = (string) $this->option('backup-nachweis');
        if ($b === '' || ! is_file($b) || filesize($b) < 1024) {
            $this->error('--apply braucht --backup-nachweis=<Dump-Datei> (existiert, nicht leer).');

            return false;
        }
        if (filemtime($b) < now()->subDay()->getTimestamp()) {
            $this->error('Backup ist älter als 24 h — bitte frisch ziehen.');

            return false;
        }
        $this->line('Backup-Nachweis: ' . $b . ' (' . round(filesize($b) / 1048576, 1) . ' MB, sha256 ' . substr(hash_file('sha256', $b), 0, 12) . ')');

        return true;
    }

    /** @param list<string> $kopf @param list<list<mixed>> $zeilen */
    protected function schreibeCsv(string $pfad, array $kopf, array $zeilen): void
    {
        $h = fopen($pfad, 'wb');
        fwrite($h, "\xEF\xBB\xBF");
        fputcsv($h, $kopf, ';', '"', '');
        foreach ($zeilen as $z) {
            fputcsv($h, array_map(fn ($v) => $v === null ? '' : (string) (is_bool($v) ? ($v ? '1' : '0') : $v), $z), ';', '"', '');
        }
        fclose($h);
    }
}
