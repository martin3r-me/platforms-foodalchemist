<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #378 — „Signale": detektierte Auffälligkeiten (Klasse B) als persistierte Inbox
 * mit Severity, Status (offen|erledigt|ignoriert), Historie und Dedup. Die
 * Entscheidungs-Queues (Klasse A) bleiben on-the-fly in der ReviewQueue.
 *
 * Engine-agnostisch (07 §7 + Martins 7-Punkte): Enum-Werte als string (PHP-Layer),
 * keine cross-modul-FKs (ref_type/ref_id als schlichte Spalten), keine CHECK/->after().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('foodalchemist_signals', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->nullable()->index();

            $table->string('type', 32)->index();                      // SignalTyp (PHP-Enum)
            $table->string('severity', 12)->default('warnung');       // SignalSeverity
            $table->string('status', 12)->default('offen')->index();  // SignalStatus

            $table->string('title');
            $table->text('description')->nullable();
            $table->json('payload')->nullable();                      // strukturierte Details (Refs/Werte)

            // Dedup + Bezug auf das betroffene Objekt (schlichte Spalten, kein FK).
            $table->string('dedup_key')->nullable()->index();
            $table->string('ref_type', 64)->nullable();
            $table->unsignedBigInteger('ref_id')->nullable();
            $table->string('source', 32)->nullable();                 // z.B. 'detektor'

            $table->timestamp('erledigt_at')->nullable();
            $table->timestamp('ignoriert_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['team_id', 'status', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_signals');
    }
};
