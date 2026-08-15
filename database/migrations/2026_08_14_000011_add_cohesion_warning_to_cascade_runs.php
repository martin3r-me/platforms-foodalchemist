<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menü-Folge-Kohärenz-Gate · Auto-Trigger (Roadmap Planung-Leitstelle · Etappe 1).
 *
 * `foodalchemist_cascade_runs.cohesion_warning` (json, nullable): trägt die abgestufte Warnung
 * ({@see \Platform\FoodAlchemist\Services\PairingService::menuKohaesionWarnung}: {stufe, score, text})
 * einer per Fan-out ERFUNDENEN Menüfolge. Der Motor scored die Folge automatisch, sobald alle
 * erfundenen Gericht-Steps eines Concept-Steps durch sind (Grounding komplett → Anker vorhanden →
 * scorebar), und persistiert das Ergebnis hier — statt auf den manuellen „Kohäsion prüfen"-Klick
 * im Conceptor zu warten. `null` = nichts zu beurteilen (kein Fan-out, zu wenig Gerichte oder kein
 * bewertetes Aroma-Paar) — ein Nichts wird nicht als Warnung verkauft.
 *
 * Additiv/idempotent (hasColumn-Guard). Bestandsläufe bleiben `null` → unverändertes Verhalten.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('foodalchemist_cascade_runs') && ! Schema::hasColumn('foodalchemist_cascade_runs', 'cohesion_warning')) {
            Schema::table('foodalchemist_cascade_runs', function (Blueprint $table) {
                $table->json('cohesion_warning')->nullable()
                    ->comment('Auto-Score der erfundenen Menüfolge (menuKohaesionWarnung: stufe/score/text)');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('foodalchemist_cascade_runs') && Schema::hasColumn('foodalchemist_cascade_runs', 'cohesion_warning')) {
            Schema::table('foodalchemist_cascade_runs', function (Blueprint $table) {
                $table->dropColumn('cohesion_warning');
            });
        }
    }
};
