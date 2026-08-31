<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #380 Composer — Per-Kapitel-PAX: jedes Angebot-Kapitel kann eine eigene Gästezahl tragen
 * (z. B. Tages-VA: Frühstück 80 · Dinner 50). NULL = erbt die Angebots-Pax (Anfrage-Tab).
 * Der Gesamtpreis summiert je Kapitel effektive_pax × €/Person. Additiv/nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_offer_chapters', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_offer_chapters', 'personen')) {
                $table->unsignedInteger('personen')->nullable()->after('price_mode');
            }
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_offer_chapters', function (Blueprint $table) {
            if (Schema::hasColumn('foodalchemist_offer_chapters', 'personen')) {
                $table->dropColumn('personen');
            }
        });
    }
};
