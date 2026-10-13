<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 82 §9 (Schritt 9, PR 4): hartes Gate „Struktur + Musterseite freigeben“ am Projekt (Person = CRM-Kontakt + Zeitpunkt,
 * der Name wird festgehalten, falls der Kontakt später geändert wird) und `projekt_id` am Foodbook (Foodbook aus der Akte).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_projekte', function (Blueprint $table) {
            $table->timestamp('struktur_freigegeben_at')->nullable()->after('mandant_team_id');
            $table->unsignedBigInteger('struktur_freigegeben_von_contact_id')->nullable()->after('struktur_freigegeben_at');
            $table->string('struktur_freigegeben_von_name', 160)->nullable()->after('struktur_freigegeben_von_contact_id');
        });
        Schema::table('foodalchemist_foodbooks', function (Blueprint $table) {
            $table->unsignedBigInteger('projekt_id')->nullable()->index()->comment('Foodbook aus einer Projekt-Akte (nur nach Gate)');
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_foodbooks', function (Blueprint $table) {
            $table->dropIndex(['projekt_id']);
            $table->dropColumn('projekt_id');
        });
        Schema::table('foodalchemist_projekte', function (Blueprint $table) {
            $table->dropColumn(['struktur_freigegeben_at', 'struktur_freigegeben_von_contact_id', 'struktur_freigegeben_von_name']);
        });
    }
};
