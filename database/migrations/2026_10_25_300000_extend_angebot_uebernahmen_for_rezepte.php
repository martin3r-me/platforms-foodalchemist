<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 82 §12 B / Schritt 4d: dieselbe Historie-Tabelle trägt auch die Umstellung am REZEPT (nicht nur im Angebot): `angebot_id` wird nullable, `rezept_id`
 * und `art` kommen dazu. art = angebot (4b) | rezept (Übernehmen am Rezept, bestätigt) | auto (Rezept folgte der neuen Fassung ohne Allergen-Änderung).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_angebot_uebernahmen', function (Blueprint $table) {
            $table->unsignedBigInteger('angebot_id')->nullable()->change();
            $table->unsignedBigInteger('rezept_id')->nullable()->after('angebot_id')->index();
            $table->string('art', 12)->default('angebot')->after('rezept_id');
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_angebot_uebernahmen', function (Blueprint $table) {
            $table->dropColumn(['rezept_id', 'art']);
        });
    }
};
