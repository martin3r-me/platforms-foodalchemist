<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 82 §13 C3 — „Mandant beenden“ als Ablauf. Eine Zeile je Lauf (je Kunden-Hauptteam): welche Schritte verlangt sind
 * und was davon erledigt ist, damit ein Abbruch fortsetzbar bleibt und jeder Schritt idempotent läuft.
 * Reine Verwaltungsdaten (§13 G): nie Inhalte des Mandanten, nur Schrittnamen, Zustände und Zeiten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('foodalchemist_mandant_beenden_laeufe', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('team_id')->index();            // Kunden-Hauptteam (Mandant)
            $table->string('status', 16)->default('offen');            // offen | abgeschlossen | abgebrochen
            $table->json('schritte');                                  // verlangte Schritte in Reihenfolge
            $table->json('fortschritt')->nullable();                   // schritt => {status, at, von, details}
            $table->unsignedBigInteger('gestartet_von')->nullable();
            $table->timestamp('abgeschlossen_at')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_mandant_beenden_laeufe');
    }
};
