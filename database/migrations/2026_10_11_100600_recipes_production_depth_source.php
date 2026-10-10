<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Herkunft der Fertigungstiefe (kaskade | ki | manuell). Die Kaskade setzt sie deterministisch (Zukauf → convenience,
 * TK-Hauptzeile → teilfertig); die KI-Anreicherung darf sie dann nicht überschreiben (Dominique 10.10.).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('foodalchemist_recipes', 'production_depth_source')) {
            Schema::table('foodalchemist_recipes', function (Blueprint $table) {
                $table->string('production_depth_source', 12)->nullable()->after('production_depth');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('foodalchemist_recipes', 'production_depth_source')) {
            Schema::table('foodalchemist_recipes', fn (Blueprint $table) => $table->dropColumn('production_depth_source'));
        }
    }
};
