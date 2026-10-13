<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 82 §12 B / Durchstich Schritt 2: Fassung = eingefrorene, schreibgeschützte Rezept-Zeile (ist_fassung = 1,
 * fassung_von_id = Arbeitszeile). Hier die Felder der Veröffentlichung. recipe_key einer Fassung = <key>@f<nr>
 * (UNIQUE(team_id, recipe_key) bleibt erfüllt, auch über soft-gelöschte Fassungen).
 */
return new class extends Migration
{
    private const SPALTEN = ['fassung_nr', 'inhalt_hash', 'veroeffentlicht_at', 'veroeffentlicht_von', 'aenderungsnotiz', 'fassung_allergen_geaendert'];

    public function up(): void
    {
        Schema::table('foodalchemist_recipes', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_recipes', 'fassung_nr')) {
                $table->unsignedInteger('fassung_nr')->nullable();
            }
            if (! Schema::hasColumn('foodalchemist_recipes', 'inhalt_hash')) {
                $table->string('inhalt_hash', 64)->nullable()->comment('Inhalt der Fassung (Zutaten, Mengen, Schritte, Unter-Fassungen) — gleich = wiederverwenden');
            }
            if (! Schema::hasColumn('foodalchemist_recipes', 'veroeffentlicht_at')) {
                $table->timestamp('veroeffentlicht_at')->nullable();
            }
            if (! Schema::hasColumn('foodalchemist_recipes', 'veroeffentlicht_von')) {
                $table->unsignedBigInteger('veroeffentlicht_von')->nullable();
            }
            if (! Schema::hasColumn('foodalchemist_recipes', 'aenderungsnotiz')) {
                $table->text('aenderungsnotiz')->nullable();
            }
            if (! Schema::hasColumn('foodalchemist_recipes', 'fassung_allergen_geaendert')) {
                $table->boolean('fassung_allergen_geaendert')->default(false);
            }
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_recipes', function (Blueprint $table) {
            foreach (self::SPALTEN as $spalte) {
                if (Schema::hasColumn('foodalchemist_recipes', $spalte)) {
                    $table->dropColumn($spalte);
                }
            }
        });
    }
};
