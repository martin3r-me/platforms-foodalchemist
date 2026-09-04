<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * GN-Nennvolumen auf EINE Quelle vereinheitlichen: Händlerblatt nach **EuroNorm 631-1**
 * (Badorf Fachgroßhandel, GN-Behälter Edelstahl mit Randverstärkung), ergänzt um GN 1/9
 * (METRO/WAS Germany: 1/9-100 mm = 1,0 l).
 *
 * Anlass: der erste Seed mischte Werte aus mehreren Händlerseiten. Drei davon lagen ZU HOCH
 * (GN 1/1-40 mit 5,5 statt 5,0 l · GN 1/2-40 mit 2,3 statt 2,0 l · GN 1/3-40 mit 1,4 statt 1,5 —
 * letzteres zu niedrig). Ein zu hohes Nennvolumen schlägt systematisch ZU WENIGE Behälter vor,
 * und das merkt niemand, bis die Ware nicht hineinpasst.
 *
 * ★ Nennvolumen schwanken zwischen Herstellern real um ein paar Prozent (GN 1/1-65 wird als
 * 8,8 und als 9,0 l verkauft). Deshalb nicht „der richtige Wert", sondern: EINE konsistente
 * Quelle schlägt einen Mix aus vielen. Wer genauer weiß, pflegt am Behälter nach.
 *
 * Neue Größen: GN 1/3-20, GN 1/6-200 und die Füllung von GN 1/2-20 (stand auf NULL und war
 * damit „nicht bemessbar").
 */
return new class extends Migration
{
    private const TABELLE = 'foodalchemist_vocab_containers';

    /** format => [laenge, breite, [tiefe => liter]] — EuroNorm 631-1. */
    private const NORM = [
        '1/1' => [530, 325, [20 => 2.5, 40 => 5.0, 65 => 9.0, 100 => 14.0, 150 => 21.0, 200 => 28.0]],
        '1/2' => [325, 265, [20 => 1.25, 40 => 2.0, 65 => 4.0, 100 => 6.5, 150 => 9.5, 200 => 12.5]],
        '1/3' => [325, 176, [20 => 0.75, 40 => 1.5, 65 => 2.5, 100 => 4.0, 150 => 5.7, 200 => 7.8]],
        '1/4' => [265, 162, [65 => 1.8, 100 => 2.8, 150 => 4.0, 200 => 5.5]],
        '1/6' => [176, 162, [65 => 1.0, 100 => 1.6, 150 => 2.4, 200 => 3.4]],
    ];

    public function up(): void
    {
        // Nur Teams anfassen, die ueberhaupt GN fuehren — sonst legte diese Migration einem
        // fremden Team einen Katalog an, den es nie bestellt hat.
        $teams = DB::table(self::TABELLE)->whereNull('deleted_at')->where('familie', 'GN')
            ->select('team_id')->distinct()->pluck('team_id');

        foreach ($teams as $teamId) {
            foreach (self::NORM as $format => [$laenge, $breite, $tiefen]) {
                foreach ($tiefen as $tiefe => $liter) {
                    $this->setzeOderLege($teamId, $format, $laenge, $breite, (int) $tiefe, (float) $liter);
                }
            }
        }
    }

    public function down(): void
    {
        // Nicht umkehrbar: welcher Wert vorher aus welcher Haendlerseite stammte, ist nicht
        // rekonstruierbar. Rueckweg ist das Backup.
    }

    private function setzeOderLege(?int $teamId, string $format, int $laenge, int $breite, int $tiefe, float $liter): void
    {
        $zeile = DB::table(self::TABELLE)->whereNull('deleted_at')
            ->when($teamId === null, fn ($q) => $q->whereNull('team_id'), fn ($q) => $q->where('team_id', $teamId))
            ->where('familie', 'GN')->where('format_code', $format)->where('tiefe_mm', $tiefe)
            ->first(['id']);

        if ($zeile !== null) {
            DB::table(self::TABELLE)->where('id', $zeile->id)->update([
                'volumen_l' => $liter,
                'laenge_mm' => $laenge,
                'breite_mm' => $breite,
                'updated_at' => now(),
            ]);

            return;
        }

        $name = "GN {$format} {$tiefe}mm";
        DB::table(self::TABELLE)->insert([
            'uuid' => (string) Str::uuid(),
            'team_id' => $teamId,
            'slug' => Str::slug($name, '_'),
            'name' => $name,
            'group_name' => 'GN',
            'familie' => 'GN',
            'format_code' => $format,
            'laenge_mm' => $laenge,
            'breite_mm' => $breite,
            'tiefe_mm' => $tiefe,
            'volumen_l' => $liter,
            'nutzfaktor' => 0.85,
            'sort_order' => 100,
            'is_inactive' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
