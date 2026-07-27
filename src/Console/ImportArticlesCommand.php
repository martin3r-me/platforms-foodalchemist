<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Services\FileArticleImportService;

/**
 * Spec 13 · S1a — Kanal B: Artikel-Datei eines Lieferanten einlesen.
 *
 * Bewusst **dry-run als Default**: ein Import berührt den Katalog, aus dem jede
 * Kalkulation ihren EK zieht. Geschrieben wird nur mit `--apply`; die Ausgabe ist
 * in beiden Modi dieselbe, damit der Trockenlauf eine echte Vorschau ist und nicht
 * eine andere Codebahn.
 *
 * Resumefähig ohne Zustand: der Upsert ist idempotent (leere Zelle ändert nichts,
 * unveränderte Zeile bleibt unangetastet) — ein abgebrochener Lauf wird durch
 * erneutes Ausführen derselben Datei fortgesetzt.
 *
 * Vorlage + Spalten-Liste: docs/IMPORT_Kanal_B_Artikel_Vorlage.md
 */
class ImportArticlesCommand extends Command
{
    protected $signature = 'foodalchemist:import-articles
        {--file= : Pfad zur CSV/TSV-Datei (eine Zeile je Artikel)}
        {--supplier= : Lieferant (ID) — eine Datei = ein Lieferant}
        {--team= : Besitzer-Team des Imports (ID)}
        {--dry-run : nur prüfen und berichten (Default)}
        {--apply : wirklich schreiben}
        {--zeilen=25 : höchstens so viele Zeilen-Befunde ausgeben}';

    protected $description = 'Kanal B (Spec 13): Lieferanten-Artikel aus einer CSV-Datei upserten (Preis/Nährwerte/Allergene folgen in S1b/S1c).';

    public function handle(FileArticleImportService $import): int
    {
        $pfad = (string) $this->option('file');
        if ($pfad === '') {
            $this->error('--file fehlt.');

            return self::FAILURE;
        }
        $team = Team::find((int) $this->option('team'));
        if (! $team) {
            $this->error('--team=ID fehlt oder existiert nicht.');

            return self::FAILURE;
        }
        $supplierId = (int) $this->option('supplier');
        if ($supplierId <= 0) {
            $this->error('--supplier=ID fehlt. Sichtbare Lieferanten: '
                . FoodAlchemistSupplier::visibleToTeam($team)->orderBy('name')->limit(20)
                    ->get(['id', 'name'])->map(fn ($s) => "#{$s->id} {$s->name}")->implode(', '));

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        if (! $apply) {
            $this->warn('TROCKENLAUF — es wird nichts geschrieben (mit --apply scharf stellen).');
        }

        try {
            $runId = $apply ? $import->starteRun($team->id, 0) : null;
            $bericht = $import->importiere($team, $supplierId, $pfad, $apply);
            if ($runId !== null) {
                $import->beendeRun($runId, $bericht);
            }
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("── {$bericht['datei']} → {$bericht['lieferant']} (Team {$team->id}) · {$bericht['zeilen']} Zeile(n)");
        $this->line('   Spalten erkannt: ' . implode(', ', $bericht['spalten']['erkannt']));
        foreach ($bericht['hinweise'] as $h) {
            $this->warn('   ! ' . $h);
        }

        $max = max(1, (int) $this->option('zeilen'));
        $gezeigt = 0;
        foreach ($bericht['befunde'] as $b) {
            if ($b['status'] === 'unveraendert') {
                continue; // Rauschen: der Normalfall eines Wiederholungslaufs
            }
            if ($gezeigt++ >= $max) {
                break;
            }
            $zeile = "   Z{$b['zeile']} [{$b['status']}] {$b['artikel']} {$b['bezeichnung']}";
            if (isset($b['felder']) && $b['felder'] !== []) {
                $zeile .= ' · ' . implode(',', $b['felder']);
            }
            if (isset($b['grund'])) {
                $zeile .= ' · ' . $b['grund'];
            }
            $b['status'] === 'fehler' ? $this->warn($zeile) : $this->line($zeile);
            foreach ($b['warnungen'] ?? [] as $w) {
                $this->line("      ⚠ {$w}");
            }
        }

        $this->info("   → neu {$bericht['neu']} · aktualisiert {$bericht['aktualisiert']}"
            . " · unverändert {$bericht['unveraendert']} · übersprungen {$bericht['uebersprungen']}"
            . " · Fehler {$bericht['fehler']}" . ($apply ? '' : ' (nichts geschrieben)'));

        if (! $apply && ($bericht['neu'] + $bericht['aktualisiert']) > 0) {
            $this->line('   Vor dem scharfen Lauf: DB-Backup ziehen, dann denselben Aufruf mit --apply.');
        }

        return $bericht['fehler'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
