<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 82 Abo Pakete (PR 2): eigene Kopie eines Abo-Pakets merkt Original und Stand (wie recipes/concepts/formats, 2026_10_09_100600).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('foodalchemist_packages') && ! Schema::hasColumn('foodalchemist_packages', 'kopie_von_id')) {
            Schema::table('foodalchemist_packages', function (Blueprint $table) {
                $table->unsignedBigInteger('kopie_von_id')->nullable();
                $table->timestamp('kopie_stand_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('foodalchemist_packages', 'kopie_von_id')) {
            Schema::table('foodalchemist_packages', function (Blueprint $table) {
                $table->dropColumn(['kopie_von_id', 'kopie_stand_at']);
            });
        }
    }
};
