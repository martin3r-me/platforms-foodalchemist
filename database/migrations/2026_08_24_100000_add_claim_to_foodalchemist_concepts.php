<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Format-Modul (Phase D, Concepter 2.0): `claim` pro Concept — als Edition (=
 * Unterkapitel eines Formats) bekommt es damit Foodbook-Kapitel-Parität beim
 * Wording: consumer_name (Konsumenten-Titel) + claim (NEU) + description
 * (Hinführung). Nutzbar auch im Concepter allgemein.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_concepts')
            || Schema::hasColumn('foodalchemist_concepts', 'claim')) {
            return;
        }

        Schema::table('foodalchemist_concepts', function (Blueprint $table) {
            $table->string('claim')->nullable()->after('consumer_name');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('foodalchemist_concepts')
            || ! Schema::hasColumn('foodalchemist_concepts', 'claim')) {
            return;
        }

        Schema::table('foodalchemist_concepts', function (Blueprint $table) {
            $table->dropColumn('claim');
        });
    }
};
