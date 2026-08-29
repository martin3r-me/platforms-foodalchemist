<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ebene 2: durable „aktiver Betrieb" je (User, Team) — der Web-Sidebar-Dropdown speichert bisher
 * nur in der HTTP-Session, die MCP-Calls nicht teilen. Damit die Betriebs-„Brille" auch per MCP
 * (outlets.SET_ACTIVE) persistent gesetzt werden kann und von beiden Kontexten gelesen wird,
 * liegt sie zusätzlich hier. NULL outlet_id = zurück auf Team-Baseline. Ein Datensatz je User+Team.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('foodalchemist_active_outlets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('team_id');
            $table->unsignedBigInteger('outlet_id')->nullable();   // null = Team-Baseline
            $table->timestamps();
            $table->unique(['user_id', 'team_id'], 'fa_active_outlet_user_team_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_active_outlets');
    }
};
