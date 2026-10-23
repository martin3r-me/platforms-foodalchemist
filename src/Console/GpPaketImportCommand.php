<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Platform\Core\Models\User;
use Platform\FoodAlchemist\Exceptions\GpPaketAbgelehntException;
use Platform\FoodAlchemist\Services\GpPaketImportService;
use Platform\FoodAlchemist\Support\GpPaketZaehler;

/**
 * GP-Paket → io (Plan `_GP_Import_Plan_io.md`, E2; Kurator/Dominique 23.10.2026).
 *
 * Default = Trockenlauf: schreibt NUR Berichtsdateien (Kennzahlen je Warengruppe, Ablehnungen, Konflikte, Guard-Liste, Park-Liste).
 * `--apply` braucht einen frischen Backup-Nachweis (≤ 24 h), läuft in EINER Transaktion und schreibt ein Protokoll, das `--zurueck`
 * umkehrt. `--verify` vergleicht nach dem Lauf Soll/Ist und schreibt nichts. Nur das Master-Team, nur ein Plattform-Admin (`--admin=<E-Mail>`).
 * Vor `--apply`: während des Laufs darf niemand GPs oder Verknüpfungen pflegen; danach `queue:restart`, Embeddings off-peak.
 */
class GpPaketImportCommand extends Command
{
    protected $signature = 'foodalchemist:gp-paket-import
        {--paket= : Paket-Verzeichnis (gps.jsonl, gp_artikel.jsonl, …, manifest.json, optional gp_anker.jsonl) — Pflicht}
        {--admin= : E-Mail des Plattform-Admins (Pflicht; es gibt in der Konsole keine Anmeldung)}
        {--dry-run : nur rechnen und Berichte schreiben (Standard)}
        {--apply : wirklich schreiben (braucht --backup-nachweis)}
        {--verify : Soll/Ist nach einem Lauf (schreibt nichts)}
        {--zurueck= : Protokoll (JSON) eines --apply-Laufs zurücknehmen}
        {--nur-wg= : Teillauf: nur diese Warengruppe (Code, z. B. 04)}
        {--bericht= : Ordner für Berichte und Protokoll (Standard: storage/app/fa-gp-paket-import)}
        {--backup-nachweis= : Pfad zum Backup-Dump (Pflicht bei --apply; existiert, nicht älter als 24 h)}
        {--ohne-anker : Anker-Phase überspringen}
        {--umhaengen : Artikel, die schon einem anderen GP gehören, umhängen (Standard: überspringen und melden)}
        {--vorlauf= : Protokoll des letzten --apply-Laufs (Standard: neuestes *_gp_paket_protokoll.json im Berichtsordner)}
        {--ohne-recompute : nach dem Lauf Leads bestehender GPs nicht neu verrechnen}';

    protected $description = 'GP-Paket (Skript 300) nach io: GPs global anlegen, Artikel über legacy_id verknüpfen (Allergen-Guard), Anker setzen (Trockenlauf, Backup, Protokoll, Rückweg)';

