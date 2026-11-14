<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Services\GpFelderPlanService;

/**
 * GP-Lauf (Kurator 14.11.2026, Regelwerk v3.10.0): Strukturfelder vieler GPs nach JSONL-Plan setzen, Namen genau wie updateGp/gps.PUT neu bilden.
 *
 * Plan je Zeile: `{"gp_id":N,"name_alt":"…","felder":{…},"notiz":"…"}`. Trockenlauf = Default (schreibt nur Bericht + Konfliktliste); `--apply` braucht
 * einen Backup-Nachweis (< 24 h), schreibt ein Protokoll und ist per `--zurueck=<Protokoll>` umkehrbar. `--global` = globale Stamm-GPs (nur Master).
 * Konflikte (Name seit Plan geändert, Regelwerk-Fehler, Namens-/Key-Kollision = Merge-Kandidat, unzulässige Werte) werden nie geschrieben.
 * Danach: Zähler + ungültige Leads, Rezepte der geänderten GPs neu rechnen (außer --ohne-recompute). Kein KI-Aufruf.
 */
class GpFelderCommand extends Command
{
    use Concerns\GpLaufKonsole;

    protected $signature = 'foodalchemist:gp-felder
        {--team= : Team, dem die GPs gehören (Standard: konfiguriertes Master-Team)}
        {--global : GLOBALE Stamm-GPs (team_id NULL, stamm_class stamm_paket); nur als Master-Team}
        {--wg= : nur GPs dieser Warengruppe (Code, z. B. 03)}
        {--plan= : Plan (JSONL: gp_id, name_alt, felder{…}, notiz)}
        {--bericht= : Ordner für Bericht, Konfliktliste und Protokoll (Standard: storage/app/fa-gp-felder)}
        {--backup-nachweis= : Pfad zum Backup-Dump (Pflicht bei --apply; nicht älter als 24 h)}
        {--apply : wirklich schreiben}
        {--ohne-recompute : danach keine Rezepte neu rechnen}
        {--zurueck= : Protokoll (JSON) eines --apply-Laufs zurücknehmen}';

    protected $description = 'GP-Lauf: Felder nach JSONL-Plan setzen, Namen nach Regelwerk neu bilden (Trockenlauf, Backup, Protokoll, Rückweg)';

    public function handle(GpFelderPlanService $svc): int
    {
        if (($hinweis = \Platform\FoodAlchemist\Support\Speicher::anheben('1024M')) !== null) {   // PHP 8.5: nie über max_memory_limit
            $this->warn($hinweis);
        }
        if ($this->option('zurueck') !== null) {
            $pfad = (string) $this->option('zurueck');
            if (! is_file($pfad) || ! is_array($p = json_decode((string) file_get_contents($pfad), true))) {
                $this->error("Protokoll nicht lesbar: {$pfad}");

                return self::FAILURE;
            }
            $r = $svc->zuruecknehmen($p);
            $this->info("Zurückgenommen: {$r['zurueck']} · übersprungen (inzwischen geändert): {$r['uebersprungen']}");
            if (! $this->option('ohne-recompute') && ($ids = array_column($p['felder'] ?? [], 'id')) !== []) {
                $this->info(app(\Platform\FoodAlchemist\Services\GpLaufNachlauf::class)->rechneRezepte($ids) . ' Rezept(e) neu gerechnet.');
            }

            return self::SUCCESS;
        }

        if (($b = $this->bereichAusOptionen()) === null) {
            return self::FAILURE;
        }
        ['team' => $team, 'global' => $global] = $b;
        $planPfad = (string) $this->option('plan');
        if ($planPfad === '' || ! is_readable($planPfad)) {
            $this->error('--plan=<JSONL> fehlt oder ist nicht lesbar.');

            return self::FAILURE;
        }
        $apply = (bool) $this->option('apply');
        if ($apply && ! $this->backupOk()) {
            return self::FAILURE;
        }
        $bericht = (string) ($this->option('bericht') ?: storage_path('app/fa-gp-felder'));
        File::ensureDirectoryExists($bericht);
        $stempel = now()->format('Ymd_His');
        $wg = $this->option('wg') !== null ? (string) $this->option('wg') : null;

        $gelesen = $svc->lesePlan($planPfad);
        $plan = $svc->plane($global ? null : $team, $team, $gelesen['zeilen'], $wg);
        $konflikte = [...$gelesen['konflikte'], ...$plan['konflikte']];
        $ziel = $global ? 'GLOBALE Stamm-GPs (Master-Team ' . $team . ')' : 'Team ' . $team;
        $this->line($apply ? '<comment>APPLY</comment> auf ' . $ziel : 'TROCKENLAUF auf ' . $ziel . ' (schreibt nur Berichte)');
        $this->info(sprintf('Plan: %d Zeile(n) · ausführbar %d · ohne Änderung %d · Konflikte %d',
            count($gelesen['zeilen']) + count($gelesen['konflikte']), count($plan['aenderungen']), $plan['ohne_aenderung'], count($konflikte)));
        $jeFeld = [];
        foreach ($plan['aenderungen'] as $a) {
            foreach ($a['geaendert'] as $f) {
                $jeFeld[$f] = ($jeFeld[$f] ?? 0) + 1;
            }
        }
        arsort($jeFeld);
        if ($jeFeld !== []) {
            $this->table(['Feld', 'GPs'], array_map(fn ($k, $v) => [$k, $v], array_keys($jeFeld), $jeFeld));
        }

        $this->schreibeCsv("{$bericht}/{$stempel}_felder_bericht.csv", ['gp_id', 'name_alt', 'name_neu', 'geaenderte_felder', 'status', 'hinweise'], $plan['bericht']);
        $this->schreibeCsv("{$bericht}/{$stempel}_felder_konflikte.csv", ['zeile', 'gp_id', 'name_alt', 'grund'],
            array_map(fn ($k) => [$k['zeile'], $k['gp_id'], $k['name_alt'], $k['grund']], $konflikte));
        $this->info("Bericht: {$bericht}/{$stempel}_felder_bericht.csv · Konflikte: {$bericht}/{$stempel}_felder_konflikte.csv");

        if (! $apply) {
            $this->comment('Trockenlauf — nichts geändert.');

            return self::SUCCESS;
        }

        $teamModell = Team::findOrFail($team);
        $protokoll = ['lauf' => $stempel, 'team' => $team, 'global' => $global, 'wg' => $wg, 'plan' => $planPfad, 'felder' => [], 'n_las' => [], 'lead' => []];
        $r = $svc->fuehreAus($teamModell, $plan['aenderungen'], $protokoll);
        foreach ($r['fehler'] as $f) {
            $this->warn("GP {$f['gp_id']} nicht geschrieben: {$f['grund']}");
        }
        $rezepte = $svc->nachlauf($teamModell, $r['betroffen'], $protokoll, ! $this->option('ohne-recompute'));
        $pfad = "{$bericht}/{$stempel}_felder_protokoll.json";
        File::put($pfad, json_encode($protokoll, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->info(sprintf('Geschrieben: %d GP(s), Fehler %d, Leads neu %d, Rezepte neu gerechnet %d. Protokoll: %s',
            count($r['betroffen']), count($r['fehler']), count($protokoll['lead']), $rezepte, $pfad));
        $this->warn('Danach: Embeddings off-peak (foodalchemist:embed), ggf. foodalchemist:gp-allergen-backfill.');

        return self::SUCCESS;
    }
}
