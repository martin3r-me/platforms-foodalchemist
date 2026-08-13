<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WaWi light: Kontingent/Rahmenabruf am Lieferantenartikel.
 * Keine Lagerbuchung, sondern operative Sicht auf verfügbare Rahmenmenge.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_supplier_items', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_supplier_items', 'quota_qty_packs')) {
                $table->decimal('quota_qty_packs', 12, 2)->nullable()->after('is_discontinued');
            }
            if (! Schema::hasColumn('foodalchemist_supplier_items', 'quota_used_packs')) {
                $table->decimal('quota_used_packs', 12, 2)->nullable()->after('quota_qty_packs');
            }
            if (! Schema::hasColumn('foodalchemist_supplier_items', 'quota_valid_from')) {
                $table->date('quota_valid_from')->nullable()->after('quota_used_packs');
            }
            if (! Schema::hasColumn('foodalchemist_supplier_items', 'quota_valid_to')) {
                $table->date('quota_valid_to')->nullable()->after('quota_valid_from');
            }
            if (! Schema::hasColumn('foodalchemist_supplier_items', 'quota_note')) {
                $table->text('quota_note')->nullable()->after('quota_valid_to');
            }
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_supplier_items', function (Blueprint $table) {
            foreach (['quota_note', 'quota_valid_to', 'quota_valid_from', 'quota_used_packs', 'quota_qty_packs'] as $column) {
                if (Schema::hasColumn('foodalchemist_supplier_items', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