    public function handle(GpPaketImportService $svc): int
    {
        if (($hinweis = \Platform\FoodAlchemist\Support\Speicher::anheben('1536M')) !== null) {   // PHP 8.5: nie über max_memory_limit
            $this->warn($hinweis);
        }
        $modi = array_filter([(bool) $this->option('apply'), (bool) $this->option('verify'), $this->option('zurueck') !== null, (bool) $this->option('dry-run')]);
        if (count($modi) > 1) {
            $this->error('Nur EINE Betriebsart: --dry-run (Standard), --apply, --verify oder --zurueck.');

            return self::FAILURE;
        }
        $user = $this->admin();
        if ($user === null) {
            return self::FAILURE;
        }
        $bericht = (string) ($this->option('bericht') ?: storage_path('app/fa-gp-paket-import'));
        File::ensureDirectoryExists($bericht);
        $stempel = now()->format('Ymd_His');

        try {
            if ($this->option('zurueck') !== null) {
                return $this->zurueck($svc, (string) $this->option('zurueck'), $user);
            }
            if ((string) $this->option('paket') === '') {
                $this->error('--paket=<Verzeichnis> fehlt.');

                return self::FAILURE;
            }
            if ($this->option('verify')) {
                return $this->verify($svc, $user, $bericht, $stempel);
            }
            $apply = (bool) $this->option('apply');
            if ($apply && ! $this->backupOk()) {
                return self::FAILURE;
            }
            $vorlauf = $this->option('vorlauf') !== null ? (string) $this->option('vorlauf') : $this->neuesterVorlauf($bericht);
            $this->line($apply ? '<comment>APPLY</comment> (eine Transaktion, Protokoll folgt)' : 'TROCKENLAUF (schreibt nur Berichte)');
            $this->line('Vorlauf-Protokoll (Hand-Änderungen erkennen): ' . ($vorlauf ?: 'keins — abweichende bestehende GPs werden NICHT überschrieben, nur gelistet'));

            $r = $svc->lauf(['paket' => (string) $this->option('paket'), 'apply' => $apply, 'user' => $user, 'umhaengen' => (bool) $this->option('umhaengen'),
                'nur_wg' => $this->option('nur-wg'), 'ohne_anker' => (bool) $this->option('ohne-anker'), 'vorlauf' => $vorlauf, 'ohne_recompute' => (bool) $this->option('ohne-recompute')]);
        } catch (GpPaketAbgelehntException $e) {
            $this->error('PAKET ABGELEHNT — es wurde nichts geplant und nichts geschrieben.');
            foreach ($e->gruende as $g) {
                $this->line(' · ' . $g);
            }
            $this->schreibeCsv("{$bericht}/{$stempel}_paket_abgelehnt.csv", ['grund'], array_map(fn ($g) => [$g], $e->gruende));

            return self::FAILURE;
        } catch (\Platform\FoodAlchemist\Exceptions\FaRechtFehltException|\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->zeige($r['zaehler']);
        foreach ($r['hinweise'] as $h) {
            $this->warn($h);
        }
        $this->schreibeBerichte($r, $bericht, $stempel);
        $this->line("Dauer: {$r['dauer_s']} s — Berichte: {$bericht}/{$stempel}_*");

        if ($r['protokoll'] !== null) {
            $pfad = "{$bericht}/{$stempel}_gp_paket_protokoll.json";
            File::put($pfad, json_encode($r['protokoll'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $p = $r['protokoll'];
            $this->info(sprintf('Ausgeführt: %d GPs neu, %d geändert, %d Verknüpfungen, %d Struktur-Zeilen neu, %d Leads gesetzt/geändert, %d Rezepte neu gerechnet. Protokoll: %s',
                count($p['gps_neu']), count($p['gps_geaendert']), count($p['verknuepfungen']), count($p['struktur_neu']), count($p['lead']), $p['recompute'], $pfad));
            $this->warn('Danach: queue:restart · foodalchemist:gp-allergen-backfill · Embeddings off-peak (foodalchemist:embed) · Allergen-Konsistenzlauf (foodalchemist:gp-homogenitaet-bericht) · Anker-Check · --verify.');
            $this->comment("Zurück mit: --zurueck={$pfad}");
        } else {
            $this->comment('Trockenlauf — nichts geändert.');
        }

        return self::SUCCESS;
    }

    private function admin(): ?User
    {
        $mail = strtolower(trim((string) $this->option('admin')));
        if ($mail === '') {
            $this->error('--admin=<E-Mail des Plattform-Admins> fehlt (Plan §4.1: nur Master-Kontext, nur Plattform-Admin).');

            return null;
        }
        $user = User::whereRaw('LOWER(email) = ?', [$mail])->first();
        if ($user === null) {
            $this->error("Kein Benutzer mit der E-Mail {$mail}.");

            return null;
        }

        return $user;
    }

    private function backupOk(): bool
    {
        $b = (string) $this->option('backup-nachweis');
        if ($b === '' || ! is_file($b) || filesize($b) < 1024) {
            $this->error('--apply braucht --backup-nachweis=<Dump-Datei> (existiert, nicht leer): Dump von foodalchemist_gps, …_supplier_item_structures, …_gp_forms, …_gp_textures, …_gp_count_unit_defaults, …_component_equivalents, …_gp_anchor_mappings.');

            return false;
        }
        if (filemtime($b) < now()->subDay()->getTimestamp()) {
            $this->error('Backup ist älter als 24 h — bitte frisch ziehen.');

            return false;
        }
        $this->line('Backup-Nachweis: ' . $b . ' (' . round(filesize($b) / 1048576, 1) . ' MB, sha256 ' . substr(hash_file('sha256', $b), 0, 12) . ')');

        return true;
    }

    private function neuesterVorlauf(string $bericht): ?string
    {
        $dateien = glob($bericht . '/*_gp_paket_protokoll.json') ?: [];
        sort($dateien);

        return $dateien === [] ? null : end($dateien);
    }

    private function zurueck(GpPaketImportService $svc, string $pfad, User $user): int
    {
        if (! is_file($pfad) || ! is_array($p = json_decode((string) file_get_contents($pfad), true))) {
            $this->error("Protokoll nicht lesbar: {$pfad}");

            return self::FAILURE;
        }
        try {
            $r = $svc->zuruecknehmen($p, $user);
        } catch (\Platform\FoodAlchemist\Exceptions\FaRechtFehltException|\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->info("Zurückgenommen: {$r['zurueck']} · übersprungen (inzwischen geändert): {$r['uebersprungen']}");
        $this->warn('Die GPs des Laufs sind weich gelöscht (kein Hard-Delete). Danach queue:restart; Embeddings der gelöschten GPs räumt der Observer-freie Weg nicht auf — bei Bedarf foodalchemist:embed.');

        return self::SUCCESS;
    }

    private function verify(GpPaketImportService $svc, User $user, string $bericht, string $stempel): int
    {
        try {
            $r = $svc->verifiziere(['paket' => (string) $this->option('paket'), 'user' => $user, 'nur_wg' => $this->option('nur-wg'), 'ohne_anker' => (bool) $this->option('ohne-anker')]);
        } catch (GpPaketAbgelehntException $e) {
            $this->error('PAKET ABGELEHNT: ' . $e->getMessage());

            return self::FAILURE;
        } catch (\Platform\FoodAlchemist\Exceptions\FaRechtFehltException|\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->table(['Soll/Ist', 'Wert'], array_map(fn ($z) => [$z['kennzahl'], $z['wert']], $r['soll_ist']));
        $this->schreibeCsv("{$bericht}/{$stempel}_verify_abweichungen.csv", ['wg', 'gp_key', 'art', 'detail'], array_map(fn ($a) => [$a['wg'], $a['gp_key'], $a['art'], $a['detail']], $r['abweichungen']));
        $this->schreibeCsv("{$bericht}/{$stempel}_verify_erwartet.csv", ['wg', 'gp_key', 'art', 'detail'], array_map(fn ($a) => [$a['wg'], $a['gp_key'], $a['art'], $a['detail']], $r['erwartet']));
        if ($r['abweichungen'] === []) {
            $this->info('VERIFY grün — keine Abweichung. Erwartete Lücken: ' . count($r['erwartet']) . " → {$bericht}/{$stempel}_verify_erwartet.csv");

            return self::SUCCESS;
        }
        $this->error(count($r['abweichungen']) . " Abweichung(en) → {$bericht}/{$stempel}_verify_abweichungen.csv");

        return self::FAILURE;
    }

    private function zeige(GpPaketZaehler $z): void
    {
        $this->newLine();
        $g = $z->gesamt();
        $this->table(['Kennzahl (gesamt)', 'Wert'], array_map(fn ($k, $t) => [$t, $g[$k]], array_keys(GpPaketZaehler::BESCHRIFTUNG), GpPaketZaehler::BESCHRIFTUNG));
        $kurz = ['gp_paket' => 'GPs', 'gp_neu' => 'neu', 'gp_unveraendert' => 'unv.', 'gp_geaendert' => 'geänd.', 'gp_konflikt' => 'Konfl.', 'gp_abgelehnt' => 'abgel.', 'v_neu' => 'Verkn. neu',
            'v_bestehend' => 'best.', 'v_ohne_treffer' => 'ohne Tr.', 'v_mehrdeutig' => 'mehrd.', 'v_guard_abgelehnt' => 'Guard', 'lead_park' => 'Park', 'n_las_erwartet' => 'Σ n_las'];
        $this->line('Je Warengruppe:');
        $this->table(array_merge(['WG'], array_values($kurz)), array_map(fn ($wg, $zeile) => array_merge([$wg], array_map(fn ($k) => $zeile[$k], array_keys($kurz))), array_keys($z->je()), $z->je()));
    }

    /** @param array<string,mixed> $r */
    private function schreibeBerichte(array $r, string $dir, string $stempel): void
    {
        $l = $r['listen'];
        $z = $r['zaehler'];
        $kopf = ['wg', ...array_keys(GpPaketZaehler::BESCHRIFTUNG)];
        $zeilen = [];
        foreach ($z->je() as $wg => $werte) {
            $zeilen[] = array_merge([$wg], array_values($werte));
        }
        $zeilen[] = array_merge(['GESAMT'], array_values($z->gesamt()));
        $this->schreibeCsv("{$dir}/{$stempel}_kennzahlen.csv", $kopf, $zeilen);
        File::put("{$dir}/{$stempel}_kennzahlen.json", json_encode(['modus' => $r['modus'], 'gesamt' => $z->gesamt(), 'je_wg' => $z->je(), 'dauer_s' => $r['dauer_s']], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $csv = fn (string $name, array $kopf, array $rows) => $this->schreibeCsv("{$dir}/{$stempel}_{$name}.csv", $kopf, array_map(fn ($x) => array_map(fn ($k) => $x[$k] ?? '', $kopf), $rows));
        $csv('gp_abgelehnt', ['wg', 'gp_key', 'name', 'code', 'grund'], $l['gp_abgelehnt']);
        $csv('review_ohne_match', ['wg', 'gp_key', 'gp_name', 'legacy_id', 'grund', 'lead'], $l['review_ohne_match']);
        $csv('guard_abgelehnt', ['wg', 'gp_key', 'gp_name', 'legacy_id', 'artikel_id', 'lieferant', 'artikel', 'abweichend_von_artikel_id', 'abweichungen', 'vorschlag'], $l['guard_abgelehnt']);
        $csv('konflikte', ['wg', 'gp_key', 'feld', 'io', 'paket', 'hinweis'], $l['konflikte']);
        $csv('lead_park', ['wg', 'gp_key', 'name', 'artikel'], $l['lead_park']);
        $csv('gps_ohne_artikel', ['wg', 'gp_key', 'name', 'requires_la', 'status_neu'], $l['ohne_artikel']);
        $csv('tentative_nun_mit_artikel', ['wg', 'gp_key', 'name', 'artikel'], $l['tentative_mit_artikel']);
        $csv('kinder_abgelehnt', ['wg', 'datei', 'gp_key', 'grund'], $l['kinder_abgelehnt']);
        $csv('anker_abgelehnt', ['wg', 'gp_key', 'anchor_slug', 'grund'], $l['anker_abgelehnt']);
        $this->schreibeCsv("{$dir}/{$stempel}_gp_key_zu_id.csv", ['gp_key', 'gp_id'], array_map(fn ($k, $id) => [$k, $id ?? ''], array_keys($r['gp_key_zu_id']), $r['gp_key_zu_id']));
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
