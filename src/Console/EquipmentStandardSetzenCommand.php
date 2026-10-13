<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Spec 82 §6 — Standardausstattung im Equipment-Vokabular setzen (Dominique 13.10.: 15 Einträge).
 *
 * Idempotent: setzt genau diese Liste auf `is_standard = 1` und alle anderen Einträge auf 0. Gematcht wird nach
 * Name (Groß-/Kleinschreibung, Umlaute und Sonderzeichen egal). Bewusst ein Befehl und keine Migration: ein Deploy
 * soll keine Daten ändern. Mit --dry-run wird nur gezeigt, nichts geschrieben.
 */
class EquipmentStandardSetzenCommand extends Command
{
    /** Standardausstattung: gilt in jeder Küche als vorhanden, solange ein Team sie nicht ausdrücklich als fehlend markiert. */
    public const STANDARD = [
        'Topf', 'Kasserolle', 'Pfanne', 'Chinois / Passiersieb', 'Backblech', 'Bräter', 'Spritzbeutel', 'Kuchenform',
        'Stabmixer', 'Pürierstab', 'Mixer', 'Küchenmaschine / Planetenrührwerk', 'Induktionsplatte', 'Mörser', 'Mandoline / Hobel',
    ];

    protected $signature = 'foodalchemist:equipment-standard-setzen {--dry-run : nur anzeigen, nichts schreiben}';

    protected $description = 'Setzt das Flag Standardausstattung am Equipment-Vokabular (15 Einträge), idempotent';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $zeilen = DB::table('foodalchemist_vocab_kitchen_equipment')->whereNull('deleted_at')->get(['id', 'name', 'is_standard']);
        $soll = collect(self::STANDARD)->mapWithKeys(fn ($n) => [$this->norm($n) => $n]);

        $setzen = $schon = $zuruecksetzen = [];
        $gefunden = [];
        foreach ($zeilen as $z) {
            $key = $this->norm((string) $z->name);
            if ($soll->has($key)) {
                $gefunden[$key] = true;
                (bool) $z->is_standard ? $schon[] = $z : $setzen[] = $z;
            } elseif ((bool) $z->is_standard) {
                $zuruecksetzen[] = $z;
            }
        }
        $fehlt = $soll->reject(fn ($n, $k) => isset($gefunden[$k]))->values();

        if (! $dry) {
            DB::transaction(function () use ($setzen, $zuruecksetzen) {
                DB::table('foodalchemist_vocab_kitchen_equipment')->whereIn('id', array_map(fn ($z) => $z->id, $setzen))->update(['is_standard' => true]);
                DB::table('foodalchemist_vocab_kitchen_equipment')->whereIn('id', array_map(fn ($z) => $z->id, $zuruecksetzen))->update(['is_standard' => false]);
            });
        }

        $this->line(($dry ? '[Trockenlauf] ' : '') . 'Standardausstattung (' . count(self::STANDARD) . ' Namen)');
        $this->line('  neu gesetzt:        ' . $this->liste($setzen));
        $this->line('  schon gesetzt:      ' . $this->liste($schon));
        $this->line('  zurückgesetzt:      ' . $this->liste($zuruecksetzen));
        $this->line('  nicht gefunden:     ' . ($fehlt->isEmpty() ? '–' : $fehlt->implode(', ')));

        return self::SUCCESS;
    }

    private function liste(array $zeilen): string
    {
        return $zeilen === [] ? '–' : implode(', ', array_map(fn ($z) => $z->name . " (#{$z->id})", $zeilen));
    }

    private function norm(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = strtr($s, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);

        return preg_replace('/[^a-z0-9]+/', '', $s) ?? '';
    }
}
