<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GP: Diät-Marker des NAMENS als Felder (Entscheid Dominique 25.10.2026): „… / (Vegan)“, „(Glutenfrei)“, „(Laktosefrei)“ sind ein Namensmerkmal wie „(Bio)“,
 * hatten aber kein Feld — der Name war aus den gespeicherten Feldern nicht rekonstruierbar. Drei Boolesche Spalten (statt JSON): einzeln abfragbar, einzeln in der
 * Paket-Whitelist, feste Render-Reihenfolge im Namen (Bio, Vegan, Glutenfrei, Laktosefrei). Bewusst GETRENNT von den Artikel-Tags `tag_is_*` (tri-state, aus den
 * Lieferantenartikeln abgeleitet, sagen etwas über die Ware, nicht über die Namensentscheidung des GP). Bestand = false; Befüllung per Stufe-1-Lauf aus den Namen.
 */
return new class extends Migration
{
    private const SPALTEN = ['namens_vegan', 'namens_glutenfrei', 'namens_laktosefrei'];

    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_gps')) {
            return;
        }
        foreach (self::SPALTEN as $spalte) {
            if (! Schema::hasColumn('foodalchemist_gps', $spalte)) {
                Schema::table('foodalchemist_gps', function (Blueprint $table) use ($spalte) {
                    $table->boolean($spalte)->default(false)->comment('Namensmarker „/ (' . ucfirst(substr($spalte, 7)) . ')“ — Namensentscheid, nicht der Artikel-Tag tag_is_*');
                });
            }
        }
    }

    public function down(): void
    {
        foreach (self::SPALTEN as $spalte) {
            if (Schema::hasTable('foodalchemist_gps') && Schema::hasColumn('foodalchemist_gps', $spalte)) {
                Schema::table('foodalchemist_gps', fn (Blueprint $table) => $table->dropColumn($spalte));
            }
        }
    }
};
