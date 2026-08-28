<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 43 — Form-Scoping der Präsentations-Designs: ein Design kann für eine
 * oder mehrere Ausgabeformen gelten (foodbook|speisekarte|speiseplan). NULL/leer
 * = gilt für alle Formen. Der Design-Picker jeder Form filtert hierauf.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('foodalchemist_presentation_designs', 'output_types')) {
            Schema::table('foodalchemist_presentation_designs', function (Blueprint $table) {
                $table->json('output_types')->nullable()->after('base_slug');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('foodalchemist_presentation_designs', 'output_types')) {
            Schema::table('foodalchemist_presentation_designs', function (Blueprint $table) {
                $table->dropColumn('output_types');
            });
        }
    }
};
