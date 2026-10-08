<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 79 Nachtrag (Dominique 2026-10-08): zwei Achsen statt einer.
 *  - Kategorie = WAS (Food, Getränke, Ambiente & Deko, Service & Format) — „event" heißt jetzt „format",
 *    weil Veranstaltungs- und Service-Formate nicht nur Events betreffen.
 *  - Sparten = FÜR WEN (Event & Bankett, Betriebsgastronomie, Care, Bildung, Restaurant & Hotel,
 *    Delivery & To-go), Mehrfachauswahl, Filter je Team. Leer = für alle Sparten.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('foodalchemist_trends') && ! Schema::hasColumn('foodalchemist_trends', 'sparten')) {
            Schema::table('foodalchemist_trends', function (Blueprint $table) {
                $table->json('sparten')->nullable();
            });
        }
        if (Schema::hasTable('foodalchemist_trends')) {
            DB::table('foodalchemist_trends')->where('kategorie', 'event')->update(['kategorie' => 'format']);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('foodalchemist_trends')) {
            DB::table('foodalchemist_trends')->where('kategorie', 'format')->update(['kategorie' => 'event']);
            if (Schema::hasColumn('foodalchemist_trends', 'sparten')) {
                Schema::table('foodalchemist_trends', fn (Blueprint $table) => $table->dropColumn('sparten'));
            }
        }
    }
};
