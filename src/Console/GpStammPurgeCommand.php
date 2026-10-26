<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Platform\Core\Models\User;
use Platform\FoodAlchemist\Services\GpStamm\GpStammPurgeService;

/**
 * Löscht den GLOBALEN GP-Stamm (team_id NULL) vor dem Neu-Import (Plan_Neuaufbau Schritt 4, Punkt 2).
 *
 * Default = Trockenlauf: zählt je Tabelle, prüft die Riegel, schreibt einen Bericht — ändert nichts. `--apply` braucht einen frischen Backup-Nachweis
 * (≤ 24 h) und einen Plattform-Admin, läuft in EINER Transaktion und löscht danach die Vektoren. Team-eigene GPs, Lieferantenartikel (supplier_items,
 * Allergene, Preise) bleiben unberührt. Rückweg = Dump-Wiederherstellung (die GP-Tabellen), danach `embed`.
 */
class GpStammPurgeCommand extends Command
{
    protected $signature = 'foodalchemist:gp-stamm-purge
        {--apply : wirklich löschen (braucht --backup-nachweis und --admin)}
        {--backup-nachweis= : Pfad zum Backup-Dump (Pflicht bei --apply; existiert, nicht leer, nicht älter als 24 h)}
        {--admin= : E-Mail des Plattform-Admins (Pflicht bei --apply)}
        {--erwartet-gps= : Soll-Zahl globaler GPs (z. B. 7158 für io); der Bericht zeigt Soll/Ist und die Differenz}
        {--bericht= : Ordner für Bericht und Protokoll (Standard: storage/app/fa-gp-stamm-purge)}
        {--ohne-vektoren : Embedding-Punkte nicht löschen}';

    protected $description = 'Globalen GP-Stamm (team_id NULL) samt Kindern hart löschen — Neu-Import-Vorbereitung (Trockenlauf, Riegel, Backup-Nachweis, Protokoll)';

    public function handle(GpStammPurgeService $svc): int
    {
        $bericht = (string) ($this->option('bericht') ?: storage_path('app/fa-gp-stamm-purge'));
        File::ensureDirectoryExists($bericht);
        $stempel = now()->format('Ymd_His');
        $z = $svc->zaehlen();
        $this->tabelle($z);
        $soll = $this->option('erwartet-gps') !== null ? (int) $this->option('erwartet-gps') : null;
        if ($soll !== null) {
            $diff = $z['gps_global'] - $soll;
            $this->line("Soll/Ist globale GPs: Soll {$soll} · Ist {$z['gps_global']} · Differenz ".($diff >= 0 ? '+' : '').$diff);
            $z['soll_gps_global'] = $soll;
            $z['differenz_gps_global'] = $diff;
        }
        $riegel = $svc->riegelTreffer($z);
        File::put("{$bericht}/{$stempel}_gp_stamm_purge_trockenlauf.json", json_encode($z, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        if ($riegel !== []) {
            $this->error('RIEGEL GREIFT — an globalen GPs hängen noch Daten: '.implode(', ', $riegel).'. Apply wäre abgebrochen.');
        }
        if (! $this->option('apply')) {
            $this->comment('Trockenlauf — nichts gelöscht. Bericht: '.$bericht);

            return $riegel === [] ? self::SUCCESS : self::FAILURE;
        }
        if ($riegel !== []) {
            return self::FAILURE;
        }
        if (! $this->backupOk()) {
            return self::FAILURE;
        }
        $user = $this->admin();
        if ($user === null) {
            return self::FAILURE;
        }
        try {
            $p = $svc->ausfuehren($user, ! $this->option('ohne-vektoren'));
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $pfad = "{$bericht}/{$stempel}_gp_stamm_purge_protokoll.json";
        File::put($pfad, json_encode($p, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->info('Gelöscht (je Tabelle):');
        foreach ($p['geloescht'] as $t => $n) {
            $this->line("  {$t}: {$n}");
        }
        $this->info("Vektoren: {$p['vektoren']['versucht']} versucht, {$p['vektoren']['fehler']} Fehler · globale GPs danach: {$p['nachher']['gps_global']} · Team-GPs: {$p['nachher']['gps_team_eigen']} · Dauer {$p['dauer_s']} s");
        $this->warn("Protokoll: {$pfad}. Danach: gp-paket-import, gp-allergen-backfill, embed, recompute.");

        return self::SUCCESS;
    }

    private function tabelle(array $z): void
    {
        $this->info("Globale GPs: {$z['gps_global']} (aktiv {$z['gps_global_aktiv']}, soft-gelöscht {$z['gps_global_soft_geloescht']}) · Team-eigene GPs (bleiben): {$z['gps_team_eigen']}");
        $zeilen = [];
        foreach ($z['riegel'] as $k => $n) {
            $zeilen[] = ['Riegel', $k, $n];
        }
        foreach ($z['ohne_fk'] as $k => $n) {
            $zeilen[] = ['ohne FK (löschen)', $k, $n];
        }
        $zeilen[] = ['Struktur (löschen)', 'foodalchemist_supplier_item_structures', $z['strukturen']];
        foreach ($z['cascade'] as $k => $n) {
            $zeilen[] = ['CASCADE-Kind (löschen)', $k, $n];
        }
        $zeilen[] = ['Selbstverweise lösen', 'derivat_von_gp_id / merged_into_id', $z['selbstverweise']];
        $zeilen[] = ['bleibt unberührt', 'foodalchemist_supplier_items', $z['supplier_items_gesamt']];
        $this->table(['Art', 'Tabelle', 'Zeilen'], $zeilen);
    }

    private function backupOk(): bool
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
        $this->line('Backup-Nachweis: '.$b.' ('.round(filesize($b) / 1048576, 1).' MB, sha256 '.substr(hash_file('sha256', $b), 0, 12).')');

        return true;
    }

    private function admin(): ?User
    {
        $mail = strtolower(trim((string) $this->option('admin')));
        if ($mail === '') {
            $this->error('--admin=<E-Mail des Plattform-Admins> fehlt (nur Plattform-Admin darf löschen).');

            return null;
        }
        $user = User::whereRaw('LOWER(email) = ?', [$mail])->first();
        if ($user === null) {
            $this->error("Kein Benutzer mit der E-Mail {$mail}.");

            return null;
        }

        return $user;
    }
}
