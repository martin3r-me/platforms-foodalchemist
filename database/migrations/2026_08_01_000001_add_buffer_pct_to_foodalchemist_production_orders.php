<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Küchen-Manager-Baustein: Überproduktions-/Puffer-% je Produktionsauftrag. Skaliert bei der
 * Explosion (recomputeOrder) die Ziel-Mengen → mehr Ansätze → mehr Einkauf. Default 0 = kein Puffer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_production_orders', function (Blueprint $t) {
            $t->decimal('buffer_pct', 5, 2)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_production_orders', function (Blueprint $t) {
            $t->dropColumn('buffer_pct');
        });
    }
};
