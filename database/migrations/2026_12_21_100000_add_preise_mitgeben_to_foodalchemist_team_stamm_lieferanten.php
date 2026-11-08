<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stamm-Preise mitgeben (Dominique 08.11.2026): je freigeschaltetem Stamm-Lieferanten eines Mandanten ein Haken, ob die Preise des
 * Master-Teams (Necta-Listenpreise der globalen Stamm-Artikel) LAUFEND mitgehen. Am Freischalt-Datensatz statt eigener Tabelle:
 * fällt die Freischaltung weg, ist die Preis-Freigabe automatisch weg. Nur Spalten hinzu, Standard aus.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_team_stamm_lieferanten') || Schema::hasColumn('foodalchemist_team_stamm_lieferanten', 'preise_mitgeben')) {
            return;
        }
        Schema::table('foodalchemist_team_stamm_lieferanten', function (Blueprint $table) {
            $table->boolean('preise_mitgeben')->default(false);
            $table->unsignedBigInteger('preise_freigegeben_von')->nullable();
            $table->timestamp('preise_freigegeben_at')->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('foodalchemist_team_stamm_lieferanten', 'preise_mitgeben')) {
            Schema::table('foodalchemist_team_stamm_lieferanten', function (Blueprint $table) {
                $table->dropColumn(['preise_mitgeben', 'preise_freigegeben_von', 'preise_freigegeben_at']);
            });
        }
    }
};
