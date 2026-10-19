<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Platform\FoodAlchemist\Services\GpBereinigenService;
use Platform\FoodAlchemist\Services\GpUmhaengenService;
use Platform\FoodAlchemist\Support\MasterTeam;

/**
 * Artikel↔GP umhängen, neue GPs anlegen, Verknüpfungen lösen, Warengruppe setzen (Kurator/Dominique 19.10.2026).
 *
 * Zwei Schritte: (1) `--entwurf-aus-review=<Review-CSV>` baut aus den Review-Urteilen einen ENTWURF der Plan-CSV (ändert nichts, schreibt nur Dateien).
 * (2) Der geprüfte Plan läuft mit `--plan=<CSV>`: Trockenlauf = Default; `--apply` braucht einen Backup-Nachweis, schreibt ein Protokoll und ist per
 * `--zurueck=<Protokoll>` umkehrbar. Ausgeführt werden nur klare Ziele; alles andere kommt in die Konfliktliste. Vor `--apply` mit Dominique
 * abstimmen: während des Laufs darf niemand GPs oder Verknüpfungen pflegen.
 */
class GpUmhaengenCommand extends Command
{
    protected $signature = 'foodalchemist:gp-umhaengen
        {--team= : Team, dem die GPs gehören (Standard: konfiguriertes Master-Team)}
        {--plan=* : Plan-CSV (strikt; Spalten aktion;supplier_item_id;gp_id_quelle;ziel_gp_id;neu_name;neu_wg;neu_sub;neu_status;notiz) — mehrfach angebbar}
        {--entwurf-aus-review=* : Review-CSV → schreibt nur einen ENTWURF der Plan-CSV (ändert nichts)}
        {--wg= : nur Quell-GPs dieser Warengruppe (Code)}
        {--bericht= : Ordner für Entwurf, Konfliktliste und Protokoll (Standard: storage/app/fa-gp-umhaengen)}
        {--backup-nachweis= : Pfad zum Backup-Dump (Pflicht bei --apply; nicht älter als 24 h)}
        {--apply : wirklich schreiben}
        {--ohne-recompute : danach keine Rezepte neu rechnen}
        {--zurueck= : Protokoll (JSON) eines --apply-Laufs zurücknehmen}';

    protected $description = 'Artikel↔GP umhängen / neue GPs anlegen / lösen / WG setzen (Entwurf aus Review, Trockenlauf, Backup, Protokoll, Rückweg)';

