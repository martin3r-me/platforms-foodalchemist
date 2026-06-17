<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #388 Geschirr-Datenbank — Leih-Lieferant (non-food).
 *
 * Spiegelt foodalchemist_suppliers STRUKTURELL (Lieferant → Artikel-Master-Detail),
 * aber EIGENSTÄNDIG: Geschirr-Leih-Caterer sind team-eigen und werden NICHT vom
 * WaWi-Slice-Import verwaltet (kein legacy_id / kein D1-global). Dominique-Vorgabe
 * 2026-06-17: „bei Geschirr sehe ich keine Grundprodukte sondern direkt den
 * Lieferantenartikel — der Aufbau vom Lieferantenmodul ist die Vorlage."
 *
 * 07 §7-Konvention: keine CHECK/Enums in DB, idempotenter hasTable-Guard.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('foodalchemist_geschirr_suppliers')) {
            return;
        }

        Schema::create('foodalchemist_geschirr_suppliers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->nullable()->index()->comment('team-eigen (kein D1-global)');
            $table->string('name')->index();
            $table->string('postal_code', 16)->nullable();
            $table->string('city')->nullable();
            $table->string('address')->nullable();
            $table->string('homepage')->nullable();
            $table->string('email_order')->nullable();
            $table->string('telefon', 48)->nullable();
            $table->text('note')->nullable();
            $table->boolean('is_inactive')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_geschirr_suppliers');
    }
};
