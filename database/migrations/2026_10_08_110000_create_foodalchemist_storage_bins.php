<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 66b · Lager einrichten: Stellplätze je Lagerort (Zone + Laufweg-Reihenfolge), Stammplatz
 * je Grundprodukt und Zählen in Gebinden (Karton · Einheit · lose). Rein additiv.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_storage_bins')) {
            Schema::create('foodalchemist_storage_bins', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->nullable()->unique();
                $table->unsignedBigInteger('team_id')->index();
                $table->unsignedBigInteger('inventory_location_id')->index();
                $table->string('name', 120);                        // „Kühlhaus · Regal A · Fach 2"
                $table->string('zone', 16)->nullable();             // kuehl | tk | trocken | getraenke | sonstig
                $table->unsignedInteger('sort_order')->default(0);  // Laufweg
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();
            });
        }
        if (! Schema::hasTable('foodalchemist_storage_bin_items')) {
            Schema::create('foodalchemist_storage_bin_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('team_id')->index();
                $table->unsignedBigInteger('inventory_location_id');
                $table->unsignedBigInteger('storage_bin_id')->index();
                $table->unsignedBigInteger('gp_id');
                $table->string('source', 16)->default('manuell');   // manuell | vorschlag
                $table->timestamps();
                // ein Stammplatz je Grundprodukt und Lagerort
                $table->unique(['team_id', 'inventory_location_id', 'gp_id'], 'fa_bin_items_team_loc_gp_unique');
            });
        }
        Schema::table('foodalchemist_inventory_count_lines', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_inventory_count_lines', 'storage_bin_id')) {
                $table->unsignedBigInteger('storage_bin_id')->nullable()->index();
                // Gebinde-Schnappschuss beim Anlegen (aus dem Lead-Artikel): 1 Karton = pack_units × Einheit,
                // 1 Einheit = unit_base (in g/ml/Stk der Zeile). null = keine Gebinde bekannt.
                $table->string('pack_label', 24)->nullable();
                $table->decimal('pack_units', 10, 3)->nullable();
                $table->string('unit_label', 24)->nullable();
                $table->decimal('unit_base', 14, 4)->nullable();
                // Eingabe, wie gezählt wurde (qty_counted bleibt die Summe in der Basiseinheit)
                $table->decimal('counted_packs', 12, 3)->nullable();
                $table->decimal('counted_units', 12, 3)->nullable();
                $table->decimal('counted_loose', 14, 4)->nullable();
            }
        });
        Schema::table('foodalchemist_inventory_counts', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_inventory_counts', 'uncounted_zeroed')) {
                $table->boolean('uncounted_zeroed')->default(false);   // „nicht gezählt = 0" beim Buchen
            }
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_inventory_counts', function (Blueprint $table) {
            $table->dropColumn('uncounted_zeroed');
        });
        Schema::table('foodalchemist_inventory_count_lines', function (Blueprint $table) {
            $table->dropIndex(['storage_bin_id']);
            $table->dropColumn(['storage_bin_id', 'pack_label', 'pack_units', 'unit_label', 'unit_base', 'counted_packs', 'counted_units', 'counted_loose']);
        });
        Schema::dropIfExists('foodalchemist_storage_bin_items');
        Schema::dropIfExists('foodalchemist_storage_bins');
    }
};
