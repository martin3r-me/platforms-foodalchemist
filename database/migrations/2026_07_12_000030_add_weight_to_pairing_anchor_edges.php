<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Station 2 (Pairing-Projektion): Override-Gewicht für abgeleitete (computed)
 * Anker-Kanten. NULL = kuratiert → Gewicht typ-getrieben via PairingService::GEWICHTE
 * (unverändertes Verhalten). Gesetzt (0..1) = computed → dieses Gewicht gewinnt in
 * edgeBest()/componentSuggestions(). So heben computed-Kanten die Coverage, ohne
 * den Kohäsions-Score zu fluten (gradiert = 0,6 × Molekül-Confidence, stets < kuratiert).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_pairing_anchor_edges', function (Blueprint $table) {
            $table->double('weight')->nullable()->after('type')
                ->comment('Override-Gewicht nur für computed-Kanten (0..1); NULL = kuratiert, typ-getrieben via GEWICHTE');
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_pairing_anchor_edges', function (Blueprint $table) {
            $table->dropColumn('weight');
        });
    }
};
