<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 83 B2 (Erweiterung): Team-Vorgabe „Posten für Gerichte" (Pass).
 *
 * Gerichte (is_sales_recipe) gehen an den Pass; ohne Rezept-Posten bekommt ihre Auftragszeile diesen Posten. Eine Spalte an
 * `team_settings`, nullable, OHNE harten FK: ein FK-Nachtrag lässt Laravel die Tabelle auf SQLite neu bauen (zerstört partielle Indizes),
 * und die Sichtbarkeit des Postens (eigenes Team + Kette) prüft der Code über `TeamScope`/`visibleToTeam`, nicht die Datenbank.
 *
 * Zweite Spalte in derselben Migration: `production_stations.ist_sammelposten` (Dominique, Spec 83 B3g). Der Pass ist ein SAMMELPOSTEN ohne
 * eigenes Personal; die Posten, deren Komponenten im Gericht stecken, arbeiten sich dort zu. Kapazität und Last hängen deshalb nicht am Pass
 * selbst (keine Überlast-Warnung für ihn); die Verteilung auf die beteiligten Posten baut die Planung.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('foodalchemist_team_settings', 'gerichte_station_id')) {
            Schema::table('foodalchemist_team_settings', function (Blueprint $table) {
                $table->unsignedBigInteger('gerichte_station_id')->nullable();
            });
        }
        if (! Schema::hasColumn('foodalchemist_production_stations', 'ist_sammelposten')) {
            Schema::table('foodalchemist_production_stations', function (Blueprint $table) {
                $table->boolean('ist_sammelposten')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('foodalchemist_team_settings', 'gerichte_station_id')) {
            Schema::table('foodalchemist_team_settings', function (Blueprint $table) {
                $table->dropColumn('gerichte_station_id');
            });
        }
        if (Schema::hasColumn('foodalchemist_production_stations', 'ist_sammelposten')) {
            Schema::table('foodalchemist_production_stations', function (Blueprint $table) {
                $table->dropColumn('ist_sammelposten');
            });
        }
    }
};
