<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reparatur (Befund 2026-09-04): `fa_recipe_darreichungen_ein_standard` sollte ein
 * PARTIELLER Unique-Index sein — „genau ein Standard pro Gericht", also
 * `(recipe_id) WHERE is_standard = 1 AND deleted_at IS NULL`.
 *
 * Auf SQLite überlebt er seine Erzeugung in 2026_07_03_000004 nicht: 2026_07_03_000007
 * hängt einen Fremdschlüssel an dieselbe Tabelle, SQLite kann das nicht in-place, und
 * Laravel baut die Tabelle neu — dabei schreibt es die Indizes mit der eigenen Grammatik
 * nach und die WHERE-Bedingung fällt weg. Aus „ein Standard" wurde ein voller UNIQUE auf
 * `recipe_id`: die Testbasis verbot MEHRERE Darreichungen je Gericht, während demo (MySQL,
 * wo der Index gar nicht angelegt wird) sie erlaubt.
 *
 * Folge war nicht bloss ein roter Test: ein Feature auf Darreichungs-Varianten liess sich
 * gar nicht testen, und ein grüner Test hätte über die MySQL-Wirklichkeit nichts gesagt.
 * Gefunden beim Bau der Report-Hochrechnung („50 Teller vs. 50 Platten").
 *
 * Idempotent: prüft die Index-Definition, ersetzt nur den verstümmelten. MySQL = No-Op.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_recipe_presentations')) {
            return;
        }
        $treiber = DB::connection()->getDriverName();
        if (! in_array($treiber, ['sqlite', 'pgsql'], true)) {
            return;                                                // MySQL: keine partiellen Indizes
        }

        if ($treiber === 'sqlite') {
            $vorhanden = collect(DB::select(
                "SELECT sql FROM sqlite_master WHERE type='index' AND name='fa_recipe_darreichungen_ein_standard'"
            ))->first();
            if ($vorhanden !== null && str_contains(mb_strtolower((string) $vorhanden->sql), 'where')) {
                return;                                            // schon partiell — nichts zu tun
            }
            if ($vorhanden !== null) {
                DB::statement('DROP INDEX fa_recipe_darreichungen_ein_standard');
            }
        }

        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS fa_recipe_darreichungen_ein_standard'
            .' ON foodalchemist_recipe_presentations (recipe_id)'
            .' WHERE is_standard = 1 AND deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        // Kein Rückbau: der Index ist die beabsichtigte Invariante, nicht die Änderung.
    }
};
