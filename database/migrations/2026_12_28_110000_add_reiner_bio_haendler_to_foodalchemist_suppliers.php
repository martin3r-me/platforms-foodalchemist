<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GP-Regelwerk v3.10.0 Regel 94 Nr. 5 (Entscheid Dominique 12.11.2026): Ein reiner Bio-Händler (z. B. EPOS Bio Partner Süd) führt nur Bio-Ware —
 * jeder seiner Artikel gilt als Bio und das GP trägt „/ (Bio)“. Lieferanten-Merkmal, gepflegt über foodalchemist.suppliers.PUT
 * (input.reiner_bio_haendler). Gelesen über {@see \Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem::istBio()}. Bestand = false. Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_suppliers') || Schema::hasColumn('foodalchemist_suppliers', 'reiner_bio_haendler')) {
            return;
        }
        Schema::table('foodalchemist_suppliers', fn (Blueprint $t) => $t->boolean('reiner_bio_haendler')->default(false)
            ->comment('Regel 94: reiner Bio-Händler — jeder Artikel gilt als Bio'));
    }

    public function down(): void
    {
        if (Schema::hasTable('foodalchemist_suppliers') && Schema::hasColumn('foodalchemist_suppliers', 'reiner_bio_haendler')) {
            Schema::table('foodalchemist_suppliers', fn (Blueprint $t) => $t->dropColumn('reiner_bio_haendler'));
        }
    }
};
