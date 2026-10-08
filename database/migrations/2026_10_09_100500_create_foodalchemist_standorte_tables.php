<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 77c · Standorte. Jeder Standort ist ein Unter-Team; das Oberteam ordnet ihm GENAU einen seiner
 * Betriebe zu (Kalkulationsprofil, fest im Unter-Team). Die Team-Brille merkt sich je User im
 * Oberteam, welcher Standort gerade angesehen wird (eigen | alle | ein Unter-Team). Rein additiv.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_team_betriebe')) {
            Schema::create('foodalchemist_team_betriebe', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->nullable()->unique('fa_tbe_uuid');
                $table->unsignedBigInteger('team_id')->unique('fa_tbe_team');   // das Unter-Team
                $table->unsignedBigInteger('outlet_id')->index('fa_tbe_outlet'); // Betrieb des Oberteams
                $table->unsignedBigInteger('set_by')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }
        if (! Schema::hasTable('foodalchemist_team_brillen')) {
            Schema::create('foodalchemist_team_brillen', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('team_id');                          // das betrachtende Oberteam
                $table->string('sicht', 16)->default('eigen');                   // eigen | alle | team
                $table->unsignedBigInteger('sicht_team_id')->nullable();         // bei sicht = team
                $table->timestamps();
                $table->unique(['user_id', 'team_id'], 'fa_tbr_user_team');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_team_brillen');
        Schema::dropIfExists('foodalchemist_team_betriebe');
    }
};
