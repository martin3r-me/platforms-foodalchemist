<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 61/75 · FA-Zusatzrecht je Mitglied. Die Rollen selbst kommen aus den Team-Einstellungen der
 * Plattform (`team_user.role`: owner/admin/member/viewer) — Entscheidung Dominique 2026-10-08. Das
 * FA ergänzt nur, was die Plattform nicht kennt: „darf Rechnungen freigeben" für Mitglieder.
 * Inhaber/Admins dürfen das immer. Rein additiv.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_team_member_flags')) {
            Schema::create('foodalchemist_team_member_flags', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->nullable()->unique('fa_tmf_uuid');
                $table->unsignedBigInteger('team_id')->index('fa_tmf_team');
                $table->unsignedBigInteger('user_id')->index('fa_tmf_user');
                $table->boolean('can_approve_invoices')->default(false);
                $table->unsignedBigInteger('set_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['team_id', 'user_id'], 'fa_tmf_team_user');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_team_member_flags');
    }
};
