<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Grundeinstellungen-Paket (Plan B, Kurator 18.10.2026, F2): Koch-Equipment und Servierformen werden je Mandant kopiert. Dafür darf der
 * fachliche Schlüssel nicht mehr GLOBAL eindeutig sein: `vocab_kitchen_equipment.slug` und `serving_forms.code` → unique(team_id, …).
 *
 * Vorprüfung: Dubletten je (team_id, Schlüssel) brechen die Migration mit Liste ab (heute unmöglich, weil global eindeutig — die Prüfung
 * schützt einen erneuten Lauf). Zeilen mit team_id NULL schützt der neue Index in MySQL nicht; das prüft der Paket-Code selbst.
 * Rückweg (down): nur, solange kein Schlüssel in zwei Teams vorkommt — sonst Abbruch mit Liste (dann zuerst die Kopien bereinigen).
 * Idempotent; Index-Namen werden über die Spalten gefunden, nicht geraten.
 */
return new class extends Migration
{
    private const ZIELE = [
        'foodalchemist_vocab_kitchen_equipment' => ['spalte' => 'slug', 'neu' => 'fa_kitchen_equipment_team_slug_uq'],
        'foodalchemist_serving_forms' => ['spalte' => 'code', 'neu' => 'fa_serving_forms_team_code_uq'],
    ];

    public function up(): void
    {
        foreach (self::ZIELE as $tabelle => ['spalte' => $spalte, 'neu' => $neu]) {
            if (! Schema::hasTable($tabelle)) {
                continue;
            }
            $dubletten = DB::table($tabelle)->select(['team_id', $spalte, DB::raw('COUNT(*) AS n')])
                ->groupBy('team_id', $spalte)->havingRaw('COUNT(*) > 1')->get();
            if ($dubletten->isNotEmpty()) {
                throw new \RuntimeException("{$tabelle}: Dubletten je (team_id, {$spalte}) — erst bereinigen: "
                    .$dubletten->map(fn ($d) => ($d->team_id ?? 'global').'/'.$d->{$spalte}.' ×'.$d->n)->implode(', '));
            }
            if (! Schema::hasIndex($tabelle, $neu)) {
                Schema::table($tabelle, fn (Blueprint $t) => $t->unique(['team_id', $spalte], $neu));
            }
            foreach ($this->uniqueNur($tabelle, [$spalte]) as $alt) {
                Schema::table($tabelle, fn (Blueprint $t) => $t->dropUnique($alt));
            }
        }
    }

    public function down(): void
    {
        foreach (self::ZIELE as $tabelle => ['spalte' => $spalte, 'neu' => $neu]) {
            if (! Schema::hasTable($tabelle)) {
                continue;
            }
            $mehrfach = DB::table($tabelle)->select([$spalte, DB::raw('COUNT(*) AS n')])->groupBy($spalte)->havingRaw('COUNT(*) > 1')->pluck($spalte);
            if ($mehrfach->isNotEmpty()) {
                throw new \RuntimeException("{$tabelle}: Rückweg blockiert — {$spalte} kommt in mehreren Teams vor: ".$mehrfach->implode(', '));
            }
            if ($this->uniqueNur($tabelle, [$spalte]) === []) {
                Schema::table($tabelle, fn (Blueprint $t) => $t->unique($spalte));
            }
            if (Schema::hasIndex($tabelle, $neu)) {
                Schema::table($tabelle, fn (Blueprint $t) => $t->dropUnique($neu));
            }
        }
    }

    /** @return list<string> Namen der Unique-Indizes, die GENAU diese Spalten abdecken */
    private function uniqueNur(string $tabelle, array $spalten): array
    {
        return array_values(array_map(fn ($i) => $i['name'], array_filter(Schema::getIndexes($tabelle),
            fn ($i) => ($i['unique'] ?? false) && ! ($i['primary'] ?? false) && array_values($i['columns']) === $spalten)));
    }
};
