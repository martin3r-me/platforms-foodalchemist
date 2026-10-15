<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jod am Rezept/Gericht (15.10.2026): ALL-MAXIMAL über die Zutaten wie die Zusatzstoffe, aber getrennt ausgewiesen, nie Teil der
 * Pflicht-Kennzeichnung ({@see \Platform\FoodAlchemist\Models\FoodAlchemistItemDeclaration::FREIWILLIG}). Keine Daten: der nächste
 * Recompute füllt die Spalte.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('foodalchemist_recipes', 'additive_iodized')) {
            Schema::table('foodalchemist_recipes', function (Blueprint $table) {
                $table->unsignedTinyInteger('additive_iodized')->nullable()->after('additive_with_type_sugar_sweetener')
                    ->comment('jodiert, freiwillig: 0=k.A. 1=nein 3=ja');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('foodalchemist_recipes', 'additive_iodized')) {
            Schema::table('foodalchemist_recipes', fn (Blueprint $table) => $table->dropColumn('additive_iodized'));
        }
    }
};
