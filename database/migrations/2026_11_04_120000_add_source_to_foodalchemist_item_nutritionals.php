<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Lineage wie bei item_allergens/item_declarations: NULL = Import, `produktpass_ki` = aus dem Produktpass gelesen. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('foodalchemist_item_nutritionals', 'source')) {
            Schema::table('foodalchemist_item_nutritionals', function (Blueprint $table) {
                $table->string('source', 16)->nullable()->after('raw_json')->comment('NULL=Import | produktpass_ki');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('foodalchemist_item_nutritionals', 'source')) {
            Schema::table('foodalchemist_item_nutritionals', function (Blueprint $table) {
                $table->dropColumn('source');
            });
        }
    }
};
