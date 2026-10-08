<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Spec 76b · Eigene Etikettenvorlage für den Wandmonitor (Küche). Additiv. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_label_templates', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_label_templates', 'is_kitchen_default')) {
                $table->boolean('is_kitchen_default')->default(false);
            }
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_label_templates', function (Blueprint $table) {
            $table->dropColumn('is_kitchen_default');
        });
    }
};
