<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Herkunft der Teller-Rolle je Gerichtszeile (Dominique 11.10.): ki | kaskade | migration | manuell. Damit ordnet
 * `teller-rollen-klassifizieren --alle` neu zu, ohne Handarbeit zu überschreiben. Rückfüllung: eine Rolle, die noch dem
 * V-21-Archiv entspricht, stammt aus der Umstellung (migration); eine Rolle mit belegtem vk.rollen-Call danach ist ki (die
 * Probeläufe). Jede andere bestehende Rolle bleibt OHNE Quelle — das Kommando überschreibt sie nicht (kein erfundenes Etikett).
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

        // Belegte KI-Rollen (Kuratorin 11.10.): die Probeläufe von teller-rollen-klassifizieren schrieben über vk.rollen. Eine
        // Rolle ohne Quelle gilt als ki, wenn es für ihr Gericht einen vk.rollen-Call NACH der Umstellung gibt (Regel
        // gericht.teller_rolle angelegt) und die Zeile seither geändert wurde. Alles ohne Beleg bleibt unbekannt.
        $umstellung = DB::table('foodalchemist_rules')->whereNull('team_id')->where('schluessel', 'gericht.teller_rolle')->value('created_at');
        if ($umstellung !== null && Schema::hasTable('foodalchemist_ai_call_log')) {
            $belegt = DB::table('foodalchemist_ai_call_log')->where('feature', 'vk.rollen')->where('target_table', 'foodalchemist_recipes')
                ->where('created_at', '>=', $umstellung)->whereNull('error')->select('target_id');
            DB::table('foodalchemist_recipe_ingredients')->whereNotNull('role')->whereNull('role_source')
                ->where('updated_at', '>=', $umstellung)->whereIn('recipe_id', $belegt)
                ->update(['role_source' => 'ki']);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('foodalchemist_recipe_ingredients', 'role_source')) {
            Schema::table('foodalchemist_recipe_ingredients', fn (Blueprint $table) => $table->dropColumn('role_source'));
        }
    }
};
