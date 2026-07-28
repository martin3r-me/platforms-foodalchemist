<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Services\DataQualityService;
use Platform\FoodAlchemist\Services\SignalTrendService;

/**
 * Datenqualitäts-Ampel für die Kaskade LA → GP → Basisrezept → VK-Gericht.
 * Rein messend (read-only). Mit --signals werden die Lücken als Signale in die
 * „Signale"-Inbox geschrieben (idempotent, Dedup) — sichtbar in der ReviewQueue
 * und via MCP signale.SEARCH. Für den Scheduler gedacht (analog signale-detektor).
 *
 * Mit --snapshot landen die Zähler zusätzlich in der Zeitreihe (Spec 21 · E1) — für die
 * Messung außer der Reihe. Im Scheduler passiert das ohnehin: `signale-detektor` schreibt
 * den Snapshot am Ende seines Laufs (dort hängt auch diese Ampel als Detektor mit drin).
 */
class DataQualityCommand extends Command
{
    protected $signature = 'foodalchemist:data-quality
        {--team= : nur dieses Team (ID), sonst alle}
        {--json : Maschinen-lesbare Ausgabe (per-Ebene JSON) statt Tabelle}
        {--signals : Lücken zusätzlich als Signale in die Inbox schreiben (idempotent)}
        {--snapshot : Zähler zusätzlich in die Zeitreihe schreiben (Spec 21 E1; für den Trend)}';

    protected $description = 'Datenqualitäts-Ampel der Kaskade (LA→GP→Basisrezept→Gericht); optional als Signale.';

    public function handle(DataQualityService $dq, SignalTrendService $trend): int
    {
        $teams = $this->option('team')
            ? Team::whereKey((int) $this->option('team'))->get()
            : Team::query()->get();

        if ($teams->isEmpty()) {
            $this->error('Kein Team gefunden (--team=ID prüfen).');

            return self::FAILURE;
        }

        $alle = [];
        foreach ($teams as $team) {
            $ebenen = $dq->messeAlleEbenen($team);
            $alle[$team->id] = $ebenen;

            if (! $this->option('json')) {
                $this->info("── Team {$team->id} ({$team->name}) ──");
                foreach ($ebenen as $ebene) {
                    $this->line("  {$ebene['label']}");
                    $rows = array_map(fn ($m) => [
                        $m['label'],
                        number_format((int) $m['wert'], 0, ',', '.'),
                        $this->ampel($m['severity']),
                    ], $ebene['metriken']);
                    $this->table(['Metrik', 'Wert', 'Ampel'], $rows);
                }
            }

            if ($this->option('signals')) {
                // 22·H4a: der Lauf hat zwei Richtungen — er meldet auch, was er zugemacht hat.
                // Ohne die zweite Zahl bliebe der Schließ-Zweig (V-011) im Betrieb unsichtbar.
                ['emittiert' => $n, 'geschlossen' => $zu] = $dq->emittiereUndSchliesse($team);
                $this->line("  → {$n} Signal(e) geschrieben/aktualisiert, {$zu} automatisch geschlossen (Lücke gemessen 0).");
            }

            // Reihenfolge ist Absicht: erst Signale emittieren, dann snapshotten — sonst
            // zählt die Signal-Seite der Zeitreihe den Stand VOR diesem Lauf.
            if ($this->option('snapshot')) {
                $rows = $trend->schreibeSnapshot($team);
                $this->line("  → {$rows} Zeitreihen-Zeile(n) geschrieben.");
            }
        }

        if ($this->option('json')) {
            $this->line(json_encode($alle, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        return self::SUCCESS;
    }

    private function ampel(string $severity): string
    {
        return match ($severity) {
            'rot' => '🔴 rot',
            'gelb' => '🟡 gelb',
            'gruen' => '🟢 grün',
            default => 'ℹ️  info',
        };
    }
}
