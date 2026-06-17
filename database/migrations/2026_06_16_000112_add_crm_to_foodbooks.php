<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #369: CRM-Kunde-Link am Foodbook (wie Angebote #380) — Firma + Kontakt aus dem
 * CRM verknüpfen statt nur Freitext-`kunde`. Nullable + index, KEIN cross-modul-FK
 * (engine-agnostisch; CRM bleibt eigenständiges Modul). MVP: nur Verlinkung, kein Rücksync.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_foodbooks', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_foodbooks', 'crm_company_id')) {
                $table->unsignedBigInteger('crm_company_id')->nullable()->index();
            }
            if (! Schema::hasColumn('foodalchemist_foodbooks', 'crm_contact_id')) {
                $table->unsignedBigInteger('crm_contact_id')->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_foodbooks', function (Blueprint $table) {
            $table->dropColumn(['crm_company_id', 'crm_contact_id']);
        });
    }
};
