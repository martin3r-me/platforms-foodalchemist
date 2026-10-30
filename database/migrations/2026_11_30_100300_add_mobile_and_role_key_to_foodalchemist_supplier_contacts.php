<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Ansprechpartner (Link-Stufe 2d): Mobilnummer und feste Rolle (Vertrieb, Disposition, Qualität/Allergene, Buchhaltung, Reklamation). `role` bleibt Freitext. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_supplier_contacts', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_supplier_contacts', 'mobile')) {
                $table->string('mobile', 64)->nullable();
            }
            if (! Schema::hasColumn('foodalchemist_supplier_contacts', 'rolle_key')) {
                $table->string('rolle_key', 24)->nullable()->index()->comment('vertrieb|disposition|qualitaet|buchhaltung|reklamation');
            }
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_supplier_contacts', function (Blueprint $table) {
            foreach (['mobile', 'rolle_key'] as $col) {
                if (Schema::hasColumn('foodalchemist_supplier_contacts', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
