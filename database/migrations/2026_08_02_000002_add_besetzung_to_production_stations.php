<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stufe 3 P3.1 — Posten-Besetzung (Rollen × Anzahl) + Schicht + Topf-Deckel.
 *
 * Aus der Besetzung leitet sich die Kapazität ab (Σ Köpfe × Schicht-Minuten); ein manuell
 * gesetztes `kapazitaet_min_pro_tag` (oder Wochentag-Override) GEWINNT weiter (Override-Ebene).
 * Der Kostensatz je Personen-Minute ergibt sich aus den Rollensätzen der Besetzung.
 *
 * `batch_max_kg`/`batch_max_pieces` = Geräte-/Topf-Deckel des Postens (der 200-l-Kessel).
 * Beim Rechnen gilt das Minimum aus Rezept- und Posten-Deckel (siehe P3.2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_production_stations', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_production_stations', 'besetzung')) {
                $table->json('besetzung')->nullable()
                    ->comment('{role_id: anzahl} — Rollen-Besetzung des Postens; leitet Kapazität + Kosten ab');
            }
            if (! Schema::hasColumn('foodalchemist_production_stations', 'schicht_minuten')) {
                $table->unsignedInteger('schicht_minuten')->nullable()
                    ->comment('Netto-Schicht-Minuten je Kopf/Tag; × Köpfe = abgeleitete Kapazität');
            }
            if (! Schema::hasColumn('foodalchemist_production_stations', 'batch_max_kg')) {
                $table->decimal('batch_max_kg', 10, 3)->nullable()
                    ->comment('Topf-/Geräte-Deckel in kg je Koch-Vorgang. NULL = kein Posten-Deckel.');
            }
            if (! Schema::hasColumn('foodalchemist_production_stations', 'batch_max_pieces')) {
                $table->decimal('batch_max_pieces', 10, 2)->nullable()
                    ->comment('Topf-/Geräte-Deckel in Stück je Koch-Vorgang. NULL = kein Posten-Deckel.');
            }
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_production_stations', function (Blueprint $table) {
            $table->dropColumn(['besetzung', 'schicht_minuten', 'batch_max_kg', 'batch_max_pieces']);
        });
    }
};
