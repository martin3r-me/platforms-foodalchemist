<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 84 §4.4 (E3, Dominique 16.10.): Support-Zugang auf Freigabe. Kein pauschaler Admin-Zugriff auf Mandanten-Inhalte; der
 * Mandant (Owner/Admin seines Hauptteams) erteilt einem bzw. allen Plattform-Admins befristet Zugang, widerrufbar, protokolliert.
 * Eine Zeile je Freigabe; aktiv = nicht widerrufen UND gültig_bis in der Zukunft.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('foodalchemist_support_freigaben', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->index()->comment('Kunden-Hauptteam, das den Zugang erteilt');
            $table->unsignedBigInteger('fuer_user_id')->nullable()->comment('NULL = alle Plattform-Admins');
            $table->unsignedBigInteger('erteilt_von')->comment('Owner/Admin des Hauptteams');
            $table->timestamp('gueltig_bis');
            $table->timestamp('widerrufen_am')->nullable();
            $table->unsignedBigInteger('widerrufen_von')->nullable();
            $table->string('grund', 255)->nullable();
            $table->timestamps();

            $table->index(['team_id', 'widerrufen_am', 'gueltig_bis']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_support_freigaben');
    }
};
