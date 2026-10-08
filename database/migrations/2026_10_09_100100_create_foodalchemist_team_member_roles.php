<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 61 §4 · FA-Rolle je Mitglied und Team (lesen | kuratieren | freigeben | admin).
 * Kein Eintrag = Lesen. Inhaber/Admins des Teams (team_user) sind immer FA-Admin — berechnet,
 * nicht gespeichert. Rein additiv, eigene FA-Tabelle (keine Core-Änderung).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_team_member_roles')) {
            Schema::create('foodalchemist_team_member_roles', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->nullable()->unique();
                $table->unsignedBigInteger('team_id')->index();
                $table->unsignedBigInteger('user_id')->index();
                $table->string('rolle', 16)->default('lesen');
                $table->unsignedBigInteger('set_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['team_id', 'user_id'], 'fa_team_member_roles_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_team_member_roles');
    }
};
