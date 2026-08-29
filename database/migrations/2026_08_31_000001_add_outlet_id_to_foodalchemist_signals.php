<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ebene 2 — die Betriebs-„Lane" für Signale. `outlet_id` NULL = Team-Core-Lane (heutiges
 * Verhalten, betriebs-unabhängige Signale bleiben hier); `outlet_id = X` = Lane von Betrieb X.
 * Die Betriebsbrille im Controlling/Review filtert nach Lane (X ODER NULL); der Dedup-Schlüssel
 * eines Signals ist ab jetzt (team, type, dedup_key, outlet_id), damit Team-Core- und
 * Betriebs-Detektion desselben Gerichts nicht kollidieren.
 *
 * Additiv, kein Backfill: der Bestand ist genau die Team-Core-Lane (NULL) — korrekt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_signals', function (Blueprint $table) {
            $table->unsignedBigInteger('outlet_id')->nullable()->after('team_id')->index();
            // Lane-gefilterte Zählungen/Listen im Controlling laufen über (team, status, type, outlet).
            $table->index(['team_id', 'status', 'type', 'outlet_id'], 'fa_signals_team_status_type_outlet_idx');
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_signals', function (Blueprint $table) {
            $table->dropIndex('fa_signals_team_status_type_outlet_idx');
            $table->dropIndex(['outlet_id']);
            $table->dropColumn('outlet_id');
        });
    }
};
