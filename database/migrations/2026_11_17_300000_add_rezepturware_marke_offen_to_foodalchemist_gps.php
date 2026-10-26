<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GP-Regelwerk v3.7.1 §5 Ausnahme 3 (Dominique 26.10.2026), zwei GP-Merkmale:
 * - `rezepturware` (nullable bool): Produkt aus mehreren Zutaten mit eigener Rezeptur — dann trägt der Name Marke/Hersteller in der Klammer und
 *   ein anderer Hersteller ist ein eigenes GP. NULL = nicht entschieden (Bestand; Befüllung per Lauf aus dem Vollpass). WG 04/15 immer false.
 * - `marke_offen` (bool): Rezepturware ohne belegte Marke (Händler ohne Marke im Artikel) — Nachlauf-Liste. Nur bei rezepturware = true.
 * Beide reisen im GP-Paket (GpPaketValidator).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_gps')) {
            return;
        }
        if (! Schema::hasColumn('foodalchemist_gps', 'rezepturware')) {
            Schema::table('foodalchemist_gps', fn (Blueprint $t) => $t->boolean('rezepturware')->nullable()
                ->comment('§5 Ausnahme 3: Rezepturware (Marke/Hersteller in der Klammer); NULL = nicht entschieden'));
        }
        if (! Schema::hasColumn('foodalchemist_gps', 'marke_offen')) {
            Schema::table('foodalchemist_gps', fn (Blueprint $t) => $t->boolean('marke_offen')->default(false)
                ->comment('§5 Ausnahme 3: Rezepturware ohne belegte Marke (Nachlauf)'));
        }
    }

    public function down(): void
    {
        foreach (['rezepturware', 'marke_offen'] as $spalte) {
            if (Schema::hasTable('foodalchemist_gps') && Schema::hasColumn('foodalchemist_gps', $spalte)) {
                Schema::table('foodalchemist_gps', fn (Blueprint $t) => $t->dropColumn($spalte));
            }
        }
    }
};
