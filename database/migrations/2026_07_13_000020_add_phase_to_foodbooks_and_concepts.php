<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R4.3 Phasen-Status: Statusmaschine Kontext → Struktur → Befüllung → Kalkulation →
 * Freigabe je Foodbook UND Konzept. Ergänzt draft/aktiv (Sichtbarkeits-Status),
 * ersetzt sie nicht — die Phase beschreibt den ARBEITSSTAND im Planungs-Workflow.
 *
 * Gate-Logik lebt im PhaseService (Kalkulation → Freigabe nur ohne rote Coverage-
 * Ampeln, Override protokolliert via LogsActivity). Default 'kontext' für Bestand.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['foodalchemist_foodbooks', 'foodalchemist_concepts'] as $tabelle) {
            if (! Schema::hasColumn($tabelle, 'phase')) {
                Schema::table($tabelle, function (Blueprint $table) {
                    $table->string('phase', 16)->default('kontext')->index(); // kontext|struktur|befuellung|kalkulation|freigabe
                    // Freigabe-Override-Protokoll (durabel am Objekt, unabhängig vom ActivityLog):
                    $table->text('phase_override_note')->nullable();
                    $table->timestamp('phase_override_at')->nullable();
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['foodalchemist_foodbooks', 'foodalchemist_concepts'] as $tabelle) {
            if (Schema::hasColumn($tabelle, 'phase')) {
                Schema::table($tabelle, function (Blueprint $table) {
                    $table->dropColumn(['phase', 'phase_override_note', 'phase_override_at']);
                });
            }
        }
    }
};
