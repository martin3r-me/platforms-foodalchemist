<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 85 FAV-1 (Dominique 19.10.): Favoriten je Team. Eine Zeile = bei diesem Team Favorit (mit Rang) oder ausgeschlossen
 * (Standort nimmt einen übernommenen Favoriten des Hauptteams heraus). Dazu der Schalter „Standorte übernehmen unsere Favoriten“
 * am Kunden-Hauptteam (NULL = AN).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_team_favoriten')) {
            Schema::create('foodalchemist_team_favoriten', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->unsignedBigInteger('team_id')->index();
                $table->foreignId('gp_id')->constrained('foodalchemist_gps')->cascadeOnDelete();
                $table->unsignedInteger('rang')->nullable();
                $table->boolean('ausgeschlossen')->default(false);
                $table->timestamps();
                $table->unique(['team_id', 'gp_id'], 'fa_team_favoriten_team_gp_unique');
            });
        }
        if (! Schema::hasColumn('foodalchemist_team_settings', 'standorte_uebernehmen_favoriten')) {
            Schema::table('foodalchemist_team_settings', function (Blueprint $table) {
                $table->boolean('standorte_uebernehmen_favoriten')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_team_favoriten');
        if (Schema::hasColumn('foodalchemist_team_settings', 'standorte_uebernehmen_favoriten')) {
            Schema::table('foodalchemist_team_settings', fn (Blueprint $table) => $table->dropColumn('standorte_uebernehmen_favoriten'));
        }
    }
};
