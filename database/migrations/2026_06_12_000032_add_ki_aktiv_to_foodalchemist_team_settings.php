<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M7-08: Kill-Switch — Team-Schalter für ALLE KI-Calls des Moduls
 * (Gateway-Guard, typisierte Exception; UI-Buttons gaten darauf).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_team_settings', function (Blueprint $table) {
            $table->boolean('ai_active')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_team_settings', function (Blueprint $table) {
            $table->dropColumn('ai_active');
        });
    }
};
