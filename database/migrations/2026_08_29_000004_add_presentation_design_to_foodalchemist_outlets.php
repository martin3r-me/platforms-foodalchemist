<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Slice F: „Vorlage je Betrieb" — jeder Betrieb (Outlet) kann eine Standard-Präsentations-
 * Vorlage tragen (editorial|menu|kiosk|navigator oder design:{id}), die beim Veröffentlichen
 * des Betriebs-Links angewandt wird. NULL = fällt auf die Dokument-Vorlage zurück.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('foodalchemist_outlets', 'presentation_design')) {
            Schema::table('foodalchemist_outlets', function (Blueprint $table) {
                $table->string('presentation_design', 48)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('foodalchemist_outlets', 'presentation_design')) {
            Schema::table('foodalchemist_outlets', function (Blueprint $table) {
                $table->dropColumn('presentation_design');
            });
        }
    }
};
