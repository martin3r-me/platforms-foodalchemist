<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 93 D · „Was heißt das für uns“ am Trend: Kurzfazit, Bedeutung je Sparte, 3–5 Gericht-/Konzept-Impulse. Eigene Spalten,
 * damit die KI nie Definition, historische Einordnung oder Begründung überschreibt; ableitung_quelle = manuell schützt eine
 * von Hand bearbeitete Ableitung dauerhaft. ableitung_fehler hält einen gescheiterten Lauf sichtbar fest.
 * Rein additiv: neue Spalten, down() entfernt nur sie.
 */
return new class extends Migration
{
    private const SPALTEN = ['ableitung', 'ableitung_quelle', 'ableitung_at', 'ableitung_fehler'];

    public function up(): void
    {
        Schema::table('foodalchemist_trends', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_trends', 'ableitung')) {
                $table->json('ableitung')->nullable();
            }
            if (! Schema::hasColumn('foodalchemist_trends', 'ableitung_quelle')) {
                $table->string('ableitung_quelle', 8)->nullable();
            }
            if (! Schema::hasColumn('foodalchemist_trends', 'ableitung_at')) {
                $table->timestamp('ableitung_at')->nullable();
            }
            if (! Schema::hasColumn('foodalchemist_trends', 'ableitung_fehler')) {
                $table->text('ableitung_fehler')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_trends', function (Blueprint $table) {
            foreach (self::SPALTEN as $spalte) {
                if (Schema::hasColumn('foodalchemist_trends', $spalte)) {
                    $table->dropColumn($spalte);
                }
            }
        });
    }
};
