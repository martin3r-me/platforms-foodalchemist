<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Services\PreislistenImportService;

/**
 * Spec 82 §3 / Schritt 4d — Preisliste eines Mandanten abgleichen und als Lauf ablegen.
 * Schreibt keine Preise und legt keine Artikel an (Stufe „Abgleich + Prüfliste").
 */
class PreislisteImportCommand extends Command
{
    protected $signature = 'foodalchemist:preisliste-import
        {datei : CSV-Datei}
        {--team= : Mandanten-Team (ID)}
        {--supplier= : Standard-Lieferant (ID), wenn die Datei keine Lieferant-Spalte hat}
        {--map=* : Spaltenzuordnung feld=Überschrift (supplier, article_number, designation, unit, price, valid_from, ean)}
        {--pruefliste= : Prüfliste als CSV in diese Datei schreiben}';

    protected $description = 'Preisliste eines Mandanten gegen sichtbare Artikel abgleichen (schreibt keine Preise)';

    public function handle(PreislistenImportService $service): int
    {
        $team = Team::find((int) $this->option('team'));
        if (! $team) {
            $this->error('--team=ID angeben (Mandanten-Team).');

            return self::FAILURE;
        }
        $mapping = [];
        foreach ((array) $this->option('map') as $paar) {
            [$feld, $ziel] = array_pad(explode('=', (string) $paar, 2), 2, '');
            $mapping[trim($feld)] = trim($ziel);
        }

        try {
            $run = $service->stage($team, (string) $this->argument('datei'), $mapping, $this->option('supplier') ? (int) $this->option('supplier') : null);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Lauf #{$run->id} ({$run->filename}) abgelegt. Es wurde kein Preis geschrieben und kein Artikel angelegt.");
        foreach ($run->counts as $k => $v) {
            $this->line(sprintf('  %-22s %d', $k, $v));
        }
        if (is_string($datei = $this->option('pruefliste')) && $datei !== '') {
            $n = $service->exportPruefliste($run, $datei);
            $this->line("Prüfliste: {$n} Zeilen → {$datei}");
        }

        return self::SUCCESS;
    }
}
