<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Platform\FoodAlchemist\Services\GpBereinigenService;
use Platform\FoodAlchemist\Services\RecipeRecomputeService;
use Platform\FoodAlchemist\Support\MasterTeam;

/**
 * GP-Stamm bereinigen (Kurator/Dominique 19.10.2026) — Dubletten mergen, deterministische Namensfixes.
 *
 * Führt GEPRÜFTE PLÄNE aus (Sichtungs-CSV), prüft jede Zeile gegen den Live-Stand und legt alles Unklare in Review-Listen ab.
 * Default = Trockenlauf (schreibt nur Berichte). `--apply` braucht einen Backup-Nachweis, schreibt ein Protokoll und ist per
 * `--zurueck=<Protokoll>` umkehrbar. Vor `--apply` mit Dominique abstimmen: während des Laufs darf niemand GPs pflegen.
 *
 * Reihenfolge je Warengruppe (Kurator): Link-Review → Re-Link → dieses Kommando → Anker. `--review-dir` liest die Link-Review-CSVs und
 * überspringt Paare mit merge_hinweis „kein Merge".
 */
class GpBereinigenCommand extends Command
{
    protected $signature = 'foodalchemist:gp-bereinigen
        {--team= : Team, dem die GPs gehören (Standard: konfiguriertes Master-Team)}
        {--phase=dubletten,namen : Komma-Liste: dubletten, namen}
        {--plan-dubletten= : Sichtungs-CSV dubletten_fa.csv}
        {--plan-namen= : Sichtungs-CSV benennung_fa.csv}
        {--review-dir= : Ordner mit <WG>_artikel.csv (merge_hinweis „kein Merge" wird respektiert)}
        {--wg= : nur diese Warengruppe (Code, z. B. 03)}
        {--key-folgt-name : gp_key/Slug bei JEDEM Namensfix mitziehen (Standard: nur bei Plural→Singular)}
        {--bericht= : Ordner für Review-Listen und Protokolle (Standard: storage/app/fa-gp-bereinigen)}
        {--backup-nachweis= : Pfad zum Backup-Dump (Pflicht bei --apply; nicht älter als 24 h)}
        {--apply : wirklich schreiben}
        {--ohne-recompute : nach dem Merge keine Rezepte neu rechnen}
        {--zurueck= : Protokoll (JSON) eines --apply-Laufs zurücknehmen}';

    protected $description = 'GP-Stamm bereinigen: Dubletten mergen, Namen nach Regelwerk korrigieren (Trockenlauf, Backup, Protokoll, Rückweg)';

    public function handle(GpBereinigenService $svc): int
    {
        ini_set('memory_limit', '1024M');

        if ($this->option('zurueck') !== null) {
            return $this->zurueck($svc, (string) $this->option('zurueck'));
        }

        $team = $this->option('team') ?? MasterTeam::id();
        if (! is_numeric($team)) {
            $this->error('Kein Team: --team=N angeben oder Master-Team konfigurieren.');

            return self::FAILURE;
        }
        $team = (int) $team;
        $phasen = array_map('trim', explode(',', (string) $this->option('phase')));
        $apply = (bool) $this->option('apply');
        $wg = $this->option('wg') !== null ? (string) $this->option('wg') : null;
        $bericht = (string) ($this->option('bericht') ?: storage_path('app/fa-gp-bereinigen'));
        File::ensureDirectoryExists($bericht);
        $stempel = now()->format('Ymd_His');

        if ($apply && ! $this->backupOk()) {
            return self::FAILURE;
        }

        $keinMerge = $this->option('review-dir') ? $svc->keinMergeAusReview((string) $this->option('review-dir')) : [];
        $this->line($this->option('review-dir') ? 'Link-Review: ' . count($keinMerge) . ' GP(s) mit „kein Merge".' : 'Kein --review-dir: Link-Review wird NICHT berücksichtigt.');
        $this->line($apply ? '<comment>APPLY</comment> auf Team ' . $team : 'TROCKENLAUF auf Team ' . $team . ' (schreibt nur Berichte)');

        $konflikte = [];
        $protokoll = ['lauf' => $stempel, 'team' => $team, 'wg' => $wg, 'phasen' => $phasen, 'moves' => [], 'deleted' => [], 'merges' => [], 'renames' => []];
        $recipeIds = [];

        if (in_array('dubletten', $phasen, true)) {
            $csv = (string) $this->option('plan-dubletten');
            if ($csv === '') {
                $this->error('--plan-dubletten fehlt.');

                return self::FAILURE;
            }
            $plan = $svc->planDubletten($team, $csv, $keinMerge, $wg);
            $konflikte = array_merge($konflikte, $plan['konflikte']);
            $this->zeigeDubletten($plan);
            if ($apply) {
                foreach ($plan['gruppen'] as $g) {
                    foreach ($g['verlierer'] as $v) {
                        $recipeIds = array_merge($recipeIds, $svc->merge($g['sieger'], $v, $protokoll)['recipe_ids']);
                    }
                }
            }
        }

        if (in_array('namen', $phasen, true)) {
            $csv = (string) $this->option('plan-namen');
            if ($csv === '') {
                $this->error('--plan-namen fehlt.');

                return self::FAILURE;
            }
            $plan = $svc->planNamen($team, $csv, $wg, (bool) $this->option('key-folgt-name'));
            $konflikte = array_merge($konflikte, $plan['konflikte']);
            $this->zeigeNamen($plan);
            $this->schreibeCsv("{$bericht}/{$stempel}_namen_plan.csv", ['gp_id', 'wg', 'regeln', 'name_alt', 'name_neu', 'key_alt', 'key_neu', 'key_wechsel', 'key_nicht_ableitbar'],
                array_map(fn ($a) => [$a['gp_id'], $a['wg'], implode(' | ', $a['regeln']), $a['alt']['name'], $a['neu']['name'], $a['alt']['gp_key'], $a['neu']['gp_key'],
                    $a['key_wechsel'] ? 'ja' : 'nein', $a['key_nicht_ableitbar'] ? 'ja' : 'nein'], $plan['aenderungen']));
            if ($apply) {
                foreach ($plan['aenderungen'] as $a) {
                    $svc->benenneUm($a, $protokoll);
                }
            }
        }

        usort($konflikte, fn ($a, $b) => [$a['wg'] ?? '', $a['phase'], $a['gp_id']] <=> [$b['wg'] ?? '', $b['phase'], $b['gp_id']]);
        $this->schreibeCsv("{$bericht}/{$stempel}_review_liste.csv", ['wg', 'phase', 'gruppe', 'gp_id', 'name', 'grund'],
            array_map(fn ($k) => [$k['wg'], $k['phase'], $k['gruppe'], $k['gp_id'], $k['name'], $k['grund']], $konflikte));
        $this->info('Review-Liste (nach Warengruppe): ' . count($konflikte) . ' Fälle → ' . "{$bericht}/{$stempel}_review_liste.csv");

        if ($apply) {
            $pfad = "{$bericht}/{$stempel}_protokoll.json";
            File::put($pfad, json_encode($protokoll, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $this->info("Protokoll: {$pfad}");
            $recipeIds = array_values(array_unique($recipeIds));
            if ($recipeIds !== [] && ! $this->option('ohne-recompute')) {
                $this->line('Rechne ' . count($recipeIds) . ' Rezept(e) neu …');
                app(RecipeRecomputeService::class)->recomputeMany($recipeIds);
            }
            $this->warn('Danach: foodalchemist:gp-allergen-backfill, ggf. foodalchemist:lead-la-repick --team=' . $team . ' (Lead-LA der Sieger prüfen), Embeddings off-peak.');
        } else {
            $this->comment('Trockenlauf — nichts geändert.');
        }

        return self::SUCCESS;
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
        $this->line('Backup-Nachweis: ' . $b . ' (' . round(filesize($b) / 1048576, 1) . ' MB, sha256 ' . substr(hash_file('sha256', $b), 0, 12) . ')');

        return true;
    }

    private function zurueck(GpBereinigenService $svc, string $pfad): int
    {
        if (! is_file($pfad)) {
            $this->error("Protokoll nicht gefunden: {$pfad}");

            return self::FAILURE;
        }
        $p = json_decode((string) file_get_contents($pfad), true);
        if (! is_array($p)) {
            $this->error('Protokoll nicht lesbar.');

            return self::FAILURE;
        }
        $r = $svc->zuruecknehmen($p);
        $this->info("Zurückgenommen: {$r['zurueck']} · übersprungen (inzwischen geändert): {$r['uebersprungen']}");

        return self::SUCCESS;
    }

    /** @param array{gruppen: list<array<string,mixed>>, konflikte: list<array<string,mixed>>} $plan */
    private function zeigeDubletten(array $plan): void
    {
        $je = [];
        $verweise = [];
        foreach ($plan['gruppen'] as $g) {
            $je[$g['wg']] = ($je[$g['wg']] ?? 0) + 1;
            foreach ($g['verweise'] as $tab) {
                foreach ($tab as $k => $n) {
                    $verweise[$k] = ($verweise[$k] ?? 0) + $n;
                }
            }
        }
        ksort($je);
        ksort($verweise);
        $this->newLine();
        $this->info('Phase Dubletten: ' . count($plan['gruppen']) . ' Merge-Gruppe(n) ausführbar, ' . count($plan['konflikte']) . ' Konflikt(e)');
        $this->table(['WG', 'Gruppen'], array_map(fn ($k, $v) => [$k, $v], array_keys($je), $je));
        $this->table(['Verweis, der umgehängt wird', 'Zeilen'], array_map(fn ($k, $v) => [$k, $v], array_keys($verweise), $verweise));
    }

    /** @param array{aenderungen: list<array<string,mixed>>, konflikte: list<array<string,mixed>>, ohne_aenderung: int} $plan */
    private function zeigeNamen(array $plan): void
    {
        $regeln = [];
        $wg = [];
        $keyWechsel = 0;
        $nichtAbleitbar = 0;
        foreach ($plan['aenderungen'] as $a) {
            foreach ($a['regeln'] as $r) {
                $regeln[$r] = ($regeln[$r] ?? 0) + 1;
            }
            $wg[$a['wg']] = ($wg[$a['wg']] ?? 0) + 1;
            $keyWechsel += $a['key_wechsel'] ? 1 : 0;
            $nichtAbleitbar += $a['key_nicht_ableitbar'] ? 1 : 0;
        }
        arsort($regeln);
        ksort($wg);
        $this->newLine();
        $this->info('Phase Namen: ' . count($plan['aenderungen']) . ' Umbenennung(en) ausführbar (davon Key-Wechsel: ' . $keyWechsel
            . ', Key nicht aus Name ableitbar: ' . $nichtAbleitbar . '), ' . count($plan['konflikte']) . ' Konflikt(e), ohne Änderung: ' . $plan['ohne_aenderung']);
        $this->table(['Regel', 'GPs'], array_map(fn ($k, $v) => [$k, $v], array_keys($regeln), $regeln));
        $this->table(['WG', 'Umbenennungen'], array_map(fn ($k, $v) => [$k, $v], array_keys($wg), $wg));
    }

    /** @param list<string> $kopf @param list<list<mixed>> $zeilen */
    private function schreibeCsv(string $pfad, array $kopf, array $zeilen): void
    {
        $h = fopen($pfad, 'wb');
        fwrite($h, "\xEF\xBB\xBF");
        fputcsv($h, $kopf, ';', '"', '');
        foreach ($zeilen as $z) {
            fputcsv($h, array_map(fn ($v) => $v === null ? '' : (string) $v, $z), ';', '"', '');
        }
        fclose($h);
    }
}
