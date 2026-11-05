<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vertiefung im Trend-Eingang: Kernaussagen mit Zahl, Bezug und Fundstelle aus der verlinkten Seite/PDF (nie Volltext),
 * Zugang (frei | bezahl | gesperrt | nicht_lesbar), Status und Zeitpunkt. Nullbar: Bestand bleibt unberührt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_trend_eingang', function (Blueprint $t) {
            $t->json('vertiefung')->nullable();
            $t->string('vertiefung_status', 20)->nullable();
            $t->timestamp('vertieft_at')->nullable();
            $t->index(['team_id', 'vertiefung_status'], 'fa_trend_eingang_vertiefung');
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_trend_eingang', function (Blueprint $t) {
            $t->dropIndex('fa_trend_eingang_vertiefung');
            $t->dropColumn(['vertiefung', 'vertiefung_status', 'vertieft_at']);
        });
    }
};
