<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 85 D-1 PR-C: VK-Altspalten am Rezept entfernen. Preis-Wahrheit ist die Standard-Darreichung
 * (`foodalchemist_recipe_presentations`): VK (`sales_net`/`sales_gross`), MwSt-Profil (`vat_profile_key`) und
 * Aufschlagsklasse (`markup_class_id`). PR-A/PR-B haben alle Leser umgestellt, PR-C die Schreiber.
 *
 * Datenlage: io hat 0 Rezepte (Kurator prüft vor dem Merge lesend), keine Daten-Migration nötig (D-1, Dominique
 * 18.10.). Der Rückweg legt die Spalten leer und nullable wieder an (ohne Daten).
 *
 * `markup_class_id` trägt einen Fremdschlüssel → `dropConstrainedForeignId` (auf SQLite baut Laravel die Tabelle
 * beim Spalten-Drop ohnehin neu, der Constraint verschwindet mit).
 */
return new class extends Migration
{
    private const SPALTEN = ['sales_net', 'sales_gross', 'vat_rate', 'markup_class_legacy_id'];

    public function up(): void
    {
        if (Schema::hasColumn('foodalchemist_recipes', 'markup_class_id')) {
            Schema::table('foodalchemist_recipes', function (Blueprint $table) {
                $table->dropConstrainedForeignId('markup_class_id');
            });
        }
        $vorhanden = array_values(array_filter(self::SPALTEN, fn ($s) => Schema::hasColumn('foodalchemist_recipes', $s)));
        if ($vorhanden !== []) {
            Schema::table('foodalchemist_recipes', function (Blueprint $table) use ($vorhanden) {
                $table->dropColumn($vorhanden);
            });
        }
    }

    public function down(): void
    {
        Schema::table('foodalchemist_recipes', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_recipes', 'markup_class_legacy_id')) {
                $table->unsignedBigInteger('markup_class_legacy_id')->nullable();
            }
            if (! Schema::hasColumn('foodalchemist_recipes', 'sales_net')) {
                $table->decimal('sales_net', 10, 2)->nullable();
            }
            if (! Schema::hasColumn('foodalchemist_recipes', 'sales_gross')) {
                $table->decimal('sales_gross', 10, 2)->nullable();
            }
            if (! Schema::hasColumn('foodalchemist_recipes', 'vat_rate')) {
                $table->decimal('vat_rate', 5, 2)->nullable();
            }
        });
        if (! Schema::hasColumn('foodalchemist_recipes', 'markup_class_id')) {
            Schema::table('foodalchemist_recipes', function (Blueprint $table) {
                $table->foreignId('markup_class_id')->nullable()->constrained('foodalchemist_markup_classes')->nullOnDelete();
            });
        }
    }
};
