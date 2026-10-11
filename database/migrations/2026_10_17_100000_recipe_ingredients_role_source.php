<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Herkunft der Teller-Rolle je Gerichtszeile (Dominique 11.10.): ki | kaskade | migration | manuell. Damit ordnet
 * `teller-rollen-klassifizieren --alle` neu zu, ohne Handarbeit zu überschreiben. Rückfüllung: eine Rolle, die noch dem
 * V-21-Archiv entspricht, stammt aus der Umstellung (migration). Jede andere bestehende Rolle bleibt OHNE Quelle — sie
 * kann seit der Umstellung von KI oder Mensch stammen; das Kommando überschreibt sie nicht (kein erfundenes Etikett).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('foodalchemist_recipe_ingredients', 'role_source')) {
            return;
        }
        Schema::table('foodalchemist_recipe_ingredients', function (Blueprint $table) {
            $table->string('role_source', 12)->nullable()->after('role');
        });
        DB::table('foodalchemist_recipe_ingredients')->whereNotNull('role')->whereColumn('role', 'role_v21')
            ->update(['role_source' => 'migration']);
    }

    public function down(): void
    {
        if (Schema::hasColumn('foodalchemist_recipe_ingredients', 'role_source')) {
            Schema::table('foodalchemist_recipe_ingredients', fn (Blueprint $table) => $table->dropColumn('role_source'));
        }
    }
};
