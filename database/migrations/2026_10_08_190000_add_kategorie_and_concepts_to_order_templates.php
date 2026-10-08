<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 73 · Bestellvorlagen: Kategorie (Gruppierung in der Liste) + Konzept/Paket als Position. Additiv.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_order_templates', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_order_templates', 'kategorie')) {
                $table->string('kategorie', 80)->nullable()->index();
            }
        });
        Schema::table('foodalchemist_order_template_lines', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_order_template_lines', 'concept_id')) {
                $table->unsignedBigInteger('concept_id')->nullable()->index();
                $table->unsignedBigInteger('paket_id')->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_order_template_lines', function (Blueprint $table) {
            $table->dropColumn(['concept_id', 'paket_id']);
        });
        Schema::table('foodalchemist_order_templates', function (Blueprint $table) {
            $table->dropColumn('kategorie');
        });
    }
};
