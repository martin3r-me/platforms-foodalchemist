<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 77b · Bereiche je Team, Einschränkung je User, Kontingente.
 * - team_bereiche: Plattform-Admin schaltet FA-Bereiche je Team ab (keine Zeile = an; Bestand verliert nichts).
 * - user_bereich_sperren: Team-Admin schränkt einzelne User ein (nur abschalten, nie erweitern).
 * - team_kontingente: Grenzen je Team — Standorte (Unter-Teams), User, KI-Budget € je Monat. NULL = unbegrenzt.
 * Rein additiv, eigene kurze Indexnamen (MySQL ≤ 64 Zeichen).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_team_bereiche')) {
            Schema::create('foodalchemist_team_bereiche', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->nullable()->unique('fa_tb_uuid');
                $table->unsignedBigInteger('team_id')->index('fa_tb_team');
                $table->string('bereich', 32);
                $table->boolean('aktiv')->default(true);
                $table->unsignedBigInteger('set_by')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->unique(['team_id', 'bereich'], 'fa_tb_team_bereich');
            });
        }
        if (! Schema::hasTable('foodalchemist_user_bereich_sperren')) {
            Schema::create('foodalchemist_user_bereich_sperren', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->nullable()->unique('fa_ubs_uuid');
                $table->unsignedBigInteger('team_id')->index('fa_ubs_team');
                $table->unsignedBigInteger('user_id')->index('fa_ubs_user');
                $table->string('bereich', 32);
                $table->unsignedBigInteger('set_by')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->unique(['team_id', 'user_id', 'bereich'], 'fa_ubs_team_user_bereich');
            });
        }
        if (! Schema::hasTable('foodalchemist_team_kontingente')) {
            Schema::create('foodalchemist_team_kontingente', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->nullable()->unique('fa_tk_uuid');
                $table->unsignedBigInteger('team_id')->unique('fa_tk_team');
                $table->unsignedInteger('max_standorte')->nullable();
                $table->unsignedInteger('max_user')->nullable();
                $table->decimal('ki_budget_eur_monat', 10, 2)->nullable();
                $table->unsignedBigInteger('set_by')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_team_kontingente');
        Schema::dropIfExists('foodalchemist_user_bereich_sperren');
        Schema::dropIfExists('foodalchemist_team_bereiche');
    }
};
