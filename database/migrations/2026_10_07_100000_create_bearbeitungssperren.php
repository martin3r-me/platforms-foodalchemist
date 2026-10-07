<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 65 · Bearbeitungssperre: ein Datensatz (Rezept, Gericht, Foodbook, Einstellungsbereich …) wird beim Klick auf
 * „Bearbeiten" für eine Person gesperrt. Freigabe mit „Speichern"/„Abbrechen", sonst nach 15 Min ohne Aktivität.
 * Flüchtige Zeilen (keine Historie, kein SoftDelete): abgelaufen = frei, der Dienst räumt beim nächsten Sperren auf.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('foodalchemist_bearbeitungssperren')) {
            return;
        }
        Schema::create('foodalchemist_bearbeitungssperren', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('team_id')->nullable()->index();
            $table->string('ziel_typ', 64);              // z.B. recipe, foodbook, settings.kalkulation
            $table->string('ziel_id', 64);               // Datensatz-ID bzw. Team-ID bei Einstellungen
            $table->unsignedBigInteger('user_id');
            $table->string('user_name')->nullable();     // Anzeige „wird von … bearbeitet" ohne Join
            $table->timestamp('seit');
            $table->timestamp('laeuft_ab')->index();
            $table->timestamps();
            $table->unique(['ziel_typ', 'ziel_id'], 'fa_bearbeitungssperre_ziel_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_bearbeitungssperren');
    }
};
