<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Platform\FoodAlchemist\Services\EquipmentStandardService;

/**
 * Spec 82 §6 — Standardausstattung im Equipment-Vokabular setzen (Dominique 13.10.: 15 Einträge).
 *
 * Idempotent: setzt genau diese Liste auf `is_standard = 1` und alle anderen Einträge auf 0. Gematcht wird nach
 * Name (Groß-/Kleinschreibung, Umlaute und Sonderzeichen egal). Bewusst ein Befehl und keine Migration: ein Deploy
 * soll keine Daten ändern. Mit --dry-run wird nur gezeigt, nichts geschrieben.
 */
class EquipmentStandardSetzenCommand extends Command
{
    /** Standardausstattung (die Liste steht im Dienst, hier nur für bestehende Aufrufer). */
    public const STANDARD = EquipmentStandardService::STANDARD;

    protected $signature = 'foodalchemist:equipment-standard-setzen {--dry-run : nur anzeigen, nichts schreiben}';

    protected $description = 'Setzt das Flag Standardausstattung am Equipment-Vokabular (15 Einträge), idempotent';

    public function handle(EquipmentStandardService $service): int
    {
        $dry = (bool) $this->option('dry-run');
        $r = $dry ? $service->setzen(true) : $service->schreibenAlsKonsole();

        $this->line(($dry ? '[Trockenlauf] ' : '') . 'Standardausstattung (' . $r['namen'] . ' Namen)');
        $this->line('  neu gesetzt:        ' . $this->liste($r['neu']));
        $this->line('  schon gesetzt:      ' . $this->liste($r['schon']));
        $this->line('  zurückgesetzt:      ' . $this->liste($r['zurueck']));
        $this->line('  nicht gefunden:     ' . ($r['nicht_gefunden'] === [] ? '–' : implode(', ', $r['nicht_gefunden'])));

        return self::SUCCESS;
    }

    private function liste(array $zeilen): string
    {
        return $zeilen === [] ? '–' : implode(', ', array_map(fn ($z) => $z['name'] . " (#{$z['id']})", $zeilen));
    }
}
