<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 82 §9/§13 B3 + G: FA-eigene Mandanten-Tabelle (bewusst NICHT an der Core-Tabelle teams).
 * Eine Zeile je Kunden-Hauptteam (Mandant neben dem Master-Team): Status (projekt|aktiv|beendet), Modus
 * (kuratiert|autark, Default autark), CRM-Firma aus dem CRM des Master-Teams, Notiz. Es sind reine Verwaltungsdaten;
 * Inhalte des Mandanten stehen hier nie. Ohne Zeile gilt: aktiv + autark (der Normalfall). Der Modus wird nur von
 * Plattform-Admins umgeschaltet (MandantenService), jede Änderung geht ins Plattform-Protokoll.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('foodalchemist_mandanten', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('team_id')->unique();
            $table->string('status', 16)->default('aktiv');
            $table->string('modus', 16)->default('autark');
            $table->unsignedBigInteger('crm_company_id')->nullable();
            $table->text('notiz')->nullable();
            $table->unsignedBigInteger('modus_geaendert_von')->nullable();
            $table->timestamp('modus_geaendert_at')->nullable();
            $table->unsignedBigInteger('geaendert_von')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_mandanten');
    }
};
