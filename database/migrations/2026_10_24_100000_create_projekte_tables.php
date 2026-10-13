<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 82 §9 (Entscheidung Dominique 13.10.: Projekt = AGENTUR-AKTE im Master-Team, Übernahme bei Lizenz).
 * Ein Projekt gehört dem MASTER-Team (team_id) und hängt an einer CRM-Firma aus dem CRM des Master-Teams. Der Mandant
 * entsteht erst bei der Lizenz (Schritt 6 des Plans); bis dahin gibt es kein Mandanten-Team, deshalb kennt das Projekt eigene
 * KUNDEN-STANDORTE (Master-Daten, ohne Team). Reine Agentur-Daten, keine Inhalte eines Mandanten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('foodalchemist_projekte', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->index();                    // Master-Team (Agentur)
            $table->unsignedBigInteger('crm_company_id')->index();             // CRM-Firma im CRM des Master-Teams
            $table->string('name');
            $table->string('phase', 24)->default('zielbild');                  // zielbild|standort|dna|paketlogik|foodbook|uebergabe
            $table->string('status', 16)->default('aktiv');                    // aktiv|pausiert|abgeschlossen
            $table->text('ziel')->nullable();                                  // Ziel in einem Satz (Projektkopf)
            $table->unsignedBigInteger('pilot_standort_id')->nullable();
            $table->unsignedBigInteger('planner_projekt_id')->nullable();      // nur Verweis auf den Office-Planner
            $table->unsignedBigInteger('mandant_team_id')->nullable();         // gesetzt bei der Lizenz-Übernahme
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['team_id', 'status']);
        });

        Schema::create('foodalchemist_projekt_standorte', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->index();                    // Master-Team (Besitzer der Akte)
            $table->unsignedBigInteger('projekt_id')->index();
            $table->string('name', 160);
            $table->boolean('ist_pilot')->default(false);
            $table->unsignedInteger('sort_order')->default(100);
            $table->text('notiz')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_projekt_standorte');
        Schema::dropIfExists('foodalchemist_projekte');
    }
};