    public function handle(GpUmhaengenService $svc, GpBereinigenService $csvSvc): int
    {
        ini_set('memory_limit', '1024M');
        $bericht = (string) ($this->option('bericht') ?: storage_path('app/fa-gp-umhaengen'));
        File::ensureDirectoryExists($bericht);
        $stempel = now()->format('Ymd_His');

        if ($this->option('zurueck') !== null) {
            return $this->zurueck($svc, (string) $this->option('zurueck'));
        }

        $reviews = array_values(array_filter((array) $this->option('entwurf-aus-review'), fn ($c) => (string) $c !== ''));
        if ($reviews !== []) {
            $e = $svc->entwurfAusReview($reviews);
            $this->schreibeCsv("{$bericht}/{$stempel}_umhaengen_entwurf.csv", GpUmhaengenService::PLAN_KOPF,
                array_map(fn ($z) => array_map(fn ($k) => $z[$k] ?? '', GpUmhaengenService::PLAN_KOPF), $e['plan']));
            $this->schreibeCsv("{$bericht}/{$stempel}_umhaengen_unaufgeloest.csv", ['supplier_item_id', 'ziel_gp', 'grund'],
                array_map(fn ($z) => [$z['supplier_item_id'], $z['ziel_gp'], $z['grund']], $e['unaufgeloest']));
            $je = collect($e['plan'])->countBy('aktion');
            $this->info(sprintf('Entwurf: %d Zeilen (umhängen %d · neues GP %d · lösen %d), %d nicht eindeutig lesbar → %s', count($e['plan']),
                $je['umhaengen'] ?? 0, $je['neues_gp'] ?? 0, $je['loesen'] ?? 0, count($e['unaufgeloest']), $bericht));
            $this->comment('Der Entwurf ist KEIN genehmigter Plan: Zeilen prüfen/ändern (Entscheidungen Dominique), dann mit --plan ausführen.');

            return self::SUCCESS;
        }

        $team = $this->option('team') ?? MasterTeam::id();
        if (! is_numeric($team)) {
            $this->error('Kein Team: --team=N angeben oder Master-Team konfigurieren.');

            return self::FAILURE;
        }
        $team = (int) $team;
        $plaene = array_values(array_filter((array) $this->option('plan'), fn ($c) => (string) $c !== ''));
        if ($plaene === []) {
            $this->error('--plan=<CSV> fehlt (oder --entwurf-aus-review für einen Entwurf).');

            return self::FAILURE;
        }
        $apply = (bool) $this->option('apply');
        if ($apply && ! $this->backupOk()) {
            return self::FAILURE;
        }

        $zeilen = [];
        foreach ($plaene as $datei) {
            $zeilen = array_merge($zeilen, $csvSvc->leseCsv($datei));
        }
        $wg = $this->option('wg') !== null ? (string) $this->option('wg') : null;
        $plan = $svc->plane($team, $zeilen, $wg);
        $this->line($apply ? '<comment>APPLY</comment> auf Team ' . $team : 'TROCKENLAUF auf Team ' . $team . ' (schreibt nur Berichte)');
        $this->zeige($plan);

        $this->schreibeCsv("{$bericht}/{$stempel}_umhaengen_konflikte.csv", ['zeile', 'aktion', 'supplier_item_id', 'gp_id', 'grund'],
            array_map(fn ($k) => [$k['zeile'], $k['aktion'], $k['supplier_item_id'], $k['gp_id'], $k['grund']], $plan['konflikte']));
        $this->info('Konflikte: ' . count($plan['konflikte']) . " → {$bericht}/{$stempel}_umhaengen_konflikte.csv");

        if (! $apply) {
            $this->comment('Trockenlauf — nichts geändert.');

            return self::SUCCESS;
        }
        if ($plan['konflikte'] !== [] && ! $this->confirm('Es gibt Konflikte (sie werden NICHT ausgeführt). Trotzdem die klaren Zeilen ausführen?', false)) {
            $this->warn('Abbruch, nichts geändert.');

            return self::FAILURE;
        }

        $protokoll = ['lauf' => $stempel, 'team' => $team, 'wg' => $wg, 'plaene' => $plaene, 'neue_gps' => [], 'verknuepfungen' => [], 'wg' => [], 'n_las' => [], 'lead' => []];
        $r = $svc->fuehreAus($team, $plan, $protokoll);
        $pfad = "{$bericht}/{$stempel}_umhaengen_protokoll.json";
        File::put($pfad, json_encode($protokoll, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->info(sprintf('Ausgeführt: %d Verknüpfungen, %d neue GPs, %d GPs mit neuem Lead. Protokoll: %s',
            count($protokoll['verknuepfungen']), count($protokoll['neue_gps']), count($r['lead_gewechselt']), $pfad));
        if (! $this->option('ohne-recompute') && $r['betroffen'] !== []) {
            $this->line('Rechne Rezepte der betroffenen GPs neu …');
            $this->info($svc->rechneRezepte($r['betroffen']) . ' Rezept(e) neu gerechnet.');
        }
        $this->warn('Danach: foodalchemist:gp-allergen-backfill (Konfidenz-Metadaten), Embeddings off-peak, ggf. Anker für neue GPs.');

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

    private function zurueck(GpUmhaengenService $svc, string $pfad): int
    {
        if (! is_file($pfad) || ! is_array($p = json_decode((string) file_get_contents($pfad), true))) {
            $this->error("Protokoll nicht lesbar: {$pfad}");

            return self::FAILURE;
        }
        $r = $svc->zuruecknehmen($p);
        $this->info("Zurückgenommen: {$r['zurueck']} · übersprungen (inzwischen geändert): {$r['uebersprungen']}");
        if ($r['gps_behalten'] !== []) {
            $this->warn('Neue GPs bleiben bestehen (es hängt inzwischen etwas daran): ' . implode(', ', $r['gps_behalten']));
        }

        return self::SUCCESS;
    }

    /** @param array{aktionen: list<array<string,mixed>>, neue_gps: array<string,array<string,mixed>>, konflikte: list<array<string,mixed>>} $plan */
    private function zeige(array $plan): void
    {
        $je = collect($plan['aktionen'])->countBy('aktion');
        $this->newLine();
        $this->table(['Aktion', 'ausführbar'], [
            ['umhängen', $je['umhaengen'] ?? 0], ['neues GP (Artikel-Zeilen)', $je['neues_gp'] ?? 0], ['lösen', $je['loesen'] ?? 0], ['Warengruppe setzen', $je['wg_setzen'] ?? 0],
            ['→ neue GPs (eindeutig)', count($plan['neue_gps'])], ['Konflikte', count($plan['konflikte'])],
        ]);
        if ($plan['neue_gps'] !== []) {
            $this->table(['Neues GP', 'WG', 'Status', 'gp_key', 'Artikel'], array_map(fn ($g) => [mb_substr($g['name'], 0, 52), $g['in']['commodity_group_code'], $g['status'], mb_substr($g['gp_key'], 0, 40), count($g['artikel'])], array_values($plan['neue_gps'])));
        }
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
